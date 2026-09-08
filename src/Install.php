<?php

namespace Pinova;

use Illuminate\Database\Schema\Blueprint;
use Nabik_Net_Database;
use Pinova\Identity\IdentityRepository;

class Install extends \Nabik\Utils\V1\Install {

	public function tasks() {
		self::create_tables();
		self::grant_capabilities();
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
		$identities      = $wpdb->prefix . 'pinova_identities';
		$conflicts       = $wpdb->prefix . 'pinova_identity_conflicts';
		$merge_runs      = $wpdb->prefix . 'pinova_identity_merge_runs';
		$merge_items     = $wpdb->prefix . 'pinova_identity_merge_items';

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
			"CREATE TABLE {$identities} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint unsigned NOT NULL,
				type varchar(20) NOT NULL,
				normalized_value varchar(191) NOT NULL,
				is_primary tinyint(1) NOT NULL DEFAULT 0,
				verified_at datetime NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				source varchar(64) NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY type_value (type, normalized_value),
				KEY user_type_status (user_id, type, status),
				KEY user_id (user_id)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$conflicts} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				value_hash char(64) NOT NULL,
				type varchar(20) NOT NULL,
				masked_value varchar(191) NOT NULL,
				user_ids text NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'open',
				detected_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY value_hash (value_hash),
				KEY status_updated (status, updated_at)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$merge_runs} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				source_user_id bigint unsigned NOT NULL,
				target_user_id bigint unsigned NOT NULL,
				started_by bigint unsigned NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				context longtext NULL,
				started_at datetime NOT NULL,
				completed_at datetime NULL,
				rolled_back_at datetime NULL,
				PRIMARY KEY  (id),
				KEY source_target (source_user_id, target_user_id),
				KEY status (status)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$merge_items} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				run_id bigint unsigned NOT NULL,
				object_type varchar(64) NOT NULL,
				object_id bigint unsigned NOT NULL,
				old_owner bigint unsigned NULL,
				new_owner bigint unsigned NULL,
				payload longtext NULL,
				status varchar(20) NOT NULL DEFAULT 'planned',
				created_at datetime NOT NULL,
				applied_at datetime NULL,
				rolled_back_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY run_object (run_id, object_type, object_id),
				KEY run_status (run_id, status)
			) {$charset_collate};"
		);

		IdentityRepository::set_ready(
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $identities ) ) === $identities
		);
	}

	public static function grant_capabilities(): void {
		foreach ( array_keys( wp_roles()->roles ) as $role_name ) {
			$role = get_role( $role_name );

			if ( $role && 'administrator' !== $role_name ) {
				$role->remove_cap( 'manage_pinova_identities' );
			}
		}

		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			$administrator->add_cap( 'manage_pinova_identities' );
		}
	}

}
