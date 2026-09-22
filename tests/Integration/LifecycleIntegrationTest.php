<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Install;
use WP_UnitTestCase;

final class LifecycleIntegrationTest extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( Install::PURGE_OPTION );
		Install::migrate();
		update_option( 'pinova_version', PINOVA_VERSION, false );
		parent::tear_down();
	}

	public function test_schema_migration_is_idempotent_and_records_version(): void {
		delete_option( Install::SCHEMA_OPTION );

		self::assertTrue( Install::migrate() );
		self::assertSame( Install::SCHEMA_VERSION, (int) get_option( Install::SCHEMA_OPTION ) );
		self::assertTrue( Install::migrate() );
		$this->assert_tables_exist();
	}

	public function test_interrupted_schema_migration_is_retried(): void {
		delete_option( Install::SCHEMA_OPTION );
		$interrupt = static function (): void {
			throw new \RuntimeException( 'Deliberate test-only migration interruption.' );
		};
		add_action( 'pinova_before_db_schema_migration', $interrupt );

		try {
			self::assertFalse( Install::migrate() );
			self::assertFalse( get_option( Install::SCHEMA_OPTION, false ) );
		} finally {
			remove_action( 'pinova_before_db_schema_migration', $interrupt );
		}

		self::assertTrue( Install::migrate() );
		self::assertSame( Install::SCHEMA_VERSION, (int) get_option( Install::SCHEMA_OPTION ) );
	}

	public function test_activation_does_not_write_to_plugin_directory(): void {
		$sentinel = PINOVA_DIR . '/.activated';
		self::assertFileDoesNotExist( $sentinel );

		Install::activate( false );

		self::assertFileDoesNotExist( $sentinel );
		$this->assert_tables_exist();
	}

	public function test_deactivation_clears_pinova_schedules(): void {
		foreach ( Install::scheduled_hooks() as $hook ) {
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, $hook );
			self::assertIsInt( wp_next_scheduled( $hook ) );
		}
		wp_schedule_single_event( time() + ( 2 * HOUR_IN_SECONDS ), 'pinova_logging_cleanup', [ 'partition' => 'expired' ] );

		Install::deactivate( false );

		foreach ( Install::scheduled_hooks() as $hook ) {
			self::assertFalse( wp_next_scheduled( $hook ) );
		}
		self::assertFalse( wp_next_scheduled( 'pinova_logging_cleanup', [ 'partition' => 'expired' ] ) );
	}

	public function test_uninstall_preserves_data_without_opt_in(): void {
		delete_option( Install::PURGE_OPTION );

		Install::uninstall();

		$this->assert_tables_exist();
		self::assertSame( Install::SCHEMA_VERSION, (int) get_option( Install::SCHEMA_OPTION ) );
	}

	public function test_opt_in_uninstall_purges_operational_data_but_preserves_mobile_meta(): void {
		global $wpdb;

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'pinova_mobile', '09120000000' );
		update_option( 'pinova_test_owned_option', 'delete-me', false );
		update_option( Install::PURGE_OPTION, 1, false );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'pinova_logging_cleanup' );

		Install::uninstall();

		foreach ( $this->table_names() as $table ) {
			self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		}
		self::assertFalse( get_option( 'pinova_test_owned_option', false ) );
		self::assertFalse( get_option( Install::SCHEMA_OPTION, false ) );
		self::assertFalse( wp_next_scheduled( 'pinova_logging_cleanup' ) );
		self::assertSame( '09120000000', get_user_meta( $user_id, 'pinova_mobile', true ) );
	}

	private function assert_tables_exist(): void {
		global $wpdb;

		foreach ( $this->table_names() as $table ) {
			self::assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		}
	}

	/**
	 * @return string[]
	 */
	private function table_names(): array {
		global $wpdb;

		return [
			$wpdb->prefix . 'pinova_otp',
			$wpdb->prefix . 'pinova_blocks',
			$wpdb->prefix . 'pinova_rate_limits',
			$wpdb->prefix . 'pinova_logs',
		];
	}
}
