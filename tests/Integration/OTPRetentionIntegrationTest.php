<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Install;
use Pinova\Logging\OTPHealthState;
use Pinova\Pinova;
use Pinova\Services\OTPService;
use WP_UnitTestCase;

final class OTPRetentionIntegrationTest extends WP_UnitTestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'pinova_otp' ) );
		wp_clear_scheduled_hook( 'pinova_otp_cleanup_continue' );
		delete_option( 'pinova_otp_cleanup_health' );
	}

	public function tear_down(): void {
		wp_clear_scheduled_hook( 'pinova_otp_cleanup_continue' );
		parent::tear_down();
	}

	public function test_cleanup_preserves_seven_day_boundary_live_recent_verified_and_unknown_expiry_records(): void {
		global $wpdb;

		// Freeze only this disposable SQL session so the exact boundary cannot tick during the assertion.
		self::assertNotFalse( $wpdb->query( 'SET timestamp = 1800000000' ) );
		try {
			$this->seed( 1, 'UTC_TIMESTAMP() - INTERVAL 7 DAY - INTERVAL 1 SECOND' );
			$this->seed( 1, 'UTC_TIMESTAMP() - INTERVAL 8 DAY', true );
			$this->seed( 1, 'UTC_TIMESTAMP() - INTERVAL 8 DAY', false, true );
			$this->seed( 1, 'UTC_TIMESTAMP() - INTERVAL 7 DAY' );
			$this->seed( 1, 'UTC_TIMESTAMP() - INTERVAL 7 DAY + INTERVAL 1 SECOND' );
			$this->seed( 1, 'UTC_TIMESTAMP() - INTERVAL 14 MINUTE' );
			$this->seed( 1, 'UTC_TIMESTAMP() + INTERVAL 3 MINUTE' );
			$this->seed( 1, 'UTC_TIMESTAMP() - INTERVAL 1 DAY', true );
			$this->seed( 1, 'NULL', false, true );
			self::assertSame( 3, OTPService::cleanup() );
			self::assertSame( 6, $this->count_records() );
			self::assertFalse( wp_next_scheduled( 'pinova_otp_cleanup_continue' ) );
		} finally {
			$wpdb->query( 'SET timestamp = 0' );
		}
	}

	public function test_expired_backlog_drains_in_bounded_batches_through_registered_callback(): void {
		$this->seed( 5001, 'UTC_TIMESTAMP() - INTERVAL 8 DAY' );
		$this->seed( 1, 'UTC_TIMESTAMP() + INTERVAL 3 MINUTE' );
		self::assertSame( 5000, OTPService::cleanup() );
		$health = OTPHealthState::snapshot();
		self::assertTrue( $health['read_success'] );
		self::assertSame( 'success', $health['status'] );
		self::assertSame( 5000, $health['deleted'] );
		self::assertSame( 'pending', $health['backlog'] );
		self::assertSame( 'scheduled', $health['continuation'] );
		self::assertTrue( $health['continuation_scheduled'] );
		self::assertSame( 2, $this->count_records() );
		$next = wp_next_scheduled( 'pinova_otp_cleanup_continue' );
		self::assertIsInt( $next );
		self::assertGreaterThanOrEqual( time() + 4 * MINUTE_IN_SECONDS, $next );
		self::assertTrue( wp_unschedule_event( $next, 'pinova_otp_cleanup_continue' ) );
		do_action( 'pinova_otp_cleanup_continue' );
		self::assertSame( 'clear', OTPHealthState::snapshot()['backlog'] );
		self::assertSame( 'not_needed', OTPHealthState::snapshot()['continuation'] );
		self::assertSame( 1, $this->count_records() );
		self::assertFalse( wp_next_scheduled( 'pinova_otp_cleanup_continue' ) );
	}

	public function test_cleanup_uses_an_expiry_range_without_scanning_retained_history(): void {
		global $wpdb;
		$this->seed( 5001, 'UTC_TIMESTAMP() + INTERVAL 3 MINUTE' );
		$this->seed( 1, 'UTC_TIMESTAMP() - INTERVAL 8 DAY' );
		$plan = $wpdb->get_row(
			$wpdb->prepare(
				'EXPLAIN DELETE FROM %i WHERE `expires_at` < (UTC_TIMESTAMP() - INTERVAL 7 DAY) ORDER BY `expires_at`, `id` LIMIT 5000',
				$wpdb->prefix . 'pinova_otp'
			),
			ARRAY_A
		);
		self::assertIsArray( $plan );
		self::assertSame( 'expiry_cleanup', $plan['key'] );
		self::assertSame( 'range', $plan['type'] );
		self::assertSame( 1, OTPService::cleanup() );
		self::assertSame( 5001, $this->count_records() );
	}

	public function test_cleanup_schedule_failure_returns_failure_and_allows_later_retry(): void {
		$this->seed( 5001, 'UTC_TIMESTAMP() - INTERVAL 8 DAY' );
		$fail = static fn( $pre, $event ) => 'pinova_otp_cleanup_continue' === $event->hook ? false : $pre;
		add_filter( 'pre_schedule_event', $fail, 10, 2 );
		try {
			self::assertFalse( OTPService::cleanup() );
		} finally {
			remove_filter( 'pre_schedule_event', $fail );
		}
		self::assertSame( 'failed', OTPHealthState::snapshot()['status'] );
		self::assertSame( 'pending', OTPHealthState::snapshot()['backlog'] );
		self::assertSame( 'failed', OTPHealthState::snapshot()['continuation'] );
		self::assertSame( 1, $this->count_records() );
		self::assertFalse( wp_next_scheduled( 'pinova_otp_cleanup_continue' ) );
		self::assertSame( 1, OTPService::cleanup() );
	}

	public function test_cleanup_continuation_is_deduplicated(): void {
		$this->seed( 5001, 'UTC_TIMESTAMP() - INTERVAL 8 DAY' );
		$next = time() + 5 * MINUTE_IN_SECONDS;
		self::assertTrue( wp_schedule_single_event( $next, 'pinova_otp_cleanup_continue' ) );
		self::assertSame( 5000, OTPService::cleanup() );
		self::assertSame( $next, wp_next_scheduled( 'pinova_otp_cleanup_continue' ) );
		$events = [];
		foreach ( _get_cron_array() as $hooks ) {
			$events = array_merge( $events, array_values( $hooks['pinova_otp_cleanup_continue'] ?? [] ) );
		}
		self::assertCount( 1, $events );
	}

	public function test_database_error_is_not_an_empty_success_and_does_not_continue(): void {
		global $wpdb;

		$this->seed( 1, 'UTC_TIMESTAMP() - INTERVAL 8 DAY' );
		$table = $wpdb->prefix . 'pinova_otp';
		$fail = static fn( string $query ): string => str_starts_with( $query, 'DELETE FROM ' ) && str_contains( $query, $table )
			? 'DELETE FROM `' . $table . '` WHERE `pinova_missing_column` = 1'
			: $query;
		$previous = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			self::assertFalse( OTPService::cleanup() );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous );
		}
		self::assertSame( 'failed', OTPHealthState::snapshot()['status'] );
		self::assertNull( OTPHealthState::snapshot()['deleted'] );
		self::assertSame( 'unknown', OTPHealthState::snapshot()['backlog'] );
		self::assertSame( 1, $this->count_records() );
		self::assertFalse( wp_next_scheduled( 'pinova_otp_cleanup_continue' ) );
	}

	public function test_daily_schedule_is_idempotent_and_cleanup_hooks_are_lifecycle_owned(): void {
		wp_clear_scheduled_hook( 'pinova_otp_cleanup' );
		$plugin = Pinova::instance();
		self::assertNotNull( $plugin );
		$plugin->schedule_cleanup();
		$next = wp_next_scheduled( 'pinova_otp_cleanup' );
		self::assertIsInt( $next );
		$plugin->schedule_cleanup();
		self::assertSame( $next, wp_next_scheduled( 'pinova_otp_cleanup' ) );
		self::assertSame( 'daily', wp_get_schedule( 'pinova_otp_cleanup' ) );
		self::assertContains( 'pinova_otp_cleanup', Install::scheduled_hooks() );
		self::assertContains( 'pinova_otp_cleanup_continue', Install::scheduled_hooks() );
		self::assertContains( 'pinova_logging_cleanup_continue', Install::scheduled_hooks() );
	}

	public function test_failed_backlog_read_stops_continuation_after_a_successful_delete(): void {
		global $wpdb;

		$this->seed( 1, 'UTC_TIMESTAMP() - INTERVAL 8 DAY' );
		$table = $wpdb->prefix . 'pinova_otp';
		$fail = static fn( string $query ): string => str_starts_with( $query, 'SELECT `id` FROM ' ) && str_contains( $query, $table )
			? 'SELECT `pinova_missing_column` FROM `' . $table . '` LIMIT 1'
			: $query;
		$previous = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			self::assertFalse( OTPService::cleanup() );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous );
		}
		self::assertSame( 'failed', OTPHealthState::snapshot()['status'] );
		self::assertSame( 1, OTPHealthState::snapshot()['deleted'] );
		self::assertSame( 'unknown', OTPHealthState::snapshot()['backlog'] );
		self::assertSame( 0, $this->count_records() );
		self::assertFalse( wp_next_scheduled( 'pinova_otp_cleanup_continue' ) );
	}

	private function count_records(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'pinova_otp' ) );
	}

	private function seed( int $count, string $expiry, bool $verified = false, bool $legacy = false ): void {
		global $wpdb;

		$flow = $legacy ? 'NULL' : "'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'";
		$verified_at = $verified ? 'UTC_TIMESTAMP() - INTERVAL 10 DAY' : 'NULL';
		while ( 0 < $count ) {
			$batch = min( 1000, $count );
			$rows = implode( ', ', array_fill( 0, $batch, "('retention@example.test', 'synthetic-hash', '192.0.2.1', 'login', '{}', {$expiry}, {$verified_at}, {$flow})" ) );
			self::assertSame( $batch, $wpdb->query( $wpdb->prepare( "INSERT INTO %i (`identifier`, `code`, `ip_address`, `type`, `channels`, `expires_at`, `verified_at`, `flow_id`) VALUES {$rows}", $wpdb->prefix . 'pinova_otp' ) ) );
			$count -= $batch;
		}
	}
}
