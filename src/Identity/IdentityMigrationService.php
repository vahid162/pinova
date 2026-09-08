<?php

namespace Pinova\Identity;

use RuntimeException;
use WP_User;

class IdentityMigrationService {
	private const CHECKPOINT_OPTION = 'pinova_identity_migration_checkpoint';

	public static function dry_run(): array {
		$audit = IdentityAuditService::audit();

		return self::summary( $audit );
	}

	public static function apply( int $batch_size = 250 ): array {
		if ( is_multisite() ) {
			throw new RuntimeException( 'Identity migration apply is not supported on Multisite.' );
		}

		if ( ! IdentityRepository::is_ready() ) {
			throw new RuntimeException( 'Identity tables are not installed.' );
		}

		global $wpdb;

		update_option( 'pinova_identity_maintenance', 1, false );

		try {
			$audit         = IdentityAuditService::audit( true );
			$conflict_keys = [];

			foreach ( $audit['conflicts'] as $conflict ) {
				$conflict_keys[ $conflict['type'] . ':' . $conflict['normalized_value'] ] = true;
			}

			$last_id = (int) get_option( self::CHECKPOINT_OPTION, 0 );
			$added   = 0;
			$skipped = 0;

			do {
				$user_ids = array_map(
					'intval',
					(array) $wpdb->get_col(
						$wpdb->prepare(
							'SELECT `ID` FROM %i WHERE `ID` > %d ORDER BY `ID` ASC LIMIT %d',
							$wpdb->users,
							$last_id,
							max( 1, min( 1000, $batch_size ) )
						)
					)
				);

				foreach ( $user_ids as $user_id ) {
					$user = get_userdata( $user_id );

					if ( ! $user instanceof WP_User ) {
						continue;
					}

					if ( get_user_meta( $user_id, 'pinova_merged_into', true ) || get_user_meta( $user_id, 'pinova_account_disabled', true ) ) {
						$last_id = $user_id;
						update_option( self::CHECKPOINT_OPTION, $last_id, false );
						continue;
					}

					$primary_types = [];

					foreach ( IdentityAuditService::candidates_for_user( $user ) as $candidate ) {
						$key = $candidate['type'] . ':' . $candidate['normalized_value'];

						if ( isset( $conflict_keys[ $key ] ) ) {
							++$skipped;
							continue;
						}

						try {
							IdentityRepository::add(
								$user_id,
								$candidate['type'],
								$candidate['normalized_value'],
								null,
								'legacy:' . $candidate['source'],
								! isset( $primary_types[ $candidate['type'] ] )
							);
							$primary_types[ $candidate['type'] ] = true;
							++$added;
						} catch ( IdentityConflictException $conflict ) {
							ConflictRepository::record( $conflict );
							++$skipped;
						}
					}

					$last_id = $user_id;
					update_option( self::CHECKPOINT_OPTION, $last_id, false );
				}
				$batch_count = count( $user_ids );
				$batch_limit = max( 1, min( 1000, $batch_size ) );
			} while ( $batch_count === $batch_limit );

			delete_option( self::CHECKPOINT_OPTION );
		} finally {
			delete_option( 'pinova_identity_maintenance' );
		}

		return array_merge(
			self::summary( $audit ),
			[
				'processed_identity_rows' => $added,
				'skipped_conflicts'       => $skipped,
			]
		);
	}

	private static function summary( array $audit ): array {
		return [
			'total_users'               => (int) $audit['total_users'],
			'multiple_identifier_users' => count( $audit['multiple_identifiers'] ),
			'conflicts'                 => count( $audit['conflicts'] ),
			'possible_matches'          => count( $audit['possible_matches'] ),
			'safe_identities'           => count( $audit['safe_identities'] ),
		];
	}
}
