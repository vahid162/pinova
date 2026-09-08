<?php

namespace Pinova\Identity;

use Pinova\Objects\Identifier;
use Pinova\Objects\Mobile;
use Pinova\Services\UserService;

class IdentityResolver {
	/** @throws IdentityConflictException */
	public static function resolve( Identifier $identifier, bool $record_conflict = true ): ?int {
		$type       = $identifier->get_type();
		$normalized = IdentityNormalizer::normalize( $type, $identifier->get_value() );

		if ( null === $normalized ) {
			return null;
		}

		if ( IdentityRepository::is_ready() ) {
			$user_id = IdentityRepository::find_active( $type, $normalized );

			if ( $user_id ) {
				return self::canonical_user_id( $user_id );
			}
		}

		$user_ids = self::legacy_matches( $type, $normalized );
		$user_ids = array_values( array_unique( array_map( [ self::class, 'canonical_user_id' ], $user_ids ) ) );

		if ( count( $user_ids ) > 1 ) {
			$conflict = new IdentityConflictException( $type, $normalized, $user_ids );

			if ( $record_conflict ) {
				ConflictRepository::record( $conflict );
			}

			throw $conflict;
		}

		return $user_ids[0] ?? null;
	}

	public static function claim( Identifier $identifier, int $user_id, bool $verified, string $source ): int {
		$normalized = IdentityNormalizer::normalize( $identifier->get_type(), $identifier->get_value() );

		if ( null === $normalized ) {
			throw new \RuntimeException( 'Invalid identity.' );
		}

		return IdentityRepository::add(
			self::canonical_user_id( $user_id ),
			$identifier->get_type(),
			$normalized,
			$verified ? current_time( 'mysql', true ) : null,
			$source,
			true
		);
	}

	public static function canonical_user_id( int $user_id ): int {
		$seen  = [];
		$depth = 0;

		while ( $user_id > 0 && $depth < 10 ) {
			if ( isset( $seen[ $user_id ] ) ) {
				break;
			}

			$seen[ $user_id ] = true;
			++$depth;
			$next = (int) get_user_meta( $user_id, 'pinova_merged_into', true );

			if ( $next < 1 ) {
				break;
			}

			$user_id = $next;
		}

		return $user_id;
	}

	private static function legacy_matches( string $type, string $normalized ): array {
		global $wpdb;

		if ( Identifier::TYPE_EMAIL === $type ) {
			return array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare( 'SELECT `ID` FROM %i WHERE LOWER(`user_email`) = %s', $wpdb->users, $normalized )
				)
			);
		}

		if ( Identifier::TYPE_USERNAME === $type ) {
			return array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare( 'SELECT `ID` FROM %i WHERE LOWER(`user_login`) = %s', $wpdb->users, $normalized )
				)
			);
		}

		$mobile = new Mobile( $normalized );

		if ( ! $mobile->is_valid() ) {
			return [];
		}

		$formats  = array_values( array_unique( array_map( 'strval', $mobile->possible_formats() ) ) );
		$keys     = array_values(
			array_unique(
				array_merge( UserService::mobile_possible_meta_keys(), [ 'pinova_mobile', 'billing_phone', 'shipping_phone' ] )
			)
		);
		$user_ids = self::query_user_logins( $formats );

		if ( $keys && $formats ) {
			$key_placeholders   = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
			$value_placeholders = implode( ', ', array_fill( 0, count( $formats ), '%s' ) );
			$parameters         = array_merge( [ $wpdb->usermeta ], $keys, $formats );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only placeholder tokens are constructed here.
			$query    = "SELECT DISTINCT `user_id` FROM %i WHERE `meta_key` IN ({$key_placeholders}) AND `meta_value` IN ({$value_placeholders})";
			$user_ids = array_merge(
				$user_ids,
				array_map(
					'intval',
					(array) $wpdb->get_col(
						$wpdb->prepare(
							// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The constructed query contains placeholders only.
							$query,
							...$parameters
						)
					)
				)
			);
		}

		return $user_ids;
	}

	private static function query_user_logins( array $values ): array {
		global $wpdb;

		if ( ! $values ) {
			return [];
		}

		$placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );
		$parameters   = array_merge( [ $wpdb->users ], $values );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only placeholder tokens are constructed here.
		$query = "SELECT DISTINCT `ID` FROM %i WHERE `user_login` IN ({$placeholders})";
		$rows  = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The constructed query contains placeholders only.
				$query,
				...$parameters
			)
		);

		return array_map( 'intval', (array) $rows );
	}
}
