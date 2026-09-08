<?php

declare(strict_types=1);

$wordpress_path = rtrim( (string) getenv( 'PINOVA_TEST_WORDPRESS_PATH' ), '/\\' ) . '/';
$plugin_path    = rtrim( (string) getenv( 'PINOVA_TEST_PLUGIN_PATH' ), '/\\' );
$mobile         = (string) ( $argv[1] ?? '' );

if ( ! is_file( $wordpress_path . 'wp-settings.php' ) || ! is_file( $plugin_path . '/vendor/autoload.php' ) ) {
	fwrite( STDERR, "The concurrent registration worker is not configured.\n" );
	exit( 2 );
}

define( 'ABSPATH', $wordpress_path );
define( 'DB_NAME', (string) getenv( 'PINOVA_TEST_DB_NAME' ) );
define( 'DB_USER', (string) getenv( 'PINOVA_TEST_DB_USER' ) );
define( 'DB_PASSWORD', (string) getenv( 'PINOVA_TEST_DB_PASSWORD' ) );
define( 'DB_HOST', (string) getenv( 'PINOVA_TEST_DB_HOST' ) );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
define( 'AUTH_KEY', 'pinova-worker-auth-key' );
define( 'SECURE_AUTH_KEY', 'pinova-worker-secure-auth-key' );
define( 'LOGGED_IN_KEY', 'pinova-worker-logged-in-key' );
define( 'NONCE_KEY', 'pinova-worker-nonce-key' );
define( 'AUTH_SALT', 'pinova-worker-auth-salt' );
define( 'SECURE_AUTH_SALT', 'pinova-worker-secure-auth-salt' );
define( 'LOGGED_IN_SALT', 'pinova-worker-logged-in-salt' );
define( 'NONCE_SALT', 'pinova-worker-nonce-salt' );
define( 'WP_DEBUG', false );

$table_prefix = (string) getenv( 'PINOVA_TEST_TABLE_PREFIX' );

$_SERVER['HTTP_HOST']   = 'example.org';
$_SERVER['REQUEST_URI'] = '/';

require ABSPATH . 'wp-settings.php';
require $plugin_path . '/vendor/autoload.php';

try {
	$user_id = \Pinova\Services\UserService::create( $mobile, '', [ 'role' => 'subscriber' ], true );
	$result  = [
		'success' => true,
		'user_id' => $user_id,
	];
} catch ( Throwable $throwable ) {
	$result = [
		'success' => false,
		'error'   => get_class( $throwable ),
	];
}

echo 'PINOVA_RESULT=' . wp_json_encode( $result ) . PHP_EOL;
