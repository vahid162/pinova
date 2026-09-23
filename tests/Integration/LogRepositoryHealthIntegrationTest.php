<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Logging\LogRepository;
use WP_UnitTestCase;

final class LogRepositoryHealthIntegrationTest extends WP_UnitTestCase {

	private static string $test_prefix = '';
	private static string $test_table  = '';
	private static bool $created_table = false;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;

		self::$test_prefix = $wpdb->prefix . 'health_' . bin2hex( random_bytes( 4 ) ) . '_';
		self::$test_table  = self::$test_prefix . 'pinova_logs';
		$created           = $wpdb->query(
			$wpdb->prepare(
				'CREATE TABLE %i (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `created_at` datetime NOT NULL, PRIMARY KEY (`id`))',
				self::$test_table
			)
		);
		self::$created_table = false !== $created;
		self::assertNotFalse( $created );
	}

	public static function tear_down_after_class(): void {
		global $wpdb;

		if ( self::$created_table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::$test_table ) );
		}

		parent::tear_down_after_class();
	}

	public function test_health_degrades_when_viewer_columns_are_missing(): void {
		global $wpdb;

		$original_prefix = $wpdb->prefix;
		$previous_errors = $wpdb->suppress_errors( true );
		$had_cleanup     = (bool) wp_next_scheduled( 'pinova_logging_cleanup' );
		if ( ! $had_cleanup ) {
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'pinova_logging_cleanup' );
		}

		try {
			$wpdb->prefix = self::$test_prefix;
			$health        = LogRepository::health();

			self::assertTrue( $health['table_exists'] );
			self::assertTrue( $health['cleanup_scheduled'] );
			self::assertSame( 'degraded', $health['state'] );
			self::assertNotEmpty( $wpdb->last_error );
		} finally {
			$wpdb->prefix = $original_prefix;
			$wpdb->suppress_errors( $previous_errors );
			if ( ! $had_cleanup ) {
				wp_clear_scheduled_hook( 'pinova_logging_cleanup' );
			}
		}
	}
}
