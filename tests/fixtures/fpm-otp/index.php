<?php
/** Real FPM request entrypoint. All identities and delivery work are synthetic. */

declare(strict_types=1);

if ( '1' !== getenv( 'PINOVA_FPM_FIXTURE' ) ) {
	exit( 1 );
}
if ( 'cli' === PHP_SAPI ) {
	$_GET['case'] = 'unsupported_cli';
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
}

$case = $_GET['case'] ?? 'ping';
$cases = [ 'ping', 'healthy', 'decoy', 'failed', 'replaced', 'mutated', 'served', 'partial', 'removed', 'preexisting', 'foreign', 'late', 'late_same_priority', 'late_removed', 'fatal', 'fatal_shutdown', 'finish_false', 'unsupported', 'unsupported_cli', 'untracked', 'tracked', 'content_type', 'content_length', 'content_encoding', 'flag_off', 'headroom_exact', 'headroom_blocked', 'headroom_reset' ];
if ( ! is_string( $case ) || ! in_array( $case, $cases, true ) ) {
	http_response_code( 400 );
	exit;
}
$GLOBALS['pinova_fpm_case'] = $case;

function pinova_fpm_marker( string $event ): void {
	$usage = getrusage();
	$row = [
		'case' => $GLOBALS['pinova_fpm_case'], 'event' => $event, 'pid' => getmypid(), 'ns' => hrtime( true ), 'php_version' => PHP_VERSION,
		'cpu_seconds' => $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1000000 + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1000000,
		'php_peak_bytes' => memory_get_peak_usage( true ),
	];
	$path = (string) getenv( 'PINOVA_FPM_WORK_DIR' ) . '/' . $GLOBALS['pinova_fpm_case'] . '.jsonl';
	if ( false === file_put_contents( $path, json_encode( $row, JSON_THROW_ON_ERROR ) . "\n", FILE_APPEND | LOCK_EX ) ) {
		throw new RuntimeException( 'Could not write isolated fixture marker.' );
	}
}

pinova_fpm_marker( 'request_enter' );
if ( 'ping' === $case ) {
	header( 'Content-Type: application/json' );
	echo '{"ready":true}';
	exit;
}

require __DIR__ . '/core-bootstrap.php';
require __DIR__ . '/production-adapter.php';
define( 'PINOVA_OTP_IMMEDIATE_DELIVERY', 'flag_off' !== $case );

if ( in_array( $case, [ 'headroom_exact', 'headroom_blocked' ], true ) ) {
	// Tighten only this request. Keep the actual REST core, adapter, fixture and
	// marker paths available while excluding procfs except its one optional file.
	$allowed_paths = [ dirname( __DIR__, 3 ), ABSPATH, (string) getenv( 'PINOVA_FPM_WORK_DIR' ) ];
	if ( 'headroom_exact' === $case ) {
		$allowed_paths[] = '/proc/meminfo';
	}
	if ( false === ini_set( 'open_basedir', implode( PATH_SEPARATOR, $allowed_paths ) ) ) {
		throw new RuntimeException( 'Could not apply the isolated request file policy.' );
	}
	$memory = @file_get_contents( '/proc/meminfo', false, null, 0, 8192 );
	if ( false === $memory ) {
		pinova_fpm_marker( 'headroom_memory_blocked' );
	} elseif ( 1 === preg_match( '/^MemAvailable:\s+\d+\s+kB$/m', $memory ) ) {
		pinova_fpm_marker( 'headroom_memory_readable' );
	}
	if ( false === @file_get_contents( '/proc/cpuinfo', false, null, 0, 8192 ) ) {
		pinova_fpm_marker( 'headroom_neighbor_denied' );
	}
}

// The response remains identical for synthetic eligible and decoy contexts.
$expected = [ 'success' => true, 'message' => 'Generic delivery response.', 'data' => [ 'login_method' => 'otp', 'ttl' => 180, 'jwt' => 'synthetic-signed-state' ] ];
$server = new WP_REST_Server();
$GLOBALS['wp_rest_server'] = $server;
do_action( 'rest_api_init', $server );
register_rest_route(
	'pinova-fpm/v1',
	'/issue',
	[
		'methods' => 'POST',
		'permission_callback' => '__return_true',
		'callback' => static function ( WP_REST_Request $request ) use ( $case, $expected ): WP_REST_Response {
			// Marker represents durable acknowledgement only; this fixture has no queue DB.
			pinova_fpm_marker( 'synthetic_fallback_retained' );
			if ( 'preexisting' === $case ) {
				echo 'preexisting-output';
			} elseif ( 'foreign' === $case ) {
				ob_start( static fn( string $bytes ): string => strtoupper( $bytes ) );
			}
			$response = new WP_REST_Response( $expected, 200 );
			\Pinova\Services\OTPImmediateDelivery::arm( str_repeat( '1', 32 ), $response, $request );
			if ( 'partial' === $case ) {
				echo 'partial-output';
			} elseif ( 'removed' === $case ) {
				ob_end_clean();
			}
			return $response;
		},
	]
);

if ( 'failed' === $case || 'replaced' === $case ) {
	add_filter( 'rest_post_dispatch', static fn() => new WP_REST_Response( $expected, 'failed' === $case ? 503 : 200 ), 20 );
}
if ( 'mutated' === $case ) {
	add_filter( 'rest_pre_echo_response', static fn() => [ 'changed' => true ], 20 );
}
if ( 'served' === $case ) {
	add_filter( 'rest_pre_serve_request', static function () use ( $expected ): bool { echo wp_json_encode( $expected ); return true; }, 20 );
}
if ( in_array( $case, [ 'content_type', 'content_length', 'content_encoding' ], true ) ) {
	add_filter( 'rest_pre_serve_request', static function ( $served ) use ( $case ) {
		$headers = [ 'content_type' => 'Content-Type: text/plain', 'content_length' => 'Content-Length: 1', 'content_encoding' => 'Content-Encoding: gzip' ];
		header( $headers[ $case ] );
		return $served;
	}, 20 );
}
if ( in_array( $case, [ 'late', 'late_same_priority', 'late_removed', 'fatal_shutdown' ], true ) ) {
	// Register while serving, after arm() has installed its native-flush wrapper.
	add_filter( 'rest_pre_echo_response', static function ( $data ) use ( $case ) {
		add_action( 'shutdown', static function () use ( $case ): void {
			if ( 'fatal_shutdown' === $case ) {
				pinova_fpm_marker( 'before_fatal' );
				trigger_error( 'Synthetic fixture fatal.', E_USER_ERROR );
			} elseif ( 'late_removed' === $case ) {
				ob_end_clean();
			} else {
				echo 'late-output';
			}
		}, 'late_same_priority' === $case ? 1 : 100 );
		return $data;
	}, 20 );
}
if ( 'fatal' === $case ) {
	add_filter( 'rest_pre_echo_response', static function (): void { pinova_fpm_marker( 'before_fatal' ); trigger_error( 'Synthetic fixture fatal.', E_USER_ERROR ); }, 20 );
}

$server->serve_request( '/pinova-fpm/v1/issue' );
pinova_fpm_marker( 'rest_serve_returned' );
// Registered after the production finalizer, so its absence of fast work is final.
register_shutdown_function( static function (): void { pinova_fpm_marker( 'probe_completed' ); } );

