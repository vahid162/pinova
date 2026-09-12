<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\API\RestAPI;
use Pinova\Exceptions\RateLimitException;
use Pinova\Install;
use Pinova\Logging\Logger;
use Pinova\Logging\LogRepository;
use Pinova\Services\RateLimitService;
use Pinova\Services\UserService;
use WP_UnitTestCase;

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

	private function delete_rate_limits(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'pinova_rate_limits' ) );
	}
}
