<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Logging\Logger;
use Pinova\Pinova;
use WP_REST_Response;
use WP_UnitTestCase;

final class RESTCorrelationIntegrationTest extends WP_UnitTestCase {

	/** @dataProvider response_statuses */
	public function test_served_pinova_responses_have_the_request_reference( int $status ): void {
		$server = $this->serve(
			'/pinova/correlation-test/result',
			[],
			static function () use ( $status ): void {
				register_rest_route(
					'pinova/correlation-test',
					'/result',
					[
						'methods'             => 'POST',
						'permission_callback' => '__return_true',
						'callback'            => static fn() => new WP_REST_Response( [ 'safe' => true ], $status ),
					]
				);
			}
		);

		self::assertSame( $status, $server->status );
		self::assertSame( Logger::instance()->correlation_id(), $server->sent_headers['X-Pinova-Correlation-ID'] );
	}

	public static function response_statuses(): array {
		return array_map( static fn( int $status ): array => [ $status ], [ 200, 400, 401, 403, 429, 500, 503 ] );
	}

	/** @dataProvider framework_failures */
	public function test_framework_rejections_also_have_the_reference( string $route, array $params, int $status ): void {
		$server = $this->serve( $route, $params );
		self::assertSame( $status, $server->status );
		self::assertSame( Logger::instance()->correlation_id(), $server->sent_headers['X-Pinova-Correlation-ID'] );
	}

	public static function framework_failures(): array {
		return [
			'missing argument' => [ '/pinova/user/authenticate', [], 400 ],
			'invalid code'     => [ '/pinova/user/login/otp', [ 'jwt' => 'opaque-token', 'code' => 'bad' ], 400 ],
			'permission'       => [ '/pinova/admin/test/sms', [ 'identifier' => '09120000000' ], 401 ],
			'unknown route'    => [ '/pinova/no-such-route', [], 404 ],
			'mixed-case argument error' => [ '/PiNoVa/user/authenticate', [], 400 ],
			'mixed-case permission' => [ '/PINOVA/admin/test/sms', [ 'identifier' => '09120000000' ], 401 ],
			'mixed-case unknown route' => [ '/PINOVA/no-such-route', [], 404 ],
		];
	}

	public function test_authentication_filter_rejection_receives_the_header(): void {
		$reject = static fn() => new \WP_Error( 'test_auth_denied', 'Safe denial', [ 'status' => 403 ] );
		add_filter( 'rest_authentication_errors', $reject, 999 );
		try {
			$server = $this->serve( '/pinova/user/authenticate', [] );
		} finally {
			remove_filter( 'rest_authentication_errors', $reject, 999 );
		}
		self::assertSame( 403, $server->status );
		self::assertSame( Logger::instance()->correlation_id(), $server->sent_headers['X-Pinova-Correlation-ID'] );
	}

	public function test_an_existing_reference_is_preserved_case_insensitively(): void {
		$server = $this->serve(
			'/pinova/correlation-test/existing',
			[],
			static function (): void {
				register_rest_route(
					'pinova/correlation-test',
					'/existing',
					[
						'methods'             => 'POST',
						'permission_callback' => '__return_true',
						'callback'            => static fn() => new WP_REST_Response( [], 200, [ 'x-pinova-correlation-id' => 'existing-server-reference' ] ),
					]
				);
			}
		);
		self::assertSame( 'existing-server-reference', $server->sent_headers['x-pinova-correlation-id'] );
		self::assertArrayNotHasKey( 'X-Pinova-Correlation-ID', $server->sent_headers );
	}

	public function test_unrelated_and_prefix_lookalike_routes_are_untouched(): void {
		foreach ( [ '/wp/v2/no-such-route', '/pinova-lookalike/no-such-route' ] as $route ) {
			$server = $this->serve( $route, [] );
			self::assertArrayNotHasKey( 'X-Pinova-Correlation-ID', $server->sent_headers );
		}
	}

	public function test_admin_api_client_dependencies_are_registered(): void {
		Pinova::instance()->enqueue_admin( 'toplevel_page_pinova' );
		self::assertContains( 'pinova-global', wp_scripts()->registered['pinova-admin']->deps );
		self::assertContains( 'pinova-notyf', wp_scripts()->registered['pinova-global']->deps );
	}

	private function serve( string $route, array $params, ?callable $register = null ): \Spy_REST_Server {
		global $wp_rest_server;
		$previous_server = $wp_rest_server;
		$previous_get    = $_GET;
		$previous_post   = $_POST;
		$previous_env    = $_SERVER;
		$server         = new \Spy_REST_Server();
		$wp_rest_server = $server;
		$_GET           = [];
		$_POST          = $params;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		unset( $_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_X_WP_NONCE'] );
		wp_set_current_user( 0 );

		try {
			do_action( 'rest_api_init', $server );
			if ( null !== $register ) {
				$register();
			}
			$server->serve_request( $route );
		} finally {
			$wp_rest_server = $previous_server;
			$_GET           = $previous_get;
			$_POST          = $previous_post;
			$_SERVER        = $previous_env;
		}

		return $server;
	}
}
