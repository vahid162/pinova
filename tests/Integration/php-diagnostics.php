<?php

declare(strict_types=1);

use PinovaFixture\Browser\PHPDiagnostics;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'PINOVA_THIRD_PARTY_BASELINE' ) || ! PINOVA_THIRD_PARTY_BASELINE ) {
	throw new RuntimeException( 'PHP diagnostics require the disposable real-plugin fixture.' );
}
require_once dirname( __DIR__ ) . '/fixtures/browser/PHPDiagnostics.php';
$phase = $args[0] ?? null;
if ( ! in_array( $phase, [ 'preflight', 'collect' ], true ) ) {
	throw new RuntimeException( 'Invalid PHP diagnostic phase.' );
}
$settings = [ 'debug' => defined( 'WP_DEBUG' ) && true === WP_DEBUG,
	'log' => defined( 'WP_DEBUG_LOG' ) && true === WP_DEBUG_LOG,
	'display' => defined( 'WP_DEBUG_DISPLAY' ) && is_bool( WP_DEBUG_DISPLAY ) ? WP_DEBUG_DISPLAY : null ];
$configuration_ok = true === $settings['debug'] && true === $settings['log'] && false === $settings['display'];
$path = WP_CONTENT_DIR . '/debug.log';
$logging_ready = false;
$logging_route_expected = $path === ini_get( 'error_log' );
$probe_before = [ 'read_success' => false, 'failure_reason' => 'unreadable' ];
if ( 'preflight' === $phase && $configuration_ok && $logging_route_expected ) {
	$probe_before = PHPDiagnostics::capture( $path );
	$logging_ready = error_log( PHPDiagnostics::PROBE );
}
$capture = PHPDiagnostics::capture( $path );
$snapshot = PHPDiagnostics::snapshot( $capture );
if ( 'preflight' === $phase ) {
	$logging_ready = $logging_ready && PHPDiagnostics::readiness( $probe_before, $capture );
	$result = [ 'state' => $snapshot['state'], 'reason' => $snapshot['reason'], 'cursor' => $snapshot['cursor'], 'logging_ready' => $logging_ready, 'logging_route_expected' => $logging_route_expected ];
} else {
	$baseline = json_decode( $args[1] ?? 'null', true );
	$result = PHPDiagnostics::summarize( $capture, is_array( $baseline ) ? $baseline : null );
}
echo 'PINOVA_BROWSER_PHP_DIAGNOSTICS ' . json_encode( [ 'schema' => 'pinova.fixture-php-diagnostics.v1',
	'phase' => $phase, 'settings' => $settings, 'configuration_ok' => $configuration_ok, 'result' => $result ] ) . "\n";
