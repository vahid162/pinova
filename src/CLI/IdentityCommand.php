<?php

namespace Pinova\CLI;

use Pinova\Identity\ConflictRepository;
use Pinova\Identity\IdentityAuditService;
use Pinova\Identity\IdentityMergeService;
use Pinova\Identity\IdentityMigrationService;
use Throwable;

class IdentityCommand {
	/** Audit current legacy identifiers without changing users or identities. */
	public function audit( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		$audit = IdentityAuditService::audit( false );
		self::output(
			[
				'total_users'               => $audit['total_users'],
				'multiple_identifier_users' => count( $audit['multiple_identifiers'] ),
				'conflicts'                 => count( $audit['conflicts'] ),
				'possible_matches'          => count( $audit['possible_matches'] ),
				'safe_identities'           => count( $audit['safe_identities'] ),
			]
		);
	}

	/** Migrate safe identities in resumable batches. */
	public function migrate( array $args, array $assoc_args ): void {
		unset( $args );
		$apply = self::mode( $assoc_args );

		try {
			$result = $apply
				? IdentityMigrationService::apply( absint( $assoc_args['batch-size'] ?? 250 ) )
				: IdentityMigrationService::dry_run();
			self::output( $result );
		} catch ( Throwable $throwable ) {
			\WP_CLI::error( $throwable->getMessage() );
		}
	}

	/** List masked identity conflicts. */
	public function conflicts( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		$audit = IdentityAuditService::audit( false );
		$rows  = [];

		foreach ( $audit['conflicts'] as $conflict ) {
			$rows[] = [
				'type'         => $conflict['type'],
				'masked_value' => ConflictRepository::mask( $conflict['type'], $conflict['normalized_value'] ),
				'user_ids'     => implode( ',', array_map( 'intval', $conflict['user_ids'] ) ),
			];
		}

		if ( ! $rows ) {
			\WP_CLI::success( 'No deterministic identity conflicts found.' );

			return;
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'type', 'masked_value', 'user_ids' ] );
	}

	/** Merge a source user into an explicitly selected canonical target user. */
	public function merge( array $args, array $assoc_args ): void {
		$source = absint( $args[0] ?? 0 );
		$target = absint( $assoc_args['into'] ?? 0 );
		$apply  = self::mode( $assoc_args );

		if ( ! $source || ! $target ) {
			\WP_CLI::error( 'Usage: wp pinova identity merge <source> --into=<target> --dry-run|--apply [--yes]' );
		}

		try {
			$result = $apply
				? IdentityMergeService::apply( $source, $target, isset( $assoc_args['resume'] ) ? absint( $assoc_args['resume'] ) : null )
				: IdentityMergeService::dry_run( $source, $target );
			self::output( $result );
		} catch ( Throwable $throwable ) {
			\WP_CLI::error( $throwable->getMessage() );
		}
	}

	/** Roll back only objects still owned by the target state created by a merge run. */
	public function rollback( array $args, array $assoc_args ): void {
		$run_id = absint( $args[0] ?? 0 );
		$apply  = self::mode( $assoc_args );

		if ( ! $run_id ) {
			\WP_CLI::error( 'Usage: wp pinova identity rollback <run-id> --dry-run|--apply [--yes]' );
		}

		try {
			self::output( IdentityMergeService::rollback( $run_id, $apply ) );
		} catch ( Throwable $throwable ) {
			\WP_CLI::error( $throwable->getMessage() );
		}
	}

	private static function mode( array $assoc_args ): bool {
		$apply   = array_key_exists( 'apply', $assoc_args );
		$dry_run = array_key_exists( 'dry-run', $assoc_args );

		if ( $apply === $dry_run ) {
			\WP_CLI::error( 'Choose exactly one of --dry-run or --apply.' );
		}

		if ( $apply && ! array_key_exists( 'yes', $assoc_args ) ) {
			\WP_CLI::error( '--apply requires the explicit --yes flag.' );
		}

		return $apply;
	}

	private static function output( array $data ): void {
		\WP_CLI::log( (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}
}
