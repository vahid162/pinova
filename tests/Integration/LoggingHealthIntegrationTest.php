<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Install;
use Pinova\Logging\HealthState;
use Pinova\Logging\LogRepository;
use Pinova\Logging\OTPHealthState;
use WP_UnitTestCase;

final class LoggingHealthIntegrationTest extends WP_UnitTestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();
		LogRepository::delete_all();
		delete_option( 'pinova_logging_cleanup_health' );
		delete_option( 'pinova_logging_fallback_health' );
		delete_option( 'pinova_otp_cleanup_health' );
	}

	public function test_cleanup_failure_is_distinct_from_a_successful_empty_delete(): void {
		global $wpdb;

		self::assertSame( 0, LogRepository::cleanup() );
		self::assertSame( 'success', HealthState::cleanup()['status'] );
		$table           = $wpdb->prefix . 'pinova_logs';
		$fail            = static function ( string $query ) use ( $table ): string {
			return str_starts_with( $query, 'DELETE FROM ' ) && str_contains( $query, $table )
				? 'SELECT `pinova_missing_column` FROM `' . $table . '` LIMIT 1'
				: $query;
		};
		$previous_errors = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			self::assertFalse( LogRepository::cleanup() );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous_errors );
		}
		self::assertSame( 'failed', HealthState::cleanup()['status'] );
		self::assertSame( 0, HealthState::cleanup()['deleted'] );
		self::assertSame( 'degraded', LogRepository::health()['state'] );
	}

	public function test_paginate_read_failure_is_not_a_successful_empty_result(): void {
		global $wpdb;

		self::assertTrue( LogRepository::paginate()['success'] );
		$table           = $wpdb->prefix . 'pinova_logs';
		$fail            = static function ( string $query ) use ( $table ): string {
			return str_contains( $query, 'SELECT COUNT(*)' ) && str_contains( $query, $table )
				? 'SELECT `pinova_missing_column` FROM `' . $table . '` LIMIT 1'
				: $query;
		};
		$previous_errors = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			self::assertFalse( LogRepository::paginate()['success'] );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous_errors );
		}
		self::assertFalse( LogRepository::paginate( 1, 50, 'invalid' )['success'] );
	}

	public function test_diagnostic_reads_bound_oversized_historical_context(): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'pinova_logs',
			[
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
				'level'          => 'error',
				'event'          => 'auth.request_failed',
				'correlation_id' => wp_generate_uuid4(),
				'context'        => '{"password":"' . str_repeat( 'secret', 2000 ) . '"}',
			]
		);
		foreach ( [ LogRepository::paginate(), LogRepository::incident_batch( [] ) ] as $result ) {
			self::assertTrue( $result['success'] );
			self::assertTrue( $result['rows'][0]['context_truncated'] );
			self::assertSame( '{}', $result['rows'][0]['context'] );
		}
	}

	public function test_health_distinguishes_overdue_and_missing_cleanup_without_writes(): void {
		wp_clear_scheduled_hook( 'pinova_logging_cleanup' );
		$missing = LogRepository::health();
		self::assertFalse( $missing['cleanup_scheduled'] );
		self::assertSame( 'degraded', $missing['state'] );
		self::assertSame( 'unknown', $missing['cleanup']['status'] );
		wp_schedule_single_event( time() - 2 * HOUR_IN_SECONDS, 'pinova_logging_cleanup' );
		$overdue = LogRepository::health();
		self::assertTrue( $overdue['cleanup_scheduled'] );
		self::assertTrue( $overdue['cleanup_overdue'] );
		self::assertSame( 'degraded', $overdue['state'] );
		self::assertFalse( get_option( 'pinova_logging_cleanup_health', false ) );
		wp_clear_scheduled_hook( 'pinova_logging_cleanup' );
	}

	public function test_health_state_discards_arbitrary_stored_payloads(): void {
		update_option(
			'pinova_logging_cleanup_health',
			[
				'status'   => 'private-token',
				'at'       => time(),
				'deleted'  => 'secret',
				'password' => 'secret',
			],
			false
		);
		update_option(
			'pinova_logging_fallback_health',
			[
				'at'          => time(),
				'woocommerce' => 'private-token',
				'php'         => 'secret',
				'trace'       => '/private/path',
			],
			false
		);
		$state = wp_json_encode( [ HealthState::cleanup(), HealthState::fallback() ] );
		self::assertStringNotContainsString( 'private-token', $state );
		self::assertStringNotContainsString( 'secret', $state );
		self::assertStringNotContainsString( '/private/path', $state );
	}

	public function test_health_state_is_non_autoloaded_and_fallback_is_bounded(): void {
		global $wpdb;

		HealthState::record_fallback( true, false );
		$first = get_option( 'pinova_logging_fallback_health' );
		HealthState::record_fallback( false, true );
		self::assertSame( $first, get_option( 'pinova_logging_fallback_health' ) );
		self::assertSame( 'attempted', HealthState::fallback()['woocommerce'] );
		self::assertSame( 'failed', HealthState::fallback()['php'] );
		$autoload = $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, 'pinova_logging_fallback_health' ) );
		self::assertNotContains( $autoload, [ 'yes', 'on', 'auto-on', 'auto' ] );
	}

	public function test_health_recording_failure_and_recursive_option_hooks_are_nonfatal(): void {
		$throw = static function (): void {
			throw new \RuntimeException( 'private option failure' );
		};
		add_filter( 'pre_update_option_pinova_logging_cleanup_health', $throw );
		try {
			HealthState::record_cleanup( true );
			self::assertSame( 'unknown', HealthState::cleanup()['status'] );
		} finally {
			remove_filter( 'pre_update_option_pinova_logging_cleanup_health', $throw );
		}
		$calls   = 0;
		$reenter = static function ( $value ) use ( &$calls ) {
			++$calls;
			HealthState::record_cleanup( false );
			return $value;
		};
		add_filter( 'pre_update_option_pinova_logging_cleanup_health', $reenter );
		try {
			HealthState::record_cleanup( true );
			self::assertSame( 1, $calls );
			self::assertSame( 'success', HealthState::cleanup()['status'] );
		} finally {
			remove_filter( 'pre_update_option_pinova_logging_cleanup_health', $reenter );
		}
	}

	public function test_otp_cleanup_health_is_finite_non_autoloaded_and_current_schedule_reads_do_not_write(): void {
		global $wpdb;
		OTPHealthState::record_cleanup( true, 5000, 'pending', 'scheduled' );
		wp_clear_scheduled_hook( 'pinova_otp_cleanup_continue' );
		$queries = [];
		$inspect = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $inspect );
		try {
			$health = OTPHealthState::snapshot();
		} finally {
			remove_filter( 'query', $inspect );
		}
		self::assertTrue( $health['read_success'] );
		self::assertSame( 'success', $health['status'] );
		self::assertSame( 5000, $health['deleted'] );
		self::assertSame( 'pending', $health['backlog'] );
		self::assertTrue( $health['continuation_missing'] );
		foreach ( $queries as $query ) {
			self::assertStringStartsWith( 'SELECT ', $query );
		}
		$autoload = $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, 'pinova_otp_cleanup_health' ) );
		self::assertNotContains( $autoload, [ 'yes', 'on', 'auto-on', 'auto' ] );
		wp_schedule_single_event( time() - 2 * HOUR_IN_SECONDS, 'pinova_otp_cleanup_continue' );
		self::assertTrue( OTPHealthState::snapshot()['continuation_overdue'] );
		wp_clear_scheduled_hook( 'pinova_otp_cleanup_continue' );
		update_option( 'pinova_otp_cleanup_health', [ 'status' => 'success', 'at' => time() + 30, 'deleted' => 6000, 'backlog' => 'private-token', 'password' => 'never-export-secret' ], false );
		$invalid = OTPHealthState::snapshot();
		self::assertSame( 'unknown', $invalid['status'] );
		self::assertNull( $invalid['deleted'] );
		self::assertSame( 'unknown', $invalid['backlog'] );
		self::assertStringNotContainsString( 'private-token', wp_json_encode( $invalid ) );
		self::assertStringNotContainsString( 'never-export-secret', wp_json_encode( $invalid ) );
	}

	public function test_otp_cleanup_health_read_and_write_failures_and_recursive_hooks_are_nonfatal(): void {
		$throw = static function (): never {
			throw new \RuntimeException( 'Synthetic health store failure.' );
		};
		add_filter( 'pre_update_option_pinova_otp_cleanup_health', $throw );
		try {
			OTPHealthState::record_cleanup( true, 0, 'clear', 'not_needed' );
			self::assertSame( 'unknown', OTPHealthState::snapshot()['status'] );
		} finally {
			remove_filter( 'pre_update_option_pinova_otp_cleanup_health', $throw );
		}
		$calls = 0;
		$reenter = static function ( $value ) use ( &$calls ) {
			++$calls;
			OTPHealthState::record_cleanup( false, null );
			return $value;
		};
		add_filter( 'pre_update_option_pinova_otp_cleanup_health', $reenter );
		try {
			OTPHealthState::record_cleanup( true, 0, 'clear', 'not_needed' );
			self::assertSame( 1, $calls );
			self::assertSame( 'success', OTPHealthState::snapshot()['status'] );
		} finally {
			remove_filter( 'pre_update_option_pinova_otp_cleanup_health', $reenter );
		}
		add_filter( 'pre_option_pinova_otp_cleanup_health', $throw );
		try {
			$failed = OTPHealthState::snapshot();
			self::assertFalse( $failed['read_success'] );
			self::assertSame( 'unknown', $failed['status'] );
			self::assertNull( $failed['deleted'] );
		} finally {
			remove_filter( 'pre_option_pinova_otp_cleanup_health', $throw );
		}
	}

	public function test_silent_otp_health_option_sql_failure_is_unknown_rather_than_a_missing_healthy_observation(): void {
		global $wpdb;
		delete_option( 'pinova_otp_cleanup_health' );
		wp_cache_delete( 'pinova_otp_cleanup_health', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$failed = false;
		$fail = static function ( string $query ) use ( &$failed ): string {
			if ( str_starts_with( $query, 'SELECT ' ) && str_contains( $query, 'pinova_otp_cleanup_health' ) ) {
				$failed = true;
				return 'SELECT `pinova_missing_health_column`';
			}
			return $query;
		};
		$previous = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			$health = OTPHealthState::snapshot();
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous );
			wp_cache_delete( 'notoptions', 'options' );
		}
		self::assertTrue( $failed, 'The test must exercise a real failed option SELECT.' );
		self::assertFalse( $health['read_success'] );
		self::assertSame( 'unknown', $health['status'] );
		self::assertNull( $health['deleted'] );
	}
}
