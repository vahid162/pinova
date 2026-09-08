<?php

namespace Pinova\Identity;

class ConflictRepository {
	public static function record( IdentityConflictException $conflict ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'pinova_identity_conflicts';
		$hash  = hash_hmac(
			'sha256',
			$conflict->get_identity_type() . ':' . $conflict->get_normalized_value(),
			wp_salt( 'auth' )
		);

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (`value_hash`, `type`, `masked_value`, `user_ids`, `status`, `detected_at`, `updated_at`)
				 VALUES (%s, %s, %s, %s, 'open', UTC_TIMESTAMP(), UTC_TIMESTAMP())
				 ON DUPLICATE KEY UPDATE `user_ids` = VALUES(`user_ids`), `status` = 'open', `updated_at` = UTC_TIMESTAMP()",
				$table,
				$hash,
				$conflict->get_identity_type(),
				self::mask( $conflict->get_identity_type(), $conflict->get_normalized_value() ),
				wp_json_encode( $conflict->get_user_ids() )
			)
		);

		do_action( 'pinova/identity_conflict', $conflict->get_identity_type(), $conflict->get_user_ids(), $hash );
	}

	public static function all_open(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'pinova_identity_conflicts';

		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT `id`, `type`, `masked_value`, `user_ids`, `detected_at`, `updated_at` FROM %i WHERE `status` = 'open' ORDER BY `updated_at` DESC", $table ),
			ARRAY_A
		);
	}

	public static function mask( string $type, string $value ): string {
		if ( 'email' === $type && str_contains( $value, '@' ) ) {
			[ $local, $domain ] = explode( '@', $value, 2 );

			return substr( $local, 0, 1 ) . '***@' . $domain;
		}

		if ( 'mobile' === $type ) {
			return substr( $value, 0, 3 ) . '******' . substr( $value, -4 );
		}

		return strlen( $value ) > 4
			? substr( $value, 0, 2 ) . '***' . substr( $value, -2 )
			: '***';
	}
}
