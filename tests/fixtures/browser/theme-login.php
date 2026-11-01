<?php
/** Render the real theme adapters only inside the disposable browser CLI site. */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'ABSPATH' )
    || 'local' !== wp_get_environment_type()
    || 8890 !== wp_parse_url( home_url(), PHP_URL_PORT )
    || 'pinova-theme-browser' !== getenv( 'PINOVA_BROWSER_FIXTURE' )
    || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), [ 'localhost', '127.0.0.1' ], true ) ) {
    throw new RuntimeException( 'A run-owned loopback browser fixture is required.' );
}

add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'pre_http_request', static fn() => new WP_Error( 'pinova_fixture_no_network' ) );

$templates = [
    'woodmart' => \Pinova\Integrations\Woodmart\Load::get_template(),
    'flatsome' => \Pinova\Integrations\Flatsome\Load::get_template(),
];
foreach ( $templates as $template ) {
    if ( ! is_string( $template ) || strlen( $template ) > 131072 ) {
        throw new RuntimeException( 'The rendered theme fixture is unavailable.' );
    }
}
echo 'PINOVA_THEME_FIXTURE:' . base64_encode( wp_json_encode( $templates ) ) . PHP_EOL;
