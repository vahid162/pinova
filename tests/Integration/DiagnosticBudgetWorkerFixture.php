<?php

declare(strict_types=1);

// Core-only bootstrap selects the PHPUnit database supplied privately on stdin,
// rather than the separate development site selected by wp-load.php.
$config = json_decode( (string) fgets( STDIN ), true );
if ( ! is_array( $config ) || 'pinova-diagnostic-budget-worker' !== ( $config['marker'] ?? null ) ) {
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

function wp_salt( $scheme = 'auth' ): string {
	return $GLOBALS['config']['salt'];
}

function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
	return json_encode( $value, $flags, $depth );
}

spl_autoload_register(
	static function ( string $class ) use ( $config ): void {
		if ( str_starts_with( $class, 'Pinova\\' ) ) {
			require $config['repository'] . '/src/' . str_replace( '\\', '/', substr( $class, 7 ) ) . '.php';
		}
	}
);

$wpdb = new wpdb( $config['user'], $config['password'], $config['database'], $config['host'] );
$wpdb->set_prefix( $config['prefix'] );
$wpdb->suppress_errors();
fwrite( STDOUT, json_encode( [ 'phase' => 'ready', 'connection_id' => (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' ) ] ) . PHP_EOL );
fflush( STDOUT );
stream_set_timeout( STDIN, 5 );
if ( 'begin' !== trim( (string) fgets( STDIN ) ) ) {
	exit( 1 );
}

$barrier = null;
$barrier = static function ( string $query ) use ( &$barrier ): string {
	if ( str_starts_with( $query, 'UPDATE ' ) && str_contains( $query, 'pinova_rate_limits' ) ) {
		remove_filter( 'query', $barrier );
		fwrite( STDOUT, json_encode( [ 'phase' => 'before_cas' ] ) . PHP_EOL );
		fflush( STDOUT );
		stream_set_timeout( STDIN, 5 );
		if ( 'go' !== trim( (string) fgets( STDIN ) ) ) {
			exit( 1 );
		}
	}
	return $query;
};
add_filter( 'query', $barrier );
try {
	$admitted = \Pinova\Logging\EventThrottle::admit_diagnostic( $config['flow'], $config['deadline'] );
	fwrite( STDOUT, json_encode( [ 'phase' => 'result', 'admitted' => $admitted ] ) . PHP_EOL );
	fflush( STDOUT );
} catch ( Throwable $throwable ) {
	unset( $throwable );
	fwrite( STDOUT, json_encode( [ 'phase' => 'failed' ] ) . PHP_EOL );
	exit( 1 );
}
