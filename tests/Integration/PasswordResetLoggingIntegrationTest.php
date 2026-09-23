<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\API\UserAPI;
use Pinova\Helpers\JWT;
use Pinova\Install;
use Pinova\Logging\LogRepository;
use Pinova\Models\OTP;
use Pinova\Services\UserService;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class PasswordResetLoggingIntegrationTest extends WP_UnitTestCase {
	/** @var array<string, mixed> */
	private array $original_server;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->original_server = $_SERVER;
		$_SERVER['REMOTE_ADDR'] = '192.0.2.81';
		$wpdb->query( "DELETE FROM {$wpdb->prefix}pinova_rate_limits WHERE scope IN ('log_reset_failed', 'log_reset_succeeded')" );
		OTP::query()->delete();
		LogRepository::delete_all();
		update_option( 'pinova_logging', [ 'minimum_level' => 'info', 'diagnostic_until' => 0 ] );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$_SERVER = $this->original_server;
		OTP::query()->delete();
		LogRepository::delete_all();
		delete_option( 'pinova_logging' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** @dataProvider change_failures */
	public function test_reset_change_failure_branches_preserve_responses_and_log_only_safe_facts( string $reason, int $status ): void {
		$user = self::factory()->user->create_and_get( [ 'role' => 'native_only_policy' === $reason ? 'administrator' : 'subscriber' ] );
		$jwt = 'invalid_token' === $reason ? 'private-invalid-jwt' : UserService::generate_jwt( 'user_not_found' === $reason ? 99999999 : $user->ID );
		$response = $this->change( $jwt, 'private-invalid-reset-key', 'password_mismatch' === $reason ? 'not-the-same-password' : 'New-secret-password-123!' );

		self::assertSame( $status, $response->get_status() );
		self::assertFalse( $response->get_data()['success'] );
		self::assertSame( 0, get_current_user_id() );
		$record = $this->assert_reset_event( 'auth.password_reset_failed', $reason );
		self::assertSame( 'notice', $record['level'] );
		self::assertStringContainsString( 'forgot_change', $record['context'] );
		$this->assert_no_secrets( [ $jwt, $user->user_login, $user->user_email, 'private-invalid-reset-key', 'not-the-same-password' ] );
	}

	/** @return array<string, array{string, int}> */
	public function change_failures(): array {
		return [
			'mismatched passwords' => [ 'password_mismatch', 400 ],
			'bad signed state' => [ 'invalid_token', 401 ],
			'missing account' => [ 'user_not_found', 401 ],
			'native-only policy' => [ 'native_only_policy', 401 ],
			'invalid reset key' => [ 'invalid_reset_key', 401 ],
		];
	}

	public function test_signed_recovery_flow_is_kept_on_password_mismatch_without_logging_secrets(): void {
		$user    = self::factory()->user->create_and_get( [ 'role' => 'subscriber' ] );
		$flow_id = str_repeat( 'f', 32 );
		$jwt     = UserService::generate_jwt( $user->ID, $flow_id );
		$response = $this->change( $jwt, 'private-reset-key', 'different-password' );
		self::assertSame( 400, $response->get_status() );
		self::assertSame( $flow_id, $this->assert_reset_event( 'auth.password_reset_failed', 'password_mismatch' )['flow_id'] );
		$this->assert_no_secrets( [ $jwt, 'private-reset-key', 'different-password' ] );
	}

	public function test_repeated_failures_with_changing_tokens_and_reasons_emit_one_event(): void {
		$this->change( 'private-invalid-jwt-a', 'private-reset-key-a' );
		$this->change( 'private-invalid-jwt-b', 'private-reset-key-b', 'mismatch-password' );
		$this->assert_reset_event( 'auth.password_reset_failed', 'invalid_token' );
		self::assertSame( 1, LogRepository::paginate()['total'] );
	}

	public function test_expired_valid_reset_key_is_logged_as_rejected_without_identifying_its_contents(): void {
		$user = self::factory()->user->create_and_get( [ 'role' => 'subscriber' ] );
		$key = get_password_reset_key( $user );
		self::assertIsString( $key );
		$expire = static function (): int { return -1; };
		add_filter( 'password_reset_expiration', $expire );
		try {
			$response = $this->change( UserService::generate_jwt( $user->ID ), $key );
		} finally {
			remove_filter( 'password_reset_expiration', $expire );
		}
		self::assertSame( 401, $response->get_status() );
		$this->assert_reset_event( 'auth.password_reset_failed', 'invalid_reset_key' );
		$this->assert_no_secrets( [ $key ] );
	}

	/** @dataProvider verify_failures */
	public function test_recovery_verification_logs_native_policy_and_reset_key_generation_failures( string $reason, int $status ): void {
		$user = self::factory()->user->create_and_get( [ 'role' => 'native_only_policy' === $reason ? 'administrator' : 'subscriber' ] );
		$otp = OTP::query()->create( [ 'user_id' => $user->ID, 'identifier' => $user->user_email, 'code' => '7294', 'type' => OTP::TYPE_FORGET, 'channels' => [ 'email' => true ] ] );
		$request = new WP_REST_Request( 'POST', '/pinova/auth/forgot/verify' );
		$jwt = JWT::encode( [ 'otp_id' => $otp->id ] );
		$request->set_param( 'jwt', $jwt );
		$request->set_param( 'code', '7294' );
		$deny = static function () use ( $reason ) {
			if ( 'reset_key_generation_failed' === $reason ) {
				throw new \RuntimeException( 'private-key-generation-exception' );
			}
			return new \WP_Error( 'private-security-policy', 'private-policy-detail' );
		};
		add_filter( 'allow_password_reset', $deny );
		try {
			$response = ( new UserAPI() )->forgot_verify( $request );
		} finally {
			remove_filter( 'allow_password_reset', $deny );
		}
		self::assertSame( $status, $response->get_status() );
		self::assertFalse( $response->get_data()['success'] );
		self::assertSame( 0, get_current_user_id() );
		$this->assert_reset_event( 'auth.password_reset_failed', $reason );
		$this->assert_no_secrets( [ $user->user_login, $user->user_email, $jwt, 'private-key-generation-exception', 'private-policy-detail', '"7294"' ] );
	}

	/** @return array<string, array{string, int}> */
	public function verify_failures(): array {
		return [
			'native-only policy' => [ 'native_only_policy', 401 ],
			'core policy denial' => [ 'reset_key_rejected', 500 ],
			'key generation throwable' => [ 'reset_key_generation_failed', 500 ],
		];
	}

	public function test_native_reset_hook_failure_is_controlled_and_does_not_log_its_message(): void {
		$user = self::factory()->user->create_and_get( [ 'role' => 'subscriber' ] );
		$key = get_password_reset_key( $user );
		self::assertIsString( $key );
		$fail = static function (): void { throw new \RuntimeException( 'private-reset-hook-password' ); };
		add_action( 'password_reset', $fail );
		try {
			$response = $this->change( UserService::generate_jwt( $user->ID ), $key );
		} finally {
			remove_action( 'password_reset', $fail );
		}
		self::assertSame( 500, $response->get_status() );
		self::assertFalse( $response->get_data()['success'] );
		self::assertSame( 0, get_current_user_id() );
		$this->assert_reset_event( 'auth.password_reset_failed', 'reset_pipeline_failed' );
		self::assertNotContains( 'auth.password_reset_succeeded', array_column( LogRepository::paginate( 1, 100 )['rows'], 'event' ) );
		$this->assert_no_secrets( [ $key, 'private-reset-hook-password' ] );
	}

	public function test_success_uses_native_reset_hooks_preserves_identity_and_logs_after_reset(): void {
		$user = self::factory()->user->create_and_get( [ 'role' => 'subscriber' ] );
		$flow_id = str_repeat( 'e', 32 );
		$key = get_password_reset_key( $user );
		self::assertIsString( $key );
		$seen = [];
		$before = static function () use ( &$seen ): void { $seen[] = 'before'; };
		$after = static function () use ( &$seen ): void {
			$seen[] = 'after';
			self::assertNotContains( 'auth.password_reset_succeeded', array_column( LogRepository::paginate( 1, 100 )['rows'], 'event' ) );
		};
		add_action( 'password_reset', $before );
		add_action( 'after_password_reset', $after );
		try {
			$response = $this->change( UserService::generate_jwt( $user->ID, $flow_id ), $key );
		} finally {
			remove_action( 'password_reset', $before );
			remove_action( 'after_password_reset', $after );
		}
		self::assertTrue( $response->get_data()['success'] );
		self::assertSame( $user->ID, get_current_user_id() );
		self::assertSame( [ 'before', 'after' ], $seen );
		$updated = get_userdata( $user->ID );
		self::assertSame( $user->user_login, $updated->user_login );
		self::assertTrue( wp_check_password( 'New-secret-password-123!', $updated->user_pass, $user->ID ) );
		$record = $this->assert_reset_event( 'auth.password_reset_succeeded' );
		self::assertSame( 'info', $record['level'] );
		self::assertSame( $user->ID, (int) $record['user_id'] );
		self::assertSame( $flow_id, $record['flow_id'] );
		$session = array_values( array_filter( LogRepository::paginate( 1, 100 )['rows'], static fn( array $row ): bool => 'auth.session_created' === $row['event'] ) );
		self::assertSame( $flow_id, $session[0]['flow_id'] );
		$this->assert_no_secrets( [ $user->user_login, $user->user_email, $key ] );
	}

	public function test_logger_failure_does_not_interrupt_successful_password_reset(): void {
		$user = self::factory()->user->create_and_get( [ 'role' => 'subscriber' ] );
		$key = get_password_reset_key( $user );
		self::assertIsString( $key );
		$fail = static function (): void { throw new \RuntimeException( 'private-logging-config-failure' ); };
		add_filter( 'pinova/logging_minimum_level', $fail );
		try {
			$response = $this->change( UserService::generate_jwt( $user->ID ), $key );
		} finally {
			remove_filter( 'pinova/logging_minimum_level', $fail );
		}
		self::assertTrue( $response->get_data()['success'] );
		self::assertSame( $user->ID, get_current_user_id() );
		self::assertSame( 0, LogRepository::paginate()['total'] );
	}

	private function change( string $jwt, string $key, string $confirmation = 'New-secret-password-123!' ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/pinova/auth/forgot/change' );
		$request->set_param( 'jwt', $jwt );
		$request->set_param( 'reset_key', $key );
		$request->set_param( 'password_1', 'New-secret-password-123!' );
		$request->set_param( 'password_2', $confirmation );
		return ( new UserAPI() )->forgot_change( $request );
	}

	/** @return array<string, mixed> */
	private function assert_reset_event( string $event, string $reason = '' ): array {
		$records = array_values( array_filter( LogRepository::paginate( 1, 100 )['rows'], static function ( array $row ) use ( $event ): bool { return $event === $row['event']; } ) );
		self::assertCount( 1, $records );
		if ( '' !== $reason ) {
			self::assertStringContainsString( '"reason":"' . $reason . '"', $records[0]['context'] );
		}
		return $records[0];
	}

	/** @param string[] $additional */
	private function assert_no_secrets( array $additional = [] ): void {
		$serialized = wp_json_encode( LogRepository::paginate( 1, 100 )['rows'] );
		foreach ( array_merge( [ 'New-secret-password-123!', '192.0.2.81' ], $additional ) as $secret ) {
			self::assertStringNotContainsString( $secret, $serialized );
		}
	}
}
