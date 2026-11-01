<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Install;
use Pinova\Logging\EventThrottle;
use Pinova\Services\RateLimitService;
use WP_UnitTestCase;

/** Shared budgets use independent autocommit connections, not test transactions. */
final class DiagnosticBudgetIntegrationTest extends WP_UnitTestCase {
	private \wpdb $original_database;
	private \wpdb $database;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->original_database = $wpdb;
		$this->database          = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->database->set_prefix( $wpdb->prefix );
		$this->database->suppress_errors();
		$wpdb = $this->database;
		$this->clear_budget();
	}

	public function tear_down(): void {
		global $wpdb;
		$this->clear_budget();
		$wpdb = $this->original_database;
		$this->database->close();
		parent::tear_down();
	}

	public function test_missing_state_is_unknown_and_never_created_by_admission_or_reads(): void {
		$queries = [];
		$record  = static function ( string $query ) use ( &$queries ): string { $queries[] = $query; return $query; };
		add_filter( 'query', $record );
		try {
			self::assertFalse( EventThrottle::admit_diagnostic( str_repeat( 'a', 32 ), time() + 60 ) );
			self::assertSame( 'unknown', EventThrottle::diagnostics_snapshot()['coverage'] );
		} finally {
			remove_filter( 'query', $record );
		}
		self::assertSame( [], array_values( array_filter( $queries, static fn( string $query ): bool => (bool) preg_match( '/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $query ) ) ) );
		self::assertNull( $this->row() );
		self::assertTrue( EventThrottle::initialize_diagnostics() );
		$this->clear_budget();
		self::assertFalse( EventThrottle::admit_diagnostic( str_repeat( 'a', 32 ), time() + 60 ) );
		self::assertNull( $this->row(), 'A previously initialized but lost row is not recreated by emission.' );
	}

	public function test_backend_exceptions_restore_the_error_policy_without_emitting_or_repairing(): void {
		self::assertTrue( EventThrottle::initialize_diagnostics() );
		$before = $this->row();
		$this->database->suppress_errors( false );
		$fail = static function ( string $query ): string {
			if ( str_contains( $query, 'pinova_rate_limits' ) ) {
				throw new \RuntimeException( 'private-budget-backend-message' );
			}
			return $query;
		};
		add_filter( 'query', $fail );
		try {
			self::assertFalse( EventThrottle::admit_diagnostic( str_repeat( 'a', 32 ), time() + 60 ) );
			self::assertFalse( $this->database->suppress_errors );
			self::assertSame( 'unknown', EventThrottle::diagnostics_snapshot()['coverage'] );
			self::assertFalse( $this->database->suppress_errors );
		} finally {
			remove_filter( 'query', $fail );
		}
		self::assertSame( $before, $this->row() );
	}

	public function test_initialization_is_idempotent_and_all_admissions_share_one_flow_lifetime(): void {
		self::assertTrue( EventThrottle::initialize_diagnostics() );
		$flow = str_repeat( 'a', 32 );
		for ( $index = 0; $index < 11; ++$index ) {
			self::assertTrue( EventThrottle::admit_diagnostic( $flow, time() + 60 ) );
		}
		$before = $this->row();
		self::assertTrue( EventThrottle::initialize_diagnostics() );
		self::assertSame( $before, $this->row() );
		self::assertTrue( EventThrottle::admit_diagnostic( $flow, time() + 60 ) );
		self::assertFalse( EventThrottle::admit_diagnostic( $flow, time() + 60 ) );
		self::assertSame( 12, (int) $this->row()['hits'] );
		self::assertSame( 'incomplete', EventThrottle::diagnostics_snapshot()['coverage'] );
	}

	public function test_two_independent_workers_cannot_both_take_the_twelfth_flow_admission(): void {
		self::assertTrue( EventThrottle::initialize_diagnostics() );
		$flow     = str_repeat( 'b', 32 );
		$deadline = time() + 120;
		for ( $index = 0; $index < 11; ++$index ) {
			self::assertTrue( EventThrottle::admit_diagnostic( $flow, $deadline ) );
		}
		self::assertSame( 1, array_sum( $this->race( [ $flow, $flow ], $deadline ) ) );
		self::assertSame( 12, (int) $this->row()['hits'] );
		$slots = json_decode( $this->row()['payload'], true )['slots'];
		self::assertSame( 12, $slots[ $this->slot( $flow ) ][0] );
	}

	public function test_two_independent_workers_cannot_both_take_the_thousandth_site_admission(): void {
		self::assertTrue( EventThrottle::initialize_diagnostics() );
		self::assertSame( 1, $this->database->update( $this->table(), [ 'hits' => 999 ], [ 'scope' => 'log_diagnostics' ] ) );
		self::assertSame( 1, array_sum( $this->race( [ str_repeat( 'c', 32 ), str_repeat( 'd', 32 ) ], time() + 120 ) ) );
		self::assertSame( 1000, (int) $this->row()['hits'] );
		self::assertFalse( EventThrottle::admit_diagnostic( str_repeat( 'e', 32 ), time() + 120 ) );
	}

	public function test_site_window_rollover_preserves_a_still_live_flow_counter(): void {
		self::assertTrue( EventThrottle::initialize_diagnostics() );
		$flow     = str_repeat( 'a', 32 );
		$deadline = time() + 120;
		for ( $index = 0; $index < 11; ++$index ) {
			self::assertTrue( EventThrottle::admit_diagnostic( $flow, $deadline ) );
		}
		$this->database->query( $this->database->prepare( 'UPDATE %i SET reset_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND, updated_at = UTC_TIMESTAMP() - INTERVAL 2 SECOND WHERE scope = %s', $this->table(), 'log_diagnostics' ) );
		self::assertTrue( EventThrottle::admit_diagnostic( $flow, $deadline ) );
		self::assertSame( 1, (int) $this->row()['hits'] );
		self::assertFalse( EventThrottle::admit_diagnostic( $flow, $deadline ) );
	}

	public function test_colliding_flows_share_twelve_and_retain_the_maximum_admitted_deadline(): void {
		self::assertTrue( EventThrottle::initialize_diagnostics() );
		$seen = [];
		for ( $index = 0; $index <= 1024; ++$index ) {
			$flow = md5( 'diagnostic-collision-' . $index );
			$slot = $this->slot( $flow );
			if ( isset( $seen[ $slot ] ) ) {
				$first = $seen[ $slot ];
				for ( $repeat = 0; $repeat < 11; ++$repeat ) {
					self::assertTrue( EventThrottle::admit_diagnostic( $first, time() + 60 ) );
				}
				$deadline = time() + 120;
				self::assertTrue( EventThrottle::admit_diagnostic( $flow, $deadline ) );
				self::assertFalse( EventThrottle::admit_diagnostic( $first, time() + 60 ) );
				self::assertSame( [ 12, $deadline ], json_decode( $this->row()['payload'], true )['slots'][ $slot ] );
				self::assertStringNotContainsString( $flow, $this->row()['payload'] );
				return;
			}
			$seen[ $slot ] = $flow;
		}
		self::fail( 'More flows than fixed slots must collide.' );
	}

	public function test_crossing_the_selected_reset_before_cas_suppresses_without_corrupting_state(): void {
		self::assertTrue( EventThrottle::initialize_diagnostics() );
		$flow     = str_repeat( 'b', 32 );
		$deadline = time() + 120;
		for ( $index = 0; $index < 11; ++$index ) {
			self::assertTrue( EventThrottle::admit_diagnostic( $flow, $deadline ) );
		}
		// Use the disposable database's real session clock, as retention tests do.
		// Moving it at the CAS boundary is deterministic and avoids a sleep race.
		$anchor = time();
		self::assertNotFalse( $this->database->query( 'SET timestamp = ' . $anchor ) );
		$advance = null;
		try {
			$this->database->update( $this->table(), [ 'reset_at' => gmdate( 'Y-m-d H:i:s', $anchor + 1 ), 'updated_at' => gmdate( 'Y-m-d H:i:s', $anchor ) ], [ 'scope' => 'log_diagnostics' ] );
			$before  = $this->row();
			$advance = static function ( string $query ) use ( &$advance, $anchor ): string {
				if ( str_starts_with( $query, 'UPDATE ' ) && str_contains( $query, 'pinova_rate_limits' ) ) {
					remove_filter( 'query', $advance );
					global $wpdb;
					self::assertNotFalse( $wpdb->query( 'SET timestamp = ' . ( $anchor + 1 ) ) );
				}
				return $query;
			};
			add_filter( 'query', $advance );
			self::assertFalse( EventThrottle::admit_diagnostic( $flow, $deadline ) );
			self::assertSame( $before, $this->row() );
			self::assertTrue( EventThrottle::diagnostics_snapshot()['read_success'] );
			self::assertTrue( EventThrottle::admit_diagnostic( $flow, $deadline ) );
			self::assertSame( 1, (int) $this->row()['hits'] );
			self::assertFalse( EventThrottle::admit_diagnostic( $flow, $deadline ) );
		} finally {
			if ( null !== $advance ) {
				remove_filter( 'query', $advance );
			}
			$this->database->query( 'SET timestamp = 0' );
		}
	}

	public function test_many_flows_have_one_bounded_row_and_repeated_drops_stop_writing(): void {
		self::assertTrue( EventThrottle::initialize_diagnostics() );
		$deadline = time() + 120;
		for ( $index = 0; $index < 1025; ++$index ) {
			EventThrottle::admit_diagnostic( md5( 'diagnostic-many-' . $index ), $deadline );
		}
		self::assertSame( '1', $this->database->get_var( $this->database->prepare( 'SELECT COUNT(*) FROM %i WHERE scope = %s', $this->table(), 'log_diagnostics' ) ) );
		$row   = $this->row();
		$slots = json_decode( $row['payload'], true )['slots'];
		self::assertLessThanOrEqual( 1024, count( $slots ) );
		self::assertLessThanOrEqual( 65536, strlen( $row['payload'] ) );
		self::assertSame( 1000, (int) $row['hits'] );
		$writes = 0;
		$record = static function ( string $query ) use ( &$writes ): string { if ( preg_match( '/\A(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $query ) ) { ++$writes; } return $query; };
		add_filter( 'query', $record );
		try {
			for ( $index = 0; $index < 5; ++$index ) {
				self::assertFalse( EventThrottle::admit_diagnostic( str_repeat( 'f', 32 ), $deadline ) );
			}
			EventThrottle::diagnostics_snapshot();
		} finally {
			remove_filter( 'query', $record );
		}
		self::assertSame( 0, $writes, 'The already-coalesced exhausted window must not write for every dropped observation.' );
	}

	/** @dataProvider corrupt_states */
	public function test_corrupt_or_oversized_payloads_are_not_repaired( string $payload ): void {
		self::assertTrue( EventThrottle::initialize_diagnostics() );
		$this->database->update( $this->table(), [ 'payload' => $payload ], [ 'scope' => 'log_diagnostics' ] );
		$before = $this->row();
		self::assertFalse( EventThrottle::admit_diagnostic( str_repeat( 'a', 32 ), time() + 60 ) );
		self::assertFalse( EventThrottle::diagnostics_snapshot()['read_success'] );
		self::assertFalse( EventThrottle::initialize_diagnostics() );
		self::assertSame( $before, $this->row() );
	}

	public static function corrupt_states(): array {
		return [ [ '{broken' ], [ '{"v":1,"slots":{"1024":[1,1]},"limited":false}' ], [ str_repeat( 'x', 65537 ) ] ];
	}

	public function test_database_clock_reversal_and_invalid_flow_deadlines_never_consume_budget(): void {
		self::assertTrue( EventThrottle::initialize_diagnostics() );
		$before = $this->row();
		foreach ( [ [ 'private-flow', time() + 60 ], [ str_repeat( 'a', 32 ), time() ], [ str_repeat( 'a', 32 ), time() + 600 ] ] as [ $flow, $deadline ] ) {
			self::assertFalse( EventThrottle::admit_diagnostic( $flow, $deadline ) );
		}
		self::assertSame( $before, $this->row() );
		$this->database->query( $this->database->prepare( 'UPDATE %i SET updated_at = UTC_TIMESTAMP() + INTERVAL 30 SECOND WHERE scope = %s', $this->table(), 'log_diagnostics' ) );
		$before = $this->row();
		self::assertFalse( EventThrottle::admit_diagnostic( str_repeat( 'a', 32 ), time() + 60 ) );
		self::assertSame( 'unknown', EventThrottle::diagnostics_snapshot()['coverage'] );
		self::assertSame( $before, $this->row() );
	}

	public function test_cas_replay_from_an_independent_connection_loses_the_observation(): void {
		self::assertTrue( EventThrottle::initialize_diagnostics() );
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->set_prefix( $this->database->prefix );
		$other->suppress_errors();
		self::assertNotSame( $this->database->get_var( 'SELECT CONNECTION_ID()' ), $other->get_var( 'SELECT CONNECTION_ID()' ) );
		$replay = null;
		$replay = static function ( string $query ) use ( &$replay, $other ): string {
			if ( str_starts_with( $query, 'UPDATE ' ) && str_contains( $query, 'pinova_rate_limits' ) ) {
				remove_filter( 'query', $replay );
				self::assertSame( 1, $other->query( $query ) );
			}
			return $query;
		};
		add_filter( 'query', $replay );
		try {
			self::assertFalse( EventThrottle::admit_diagnostic( str_repeat( 'a', 32 ), time() + 60 ) );
		} finally {
			remove_filter( 'query', $replay );
			$other->close();
		}
		self::assertSame( 1, (int) $this->row()['hits'] );
	}

	public function test_daily_rate_limit_cleanup_preserves_only_the_fixed_diagnostic_budget(): void {
		self::assertTrue( EventThrottle::initialize_diagnostics() );
		$ordinary_key = hash( 'sha256', 'diagnostic-cleanup-' . wp_generate_uuid4() );
		$other_key    = hash( 'sha256', 'diagnostic-cleanup-other-' . wp_generate_uuid4() );
		$old          = time() - 2 * DAY_IN_SECONDS;
		$this->database->update( $this->table(), [ 'reset_at' => gmdate( 'Y-m-d H:i:s', $old ), 'updated_at' => gmdate( 'Y-m-d H:i:s', $old - 1 ) ], [ 'scope' => 'log_diagnostics' ] );
		self::assertSame( 1, $this->database->insert( $this->table(), [ 'bucket_key' => $ordinary_key, 'scope' => 'diagnostic_cleanup_fixture', 'hits' => 1, 'reset_at' => gmdate( 'Y-m-d H:i:s', $old ), 'updated_at' => gmdate( 'Y-m-d H:i:s', $old ) ] ) );
		self::assertSame( 1, $this->database->insert( $this->table(), [ 'bucket_key' => $other_key, 'scope' => 'log_diagnostics', 'hits' => 1, 'reset_at' => gmdate( 'Y-m-d H:i:s', $old ), 'updated_at' => gmdate( 'Y-m-d H:i:s', $old ) ] ) );
		$before = $this->row();
		try {
			RateLimitService::cleanup();
			self::assertNull( $this->database->get_var( $this->database->prepare( 'SELECT bucket_key FROM %i WHERE bucket_key = %s', $this->table(), $ordinary_key ) ) );
			self::assertNull( $this->database->get_var( $this->database->prepare( 'SELECT bucket_key FROM %i WHERE bucket_key = %s', $this->table(), $other_key ) ) );
			self::assertSame( $before, $this->row(), 'Ordinary maintenance must not turn a sticky initialized budget into missing state.' );
			self::assertTrue( EventThrottle::diagnostics_snapshot()['read_success'] );
			self::assertTrue( EventThrottle::admit_diagnostic( str_repeat( 'a', 32 ), time() + 60 ) );
		} finally {
			$this->database->delete( $this->table(), [ 'bucket_key' => $ordinary_key ] );
			$this->database->delete( $this->table(), [ 'bucket_key' => $other_key ] );
		}
	}

	private function race( array $flows, int $deadline ): array {
		self::assertTrue( function_exists( 'proc_open' ) );
		$workers = [];
		try {
			foreach ( $flows as $flow ) {
				$pipes   = [];
				$process = proc_open( [ PHP_BINARY, __DIR__ . '/DiagnosticBudgetWorkerFixture.php' ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
				self::assertIsResource( $process );
				$worker    = [ 'process' => $process, 'pipes' => $pipes ];
				$workers[] = $worker;
				fwrite( $pipes[0], wp_json_encode( [ 'marker' => 'pinova-diagnostic-budget-worker', 'wordpress' => ABSPATH, 'repository' => dirname( __DIR__, 2 ), 'user' => DB_USER, 'password' => DB_PASSWORD, 'database' => DB_NAME, 'host' => DB_HOST, 'prefix' => $this->database->prefix, 'salt' => wp_salt( 'auth' ), 'flow' => $flow, 'deadline' => $deadline ] ) . PHP_EOL );
			}
			$connections = [];
			foreach ( $workers as $worker ) {
				$connections[] = $this->reply( $worker, 'ready' )['connection_id'];
			}
			self::assertCount( 2, array_unique( $connections ) );
			foreach ( $workers as $worker ) {
				fwrite( $worker['pipes'][0], 'begin' . PHP_EOL );
			}
			foreach ( $workers as $worker ) {
				$this->reply( $worker, 'before_cas' );
			}
			foreach ( $workers as $worker ) {
				fwrite( $worker['pipes'][0], 'go' . PHP_EOL );
			}
			$results = [];
			foreach ( $workers as $worker ) {
				$reply = $this->reply( $worker, 'result' );
				self::assertIsBool( $reply['admitted'] );
				$results[] = (int) $reply['admitted'];
			}
			return $results;
		} finally {
			foreach ( $workers as $worker ) {
				if ( proc_get_status( $worker['process'] )['running'] ) {
					proc_terminate( $worker['process'], 9 );
				}
				foreach ( $worker['pipes'] as $pipe ) {
					fclose( $pipe );
				}
				proc_close( $worker['process'] );
			}
		}
	}

	private function reply( array $worker, string $phase ): array {
		$read   = [ $worker['pipes'][1] ];
		$write  = null;
		$except = null;
		self::assertSame( 1, stream_select( $read, $write, $except, 5 ), 'The independent diagnostic worker did not respond within its bound.' );
		$reply = json_decode( (string) fgets( $worker['pipes'][1] ), true );
		self::assertIsArray( $reply );
		self::assertSame( $phase, $reply['phase'] ?? null );
		return $reply;
	}

	private function slot( string $flow ): int {
		return (int) ( hexdec( substr( hash_hmac( 'sha256', 'pinova:diagnostic-flow:v1:' . $flow, wp_salt( 'auth' ) ), 0, 4 ) ) % 1024 );
	}

	private function row(): ?array {
		return $this->database->get_row( $this->database->prepare( 'SELECT * FROM %i WHERE bucket_key = %s', $this->table(), EventThrottle::diagnostic_bucket_key() ), ARRAY_A );
	}

	private function table(): string {
		return $this->database->prefix . 'pinova_rate_limits';
	}

	private function clear_budget(): void {
		$this->database->query( $this->database->prepare( 'DELETE FROM %i WHERE scope = %s', $this->table(), 'log_diagnostics' ) );
	}
}
