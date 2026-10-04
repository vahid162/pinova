<?php
/** REST lifecycle regression on the checksum-verified disposable CI site only. */

declare(strict_types=1);

use Pinova\Integrations\WpForo\Load;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'PINOVA_THIRD_PARTY_BASELINE' ) || ! PINOVA_THIRD_PARTY_BASELINE
	|| 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), [ 'localhost', '127.0.0.1' ], true ) ) {
	throw new RuntimeException( 'REST regressions require the disposable loopback site.' );
}
define( 'REST_REQUEST', true );
$check = static function ( bool $condition, string $label ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'REST regression failed: ' . $label );
	}
};
$error = new WP_Error( 'fixture_rest_denied', 'Synthetic denial', [ 'status' => 403 ] );
$original_topic = WPF()->topic;
WPF()->topic = null;
$check( $error === Load::rest_initialize( $error ) && null === WPF()->topic, 'upstream denial is preserved without initialization' );
$check( null === Load::rest_initialize( null ) && is_object( WPF()->topic ), 'missing topic service initialized through native lifecycle' );
$topic = WPF()->topic;
$check( true === Load::rest_initialize( true ) && $topic === WPF()->topic, 'successful authentication and existing service remain unchanged' );
WPF()->topic = $original_topic;
WP_CLI::line( 'PINOVA_REST_COMPLETE' );
