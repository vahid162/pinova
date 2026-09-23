<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Carbon\Carbon;
use Pinova\API\UserAPI;
use Pinova\Helpers\JWT;
use Pinova\Install;
use Pinova\Logging\BuildMetadata;
use Pinova\Logging\Logger;
use Pinova\Logging\LogRepository;
use Pinova\Models\OTP;
use Pinova\Objects\Identifier;
use Pinova\Services\OTPService;
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

	public function test_new_otp_reuses_one_server_signed_flow_across_requests(): void {
		global $wpdb;

		$user_id = self::factory()->user->create( [ 'user_email' => 'flow-issue@example.test', 'role' => 'subscriber' ] );
		$mail    = static fn(): bool => true;
		add_filter( 'pre_wp_mail', $mail );
		try {
			$first  = $this->authenticate( 'flow-issue@example.test', false, true );
			$second = $this->authenticate( 'flow-issue@example.test', false, true );
		} finally {
			remove_filter( 'pre_wp_mail', $mail );
		}

		self::assertSame( 200, $first->get_status() );
		self::assertSame( 200, $second->get_status() );
		$first_payload  = JWT::decode( $first->get_data()['data']['jwt'] );
		$second_payload = JWT::decode( $second->get_data()['data']['jwt'] );
		$otp            = OTP::query()->findOrFail( $first_payload['otp_id'] );
		self::assertSame( $user_id, (int) $otp->user_id );
		self::assertMatchesRegularExpression( '/\A[a-f0-9]{32}\z/', $otp->flow_id );
		self::assertSame( $otp->flow_id, $first_payload['flow_id'] );
		self::assertSame( $first_payload['flow_id'], $second_payload['flow_id'] );
		self::assertSame( $first_payload['otp_id'], $second_payload['otp_id'] );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT `flow_id`, `context` FROM %i WHERE `event` = %s ORDER BY `id` DESC LIMIT 1', $wpdb->prefix . 'pinova_logs', 'otp.created' ), ARRAY_A );
		self::assertSame( $otp->flow_id, $row['flow_id'] );
		self::assertArrayNotHasKey( 'flow_id', json_decode( $row['context'], true ) );
	}

	public function test_signed_flow_mismatch_and_unsigned_override_cannot_verify(): void {
		$otp = $this->create_otp( self::factory()->user->create( [ 'role' => 'subscriber' ] ), 'flow-verify@example.test', OTP::TYPE_LOGIN );
		$otp->flow_id = str_repeat( 'a', 32 );
		$otp->save();
		$jwt = JWT::encode( [ 'otp_id' => $otp->id, 'flow_id' => str_repeat( 'b', 32 ) ] );

		$request = new WP_REST_Request( 'POST', '/pinova/user/login/otp' );
		$request->set_param( 'jwt', $jwt );
		$request->set_param( 'code', '1234' );
		self::assertSame( 401, ( new UserAPI() )->login_otp( $request )->get_status() );
		self::assertNull( $otp->fresh()->verified_at );
		self::assertSame( 0, (int) $otp->fresh()->attempts );

		$request->set_param( 'jwt', JWT::encode( [ 'otp_id' => $otp->id, 'flow_id' => $otp->flow_id ] ) );
		$request->set_param( 'flow_id', $otp->flow_id );
		self::assertSame( 400, ( new UserAPI() )->login_otp( $request )->get_status() );
		self::assertNull( $otp->fresh()->verified_at );
	}

	public function test_otp_verification_and_session_use_the_record_flow(): void {
		global $wpdb;

		$user_id      = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$otp          = $this->create_otp( $user_id, 'flow-session@example.test', OTP::TYPE_LOGIN );
		$otp->flow_id = str_repeat( 'c', 32 );
		$otp->save();
		$request = new WP_REST_Request( 'POST', '/pinova/user/login/otp' );
		$request->set_param( 'jwt', JWT::encode( [ 'otp_id' => $otp->id, 'flow_id' => $otp->flow_id ] ) );
		$request->set_param( 'code', '1234' );

		self::assertSame( 200, ( new UserAPI() )->login_otp( $request )->get_status() );
		self::assertSame( $user_id, get_current_user_id() );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT `event`, `flow_id` FROM %i WHERE `event` IN (%s, %s) ORDER BY `id` ASC', $wpdb->prefix . 'pinova_logs', 'otp.verified', 'auth.session_created' ), ARRAY_A );
		self::assertSame( [ [ 'event' => 'otp.verified', 'flow_id' => $otp->flow_id ], [ 'event' => 'auth.session_created', 'flow_id' => $otp->flow_id ] ], $rows );
	}

	public function test_recovery_keeps_the_flow_in_server_signed_reset_state(): void {
		$user_id      = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$user         = get_userdata( $user_id );
		$otp          = $this->create_otp( $user_id, $user->user_email, OTP::TYPE_FORGET );
		$otp->flow_id = str_repeat( 'd', 32 );
		$otp->save();
		$request = new WP_REST_Request( 'POST', '/pinova/auth/forgot/verify' );
		$request->set_param( 'jwt', JWT::encode( [ 'otp_id' => $otp->id, 'flow_id' => $otp->flow_id ] ) );
		$request->set_param( 'code', '1234' );

		$response = ( new UserAPI() )->forgot_verify( $request );
		self::assertSame( 200, $response->get_status() );
		$state = JWT::decode( $response->get_data()['data']['jwt'] );
		self::assertSame( $user_id, $state['user_id'] );
		self::assertSame( $otp->flow_id, $state['flow_id'] );
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

		$this->assert_verification_log( $otp, 'otp.verify_failed', [ 'reason' => 'purpose_mismatch' ] );
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
		$this->assert_verification_log( $otp, 'otp.verify_failed', [ 'reason' => 'purpose_mismatch' ] );
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

	/**
	 * @dataProvider verification_failure_reasons
	 */
	public function test_known_record_failures_log_only_trusted_identifier_metadata( string $reason ): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$otp     = $this->create_otp( $user_id, 'Trusted-OTP@example.test', OTP::TYPE_LOGIN );
		$extra   = [ 'reason' => $reason ];

		if ( 'expired' === $reason ) {
			$otp->expires_at = Carbon::now()->subMinute();
		} elseif ( 'already_verified' === $reason ) {
			$otp->verified_at = Carbon::now();
		} elseif ( 'attempt_limit' === $reason ) {
			$otp->attempts = 5;
		} elseif ( 'ip_mismatch' === $reason ) {
			$_SERVER['REMOTE_ADDR']  = '192.0.2.26';
			$extra['ip_fingerprint'] = Logger::instance()->fingerprint( '192.0.2.26', 'ip' );
		}
		$otp->save();

		$expected_types = 'purpose_mismatch' === $reason ? [ OTP::TYPE_FORGET ] : [ OTP::TYPE_LOGIN ];
		$code           = 'invalid_code' === $reason ? '5678' : '1234';
		$caught         = null;
		try {
			OTPService::verify( JWT::encode( [ 'otp_id' => $otp->id ] ), $code, $expected_types );
		} catch ( \Exception $exception ) {
			$caught = $exception;
		}

		self::assertInstanceOf( \Exception::class, $caught );
		self::assertSame( 'already_verified' === $reason, $otp->fresh()->isVerified() );
		$attempts = 'attempt_limit' === $reason ? 5 : ( 'invalid_code' === $reason ? 1 : 0 );
		self::assertSame( $attempts, (int) $otp->fresh()->attempts );
		self::assertSame( 0, get_current_user_id() );

		if ( in_array( $reason, [ 'expired', 'already_verified', 'attempt_limit', 'invalid_code' ], true ) ) {
			$extra['attempts'] = $attempts;
		}
		$this->assert_verification_log( $otp, 'otp.verify_failed', $extra );
	}

	public static function verification_failure_reasons(): array {
		return [
			'purpose mismatch' => [ 'purpose_mismatch' ],
			'expired'          => [ 'expired' ],
			'already verified' => [ 'already_verified' ],
			'attempt limit'    => [ 'attempt_limit' ],
			'IP mismatch'      => [ 'ip_mismatch' ],
			'invalid code'     => [ 'invalid_code' ],
		];
	}

	/**
	 * @dataProvider verification_identifiers
	 */
	public function test_successful_verification_logs_identifier_not_code_fingerprint( string $identifier ): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$otp     = $this->create_otp( $user_id, $identifier, OTP::TYPE_FORGET );

		$user = OTPService::verify( JWT::encode( [ 'otp_id' => $otp->id ] ), '1234', [ OTP::TYPE_FORGET ] );

		self::assertSame( $user_id, $user->ID );
		self::assertTrue( $otp->fresh()->isVerified() );
		$this->assert_verification_log( $otp, 'otp.verified' );
	}

	public static function verification_identifiers(): array {
		return [
			'email'    => [ 'Trusted-OTP@example.test' ],
			'mobile'   => [ '09351234569' ],
			'username' => [ 'trusted-otp-user' ],
		];
	}

	/**
	 * @dataProvider untrusted_verification_reasons
	 */
	public function test_verification_without_a_record_omits_identifier_metadata( string $reason ): void {
		$jwt = JWT::encode(
			[
				'otp_id'                 => 0,
				'user_id'                => 99,
				'otp_type'               => OTP::TYPE_LOGIN,
				'identifier'             => 'untrusted-otp@example.test',
				'identifier_type'        => 'email',
				'identifier_fingerprint' => str_repeat( 'a', 32 ),
			]
		);
		if ( 'invalid_token' === $reason ) {
			$jwt .= 'invalid-signature';
		}

		$caught = null;
		try {
			OTPService::verify( $jwt, '1234', [ OTP::TYPE_LOGIN ] );
		} catch ( \Exception $exception ) {
			$caught = $exception;
		}

		self::assertInstanceOf( \Exception::class, $caught );
		$rows = LogRepository::paginate( 1, 10 )['rows'];
		self::assertCount( 1, $rows );
		self::assertSame( 'otp.verify_failed', $rows[0]['event'] );
		self::assertNull( $rows[0]['user_id'] );
		self::assertNull( $rows[0]['flow_id'] );
		$context = json_decode( $rows[0]['context'], true );
		self::assertSame( $reason, $context['reason'] );
		self::assertArrayHasKey( 'exception_class', $context );
		self::assertSame( [ 'reason', 'exception_class', 'exception_code' ], array_values( array_diff( array_keys( $context ), [ 'build_commit', 'package_identity', 'release_tag' ] ) ) );
		self::assertStringNotContainsString( 'untrusted-otp@example.test', $rows[0]['context'] );
		self::assertStringNotContainsString( $jwt, $rows[0]['context'] );
	}

	public static function untrusted_verification_reasons(): array {
		return [
			'invalid token'  => [ 'invalid_token' ],
			'missing record' => [ 'record_not_found' ],
		];
	}

	private function assert_verification_log( OTP $otp, string $event, array $extra = [] ): void {
		$rows = array_values(
			array_filter(
				LogRepository::paginate( 1, 10 )['rows'],
				static fn( array $row ): bool => $event === $row['event']
			)
		);
		self::assertCount( 1, $rows );
		self::assertSame( $event, $rows[0]['event'] );
		self::assertSame( $otp->flow_id, $rows[0]['flow_id'] );
		if ( null === $otp->user_id ) {
			self::assertNull( $rows[0]['user_id'] );
		} else {
			self::assertSame( (int) $otp->user_id, (int) $rows[0]['user_id'] );
		}

		$identifier  = new Identifier( (string) $otp->identifier );
		$fingerprint = Logger::instance()->fingerprint( $identifier->get_value(), $identifier->get_type() );
		$expected    = $extra + [
			'otp_type'               => $otp->type,
			'identifier_type'        => $identifier->get_type(),
			'identifier_fingerprint' => $fingerprint,
		];
		$expected    = array_merge( $expected, BuildMetadata::info() );
		$context = json_decode( $rows[0]['context'], true );
		self::assertIsArray( $context );
		ksort( $expected );
		ksort( $context );
		self::assertSame( $expected, $context );
		self::assertNotSame( Logger::instance()->fingerprint( '1234', $identifier->get_type() ), $fingerprint );
		self::assertNotSame( Logger::instance()->fingerprint( $identifier->get_value(), 'otp' ), $fingerprint );
		self::assertStringNotContainsString( $identifier->get_value(), $rows[0]['context'] );
		self::assertStringNotContainsString( (string) $otp->code, $rows[0]['context'] );
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
