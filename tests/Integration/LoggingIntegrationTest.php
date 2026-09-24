<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Admin\Logs;
use Pinova\API\AdminAPI;
use Pinova\API\RestAPI;
use Pinova\Exceptions\RateLimitException;
use Pinova\Gateways\BaseGateway;
use Pinova\Install;
use Pinova\Logging\Logger;
use Pinova\Logging\LogRepository;
use Pinova\Objects\Identifier;
use Pinova\Services\RateLimitService;
use Pinova\Services\UserService;
use WP_REST_Request;
use WP_UnitTestCase;

final class LoggingTestGateway extends BaseGateway {

	protected string $name = 'Logging test gateway';

	protected string $url = 'example.test';

	public static bool $fail_with_error = false;
	public static bool $fail_with_exception = false;
	public static bool $return_false = false;

	public function send( string $mobile, string $message ): bool {
		if ( self::$fail_with_error ) {
			throw new \Error( 'Deliberate test-only transport failure.' );
		}
		if ( self::$fail_with_exception ) {
			throw new \RuntimeException( 'Provider echoed api_key=secret-token and mobile=' . $mobile );
		}

		return ! self::$return_false;
	}

	public function is_enable(): bool {
		return true;
	}

	public function options(): array {
		return [];
	}
}

final class LoggingIntegrationTest extends WP_UnitTestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();
		LogRepository::delete_all();
		$this->delete_rate_limits();
		update_option(
			'pinova_logging',
			[
				'minimum_level'    => 'warning',
				'retention_days'   => 14,
				'diagnostic_until' => 0,
			]
		);
	}

	public function tear_down(): void {
		LoggingTestGateway::$fail_with_error = false;
		LoggingTestGateway::$fail_with_exception = false;
		LoggingTestGateway::$return_false = false;
		LogRepository::delete_all();
		$this->delete_rate_limits();
		delete_option( 'pinova_logging' );
		parent::tear_down();
	}

	public function test_database_log_contains_no_raw_identifier_or_exception_message(): void {
		global $wpdb;

		$raw = 'person@example.test';
		Logger::instance()->warning(
			'auth.test_failure',
			[
				'user_id'                => 17,
				'identifier_type'        => 'email',
				'identifier_fingerprint' => Logger::instance()->fingerprint( $raw, 'email' ),
				'email'                  => $raw,
				'exception'              => new \RuntimeException( 'provider leaked ' . $raw ),
			]
		);

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY `id` DESC LIMIT 1', $wpdb->prefix . 'pinova_logs' ), ARRAY_A );

		self::assertIsArray( $row );
		self::assertSame( 'auth.test_failure', $row['event'] );
		self::assertSame( '17', $row['user_id'] );
		self::assertStringNotContainsString( $raw, $row['context'] );
		self::assertStringNotContainsString( 'provider leaked', $row['context'] );
		self::assertStringContainsString( 'identifier_fingerprint', $row['context'] );
		self::assertStringContainsString( 'RuntimeException', $row['context'] );
	}

	public function test_admin_sms_test_is_audited_when_minimum_level_is_error(): void {
		update_option(
			'pinova_logging',
			[
				'minimum_level'    => 'error',
				'retention_days'   => 14,
				'diagnostic_until' => 0,
			]
		);

		$filter = static fn(): array => [
			'gateway'     => LoggingTestGateway::class,
			'message_code' => 'pattern:test\ncode:{{otp}}',
		];
		add_filter( 'pre_option_pinova_sms', $filter );

		try {
			$request = new WP_REST_Request( 'POST', '/pinova/admin/test/sms' );
			$request->set_param( 'identifier', new Identifier( '09120000000' ) );
			$response = ( new AdminAPI() )->test_sms( $request );
		} finally {
			remove_filter( 'pre_option_pinova_sms', $filter );
		}

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'کد تأیید با موفقیت پیامک شد.', $response->get_data()['message'] );
		$this->assert_latest_event( 'admin.sms_test_succeeded', 'notice' );
	}

	public function test_admin_sms_test_catches_throwable_and_audits_failure(): void {
		LoggingTestGateway::$fail_with_error = true;
		$filter = static fn(): array => [
			'gateway'     => LoggingTestGateway::class,
			'message_code' => 'pattern:test\ncode:{{otp}}',
		];
		add_filter( 'pre_option_pinova_sms', $filter );

		try {
			$request = new WP_REST_Request( 'POST', '/pinova/admin/test/sms' );
			$request->set_param( 'identifier', new Identifier( '09120000000' ) );
			$response = ( new AdminAPI() )->test_sms( $request );
		} finally {
			remove_filter( 'pre_option_pinova_sms', $filter );
		}

		self::assertSame( 503, $response->get_status() );
		self::assertSame( 'خطای داخلی هنگام ارسال پیامک رخ داده است.', $response->get_data()['message'] );
		$this->assert_latest_event( 'admin.sms_test_failed', 'error' );
	}

	public function test_admin_sms_test_treats_false_provider_result_as_failure(): void {
		LoggingTestGateway::$return_false = true;
		$filter = static fn(): array => [
			'gateway'      => LoggingTestGateway::class,
			'message_code' => 'pattern:test\ncode:{{otp}}',
		];
		add_filter( 'pre_option_pinova_sms', $filter );
		try {
			$request = new WP_REST_Request( 'POST', '/pinova/admin/test/sms' );
			$request->set_param( 'identifier', new Identifier( '09120000000' ) );
			$response = ( new AdminAPI() )->test_sms( $request );
		} finally {
			remove_filter( 'pre_option_pinova_sms', $filter );
		}

		self::assertSame( 503, $response->get_status() );
		$this->assert_latest_event( 'admin.sms_test_failed', 'error' );
	}

	public function test_admin_sms_test_does_not_return_provider_exception_text(): void {
		LoggingTestGateway::$fail_with_exception = true;
		$filter = static fn(): array => [
			'gateway'      => LoggingTestGateway::class,
			'message_code' => 'pattern:test\ncode:{{otp}}',
		];
		add_filter( 'pre_option_pinova_sms', $filter );
		try {
			$request = new WP_REST_Request( 'POST', '/pinova/admin/test/sms' );
			$request->set_param( 'identifier', new Identifier( '09120000000' ) );
			$response = ( new AdminAPI() )->test_sms( $request );
		} finally {
			remove_filter( 'pre_option_pinova_sms', $filter );
		}

		self::assertSame( 503, $response->get_status() );
		self::assertSame( 'خطای داخلی هنگام ارسال پیامک رخ داده است.', $response->get_data()['message'] );
		self::assertStringNotContainsString( 'secret-token', wp_json_encode( $response->get_data() ) );
		$this->assert_latest_event( 'admin.sms_test_failed', 'error' );
	}

	public function test_single_page_log_viewer_renders_without_null_pagination_deprecation(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		Logger::instance()->audit( 'error', 'auth.request_failed', [ 'user_id' => $user_id ] );

		$original_get = $_GET;
		$_GET         = [];
		$buffer_level = ob_get_level();
		$html         = '';
		set_error_handler(
			static function ( int $severity, string $message ): bool {
				if ( E_DEPRECATED === $severity ) {
					throw new \ErrorException( $message, 0, $severity );
				}

				return false;
			}
		);

		try {
			ob_start();
			Logs::render();
			$html = (string) ob_get_clean();
		} finally {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			restore_error_handler();
			$_GET = $original_get;
		}

		self::assertStringContainsString( 'auth.request_failed', $html );
	}

	public function test_identity_conflict_writes_count_and_fingerprint_only(): void {
		global $wpdb;

		$mobile = '09121111111';
		self::factory()->user->create( [ 'user_login' => '989121111111' ] );
		$second_user_id = self::factory()->user->create( [ 'user_login' => 'logging_conflict_user' ] );
		update_user_meta( $second_user_id, 'digits_phone', '+989121111111' );

		self::assertNull( UserService::get_by_mobile( $mobile ) );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY `id` DESC LIMIT 1', $wpdb->prefix . 'pinova_logs' ), ARRAY_A );

		self::assertSame( 'identity.mobile_conflict', $row['event'] );
		self::assertStringContainsString( '"candidate_count":2', $row['context'] );
		self::assertStringNotContainsString( $mobile, $row['context'] );
		self::assertArrayNotHasKey( 'candidate_user_ids', json_decode( $row['context'], true ) );
	}

	public function test_retention_cleanup_deletes_only_expired_rows(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'pinova_logs';
		$base  = [
			'level'          => 'warning',
			'event'          => 'retention.test',
			'correlation_id' => 'retention-test',
			'user_id'        => null,
			'context'        => '{}',
		];
		$wpdb->insert( $table, [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS ) ] + $base );
		$wpdb->insert( $table, [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ] + $base );

		self::assertSame( 1, LogRepository::cleanup() );
		self::assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) );
	}

	public function test_rest_responses_expose_only_the_correlation_id(): void {
		$response = RestAPI::response( false, 'Safe failure', [], 400 );

		self::assertSame( Logger::instance()->correlation_id(), $response->get_headers()['X-Pinova-Correlation-ID'] );
		self::assertArrayNotHasKey( 'identifier', $response->get_headers() );
	}

	public function test_rate_limit_transition_is_logged_only_once_per_window(): void {
		global $wpdb;

		RateLimitService::consume( 'logging_test', 'subject', 1, MINUTE_IN_SECONDS );

		foreach ( [ 2, 3 ] as $attempt ) {
			try {
				RateLimitService::consume( 'logging_test', 'subject', 1, MINUTE_IN_SECONDS );
				self::fail( 'Expected attempt ' . $attempt . ' to be rate limited.' );
			} catch ( RateLimitException $exception ) {
				self::assertGreaterThan( 0, $exception->get_retry_after() );
			}
		}

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE `event` = %s',
				$wpdb->prefix . 'pinova_logs',
				'security.rate_limited'
			)
		);

		self::assertSame( 1, $count );
	}

	private function assert_latest_event( string $event, string $level ): void {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT `event`, `level`, `context` FROM %i ORDER BY `id` DESC LIMIT 1',
				$wpdb->prefix . 'pinova_logs'
			),
			ARRAY_A
		);

		self::assertIsArray( $row );
		self::assertSame( $event, $row['event'] );
		self::assertSame( $level, $row['level'] );
		self::assertStringNotContainsString( 'Deliberate test-only transport failure.', $row['context'] );
	}

	private function delete_rate_limits(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'pinova_rate_limits' ) );
	}
}
