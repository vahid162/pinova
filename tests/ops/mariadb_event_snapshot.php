<?php

declare(strict_types=1);

/** Isolated native characterization; never invoke against an installed site. */
if ( 'cli' !== PHP_SAPI || 'true' !== getenv( 'GITHUB_ACTIONS' ) || 'github-hosted' !== getenv( 'RUNNER_ENVIRONMENT' )
	|| 1 !== preg_match( '/\A[0-9]+\z/D', getenv( 'GITHUB_RUN_ID' ) ?: '' ) || 1 !== preg_match( '/\A[0-9]+\z/D', getenv( 'GITHUB_RUN_ATTEMPT' ) ?: '' )
	|| 1 !== preg_match( '/\A[0-9a-f]{40}\z/D', getenv( 'GITHUB_SHA' ) ?: '' )
	|| '127.0.0.1' !== getenv( 'PINOVA_NATIVE_DB_HOST' ) || '3307' !== getenv( 'PINOVA_NATIVE_DB_PORT' ) ) {
	fwrite( STDERR, "Native witness requires its disposable hosted fixture.\n" );
	exit( 2 );
}

require dirname( __DIR__, 2 ) . '/tools/monitoring/event_snapshot.php';

use Pinova\Monitoring\EventSnapshot;

$database_name = 'pinova_witness_' . getenv( 'GITHUB_RUN_ID' ) . '_' . getenv( 'GITHUB_RUN_ATTEMPT' );
if ( getenv( 'PINOVA_NATIVE_DB_NAME' ) !== $database_name || 64 < strlen( $database_name ) || 'fixture-only' !== getenv( 'PINOVA_NATIVE_DB_PASSWORD' ) ) {
	exit( 2 );
}
$table = 'pinova_witness_events';
$case = 'fixture_connection';
$assertion = null;
$completed = [];
$connections = [];
$snapshots = [];
$owns_table = false;
$receipt = [ 'schema' => 'pinova.native-event-witness-proof.v1', 'source_commit' => getenv( 'GITHUB_SHA' ), 'state' => 'failed', 'cleanup_status' => 'pending' ];
$connect = static function () use ( $database_name, &$connections ): mysqli {
	$connection = new mysqli( '127.0.0.1', 'root', 'fixture-only', $database_name, 3307 );
	$connection->set_charset( 'utf8mb4' );
	$connections[] = $connection;
	return $connection;
};
$check = static function ( bool $condition, string $code ) use ( &$assertion ): void {
	if ( ! $condition ) {
		$assertion = $code;
		throw new RuntimeException( 'native_fixture_assertion_failed' );
	}
};
try {
	$writer = $connect();
	$pending = $connect();
	$reader = $connect();
	$metadata = $connect();
	$version = $writer->query( 'SELECT VERSION()' )->fetch_row()[0];
	$check( 1 === preg_match( '/\A10\.11\.10-MariaDB(?:[-+][A-Za-z0-9._+~-]+)*\z/D', $version ), 'native_version_matches_verified_source' );
	$receipt['server_version'] = $version;
	$check( $database_name === $writer->query( 'SELECT DATABASE()' )->fetch_row()[0], 'run_owned_database_required' );
	$writer->query( 'CREATE TABLE `' . $table . '` (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, level varchar(12) NOT NULL, event varchar(100) NOT NULL, user_id bigint unsigned NULL, flow_id char(32) NULL, context longtext NULL) ENGINE=InnoDB' );
	$owns_table = true;
	$reader->query( 'SET SESSION TRANSACTION READ ONLY' );
	$metadata->query( 'SET SESSION TRANSACTION READ ONLY' );
	$adapter = new EventSnapshot( $reader, $table, $metadata );
	$sequence = 1;
	$baseline = $adapter->baseline( $sequence++ );
	$check( 'observed' === $baseline['state'] && [] === $baseline['rows'] && 1 === $baseline['next_id_after'], 'empty_baseline_is_native_allocation_fence' );
	$check( $baseline['connection_before'] !== $baseline['metadata_connection_before'], 'independent_metadata_connection_witnessed' );
	$identity = $baseline['identity_before'];
	$snapshots['baseline'] = $baseline;
	$completed[] = 'empty_baseline_and_independent_native_identity';

	$case = 'finite_projection_and_privacy_update';
	$writer->query( "INSERT INTO `{$table}` (level,event,user_id,flow_id,context) VALUES ('warning','private-event-secret',42,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','private-context-secret')" );
	$first = $adapter->read( $sequence++, 0 );
	$check( 'observed' === $first['state'] && [ [ 'id' => 1, 'level' => 'warning' ] ] === $first['rows'], 'all_severity_rows_are_finite' );
	$check( $identity === $first['identity_before'], 'auto_increment_option_does_not_change_definition_identity' );
	$check( false === strpos( json_encode( $first ), 'private-' ), 'personal_or_arbitrary_columns_never_leave_native_adapter' );
	$writer->query( "UPDATE `{$table}` SET user_id=NULL,flow_id=NULL,context='{}' WHERE id=1" );
	$after_privacy = $adapter->read( $sequence++, 0 );
	$check( $first['rows'] === $after_privacy['rows'] && $identity === $after_privacy['identity_after'], 'privacy_updates_preserve_allocated_severity_facts' );
	$snapshots['first_committed'] = $after_privacy;
	$completed[] = $case;

	$case = 'out_of_order_commit_and_fresh_snapshots';
	$pending->begin_transaction();
	$pending->query( "INSERT INTO `{$table}` (level,event) VALUES ('error','synthetic')" );
	$writer->query( "INSERT INTO `{$table}` (level,event) VALUES ('notice','synthetic')" );
	$before_commit = $adapter->read( $sequence++, 1 );
	$check( 'observed' === $before_commit['state'] && [ [ 'id' => 3, 'level' => 'notice' ] ] === $before_commit['rows'] && 4 === $before_commit['next_id_before'], 'higher_committed_id_does_not_hide_pending_lower_allocation' );
	$pending->commit();
	$after_commit = $adapter->read( $sequence++, 1, 3 );
	$check( [ [ 'id' => 2, 'level' => 'error' ], [ 'id' => 3, 'level' => 'notice' ] ] === $after_commit['rows'], 'fresh_autocommit_observation_resolves_lower_id' );
	$snapshots['before_lower_commit'] = $before_commit;
	$snapshots['after_lower_commit'] = $after_commit;
	$completed[] = $case;

	$case = 'rollback_gap_and_deleted_final_suffix';
	$pending->begin_transaction();
	$pending->query( "INSERT INTO `{$table}` (level,event) VALUES ('critical','synthetic')" );
	$pending->rollback();
	$rolled_back = $adapter->read( $sequence++, 3 );
	$check( [] === $rolled_back['rows'] && 5 === $rolled_back['next_id_before'] && 4 === $rolled_back['query_through_id'], 'native_allocation_counter_retains_rollback_hole' );
	$writer->query( "INSERT INTO `{$table}` (level,event) VALUES ('alert','synthetic')" );
	$writer->query( "DELETE FROM `{$table}` WHERE id=5" );
	$deleted = $adapter->read( $sequence++, 3 );
	$check( [] === $deleted['rows'] && 6 === $deleted['next_id_before'] && 5 === $deleted['query_through_id'], 'delete_of_allocated_suffix_is_not_hidden_by_max_id' );
	$check( '3' === (string) $writer->query( 'SELECT MAX(id) FROM `' . $table . '`' )->fetch_row()[0], 'suffix_control_has_lower_visible_maximum' );
	$snapshots['rollback_gap'] = $rolled_back;
	$snapshots['deleted_suffix'] = $deleted;
	$completed[] = $case;

	$case = 'fixed_end_fence_excludes_later_allocations';
	$writer->query( "INSERT INTO `{$table}` (level,event) VALUES ('info','synthetic')" );
	$fenced = $adapter->read( $sequence++, 0, 3 );
	$check( 3 === $fenced['query_through_id'] && [ 1, 2, 3 ] === array_column( $fenced['rows'], 'id' ) && 7 === $fenced['next_id_before'], 'later_insert_does_not_extend_frozen_cohort' );
	$completed[] = $case;

	$case = 'stale_snapshot_dirty_reads_and_session_state_rejected';
	$reader->query( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
	$reader->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' );
	$stale = $adapter->read( $sequence++, 0 );
	$check( 'needs_review' === $stale['state'] && 'native_session_state' === $stale['failure_code'], 'active_repeatable_read_snapshot_is_rejected' );
	$reader->rollback();
	$reader->query( 'SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED' );
	$dirty = $adapter->read( $sequence++, 0 );
	$check( 'needs_review' === $dirty['state'] && 'native_session_state' === $dirty['failure_code'], 'dirty_read_isolation_is_rejected' );
	$reader->query( 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED' );
	$reader->autocommit( false );
	$not_autocommit = $adapter->read( $sequence++, 0 );
	$check( 'needs_review' === $not_autocommit['state'] && 'native_session_state' === $not_autocommit['failure_code'], 'manual_transaction_mode_is_rejected' );
	$reader->rollback();
	$reader->autocommit( true );
	$reader->query( 'SET SESSION auto_increment_increment=2' );
	$not_dense = $adapter->read( $sequence++, 0 );
	$check( 'needs_review' === $not_dense['state'] && 'native_session_state' === $not_dense['failure_code'], 'non_dense_allocation_increment_is_rejected' );
	$reader->query( 'SET SESSION auto_increment_increment=1' );
	$completed[] = $case;

	$case = 'row_bound_and_invalid_native_level';
	$bounded = ( new EventSnapshot( $reader, $table, $metadata, 2 ) )->read( $sequence++, 0, 3 );
	$check( 'needs_review' === $bounded['state'] && 'native_read_bound' === $bounded['failure_code'] && [] === $bounded['rows'], 'bounded_query_cannot_return_a_quiet_partial_snapshot' );
	$writer->query( "INSERT INTO `{$table}` (level,event) VALUES ('secret-level','synthetic')" );
	$invalid = $adapter->read( $sequence++, 6 );
	$check( 'needs_review' === $invalid['state'] && 'invalid_native_rows' === $invalid['failure_code'] && false === strpos( json_encode( $invalid ), 'secret-level' ), 'invalid_level_is_never_returned_or_silently_skipped' );
	$completed[] = $case;

	$case = 'native_table_recreation_and_counter_reset';
	$writer->query( 'DROP TABLE `' . $table . '`' );
	$owns_table = false;
	$writer->query( 'CREATE TABLE `' . $table . '` (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, level varchar(12) NOT NULL, event varchar(100) NOT NULL, user_id bigint unsigned NULL, flow_id char(32) NULL, context longtext NULL) ENGINE=InnoDB' );
	$owns_table = true;
	$recreated = $adapter->baseline( $sequence++ );
	$check( 'observed' === $recreated['state'] && $identity['table'] !== $recreated['identity_before']['table'] && $identity['definition'] === $recreated['identity_before']['definition'], 'native_table_id_distinguishes_same_schema_recreation' );
	$reset = $adapter->read( $sequence++, 6 );
	$check( 'needs_review' === $reset['state'] && 'native_counter_regressed' === $reset['failure_code'], 'counter_reset_cannot_pass_a_previous_contiguous_cursor' );
	$completed[] = $case;

	$case = 'closed_connection_and_invalid_arguments';
	$check( 'invalid_arguments' === $adapter->read( 0, 0 )['failure_code'], 'invalid_sequence_fails_before_native_read' );
	$reader->close();
	$closed = $adapter->read( $sequence++, 0 );
	$check( 'needs_review' === $closed['state'] && 'native_read_failed' === $closed['failure_code'], 'lost_connection_never_becomes_empty_healthy_rows' );
	$check( false === strpos( json_encode( $closed ), $database_name ), 'native_failure_does_not_expose_qualified_table' );
	$completed[] = $case;
	$receipt['state'] = 'passed';
} catch ( Throwable $error ) {
	unset( $error );
	$receipt['failure_case'] = $case;
	$receipt['failure_assertion'] = $assertion ?? 'native_fixture_or_query_unavailable';
} finally {
	try {
		if ( isset( $pending ) ) {
			$pending->rollback();
		}
		if ( isset( $writer ) && $owns_table ) {
			$writer->query( 'DROP TABLE IF EXISTS `' . $table . '`' );
		}
		$receipt['cleanup_status'] = 'complete';
	} catch ( Throwable $error ) {
		unset( $error );
		$receipt['cleanup_status'] = 'needs_review';
		$receipt['state'] = 'failed';
	}
	foreach ( $connections as $connection ) {
		try {
			$connection->close();
		} catch ( Throwable $error ) {
			unset( $error );
		}
	}
}
$receipt['completed_cases'] = $completed;
$receipt['case_count'] = count( $completed );
$receipt['allocation_source'] = 'mariadb_live_show_table_status_v1';
$receipt['table_identity_basis'] = 'native_innodb_table_id_and_space';
$receipt['resource_acceptance'] = false;
$receipt['production_coverage_acceptance'] = false;
$receipt['scope'] = 'isolated_native_counter_characterization';
$receipt['observations'] = $snapshots;
$receipt['adapter_sha256'] = hash_file( 'sha256', dirname( __DIR__, 2 ) . '/tools/monitoring/event_snapshot.php' );
$encoded = json_encode( $receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
$directory = getenv( 'RUNNER_TEMP' );
$path = is_string( $directory ) ? $directory . '/pinova-native-event-witness-' . getenv( 'GITHUB_RUN_ID' ) . '-' . getenv( 'GITHUB_RUN_ATTEMPT' ) . '.json' : '';
if ( ! is_string( $directory ) || ! is_dir( $directory ) || realpath( $directory ) !== $directory ) {
	fwrite( STDERR, "Native witness receipt unavailable.\n" );
	exit( 1 );
}
$mask = umask( 0077 );
$file = fopen( $path, 'x' );
umask( $mask );
if ( false === $file || strlen( $encoded ) !== fwrite( $file, $encoded ) ) {
	fwrite( STDERR, "Native witness receipt unavailable.\n" );
	exit( 1 );
}
fclose( $file );
echo 'Native event witness ' . $receipt['state'] . ': ' . count( $completed ) . " native cases retained.\n";
if ( 'passed' !== $receipt['state'] ) {
	fwrite( STDERR, 'Native witness failure: ' . ( $receipt['failure_assertion'] ?? 'cleanup_incomplete' ) . "\n" );
	exit( 1 );
}
