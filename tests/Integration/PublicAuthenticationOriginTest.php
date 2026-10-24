<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Install;
use WP_REST_Request;
use WP_UnitTestCase;

final class PublicAuthenticationOriginTest extends WP_UnitTestCase {
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'pinova_rate_limits' ) );
		wp_set_current_user( 0 );
	}

	/** @dataProvider foreign_headers */
	public function test_cross_site_password_requests_cannot_create_a_session( array $headers ): void {
		$id = self::factory()->user->create( [ 'user_login' => 'origin_fixture', 'user_pass' => 'Fixture-password-874!', 'role' => 'subscriber' ] );
		$cookies = 0;
		$observe = static function () use ( &$cookies ): void { ++$cookies; };
		add_action( 'set_auth_cookie', $observe );
		try {
			$response = $this->dispatch( $headers );
			self::assertSame( 403, $response->get_status() );
			self::assertSame( 'pinova_invalid_origin', $response->get_data()['code'] );
			self::assertSame( 0, $cookies );
			self::assertSame( '', get_user_meta( $id, 'pinova_login_method', true ) );
		} finally {
			remove_action( 'set_auth_cookie', $observe );
			wp_set_current_user( 0 );
		}
	}

	public static function foreign_headers(): array {
		return [
			'foreign origin' => [ [ 'Origin' => 'https://foreign.example.invalid' ] ],
			'opaque origin' => [ [ 'Origin' => 'null' ] ],
			'origin path' => [ [ 'Origin' => 'https://foreign.example.invalid/path' ] ],
			'origin credentials' => [ [ 'Origin' => 'https://user@foreign.example.invalid' ] ],
			'origin query' => [ [ 'Origin' => 'https://foreign.example.invalid?value=1' ] ],
			'foreign referer' => [ [ 'Referer' => 'https://foreign.example.invalid/form' ] ],
			'cross-site metadata' => [ [ 'Sec-Fetch-Site' => 'cross-site' ] ],
		];
	}

	public function test_same_origin_and_headerless_clients_keep_native_password_hooks(): void {
		self::factory()->user->create( [ 'user_login' => 'origin_fixture', 'user_pass' => 'Fixture-password-874!', 'role' => 'subscriber' ] );
		foreach ( [ [], [ 'Origin' => $this->origin( home_url() ) ], [ 'Referer' => home_url( '/login/' ) ] ] as $headers ) {
			$cookies = 0;
			$observe = static function () use ( &$cookies ): void { ++$cookies; };
			add_action( 'set_auth_cookie', $observe );
			try {
				$response = $this->dispatch( $headers );
				self::assertSame( 200, $response->get_status() );
				self::assertTrue( $response->get_data()['success'] );
				self::assertSame( 1, $cookies );
			} finally {
				remove_action( 'set_auth_cookie', $observe );
				wp_set_current_user( 0 );
			}
		}
	}

	public function test_default_port_and_host_case_are_normalized(): void {
		$parts = wp_parse_url( home_url() );
		if ( isset( $parts['port'] ) ) {
			$origin = $parts['scheme'] . '://' . strtoupper( $parts['host'] ) . ':' . $parts['port'];
		} else {
			$origin = $parts['scheme'] . '://' . strtoupper( $parts['host'] ) . ':' . ( 'https' === $parts['scheme'] ? '443' : '80' );
		}
		self::factory()->user->create( [ 'user_login' => 'origin_fixture', 'user_pass' => 'Fixture-password-874!', 'role' => 'subscriber' ] );
		try {
			self::assertSame( 200, $this->dispatch( [ 'Origin' => $origin ] )->get_status() );
		} finally {
			wp_set_current_user( 0 );
		}
	}

	private function dispatch( array $headers ) {
		global $wp_rest_server;
		$previous = $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		try {
			do_action( 'rest_api_init', $wp_rest_server );
			$request = new WP_REST_Request( 'POST', '/pinova/user/login/password' );
			$request->set_body_params( [ 'identifier' => 'origin_fixture', 'password' => 'Fixture-password-874!' ] );
			foreach ( $headers as $name => $value ) {
				$request->set_header( $name, $value );
			}
			return rest_do_request( $request );
		} finally {
			$wp_rest_server = $previous;
		}
	}

	private function origin( string $url ): string {
		$parts = wp_parse_url( $url );
		return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}
}
