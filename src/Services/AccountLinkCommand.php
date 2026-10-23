<?php

namespace Pinova\Services;

use Throwable;

/** Privileged operator tool; the default invocation is a read-only preview. */
final class AccountLinkCommand {
	/**
	 * Preview or reconcile an empty Pinova account after independently proving both owners.
	 *
	 * ## OPTIONS
	 *
	 * --canonical=<id>
	 * : Existing customer account to preserve.
	 *
	 * --source=<id>
	 * : Empty mobile-only Pinova account whose aliases will be retired.
	 *
	 * [--apply]
	 * : Apply the previously previewed operation.
	 *
	 * [--ownership-verified]
	 * : Attest independent ownership verification of both accounts; contact matching is insufficient.
	 *
	 * [--expected=<fingerprint>]
	 * : Exact state fingerprint from the latest preview; required for writes.
	 *
	 * [--rollback]
	 * : Revert only this operation's identity rows; preserve every order/permission.
	 *
	 * Use --user=<administrator> and obtain an approved private rollback backup before applying.
	 */
	public function __invoke( array $args, array $flags ): void {
		$valid     = ! $args && ! array_diff( array_keys( $flags ), [ 'canonical', 'source', 'apply', 'ownership-verified', 'expected', 'rollback' ] );
		$canonical = (string) ( $flags['canonical'] ?? '' );
		$source    = (string) ( $flags['source'] ?? '' );
		foreach ( [ 'apply', 'rollback', 'ownership-verified' ] as $flag ) {
			$valid = $valid && ( ! isset( $flags[ $flag ] ) || true === $flags[ $flag ] );
		}
		if ( ! $valid || ! preg_match( '/\A[1-9][0-9]*\z/', $canonical ) || ! preg_match( '/\A[1-9][0-9]*\z/', $source )
			|| (string) (int) $canonical !== $canonical || (string) (int) $source !== $source
			|| ( isset( $flags['apply'] ) && isset( $flags['rollback'] ) ) ) {
			\WP_CLI::error( 'Supply explicit canonical/source IDs and one supported operation.' );
		}
		try {
			$expected = (string) ( $flags['expected'] ?? '' );
			if ( isset( $flags['apply'] ) || isset( $flags['rollback'] ) ) {
				if ( ! preg_match( '/\A[a-f0-9]{64}\z/', $expected ) ) {
					\WP_CLI::error( 'Preview first and supply its exact --expected fingerprint.' );
				}
				if ( isset( $flags['apply'] ) ) {
					AccountLinkService::apply( (int) $canonical, (int) $source, $expected, isset( $flags['ownership-verified'] ) );
				} else {
					AccountLinkService::rollback( (int) $canonical, (int) $source, $expected );
				}
			}
			\WP_CLI::line( wp_json_encode( AccountLinkService::plan( (int) $canonical, (int) $source ) ) );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			\WP_CLI::error( 'Account reconciliation was not completed. Check Pinova issues and the current read-only preview; do not delete users or regenerate permissions.' );
		}
	}
}
