<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\API\UserAPI;
use Pinova\Helpers\JWT;
use Pinova\Install;
use Pinova\Logging\LogRepository;
use Pinova\Models\OTP;
use Pinova\Objects\Identifier;
use Pinova\Services\UserService;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class OTPPurposeIntegrationTest extends WP_UnitTestCase {
	private bool $had_remote_address;

	private string $remote_address = '';

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();

		$this->had_remote_address = isset( $_SERVER['REMOTE_ADDR'] );
		$this->remote_address     = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$_SERVER['REMOTE_ADDR']   = '192.0.2.25';

		OTP::query()->delete();
		LogRepository::delete_all();
		update_option(
			'pinova_logging',
			[
				'minimum_level'    => 'info',
				'retention_days'   => 14,
				'diagnostic_until' => 0,
			]
		);
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		OTP::query()->delete();
		LogRepository::delete_all();
		delete_option( 'pinova_logging' );
		wp_set_current_user( 0 );

		if ( $this->had_remote_address ) {
			$_SERVER['REMOTE_ADDR'] = $this->remote_address;
		} else {
			unset( $_SERVER['REMOTE_ADDR'] );
		}

		parent::tear_down();
	}

	public function test_authenticate_reuses_only_an_otp_with_the_requested_purpose(): void {
		$user_id = self::factory()->user->create(
			[
				'user_email' => 'purpose-reuse@example.test',
				'role'       => 'subscriber',
			]
		);
		$login_otp  = $this->create_otp( $user_id, 'purpose-reuse@example.test', OTP::TYPE_LOGIN );
		$forgot_otp = $this->create_otp( $user_id, 'purpose-reuse@example.test', OTP::TYPE_FORGET );

		$forgot_response = $this->authenticate( 'purpose-reuse@example.test', true, false );
		$forgot_payload  = JWT::decode( $forgot_response->get_data()['data']['jwt'] );

		self::assertSame( (int) $forgot_otp->id, (int) $forgot_payload['otp_id'] );

		$login_response = $this->authenticate( 'purpose-reuse@example.test', false, true );
		$login_payload  = JWT::decode( $login_response->get_data()['data']['jwt'] );

		self::assertSame( (int) $login_otp->id, (int) $login_payload['otp_id'] );
	}

	public function test_login_otp_cannot_authorize_password_recovery(): void {
		$user_id = self::factory()->user->create(
			[
				'user_email' => 'login-purpose@example.test',
				'role'       => 'subscriber',
			]
		);
		$otp = $this->create_otp( $user_id, 'login-purpose@example.test', OTP::TYPE_LOGIN );

		$response = $this->verify( 'forgot', $otp, '1234' );

		self::assertSame( 401, $response->get_status() );
		self::assertFalse( $response->get_data()['success'] );
		self::assertNull( $otp->fresh()->verified_at );
		self::assertSame( 0, (int) $otp->fresh()->attempts );
		self::assertSame( 0, get_current_user_id() );

		$log = LogRepository::paginate( 1, 1 )['rows'][0] ?? [];
		self::assertSame( 'otp.verify_failed', $log['event'] ?? null );
		self::assertStringContainsString( 'purpose_mismatch', $log['context'] ?? '' );
		self::assertStringNotContainsString( 'login-purpose@example.test', $log['context'] ?? '' );
		self::assertStringNotContainsString( '1234', $log['context'] ?? '' );
	}

	public function test_registration_otp_cannot_authorize_password_recovery_or_create_a_user(): void {
		$mobile     = '09351234567';
		$user_count = count_users()['total_users'];
		$otp        = $this->create_otp( 0, $mobile, OTP::TYPE_REGISTER );

		$response = $this->verify( 'forgot', $otp, '1234' );

		self::assertSame( 401, $response->get_status() );
		self::assertFalse( $response->get_data()['success'] );
		self::assertNull( $otp->fresh()->verified_at );
		self::assertSame( 0, (int) $otp->fresh()->attempts );
		self::assertSame( $user_count, count_users()['total_users'] );
		self::assertNull( UserService::get_by_mobile( $mobile ) );
	}

	public function test_registration_otp_remains_valid_for_the_login_endpoint(): void {
		$mobile = '09351234568';
		$otp    = $this->create_otp( 0, $mobile, OTP::TYPE_REGISTER );

		$response = $this->verify( 'login', $otp, '1234' );

		self::assertSame( 200, $response->get_status() );
		self::assertTrue( $response->get_data()['success'] );
		self::assertGreaterThan( 0, get_current_user_id() );
		self::assertSame( get_current_user_id(), UserService::get_by_mobile( $mobile ) );
	}

	public function test_password_recovery_otp_cannot_authorize_login(): void {
		$user_id = self::factory()->user->create(
			[
				'user_email' => 'recovery-purpose@example.test',
				'role'       => 'subscriber',
			]
		);
		$otp = $this->create_otp( $user_id, 'recovery-purpose@example.test', OTP::TYPE_FORGET );

		$response = $this->verify( 'login', $otp, '1234' );

		self::assertSame( 401, $response->get_status() );
		self::assertFalse( $response->get_data()['success'] );
		self::assertNull( $otp->fresh()->verified_at );
		self::assertSame( 0, (int) $otp->fresh()->attempts );
		self::assertSame( 0, get_current_user_id() );
	}

	public function test_concurrent_login_and_recovery_codes_remain_independently_valid_for_their_own_flows(): void {
		$user_id = self::factory()->user->create(
			[
				'user_email' => 'concurrent-purpose@example.test',
				'role'       => 'subscriber',
			]
		);
		$login_otp    = $this->create_otp( $user_id, 'concurrent-purpose@example.test', OTP::TYPE_LOGIN, '1357' );
		$recovery_otp = $this->create_otp( $user_id, 'concurrent-purpose@example.test', OTP::TYPE_FORGET, '2468' );

		$recovery_response = $this->verify( 'forgot', $recovery_otp, '2468' );

		self::assertSame( 200, $recovery_response->get_status() );
		self::assertTrue( $recovery_response->get_data()['success'] );
		self::assertNotNull( $recovery_otp->fresh()->verified_at );
		self::assertNull( $login_otp->fresh()->verified_at );

		$login_response = $this->verify( 'login', $login_otp, '1357' );

		self::assertSame( 200, $login_response->get_status() );
		self::assertTrue( $login_response->get_data()['success'] );
		self::assertNotNull( $login_otp->fresh()->verified_at );
		self::assertSame( $user_id, get_current_user_id() );
	}

	private function create_otp( int $user_id, string $identifier, string $type, string $code = '1234' ): OTP {
		return OTP::query()->create(
			[
				'user_id'    => $user_id > 0 ? $user_id : null,
				'identifier' => $identifier,
				'code'       => $code,
				'type'       => $type,
				'channels'   => [ 'email' => true ],
			]
		);
	}

	private function authenticate( string $identifier, bool $forget, bool $force_otp ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/pinova/user/authenticate' );
		$request->set_param( 'identifier', new Identifier( $identifier ) );
		$request->set_param( 'forget', $forget );
		$request->set_param( 'force_otp', $force_otp );

		return ( new UserAPI() )->authenticate( $request );
	}

	private function verify( string $purpose, OTP $otp, string $code ): WP_REST_Response {
		$route = 'forgot' === $purpose ? '/pinova/auth/forgot/verify' : '/pinova/user/login/otp';
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_param( 'jwt', JWT::encode( [ 'otp_id' => $otp->id ] ) );
		$request->set_param( 'code', $code );

		$api = new UserAPI();

		return 'forgot' === $purpose ? $api->forgot_verify( $request ) : $api->login_otp( $request );
	}
}
