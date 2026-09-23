<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Install;
use Pinova\Version;
use WP_UnitTestCase;

final class LifecycleIntegrationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		delete_option( Install::PURGE_OPTION );
		self::assertTrue( Install::migrate() );
	}

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

	public function test_version_one_flow_migration_is_additive_and_retryable(): void {
		global $wpdb;

		$tables = [ $wpdb->prefix . 'pinova_otp', $wpdb->prefix . 'pinova_logs' ];
		try {
			foreach ( $tables as $table ) {
				self::assertSame( 0, $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX flow_id, DROP COLUMN flow_id', $table ) ) );
			}
			update_option( Install::SCHEMA_OPTION, 1, false );
			$interrupt = static function (): void {
				throw new \RuntimeException( 'Deliberate test-only interruption after additive schema work.' );
			};
			add_action( 'pinova_after_db_schema_migration', $interrupt );
			try {
				self::assertFalse( Install::migrate() );
				self::assertSame( 1, (int) get_option( Install::SCHEMA_OPTION ) );
			} finally {
				remove_action( 'pinova_after_db_schema_migration', $interrupt );
			}
			self::assertTrue( Install::migrate() );
			self::assertSame( Install::SCHEMA_VERSION, (int) get_option( Install::SCHEMA_OPTION ) );
			foreach ( $tables as $table ) {
				self::assertNotNull( $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'flow_id' ) ) );
				self::assertNotNull( $wpdb->get_row( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'flow_id' ) ) );
			}
			self::assertTrue( Install::migrate() );
		} finally {
			Install::create_tables();
			update_option( Install::SCHEMA_OPTION, Install::SCHEMA_VERSION, false );
		}
	}

	public function test_activation_does_not_write_to_plugin_directory(): void {
		$sentinel = PINOVA_DIR . '/.activated';
		self::assertFileDoesNotExist( $sentinel );

		Install::activate( false );

		self::assertFileDoesNotExist( $sentinel );
		$this->assert_tables_exist();
	}

	public function test_existing_installation_with_missing_version_metadata_runs_legacy_migrations(): void {
		delete_option( 'pinova_version' );

		self::assertTrue( Install::migrate() );
		self::assertFalse( get_option( 'pinova_version', false ) );
		self::assertTrue( ( new Version() )->migrate() );
		self::assertSame( PINOVA_VERSION, get_option( 'pinova_version' ) );
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
		self::assertTrue( (bool) get_option( Install::PURGE_OPTION, false ) );

		/*
		 * WP_UnitTestCase rewrites persistent CREATE/DROP statements to temporary
		 * tables. Suspend those test-only filters so this assertion exercises the
		 * real uninstall behavior, then rebuild the disposable schema in finally.
		 */
		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );

		try {
			Install::uninstall();

			foreach ( $this->table_names() as $table ) {
				self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
			}
			self::assertFalse( get_option( 'pinova_test_owned_option', false ) );
			self::assertFalse( get_option( Install::SCHEMA_OPTION, false ) );
			self::assertFalse( wp_next_scheduled( 'pinova_logging_cleanup' ) );
			self::assertSame(
				'09120000000',
				$wpdb->get_var(
					$wpdb->prepare(
						'SELECT `meta_value` FROM %i WHERE `user_id` = %d AND `meta_key` = %s LIMIT 1',
						$wpdb->usermeta,
						$user_id,
						'pinova_mobile'
					)
				)
			);
		} finally {
			wp_delete_user( $user_id );
			delete_option( Install::PURGE_OPTION );
			self::assertTrue( Install::migrate() );
			self::assertSame( PINOVA_VERSION, get_option( 'pinova_version' ) );
			add_filter( 'query', [ $this, '_create_temporary_tables' ] );
			add_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		}
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
