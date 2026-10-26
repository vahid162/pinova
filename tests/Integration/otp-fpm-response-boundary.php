<?php
/** Independent HTTP observer for the real WordPress/PHP-FPM characterization. */

declare(strict_types=1);

if ( 'true' !== getenv( 'GITHUB_ACTIONS' ) || 'github-hosted' !== getenv( 'RUNNER_ENVIRONMENT' ) || '1' !== getenv( 'PINOVA_FPM_FIXTURE' ) ) {
	fwrite( STDERR, "FPM observer requires its disposable GitHub-hosted fixture.\n" );
	exit( 1 );
}
$work = (string) getenv( 'PINOVA_FPM_WORK_DIR' );
$expected_work = '/tmp/pinova-fpm-' . getenv( 'GITHUB_RUN_ID' ) . '-' . getenv( 'GITHUB_RUN_ATTEMPT' ) . '/receipt';
$base = (string) getenv( 'PINOVA_FPM_BASE_URL' );
if ( $work !== $expected_work || ! preg_match( '~^http://127\.0\.0\.1:[0-9]+$~', $base ) ) {
	throw new RuntimeException( 'Unexpected FPM observer target.' );
}

function boundary_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function boundary_markers( string $case ): array {
	$path = getenv( 'PINOVA_FPM_WORK_DIR' ) . '/' . $case . '.jsonl';
	$rows = [];
	if ( is_file( $path ) ) {
		$handle = fopen( $path, 'r' );
		boundary_check( false !== $handle && flock( $handle, LOCK_SH ), 'Cannot read independent marker file.' );
		$rows = explode( "\n", trim( (string) stream_get_contents( $handle ) ) );
		flock( $handle, LOCK_UN );
		fclose( $handle );
	}
	$result = [];
	foreach ( $rows as $line ) {
		if ( '' === $line ) {
			continue;
		}
		$row = json_decode( $line, true, 512, JSON_THROW_ON_ERROR );
		$result[ $row['event'] ] = $row;
	}
	return $result;
}

function boundary_wait( string $case, string $event ): array {
	$deadline = microtime( true ) + 8;
	do {
		$rows = boundary_markers( $case );
		if ( isset( $rows[ $event ] ) ) {
			return $rows;
		}
		usleep( 20000 );
	} while ( microtime( true ) < $deadline );
	throw new RuntimeException( 'Missing marker: ' . $case . '/' . $event );
}

function boundary_request( string $pool, string $case ): array {
	$started = hrtime( true );
	$context = stream_context_create( [ 'http' => [ 'method' => 'POST', 'header' => "Connection: close\r\nContent-Length: 0\r\n", 'ignore_errors' => true, 'timeout' => 12 ] ] );
	$body = @file_get_contents( getenv( 'PINOVA_FPM_BASE_URL' ) . '/' . $pool . '?case=' . $case, false, $context );
	$finished = hrtime( true );
	boundary_check( false !== $body, 'HTTP observer could not read a complete response: ' . $case );
	preg_match( '/^HTTP\/\S+ ([0-9]{3})/', $http_response_header[0] ?? '', $status );
	return [ 'status' => (int) ( $status[1] ?? 0 ), 'body' => $body, 'seconds' => ( $finished - $started ) / 1000000000, 'completed_ns' => $finished ];
}

if ( in_array( '--verify-cli', $argv, true ) ) {
	$rows = boundary_wait( 'unsupported_cli', 'probe_completed' );
	boundary_check( isset( $rows['synthetic_fallback_retained'] ) && ! isset( $rows['account_enter'] ) && ! isset( $rows['provider_enter'] ), 'CLI reached fast delivery or lost fallback.' );
	echo "Unsupported CLI completion rejected; synthetic fallback retained.\n";
	exit;
}

$ready = false;
for ( $attempt = 0; $attempt < 100; ++$attempt ) {
	try {
		$ping = boundary_request( 'normal', 'ping' );
		$ready = 200 === $ping['status'] && true === ( json_decode( $ping['body'], true )['ready'] ?? null );
	} catch ( Throwable $error ) {
		$ready = false;
	}
	if ( $ready ) {
		break;
	}
	usleep( 50000 );
}
boundary_check( $ready, 'Isolated FPM/nginx did not become ready.' );

