<?php
/** Only response ordering is isolated here; full-stack tests use the real queue. */

declare(strict_types=1);

namespace Pinova\Services;

function fastcgi_finish_request(): bool {
	\pinova_fpm_marker( 'finish_enter' );
	if ( 'finish_false' === ( $GLOBALS['pinova_fpm_case'] ?? '' ) ) {
		\pinova_fpm_marker( \fastcgi_finish_request() ? 'prior_finish_ok' : 'prior_finish_false' );
	}
	$finished = \fastcgi_finish_request();
	\pinova_fpm_marker( $finished ? 'finish_ok' : 'finish_false' );
	return $finished;
}

function wp_ob_end_flush_all(): void {
	\wp_ob_end_flush_all();
	\pinova_fpm_marker( 'core_flush_completed' );
}

/** This stub is deliberately limited to the DB-free response-boundary experiment. */
final class OTPQueueStore {
	public static function acquire_immediate_slot(): array {
		return [ 'lock' => 'synthetic-slot', 'connection_id' => 'synthetic-connection' ];
	}

	public static function release_immediate_slot( array $lease ): void {
		\pinova_fpm_marker( 'synthetic_slot_released' );
	}
}

final class OTPService {
	public static function deliver_queued( string $flow_id ): void {
		if ( str_repeat( '1', 32 ) !== $flow_id ) {
			throw new \RuntimeException( 'Response adapter dispatched an unrelated flow.' );
		}
		\pinova_fpm_marker( 'account_enter' );
		$case = $GLOBALS['pinova_fpm_case'];
		if ( 'decoy' === $case ) {
			\pinova_fpm_marker( 'decoy_completed' );
			return;
		}
		\pinova_fpm_marker( 'provider_enter' );
		usleep( in_array( $case, [ 'tracked', 'untracked' ], true ) ? 4500000 : 3000000 );
		\pinova_fpm_marker( 'provider_completed' );
	}
}

$adapter = (string) getenv( 'PINOVA_FPM_BOUNDARY_ADAPTER' );
if ( '' === $adapter || ! is_file( $adapter ) || is_link( $adapter ) ) {
	throw new \RuntimeException( 'Production response adapter is required.' );
}
require $adapter;
