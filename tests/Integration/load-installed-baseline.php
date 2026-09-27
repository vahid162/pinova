<?php
/** Load the checksum-verified archived plugin in an isolated WP-CLI process. */

WP_CLI::add_wp_hook( 'muplugins_loaded', static function (): void {
	if ( ! defined( 'PINOVA_THIRD_PARTY_BASELINE' ) || true !== PINOVA_THIRD_PARTY_BASELINE
		|| 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), [ 'localhost', '127.0.0.1' ], true ) ) {
		throw new RuntimeException( 'Archived plugin requires the disposable loopback fixture.' );
	}
	$root = dirname( __DIR__, 2 );
	$fixture = json_decode( file_get_contents( $root . '/tests/fixtures/third-party-baseline.json' ), true, 512, JSON_THROW_ON_ERROR );
	$path = $root . '/.build/third-party/pinova';
	$build = json_decode( file_get_contents( $path . '/build-info.json' ), true, 512, JSON_THROW_ON_ERROR );
	if ( ( $build['build_commit'] ?? '' ) !== $fixture['pinova_baseline']['commit'] ) {
		throw new RuntimeException( 'Archived plugin source does not match the installed baseline.' );
	}
	require $path . '/pinova.php';
} );
