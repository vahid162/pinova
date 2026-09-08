<?php

namespace Pinova\Identity;

use Pinova\Objects\Identifier;
use Pinova\Objects\Mobile;
use Pinova\Services\UserService;
use WP_User;

class IdentityAuditService {
	public static function audit( bool $record_conflicts = false ): array {
		global $wpdb;

		$by_identity = [];
		$by_user     = [];
		$similarity  = [];
		$last_id     = 0;

		do {
			$user_ids = array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare( 'SELECT `ID` FROM %i WHERE `ID` > %d ORDER BY `ID` ASC LIMIT 500', $wpdb->users, $last_id )
				)
			);

			foreach ( $user_ids as $user_id ) {
				$user = get_userdata( $user_id );

				if ( ! $user instanceof WP_User ) {
					continue;
				}

				$candidates          = self::candidates_for_user( $user );
				$by_user[ $user_id ] = $candidates;

				foreach ( $candidates as $candidate ) {
					$key                                     = $candidate['type'] . ':' . $candidate['normalized_value'];
					$by_identity[ $key ]['type']             = $candidate['type'];
					$by_identity[ $key ]['normalized_value'] = $candidate['normalized_value'];
					$by_identity[ $key ]['user_ids'][]       = IdentityResolver::canonical_user_id( $user_id );
				}

				$fingerprint = sanitize_title( trim( (string) $user->display_name ) );

				if ( strlen( $fingerprint ) >= 4 ) {
					$similarity[ $fingerprint ][] = $user_id;
				}
			}

			if ( $user_ids ) {
				$last_id = max( $user_ids );
			}
			$batch_count = count( $user_ids );
		} while ( 500 === $batch_count );

		$multiple_identifiers = [];

		foreach ( $by_user as $user_id => $candidates ) {
			if ( count( $candidates ) > 1 ) {
				$multiple_identifiers[] = [
					'user_id'    => $user_id,
					'identities' => $candidates,
				];
			}
		}

		$conflicts       = [];
		$safe_identities = [];

		foreach ( $by_identity as $identity ) {
			$identity['user_ids'] = array_values( array_unique( array_map( 'intval', $identity['user_ids'] ) ) );

			if ( count( $identity['user_ids'] ) > 1 ) {
				$conflicts[] = $identity;
				if ( $record_conflicts ) {
					ConflictRepository::record(
						new IdentityConflictException( $identity['type'], $identity['normalized_value'], $identity['user_ids'] )
					);
				}
			} else {
				$safe_identities[] = $identity;
			}
		}

		$possible_matches = [];

		foreach ( $similarity as $fingerprint => $user_ids ) {
			$user_ids = array_values( array_unique( array_map( 'intval', $user_ids ) ) );

			if ( count( $user_ids ) > 1 ) {
				$possible_matches[] = [
					'fingerprint' => ConflictRepository::mask( 'username', $fingerprint ),
					'user_ids'    => $user_ids,
				];
			}
		}

		return [
			'multiple_identifiers' => $multiple_identifiers,
			'conflicts'            => $conflicts,
			'possible_matches'     => $possible_matches,
			'safe_identities'      => $safe_identities,
			'total_users'          => count( $by_user ),
		];
	}

	public static function candidates_for_user( WP_User $user ): array {
		global $wpdb;

		$candidates = [];
		self::append( $candidates, Identifier::TYPE_USERNAME, (string) $user->user_login, 'user_login' );
		self::append( $candidates, Identifier::TYPE_EMAIL, (string) $user->user_email, 'user_email' );

		$login_mobile = new Mobile( (string) $user->user_login );

		if ( $login_mobile->is_valid() ) {
			self::append( $candidates, Identifier::TYPE_MOBILE, $login_mobile->get_formatted(), 'user_login_mobile' );
		}

		$keys = array_values(
			array_unique(
				array_merge( UserService::mobile_possible_meta_keys(), [ 'pinova_mobile', 'billing_phone', 'shipping_phone' ] )
			)
		);

		if ( $keys ) {
			$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
			$parameters   = array_merge( [ $wpdb->usermeta, $user->ID ], $keys );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only placeholder tokens are constructed here.
			$query = "SELECT `meta_key`, `meta_value` FROM %i WHERE `user_id` = %d AND `meta_key` IN ({$placeholders})";
			$rows  = (array) $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The constructed query contains placeholders only.
					$query,
					...$parameters
				),
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				self::append( $candidates, Identifier::TYPE_MOBILE, (string) $row['meta_value'], 'meta:' . $row['meta_key'] );
			}
		}

		return array_values( $candidates );
	}

	private static function append( array &$candidates, string $type, string $value, string $source ): void {
		$normalized = IdentityNormalizer::normalize( $type, $value );

		if ( null === $normalized ) {
			return;
		}

		$key = $type . ':' . $normalized;

		if ( ! isset( $candidates[ $key ] ) ) {
			$candidates[ $key ] = [
				'type'             => $type,
				'normalized_value' => $normalized,
				'source'           => $source,
			];
		}
	}
}
