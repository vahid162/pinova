<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Install;
use Pinova\Helper;
use WP_REST_Request;
use WP_UnitTestCase;

final class PublicRestValidationTest extends WP_UnitTestCase {
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	/** @dataProvider structured_inputs */
	public function test_malformed_fields_receive_controlled_validation_errors( string $route, array $params, string $field ): void {
		global $wp_rest_server;
		$previous = $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		wp_set_current_user( 0 );
		try {
			do_action( 'rest_api_init', $wp_rest_server );
			$request = new WP_REST_Request( 'POST', $route );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $params ) );
			$response = rest_do_request( $request );
			self::assertSame( 400, $response->get_status() );
			$data = $response->get_data();
			self::assertSame( 'rest_invalid_param', $data['code'] );
			self::assertArrayHasKey( $field, $data['data']['params'] );
		} finally {
			$wp_rest_server = $previous;
			wp_set_current_user( 0 );
		}
	}

	public static function structured_inputs(): array {
		$cases = [];
		foreach ( [ [], new \stdClass() ] as $value ) {
			$cases[] = [ '/pinova/user/authenticate', [ 'identifier' => $value ], 'identifier' ];
			$cases[] = [ '/pinova/user/login/password', [ 'identifier' => 'validation_fixture', 'password' => $value ], 'password' ];
			$cases[] = [ '/pinova/user/login/otp', [ 'jwt' => $value, 'code' => '1234' ], 'jwt' ];
			$cases[] = [ '/pinova/user/login/otp', [ 'jwt' => 'opaque-fixture', 'code' => $value ], 'code' ];
			$cases[] = [ '/pinova/auth/forgot/verify', [ 'jwt' => $value, 'code' => '1234' ], 'jwt' ];
			$cases[] = [ '/pinova/auth/forgot/change', [ 'jwt' => $value, 'reset_key' => 'fixture-key', 'password_1' => 'Fixture-password-874!', 'password_2' => 'Fixture-password-874!' ], 'jwt' ];
			$cases[] = [ '/pinova/auth/forgot/change', [ 'jwt' => 'opaque-fixture', 'reset_key' => $value, 'password_1' => 'Fixture-password-874!', 'password_2' => 'Fixture-password-874!' ], 'reset_key' ];
			$cases[] = [ '/pinova/auth/forgot/change', [ 'jwt' => 'opaque-fixture', 'reset_key' => 'fixture-key', 'password_1' => $value, 'password_2' => 'Fixture-password-874!' ], 'password_1' ];
			$cases[] = [ '/pinova/auth/forgot/change', [ 'jwt' => 'opaque-fixture', 'reset_key' => 'fixture-key', 'password_1' => 'Fixture-password-874!', 'password_2' => $value ], 'password_2' ];
			$cases[] = [ '/pinova/user/authenticate', [ 'identifier' => 'validation_fixture', 'back_url' => $value ], 'back_url' ];
		}
		return $cases;
	}

	public function test_numeric_and_localized_otp_digits_still_validate(): void {
		foreach ( [ 1234, '۱۲۳۴', '١٢٣٤' ] as $value ) {
			$request = new WP_REST_Request( 'POST', '/pinova/user/login/otp' );
			self::assertTrue( \Pinova\Services\ValidationService::code( $value, $request, 'code' ) );
			self::assertSame( '1234', $request->get_param( 'code' ) );
		}
	}

	public function test_structured_redirect_query_uses_the_same_site_fallback(): void {
		$previous_get  = $_GET;
		$previous_post = $_POST;
		try {
			$_GET['back_url'] = [ 'unexpected' ];
			unset( $_POST['back_url'] );
			self::assertSame( site_url(), Helper::get_login_back_url() );
			self::assertSame( site_url(), Helper::get_logout_back_url() );
			$_POST['back_url'] = [ 'unexpected' ];
			self::assertSame( site_url(), Helper::get_login_back_url() );
			self::assertSame( site_url(), Helper::get_logout_back_url() );
		} finally {
			$_GET  = $previous_get;
			$_POST = $previous_post;
		}
	}
}
