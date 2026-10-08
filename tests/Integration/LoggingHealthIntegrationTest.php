<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Install;
use Pinova\Logging\HealthState;
use Pinova\Logging\LogRepository;
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
}
