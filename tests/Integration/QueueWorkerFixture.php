<?php

declare(strict_types=1);

// This worker deliberately does not load wp-load.php: that would select the
// separate development database instead of the PHPUnit database supplied here.
$config = json_decode( (string) fgets( STDIN ), true );
if ( ! is_array( $config ) || 'pinova-queue-test-worker' !== ( $config['marker'] ?? null ) ) {
	exit( 1 );
}
define( 'ABSPATH', $config['wordpress'] );
define( 'WPINC', 'wp-includes' );
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', false );
define( 'MULTISITE', false );
require ABSPATH . WPINC . '/compat.php';
require ABSPATH . WPINC . '/load.php';
require ABSPATH . WPINC . '/plugin.php';
require ABSPATH . WPINC . '/formatting.php';
require ABSPATH . WPINC . ( is_file( ABSPATH . WPINC . '/class-wpdb.php' ) ? '/class-wpdb.php' : '/wp-db.php' );

// Only the queue encryption key is needed; never load site options or sessions.
function wp_salt( $scheme = 'auth' ): string {
	return $GLOBALS['config']['salt'];
}

spl_autoload_register(
	static function ( string $class ) use ( $config ): void {
		if ( str_starts_with( $class, 'Pinova\\' ) ) {
			require $config['repository'] . '/src/' . str_replace( '\\', '/', substr( $class, 7 ) ) . '.php';
		}
	}
);

$wpdb = new wpdb( $config['user'], $config['password'], $config['database'], $config['host'] );
$wpdb->suppress_errors();
$wpdb->set_prefix( $config['prefix'] );
$flow  = $config['flow'];
$claim = null;

while ( false !== ( $line = fgets( STDIN ) ) ) {
	$command = trim( $line );
	$result  = false;
	if ( 'claim' === $command ) {
		$claim  = \Pinova\Services\RateLimitService::claim_queued_otp( $flow );
		$result = null !== $claim;
	} elseif ( 'active' === $command && null !== $claim ) {
		$result = \Pinova\Services\RateLimitService::queued_otp_is_active( $flow, $claim['claim_token'] );
	} elseif ( 'finish' === $command && null !== $claim ) {
		$result = \Pinova\Services\RateLimitService::finish_queued_otp( $flow, $claim['claim_token'] );
		$claim  = null;
	} elseif ( 'exit' === $command ) {
		break;
	}
	fwrite( STDOUT, json_encode( [ 'result' => $result ] ) . PHP_EOL );
	fflush( STDOUT );
}
