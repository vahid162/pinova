<?php
/** Exercise the registered report command in fresh WP-CLI processes on disposable CI. */

declare(strict_types=1);

use Pinova\Logging\IssueMonitor;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'PINOVA_THIRD_PARTY_BASELINE' ) || true !== PINOVA_THIRD_PARTY_BASELINE
	|| ! defined( 'DISABLE_WP_CRON' ) || true !== DISABLE_WP_CRON
	|| 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), [ 'localhost', '127.0.0.1' ], true ) ) {
	throw new RuntimeException( 'Logs CLI smoke requires the marked disposable loopback site.' );
}

$check = static function ( bool $condition, string $label ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'Logs CLI regression failed: ' . $label );
	}
};
$run = static function ( string $arguments = '' ) {
	// launch=true exercises normal bootstrap/registration and captures WP_CLI::error exits.
	return WP_CLI::runcommand( 'pinova logs report' . $arguments, [ 'launch' => true, 'return' => 'all', 'exit_error' => false ] );
};
$read = static function ( string $arguments = '' ) use ( $check, $run ): array {
	$result = $run( $arguments );
	$check( 0 === $result->return_code, 'registered report command succeeds' );
	$report = json_decode( trim( $result->stdout ), true, 512, JSON_THROW_ON_ERROR );
	$check( is_array( $report ) && 'pinova.issues.v1' === $report['schema'], 'stdout contains only the versioned JSON report' );
	$check( true === $report['coverage']['valid_range'] && true === $report['coverage']['complete'] && false === $report['coverage']['truncated'], 'report has complete bounded coverage' );
	$check( true === $report['coverage']['observed_counts_only'] && 'not_verified' === $report['coverage']['physical_delivery'], 'report preserves observation and delivery limits' );
	return $report;
};

global $wpdb;
$table = $wpdb->prefix . 'pinova_logs';
$ids = [];
$queue_table = $wpdb->prefix . 'pinova_rate_limits';
$queue_keys = [];
$secret = 'pinova-cli-private-' . bin2hex( random_bytes( 8 ) );
$fingerprint = bin2hex( random_bytes( 16 ) );
$today = gmdate( 'Y-m-d' );
$from = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
$code = 'otp.channel_send_failed:provider_returned_false';
$review_before = IssueMonitor::review_state( $code );
$health_before = [ get_option( 'pinova_integration_health_wpforo', false ), get_option( 'pinova_integration_health_dokan', false ) ];

