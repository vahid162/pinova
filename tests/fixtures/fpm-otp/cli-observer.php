<?php
/** Early/final sampling fences; loaded only by this disposable fixture's CLI. */
declare(strict_types=1);

$pinova_observer_root = (string) getenv( 'PINOVA_FPM_FULL_STACK_ROOT' );
$pinova_observer_id   = (string) getenv( 'PINOVA_FPM_CLI_OBSERVATION' );
if ( 'cli' !== PHP_SAPI || 'true' !== getenv( 'GITHUB_ACTIONS' ) || 'github-hosted' !== getenv( 'RUNNER_ENVIRONMENT' )
	|| '1' !== getenv( 'PINOVA_FPM_FULL_STACK_FIXTURE' ) || '1' !== getenv( 'PINOVA_FPM_NETWORK_ISOLATED' )
	|| $pinova_observer_root !== '/tmp/pinova-fpm-full-' . getenv( 'GITHUB_RUN_ID' ) . '-' . getenv( 'GITHUB_RUN_ATTEMPT' )
	|| ! preg_match( '/\A[1-9][0-9]{0,3}\z/D', $pinova_observer_id ) || is_link( $pinova_observer_root )
	|| ! is_dir( $pinova_observer_root . '/private' ) ) {
	exit( 1 );
}
$pinova_observer_prefix = $pinova_observer_root . '/private/cli-' . $pinova_observer_id;
$pinova_observer_fence  = static function ( string $phase ) use ( $pinova_observer_prefix ): void {
	$marker = $pinova_observer_prefix . '.' . $phase;
	if ( false === file_put_contents( $marker . '.tmp', (string) getmypid(), LOCK_EX ) || ! rename( $marker . '.tmp', $marker ) ) {
		throw new RuntimeException( 'CLI sampling fence unavailable.' );
	}
	$end = hrtime( true ) + 5000000000;
	do {
		clearstatcache( true, $marker . '-ack' );
		if ( is_file( $marker . '-ack' ) ) {
			return;
		}
		usleep( 1000 );
	} while ( hrtime( true ) < $end );
	throw new RuntimeException( 'CLI sampling acknowledgement missing.' );
};
$pinova_observer_fence( 'start' );
// Append the final fence during shutdown, after the already registered
// WordPress shutdown hook, rather than sampling before command completion.
register_shutdown_function( static function () use ( $pinova_observer_fence ): void {
	register_shutdown_function( static function () use ( $pinova_observer_fence ): void { $pinova_observer_fence( 'final' ); } );
} );
unset( $pinova_observer_root, $pinova_observer_id, $pinova_observer_prefix, $pinova_observer_fence );