$healthy = boundary_request( 'normal', 'healthy' );
$markers = boundary_wait( 'healthy', 'provider_enter' );
boundary_check( 200 === $healthy['status'] && true === ( json_decode( $healthy['body'], true )['success'] ?? null ), 'Healthy complete generic JSON missing.' );
boundary_check( $healthy['seconds'] < 1.5, 'HTTP response waited for the deliberately slow provider.' );
foreach ( [ 'core_flush_completed', 'finish_enter', 'finish_ok', 'account_enter', 'provider_enter' ] as $event ) {
	boundary_check( isset( $markers[ $event ] ), 'Required server boundary marker missing: ' . $event );
}
boundary_check( $markers['finish_ok']['ns'] <= $markers['account_enter']['ns'] && $markers['account_enter']['ns'] <= $markers['provider_enter']['ns'], 'Account/provider work preceded successful native completion.' );
boundary_check( $markers['finish_ok']['pid'] === $markers['provider_enter']['pid'], 'Delivery did not use the original FPM process.' );
boundary_check( ( $markers['provider_enter']['ns'] - $markers['finish_ok']['ns'] ) / 1000000000 < 1, 'Provider did not start within the uncontended post-finish target.' );

// A second HTTP request must wait for the same one-child pool's occupied worker.
$occupied = boundary_request( 'normal', 'ping' );
$markers = boundary_wait( 'healthy', 'provider_completed' );
boundary_check( $occupied['seconds'] > 2 && $healthy['completed_ns'] < $markers['provider_completed']['ns'], 'Finished response did not expose retained FPM worker occupancy.' );

$decoy = boundary_request( 'normal', 'decoy' );
$decoy_markers = boundary_wait( 'decoy', 'decoy_completed' );
boundary_check( $healthy['body'] === $decoy['body'] && ! isset( $decoy_markers['provider_enter'] ), 'Synthetic eligible/decoy public responses differ or decoy sent.' );

$exact_headroom = boundary_request( 'normal', 'headroom_exact' );
$exact_headroom_rows = boundary_wait( 'headroom_exact', 'provider_completed' );
boundary_check( 200 === $exact_headroom['status'] && $healthy['body'] === $exact_headroom['body'] && $exact_headroom['seconds'] < 1.5,
	'Exact-file restricted request did not preserve the healthy independent response.' );
boundary_check( isset( $exact_headroom_rows['headroom_memory_readable'], $exact_headroom_rows['headroom_neighbor_denied'],
	$exact_headroom_rows['finish_ok'], $exact_headroom_rows['account_enter'], $exact_headroom_rows['provider_enter'] )
	&& $exact_headroom_rows['finish_ok']['pid'] === $exact_headroom_rows['provider_enter']['pid']
	&& $exact_headroom_rows['finish_ok']['ns'] <= $exact_headroom_rows['account_enter']['ns']
	&& $exact_headroom_rows['account_enter']['ns'] <= $exact_headroom_rows['provider_enter']['ns'],
	'Exact-file memory access did not retain neighboring-file denial and post-finish synthetic dispatch.' );

$blocked_headroom = boundary_request( 'normal', 'headroom_blocked' );
$blocked_headroom_rows = boundary_wait( 'headroom_blocked', 'probe_completed' );
boundary_check( 200 === $blocked_headroom['status'] && $healthy['body'] === $blocked_headroom['body']
	&& isset( $blocked_headroom_rows['headroom_memory_blocked'], $blocked_headroom_rows['headroom_neighbor_denied'],
		$blocked_headroom_rows['finish_ok'], $blocked_headroom_rows['synthetic_fallback_retained'] )
	&& ! isset( $blocked_headroom_rows['account_enter'] ) && ! isset( $blocked_headroom_rows['provider_enter'] ),
	'Unreadable memory reached fast delivery or failed to retain the synthetic fallback marker after native completion.' );

$reset_headroom = boundary_request( 'normal', 'headroom_reset' );
$reset_headroom_rows = boundary_wait( 'headroom_reset', 'provider_completed' );
boundary_check( 200 === $reset_headroom['status'] && $healthy['body'] === $reset_headroom['body']
	&& isset( $reset_headroom_rows['finish_ok'], $reset_headroom_rows['provider_enter'] )
	&& $reset_headroom_rows['finish_ok']['pid'] === $blocked_headroom_rows['finish_ok']['pid']
	&& $reset_headroom_rows['finish_ok']['ns'] <= $reset_headroom_rows['provider_enter']['ns'],
	'Per-request memory restriction did not reset for the same native FPM child.' );

