<?php
/**
 * DB-free host context for the real WordPress REST server, not a WordPress install.
 * No Pinova bootstrap, credentials, persistent queue, mail, or network provider loads.
 */

declare(strict_types=1);

if ( '1' !== getenv( 'PINOVA_FPM_FIXTURE' ) ) {
	exit( 1 );
}

$core = realpath( (string) getenv( 'PINOVA_FPM_WORDPRESS_DIR' ) );
if ( false === $core || ! str_starts_with( $core, '/tmp/pinova-fpm-' ) || ! is_file( $core . '/wp-includes/version.php' ) ) {
	throw new RuntimeException( 'Missing isolated WordPress core.' );
}

define( 'ABSPATH', $core . '/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_DEBUG', false );
define( 'WP_CONTENT_DIR', $core . '/wp-content' );
define( 'WP_LANG_DIR', $core . '/wp-content/languages' );
define( 'REST_REQUEST', true );
define( 'WP_INSTALLING', false );

foreach ( [
	'version.php', 'compat.php', 'plugin.php', 'load.php',
	'class-wp-object-cache.php', 'cache.php', 'functions.php', 'formatting.php',
	'class-wp-error.php', 'pomo/translations.php', 'l10n.php', 'kses.php', 'link-template.php',
	'class-wp-http-response.php', 'rest-api/class-wp-rest-response.php',
	'rest-api/class-wp-rest-request.php', 'rest-api/class-wp-rest-server.php', 'rest-api.php',
] as $file ) {
	require_once ABSPATH . WPINC . '/' . $file;
}

wp_cache_init();

// These are synthetic site options, supplied through core's documented short circuit.
add_filter(
	'pre_option',
	static function ( $pre, string $option ) {
		$options = [
			'blog_charset'        => 'UTF-8',
			'home'                => 'http://127.0.0.1',
			'siteurl'             => 'http://127.0.0.1',
			'permalink_structure' => '',
			'WPLANG'              => '',
		];
		return $options[ $option ] ?? null;
	},
	10,
	2
);

// Pluggable anonymous context only: no user, cookie, authentication, or database loads.
function is_user_logged_in(): bool {
	return false;
}

// Fail visibly if a core dependency unexpectedly needs persistent storage.
$GLOBALS['wpdb'] = new class {
	public function __call( string $method, array $arguments ) {
		throw new RuntimeException( 'DB-free REST characterization attempted database access.' );
	}
};

// Use core's actual shutdown hook and core's ordinary priority-one flush.
register_shutdown_function( 'shutdown_action_hook' );
add_action( 'shutdown', 'wp_ob_end_flush_all', 1 );

