<?php

namespace Pinova\Identity;

use RuntimeException;

class IdentityRepository {
	private const TYPES         = [ 'username', 'email', 'mobile' ];
	private static ?bool $ready = null;

	public static function set_ready( bool $ready ): void {
		self::$ready = $ready;
	}

	public static function is_ready(): bool {
		if ( null !== self::$ready ) {
			return self::$ready;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'pinova_identities';

		self::$ready = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;

		return self::$ready;
	}

	public static function find_active( string $type, string $normalized_value ): ?int {
		global $wpdb;

		$table = $wpdb->prefix . 'pinova_identities';
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT `user_id` FROM %i WHERE `type` = %s AND `normalized_value` = %s AND `status` = 'active' LIMIT 1",
				$table,
				$type,
				$normalized_value
			)
		);

		return null === $value ? null : (int) $value;
	}

	/** @throws IdentityConflictException */
	public static function add(
		int $user_id,
		string $type,
		string $normalized_value,
		?string $verified_at,
		string $source,
		bool $is_primary = false
	): int {
		global $wpdb;

		$normalized_value = IdentityNormalizer::normalize( $type, $normalized_value );

		if ( $user_id < 1 || null === $normalized_value || ! in_array( $type, self::TYPES, true ) ) {
			throw new RuntimeException( 'Invalid identity.' );
		}

		$source = substr( sanitize_key( $source ), 0, 64 );
		$source = $source ?: 'unknown';

		$table = $wpdb->prefix . 'pinova_identities';
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO %i (`user_id`, `type`, `normalized_value`, `is_primary`, `verified_at`, `status`, `source`, `created_at`, `updated_at`)
				 VALUES (%d, %s, %s, %d, NULLIF(%s, ''), 'active', %s, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
				$table,
				$user_id,
				$type,
				$normalized_value,
				$is_primary ? 1 : 0,
				$verified_at,
				$source
			)
		);

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT `id`, `user_id`, `verified_at` FROM %i WHERE `type` = %s AND `normalized_value` = %s LIMIT 1',
				$table,
				$type,
				$normalized_value
			)
		);

		if ( ! $row ) {
			throw new RuntimeException( 'Identity could not be stored.' );
		}

		if ( (int) $row->user_id !== $user_id ) {
			throw new IdentityConflictException( $type, $normalized_value, [ (int) $row->user_id, $user_id ] );
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET `status` = 'active', `source` = %s, `updated_at` = UTC_TIMESTAMP() WHERE `id` = %d",
				$table,
				$source,
				(int) $row->id
			)
		);

		if ( $verified_at && ! $row->verified_at ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET `verified_at` = COALESCE(`verified_at`, NULLIF(%s, '')), `updated_at` = UTC_TIMESTAMP() WHERE `id` = %d",
					$table,
					$verified_at,
					(int) $row->id
				)
			);
		}

		if ( $is_primary ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET `is_primary` = CASE WHEN `id` = %d THEN 1 ELSE 0 END, `updated_at` = UTC_TIMESTAMP() WHERE `user_id` = %d AND `type` = %s AND `status` = 'active'",
					$table,
					(int) $row->id,
					$user_id,
					$type
				)
			);
		}

		return (int) $row->id;
	}

	/** @throws IdentityConflictException */
	public static function replace_user_type(
		int $user_id,
		string $type,
		string $normalized_value,
		?string $verified_at,
		string $source
	): int {
		global $wpdb;

		$identity_id = self::add( $user_id, $type, $normalized_value, $verified_at, $source, true );
		$table       = $wpdb->prefix . 'pinova_identities';
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET `status` = 'superseded', `is_primary` = 0, `updated_at` = UTC_TIMESTAMP() WHERE `user_id` = %d AND `type` = %s AND `id` <> %d AND `status` = 'active'",
				$table,
				$user_id,
				$type,
				$identity_id
			)
		);

		return $identity_id;
	}

	public static function for_user( int $user_id ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE `user_id` = %d ORDER BY `type`, `is_primary` DESC, `id` ASC',
				$wpdb->prefix . 'pinova_identities',
				$user_id
			),
			ARRAY_A
		);
	}
}