try {
	foreach ( [ 'ready:', 'reserved:' ] as $prefix ) {
		$key = hash( 'sha256', 'pinova-cli-queue-' . $prefix . $secret );
		$check( 1 === $wpdb->insert( $queue_table, [
			'bucket_key' => $key, 'scope' => bin2hex( random_bytes( 16 ) ), 'hits' => 1,
			'reset_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
			'updated_at' => gmdate( 'Y-m-d H:i:s' ), 'payload' => $prefix . base64_encode( $secret ),
		] ), 'insert only owned queue snapshot fixtures' );
		$queue_keys[] = $key;
	}
	$queue_stored = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE bucket_key IN (%s, %s) ORDER BY bucket_key', $queue_table, ...$queue_keys ), ARRAY_A );
	$check( 1 === $wpdb->insert( $table, [
		'created_at' => gmdate( 'Y-m-d H:i:s' ),
		'level' => 'warning',
		'event' => 'otp.channel_send_failed',
		'correlation_id' => $secret,
		'flow_id' => bin2hex( random_bytes( 16 ) ),
		'context' => wp_json_encode( [
			'reason' => 'provider_returned_false', 'channel' => 'sms', 'duration_ms' => 37,
			'password' => $secret, 'token' => $secret, 'identifier' => $secret,
			'identifier_fingerprint' => $fingerprint, 'message' => $secret, 'path' => $secret,
		] ),
	] ), 'insert owned historical privacy fixture' );
	$ids[] = (int) $wpdb->insert_id;
	$stored = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $ids[0] ), ARRAY_A );
	$event_count_before = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );

	$default = $read();
	$check( [ $today, $today ] === $default['selected_range'], 'omitted dates select the current UTC day' );
	$explicit = $read( ' --from=' . $from . ' --to=' . $today );
	$check( [ $from, $today ] === $explicit['selected_range'], 'explicit dates select the requested range' );
	$expected = IssueMonitor::report( [ 'created_from' => $from, 'created_to' => $today ] );
	$check( $expected['issues'] === $explicit['issues'] && $expected['coverage'] === $explicit['coverage'], 'registered CLI uses the same report as administration' );
	$check( $expected['integrations']['wpforo'] === $explicit['integrations']['wpforo'] && $expected['integrations']['dokan'] === $explicit['integrations']['dokan'], 'registered CLI includes the same current integration health' );
	$check( $expected['queue_health'] === $explicit['queue_health'] && true === $explicit['queue_health']['read_success'], 'registered CLI includes the same current read-only queue snapshot' );
	$check( 0 < $explicit['queue_health']['expired'] && 0 < $explicit['queue_health']['reserved'], 'queue snapshot distinguishes expired accepted work from unaccepted reservations' );
	foreach ( [ $default, $explicit ] as $report ) {
		$json = wp_json_encode( $report );
		$check( false === strpos( $json, $secret ) && false === strpos( $json, $fingerprint ), 'legacy secrets and fingerprints are redacted' );
		$check( false === strpos( $json, base64_encode( $secret ) ), 'queue ciphertext is never exported' );
		foreach ( $queue_keys as $key ) {
			$check( false === strpos( $json, $key ), 'queue bucket identifiers are never exported' );
		}
		$found = false;
		foreach ( $report['issues'] as $issue ) {
			if ( $code !== $issue['code'] ) {
				continue;
			}
			$check( 'confirmed_failure' === $issue['category'] && IssueMonitor::explanation( $code ) === $issue['investigation_hint'], 'failure classification and shared investigation hint are retained' );
			foreach ( $issue['evidence'] as $event ) {
				if ( $ids[0] === $event['id'] ) {
					$found = true;
					$check( '' === $event['correlation_id'] && 'provider_returned_false' === $event['context']['reason'] && 37 === $event['context']['duration_ms'], 'safe evidence remains useful' );
				}
			}
		}
		$check( $found, 'owned fixture appears in report evidence' );
	}

	foreach ( [
		' unexpected',
		' --format=json',
		' --from=' . $today,
		' --to=' . $today,
		' --from=2025-02-30 --to=2025-02-30',
		' --from=' . gmdate( 'Y-m-d', time() - 7 * DAY_IN_SECONDS ) . ' --to=' . $today,
		' --from=' . $today . ' --to=' . $from,
		' --from=' . gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) . ' --to=' . gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ),
	] as $arguments ) {
		$result = $run( $arguments );
		$check( 0 !== $result->return_code && '' !== trim( $result->stderr ), 'invalid arguments fail with a CLI error' );
		$check( null === json_decode( trim( $result->stdout ), true ), 'invalid arguments never emit a successful JSON report' );
	}
	$check( $stored === $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $ids[0] ), ARRAY_A ), 'reports preserve original event bytes' );
	$check( $review_before === IssueMonitor::review_state( $code ), 'read-only CLI preserves review state' );
	$check( $health_before === [ get_option( 'pinova_integration_health_wpforo', false ), get_option( 'pinova_integration_health_dokan', false ) ], 'read-only CLI preserves transition observations' );
	$check( $event_count_before === $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ), 'read-only CLI emits no transition events' );
	$check( $queue_stored === $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE bucket_key IN (%s, %s) ORDER BY bucket_key', $queue_table, ...$queue_keys ), ARRAY_A ), 'read-only CLI preserves queue records and payload bytes' );
} finally {
	foreach ( $ids as $id ) {
		$check( 1 === $wpdb->delete( $table, [ 'id' => $id ] ), 'remove only owned fixture events' );
	}
	foreach ( $queue_keys as $key ) {
		$check( 1 === $wpdb->delete( $queue_table, [ 'bucket_key' => $key ] ), 'remove only owned queue fixtures' );
	}
}

WP_CLI::line( 'PINOVA_LOGS_COMMAND_COMPLETE' );
