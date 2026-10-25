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
$cases = [ 'ping', 'healthy', 'decoy', 'failed', 'replaced', 'mutated', 'served', 'partial', 'removed', 'preexisting', 'foreign', 'late', 'late_same_priority', 'late_removed', 'fatal', 'fatal_shutdown', 'finish_false', 'unsupported', 'unsupported_cli', 'untracked', 'tracked' ];
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
require __DIR__ . '/CharacterizationBoundary.php';

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
			$boundary = new \Pinova\Tests\FPM\CharacterizationBoundary(
				$response,
				$request,
				static function () use ( $case ): void {
					pinova_fpm_marker( 'account_enter' );
					if ( 'decoy' === $case ) {
						pinova_fpm_marker( 'decoy_completed' );
						return;
					}
					pinova_fpm_marker( 'provider_enter' );
					// Deliberately blocking, nonbillable work: no external API or recipient.
					usleep( in_array( $case, [ 'tracked', 'untracked' ], true ) ? 4500000 : 3000000 );
					pinova_fpm_marker( 'provider_completed' );
				}
			);
			$boundary->arm();
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