$rejections = [ 'failed', 'replaced', 'mutated', 'served', 'partial', 'removed', 'late', 'late_same_priority', 'late_removed', 'fatal', 'fatal_shutdown', 'content_type', 'content_length', 'content_encoding' ];
foreach ( $rejections as $case ) {
	boundary_request( 'normal', $case );
	if ( in_array( $case, [ 'fatal', 'fatal_shutdown' ], true ) ) {
		// PHP may stop remaining shutdown functions on a fatal in another finalizer.
		// A follow-up through the one-child pool establishes that the request exited.
		boundary_request( 'normal', 'ping' );
		$rows = boundary_wait( $case, 'before_fatal' );
	} else {
		$rows = boundary_wait( $case, 'probe_completed' );
	}
	boundary_check( isset( $rows['synthetic_fallback_retained'] ) && ! isset( $rows['account_enter'] ) && ! isset( $rows['provider_enter'] ), 'Rejected response performed fast delivery: ' . $case );
}
foreach ( [ 'preexisting', 'foreign', 'flag_off' ] as $case ) {
	boundary_request( 'normal', $case );
	$rows = boundary_wait( $case, 'probe_completed' );
	boundary_check( isset( $rows['synthetic_fallback_retained'] ) && ! isset( $rows['account_enter'] ) && ! isset( $rows['provider_enter'] ), 'Unsafe preexisting buffer reached fast delivery.' );
}
$false_finish = boundary_request( 'normal', 'finish_false' );
$rows = boundary_wait( 'finish_false', 'finish_false' );
boundary_check( isset( $rows['prior_finish_ok'] ) && ! isset( $rows['provider_enter'] ), 'Genuinely false second native completion did not reject delivery.' );
boundary_request( 'unsupported', 'unsupported' );
$rows = boundary_wait( 'unsupported', 'probe_completed' );
boundary_check( isset( $rows['synthetic_fallback_retained'] ) && ! isset( $rows['provider_enter'] ), 'Disabled native finish did not retain queued fallback.' );

// Native FPM timeout behavior is observed, not assumed from max_execution_time.
$untracked = boundary_request( 'untracked', 'untracked' );
$untracked_ping = boundary_request( 'untracked', 'ping' );
$untracked_rows = boundary_wait( 'untracked', 'provider_completed' );
boundary_check( $untracked['seconds'] < 1.5 && $untracked_ping['seconds'] > 3.5, 'track_finished=no did not retain the worker beyond its one-second request timeout.' );
$tracked = boundary_request( 'tracked', 'tracked' );
boundary_wait( 'tracked', 'provider_enter' );
$tracked_ping = boundary_request( 'tracked', 'ping' );
$tracked_rows = boundary_markers( 'tracked' );
boundary_check( $tracked['seconds'] < 1.5 && $tracked_ping['seconds'] < 4 && ! isset( $tracked_rows['provider_completed'] ), 'track_finished=yes did not terminate the deliberately unbounded tail.' );
$fpm_log = (string) file_get_contents( $work . '/fpm.log' );
boundary_check( str_contains( $fpm_log, 'execution timed out' ), 'Native FPM termination evidence missing.' );

require getenv( 'PINOVA_FPM_WORDPRESS_DIR' ) . '/wp-includes/version.php';
$summary = [
	'kind' => 'Production response adapter; synthetic account/provider/slot, no plugin database',
	'adapter_sha256' => hash_file( 'sha256', getenv( 'PINOVA_FPM_BOUNDARY_ADAPTER' ) ),
	'wordpress_version' => $wp_version,
	'fpm_php_version' => $markers['provider_completed']['php_version'],
	'fpm_output_buffering_bytes' => 4096,
	'core_rest_server_sha256' => hash_file( 'sha256', getenv( 'PINOVA_FPM_WORDPRESS_DIR' ) . '/wp-includes/rest-api/class-wp-rest-server.php' ),
	'healthy_http_seconds' => $healthy['seconds'],
	'healthy_occupied_followup_seconds' => $occupied['seconds'],
	'healthy_tail_seconds' => ( $markers['provider_completed']['ns'] - $markers['finish_ok']['ns'] ) / 1000000000,
	'healthy_tail_cpu_seconds' => $markers['provider_completed']['cpu_seconds'] - $markers['finish_ok']['cpu_seconds'],
	'fixture_php_peak_bytes' => $markers['provider_completed']['php_peak_bytes'],
	'untracked_occupied_followup_seconds' => $untracked_ping['seconds'],
	'tracked_occupied_followup_seconds' => $tracked_ping['seconds'],
	'rejected_scenarios' => $rejections,
	'open_basedir_compatibility' => [
		'exact_memory_file_readable' => true,
		'neighboring_proc_file_denied' => true,
		'exact_file_request_completed_before_synthetic_dispatch' => true,
		'unreadable_memory_request_finished_without_fast_dispatch' => true,
		'unreadable_memory_synthetic_fallback_marker_retained' => true,
		'request_local_policy_reset_on_same_fpm_child' => true,
	],
	'limitations' => [ 'Not production FPM CPU/RSS or real provider budget evidence.', 'Not full Pinova queue/claim/privacy integration.', 'No guarantee against arbitrary later PHP shutdown handlers.', 'Remote browser packet arrival may follow provider entry.' ],
];
file_put_contents( $work . '/artifacts/summary.json', json_encode( $summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) . "\n" );
echo json_encode( $summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) . "\n";

