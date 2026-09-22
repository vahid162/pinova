<?php

namespace Pinova;

use Illuminate\Database\Schema\Blueprint;
use Nabik_Net_Database;
use Throwable;

class Install extends \Nabik\Utils\V1\Install {

	public const SCHEMA_OPTION = 'pinova_db_schema_version';
	public const SCHEMA_VERSION = 1;
	public const PURGE_OPTION = 'pinova_delete_data_on_uninstall';

	/**
	 * Retained for compatibility with the legacy installer abstraction.
	 */
	public function tasks(): void {
		self::migrate();
	}

	/**
	 * Ensure the current site's schema exists before runtime services boot.
	 *
	 * The version option advances only after every idempotent operation succeeds,
	 * so an interrupted migration is retried on the next request.
	 */
	public static function migrate(): bool {
		$installed = (int) get_option( self::SCHEMA_OPTION, 0 );

		if ( $installed >= self::SCHEMA_VERSION ) {
			return true;
		}

		global $wpdb;
		$previous_suppression = method_exists( $wpdb, 'suppress_errors' )
			? $wpdb->suppress_errors( true )
			: false;

		try {
			do_action( 'pinova_before_db_schema_migration', $installed, self::SCHEMA_VERSION );
			self::create_tables();
			do_action( 'pinova_after_db_schema_migration', $installed, self::SCHEMA_VERSION );
			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );

			return true;
		} catch ( Throwable $throwable ) {
			return false;
		} finally {
			if ( method_exists( $wpdb, 'suppress_errors' ) ) {
				$wpdb->suppress_errors( (bool) $previous_suppression );
			}
		}
	}

	/**
	 * WordPress activation callback.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			if ( function_exists( 'deactivate_plugins' ) ) {
				deactivate_plugins( plugin_basename( PINOVA_FILE ), true );
			}

			wp_die(
				esc_html__( 'پینوا از فعال‌سازی شبکه‌ای پشتیبانی نمی‌کند. افزونه را برای هر سایت به‌صورت جداگانه فعال کنید.', 'pinova' ),
				esc_html__( 'فعال‌سازی شبکه‌ای پشتیبانی نمی‌شود', 'pinova' ),
				[ 'response' => 400 ]
			);
		}

		if ( ! self::migrate() || ! ( new Version() )->migrate() ) {
			wp_die(
				esc_html__( 'راه‌اندازی پایگاه‌داده پینوا کامل نشد. تغییرات اعمال نشد؛ دسترسی فایل و پایگاه‌داده را بررسی و دوباره تلاش کنید.', 'pinova' ),
				esc_html__( 'فعال‌سازی پینوا کامل نشد', 'pinova' ),
				[ 'response' => 500 ]
			);
		}

		if ( class_exists( Pinova::class ) ) {
			$pinova = Pinova::instance();
			if ( $pinova ) {
				$pinova->register_rewrite_rules();
			}
		}

		flush_rewrite_rules( false );
	}

	/**
	 * WordPress deactivation callback.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		unset( $network_wide );

		self::clear_scheduled_hooks();

		flush_rewrite_rules( false );
	}

	/**
	 * WordPress uninstall callback used by uninstall.php and integration tests.
	 */
	public static function uninstall(): void {
		if ( is_multisite() ) {
			$site_ids = get_sites(
				[
					'fields' => 'ids',
					'number' => 0,
				]
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::purge_current_site_data();
				restore_current_blog();
			}

			return;
		}

		self::purge_current_site_data();
	}

	/**
	 * Purge operational plugin data only after an explicit administrator opt-in.
	 * User identity metadata such as pinova_mobile is deliberately preserved.
	 */
	public static function purge_current_site_data(): bool {
		if ( ! (bool) get_option( self::PURGE_OPTION, false ) ) {
			return false;
		}

		global $wpdb;

		self::clear_scheduled_hooks();

		foreach ( self::table_names() as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Identifier is passed through the %i placeholder.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}

		$patterns = [
			$wpdb->esc_like( 'pinova_' ) . '%',
			$wpdb->esc_like( '_transient_pinova_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_pinova_' ) . '%',
			$wpdb->esc_like( '_site_transient_pinova_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_pinova_' ) . '%',
		];
		$clauses  = implode( ' OR ', array_fill( 0, count( $patterns ), 'option_name LIKE %s' ) );
		$query    = $wpdb->prepare(
			"SELECT option_name FROM %i WHERE {$clauses}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Clauses are fixed placeholders.
			array_merge( [ $wpdb->options ], $patterns )
		);
		$options  = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.

		foreach ( $options as $option ) {
			delete_option( (string) $option );
		}

		return true;
	}

	/**
	 * @return string[]
	 */
	public static function scheduled_hooks(): array {
		$hooks = [
			'pinova_rate_limit_cleanup',
			'pinova_logging_cleanup',
		];

		return array_values( array_unique( array_filter( (array) apply_filters( 'pinova_scheduled_hooks', $hooks ), 'is_string' ) ) );
	}

	/**
	 * Remove every scheduled occurrence, including jobs registered with arguments.
	 */
	private static function clear_scheduled_hooks(): void {
		$hooks = self::scheduled_hooks();

		foreach ( $hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		$cron = _get_cron_array();
		if ( ! is_array( $cron ) ) {
			return;
		}

		foreach ( $cron as $timestamp => $events ) {
			foreach ( $hooks as $hook ) {
				if ( empty( $events[ $hook ] ) || ! is_array( $events[ $hook ] ) ) {
					continue;
				}

				foreach ( $events[ $hook ] as $event ) {
					$args = isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : [];
					wp_unschedule_event( (int) $timestamp, $hook, $args );
				}
			}
		}
	}

	public static function render_migration_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'ارتقای پایگاه‌داده پینوا کامل نشد. پینوا برای حفظ دسترسی بومی وردپرس بارگذاری نشده است. دسترسی پایگاه‌داده را بررسی و درخواست را دوباره اجرا کنید.', 'pinova' )
			. '</p></div>';
	}

	public static function create_tables(): void {
		if ( ! Nabik_Net_Database::Schema()->hasTable( 'pinova_otp' ) ) {
			Nabik_Net_Database::Schema()->create(
				'pinova_otp',
				function ( Blueprint $table ): void {
					$table->bigIncrements( 'id' );
					$table->foreignId( 'user_id' )->nullable();
					$table->string( 'identifier' );
					$table->string( 'code' );
					$table->ipAddress( 'ip_address' )->index();
					$table->unsignedTinyInteger( 'attempts' )->default( 0 );
					$table->string( 'type', 25 );
					$table->json( 'channels' );
					$table->timestamp( 'expires_at' )->nullable();
					$table->timestamp( 'verified_at' )->nullable();
					$table->index( [ 'identifier', 'expires_at' ] );
				}
			);
		}

		if ( ! Nabik_Net_Database::Schema()->hasTable( 'pinova_blocks' ) ) {
			Nabik_Net_Database::Schema()->create(
				'pinova_blocks',
				function ( Blueprint $table ): void {
					$table->bigIncrements( 'id' );
					$table->string( 'identifier' )->unique();
					$table->foreignId( 'blocked_by' )->nullable();
					$table->timestamp( 'blocked_until' )->nullable();
				}
			);
		}

		self::create_wordpress_tables();
	}

	public static function create_wordpress_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$rate_limits     = $wpdb->prefix . 'pinova_rate_limits';
		$logs            = $wpdb->prefix . 'pinova_logs';

		dbDelta(
			"CREATE TABLE {$rate_limits} (
				bucket_key char(64) NOT NULL,
				scope varchar(32) NOT NULL,
				hits int unsigned NOT NULL DEFAULT 0,
				reset_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (bucket_key),
				KEY reset_at (reset_at),
				KEY scope_reset (scope, reset_at)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$logs} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				level varchar(12) NOT NULL,
				event varchar(100) NOT NULL,
				correlation_id varchar(64) NOT NULL,
				user_id bigint unsigned NULL,
				context longtext NOT NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY level_created (level, created_at),
				KEY event_created (event, created_at),
				KEY correlation_id (correlation_id)
			) {$charset_collate};"
		);
	}

	/**
	 * @return string[]
	 */
	private static function table_names(): array {
		global $wpdb;

		return [
			$wpdb->prefix . 'pinova_otp',
			$wpdb->prefix . 'pinova_blocks',
			$wpdb->prefix . 'pinova_rate_limits',
			$wpdb->prefix . 'pinova_logs',
		];
	}
}
