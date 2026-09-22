<?php

namespace Pinova;

use RuntimeException;
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
		global $wpdb;

		$installed = (int) get_option( self::SCHEMA_OPTION, 0 );

		if ( $installed >= self::SCHEMA_VERSION ) {
			return true;
		}
		$fresh_installation = self::is_fresh_installation();

		$previous_suppression = method_exists( $wpdb, 'suppress_errors' )
			? $wpdb->suppress_errors( true )
			: false;

		try {
			do_action( 'pinova_before_db_schema_migration', $installed, self::SCHEMA_VERSION );
			self::create_tables();
			do_action( 'pinova_after_db_schema_migration', $installed, self::SCHEMA_VERSION );

			if ( $fresh_installation ) {
				self::update_option_verified( 'pinova_version', PINOVA_VERSION );
			}
			self::update_option_verified( self::SCHEMA_OPTION, self::SCHEMA_VERSION );

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
				try {
					self::purge_current_site_data();
				} finally {
					restore_current_blog();
				}
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
			if ( ! preg_match( '/\A[A-Za-z0-9_]+\z/', $table ) ) {
				throw new RuntimeException( 'Pinova generated an invalid database table name.' );
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WordPress table names are internally generated and validated above.
			if ( false === $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ) ) {
				throw new RuntimeException( 'Pinova could not remove its operational database tables.' );
			}
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
		self::create_wordpress_tables();
	}

	public static function create_wordpress_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$otp             = $wpdb->prefix . 'pinova_otp';
		$blocks          = $wpdb->prefix . 'pinova_blocks';
		$rate_limits     = $wpdb->prefix . 'pinova_rate_limits';
		$logs            = $wpdb->prefix . 'pinova_logs';

		if ( ! self::database_table_exists( $otp ) ) {
			dbDelta(
				"CREATE TABLE {$otp} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint unsigned NULL,
				identifier varchar(255) NOT NULL,
				code varchar(255) NOT NULL,
				ip_address varchar(45) NOT NULL,
				attempts tinyint unsigned NOT NULL DEFAULT 0,
				type varchar(25) NOT NULL,
				channels longtext NOT NULL,
				expires_at timestamp NULL DEFAULT NULL,
				verified_at timestamp NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY ip_address (ip_address),
				KEY identifier_expires (identifier(191), expires_at)
			) {$charset_collate};"
			);
		}

		if ( ! self::database_table_exists( $blocks ) ) {
			dbDelta(
				"CREATE TABLE {$blocks} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				identifier varchar(255) NOT NULL,
				blocked_by bigint unsigned NULL,
				blocked_until timestamp NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY identifier (identifier(191))
			) {$charset_collate};"
			);
		}

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

		foreach ( self::table_names() as $table ) {
			if ( ! self::database_table_exists( $table ) ) {
				throw new RuntimeException( 'Pinova database schema is incomplete.' );
			}
		}
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

	private static function database_table_exists( string $table ): bool {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}

	private static function is_fresh_installation(): bool {
		global $wpdb;

		foreach ( self::table_names() as $table ) {
			if ( self::database_table_exists( $table ) ) {
				return false;
			}
		}

		$pattern = $wpdb->esc_like( 'pinova_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time lifecycle discovery must inspect physical state.
		$option  = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT `option_name` FROM %i WHERE `option_name` LIKE %s LIMIT 1',
				$wpdb->options,
				$pattern
			)
		);

		return null === $option;
	}

	/** @param mixed $value */
	private static function update_option_verified( string $option, $value ): void {
		if ( update_option( $option, $value, false ) ) {
			return;
		}

		$stored = get_option( $option, null );
		if ( is_scalar( $stored ) && (string) $value === (string) $stored ) {
			return;
		}

		throw new RuntimeException( 'Pinova migration state could not be persisted.' );
	}
}
