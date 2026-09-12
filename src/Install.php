<?php

namespace Pinova;

use Illuminate\Database\Schema\Blueprint;
use Nabik_Net_Database;

class Install extends \Nabik\Utils\V1\Install {

	public function tasks() {
		self::create_tables();
	}

	public static function create_tables() {

		if ( ! Nabik_Net_Database::Schema()->hasTable( 'pinova_otp' ) ) {

			Nabik_Net_Database::Schema()->create( 'pinova_otp', function ( Blueprint $table ) {
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
			} );

		}

		if ( ! Nabik_Net_Database::Schema()->hasTable( 'pinova_blocks' ) ) {

			Nabik_Net_Database::Schema()->create( 'pinova_blocks', function ( Blueprint $table ) {
				$table->bigIncrements( 'id' );
				$table->string( 'identifier' )->unique();
				$table->foreignId( 'blocked_by' )->nullable();
				$table->timestamp( 'blocked_until' )->nullable();
			} );

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

}
