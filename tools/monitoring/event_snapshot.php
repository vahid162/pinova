<?php

declare(strict_types=1);

namespace Pinova\Monitoring;

use mysqli;
use RuntimeException;
use Throwable;

/** Read-only native witnesses; loading this file performs no I/O. */
final class EventSnapshot {

	private const LEVELS = [ 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency' ];
	private const FAILURES = [ 'invalid_arguments', 'unsupported_server', 'unsupported_engine', 'native_session_state', 'native_identity_unavailable', 'native_identity_changed', 'native_counter_regressed', 'native_read_bound', 'invalid_native_rows', 'native_read_failed' ];
	private mysqli $metadata;

	/**
	 * Caller-owned, independently bounded connections; no retries or replacement.
	 * The optional existing metadata connection needs PROCESS for native table identity.
	 */
	public function __construct( private mysqli $database, private string $table, ?mysqli $metadata = null, private int $max_rows = 1000 ) {
		if ( 8 !== PHP_INT_SIZE || 1 !== preg_match( '/\A[A-Za-z0-9_]{1,64}\z/D', $table ) || 1 > $max_rows || 1000 < $max_rows ) {
			throw new RuntimeException( 'invalid_event_snapshot_configuration' );
		}
		$this->metadata = $metadata ?? $database;
	}

	/** Start from the allocation fence, including IDs not committed yet. */
	public function baseline( int $sequence ): array {
		return $this->capture( $sequence, null, null );
	}

	/** Read every severity row after the contiguous cursor, clipped to a frozen end if supplied. */
	public function read( int $sequence, int $after_id, ?int $through_id = null ): array {
		return $this->capture( $sequence, $after_id, $through_id );
	}

	private function capture( int $sequence, ?int $after_id, ?int $through_id ): array {
		$started = hrtime( true );
		$base = [ 'protocol' => 'native_autocommit_dense_ids_v1', 'adapter_sha256' => hash_file( 'sha256', __FILE__ ), 'sequence' => $sequence, 'rows' => [] ];
		try {
			if ( 1 > $sequence || ( null !== $after_id && 0 > $after_id ) || ( null !== $through_id && ( null === $after_id || $through_id < $after_id ) ) ) {
				throw new RuntimeException( 'invalid_arguments' );
			}
			$before = $this->session( $this->database );
			$meta_before = $this->metadata === $this->database ? $before : $this->session( $this->metadata );
			$this->compatible_sessions( $before, $meta_before );
			$first = $this->table_state( $before['schema_name'], $before['server'] );
			$after = $after_id ?? $first['next_id'] - 1;
			$through = null === $through_id ? $first['next_id'] - 1 : min( $through_id, $first['next_id'] - 1 );
			if ( $through < $after ) {
				throw new RuntimeException( 'native_counter_regressed' );
			}
			$rows = [];
			if ( null !== $after_id && $through > $after ) {
				$name = '`' . $before['schema_name'] . '`.`' . $this->table . '`';
				$level = 'CASE level ' . implode( ' ', array_map( static fn( string $value ): string => "WHEN '" . $value . "' THEN '" . $value . "'", self::LEVELS ) ) . ' ELSE NULL END';
				$result = $this->database->query( 'SELECT CAST(id AS CHAR(20)) AS id, ' . $level . ' AS level FROM ' . $name . ' WHERE id > ' . $after . ' AND id <= ' . $through . ' ORDER BY id ASC LIMIT ' . ( $this->max_rows + 1 ) );
				if ( false === $result || $result->num_rows > $this->max_rows ) {
					throw new RuntimeException( false === $result ? 'native_read_failed' : 'native_read_bound' );
				}
				$previous = $after;
				while ( $row = $result->fetch_assoc() ) {
					$id = self::integer( $row['id'] ?? null, 1 );
					if ( $id <= $previous || $id > $through || ! in_array( $row['level'] ?? null, self::LEVELS, true ) ) {
						throw new RuntimeException( 'invalid_native_rows' );
					}
					$rows[] = [ 'id' => $id, 'level' => $row['level'] ];
					$previous = $id;
				}
				$result->free();
			}
			$last = $this->table_state( $before['schema_name'], $before['server'] );
			$finish = $this->session( $this->database );
			$meta_finish = $this->metadata === $this->database ? $finish : $this->session( $this->metadata );
			$this->compatible_sessions( $finish, $meta_finish );
			if ( $first['identity'] !== $last['identity'] || $before['server'] !== $finish['server'] || $before['schema_name'] !== $finish['schema_name']
				|| $before['connection'] !== $finish['connection'] || $meta_before['connection'] !== $meta_finish['connection'] || $before['isolation'] !== $finish['isolation'] ) {
				throw new RuntimeException( 'native_identity_changed' );
			}
			if ( $last['next_id'] < $first['next_id'] ) {
				throw new RuntimeException( 'native_counter_regressed' );
			}
			return array_merge( $base, [
				'state' => 'observed', 'allocation_source' => 'mariadb_live_show_table_status_v1',
				'identity_before' => $first['identity'], 'identity_after' => $last['identity'],
				'connection_before' => $before['connection'], 'connection_after' => $finish['connection'],
				'metadata_connection_before' => $meta_before['connection'], 'metadata_connection_after' => $meta_finish['connection'],
				'autocommit_before' => 1, 'autocommit_after' => 1, 'in_transaction_before' => 0, 'in_transaction_after' => 0,
				'isolation_before' => $before['isolation'], 'isolation_after' => $finish['isolation'], 'increment' => 1, 'offset' => 1,
				'next_id_before' => $first['next_id'], 'next_id_after' => $last['next_id'],
				'query_after_id' => $after, 'query_through_id' => $through, 'rows' => $rows,
				'read_started_ns' => $started, 'read_completed_ns' => hrtime( true ), 'wall_epoch_ns' => (int) round( microtime( true ) * 1000000000 ),
			] );
		} catch ( Throwable $error ) {
			$code = $error instanceof RuntimeException && in_array( $error->getMessage(), self::FAILURES, true ) ? $error->getMessage() : 'native_read_failed';
			return array_merge( $base, [ 'state' => 'needs_review', 'reason' => 'native_read_failed', 'failure_code' => $code,
				'read_started_ns' => $started, 'read_completed_ns' => hrtime( true ), 'wall_epoch_ns' => (int) round( microtime( true ) * 1000000000 ) ] );
		}
	}

	private function compatible_sessions( array $primary, array $metadata ): void {
		if ( $primary['server'] !== $metadata['server'] ) {
			throw new RuntimeException( 'native_identity_changed' );
		}
	}

	private function session( mysqli $connection ): array {
		$row = self::one( $connection, 'SELECT VERSION() AS version, DATABASE() AS schema_name, CONNECTION_ID() AS connection_id, @@hostname AS hostname, @@port AS port, @@server_id AS server_id, @@session.autocommit AS autocommit, @@session.in_transaction AS in_transaction, @@session.auto_increment_increment AS increment_value, @@session.auto_increment_offset AS offset_value, @@session.tx_isolation AS isolation_value' );
		if ( 1 !== preg_match( '/\A10\.11\.10-MariaDB(?:[-+][A-Za-z0-9._+~-]+)*\z/D', (string) ( $row['version'] ?? '' ) ) ) {
			throw new RuntimeException( 'unsupported_server' );
		}
		if ( 1 !== self::integer( $row['autocommit'] ?? null ) || 0 !== self::integer( $row['in_transaction'] ?? null )
			|| 1 !== self::integer( $row['increment_value'] ?? null ) || 1 !== self::integer( $row['offset_value'] ?? null )
			|| ! in_array( $row['isolation_value'] ?? null, [ 'READ-COMMITTED', 'REPEATABLE-READ' ], true ) ) {
			throw new RuntimeException( 'native_session_state' );
		}
		$schema = $row['schema_name'] ?? '';
		if ( ! is_string( $schema ) || 1 !== preg_match( '/\A[A-Za-z0-9_]{1,64}\z/D', $schema ) ) {
			throw new RuntimeException( 'native_identity_unavailable' );
		}
		return [ 'connection' => self::integer( $row['connection_id'] ?? null, 1 ), 'schema_name' => $schema, 'isolation' => $row['isolation_value'],
			'server' => hash( 'sha256', json_encode( [ $row['version'], $row['hostname'] ?? null, $row['port'] ?? null, $row['server_id'] ?? null ], JSON_THROW_ON_ERROR ) ) ];
	}

	private function table_state( string $schema, string $server ): array {
		$status = self::one( $this->metadata, 'SHOW TABLE STATUS FROM `' . $schema . "` WHERE Name = '" . $this->table . "'" );
		if ( 'InnoDB' !== ( $status['Engine'] ?? null ) || $this->table !== ( $status['Name'] ?? null ) ) {
			throw new RuntimeException( 'unsupported_engine' );
		}
		$native = self::one( $this->metadata, "SELECT TABLE_ID, SPACE FROM information_schema.INNODB_SYS_TABLES WHERE NAME = '" . $schema . '/' . $this->table . "'" );
		$native_id = self::integer( $native['TABLE_ID'] ?? null, 1 );
		$space = self::integer( $native['SPACE'] ?? null );
		$create = self::one( $this->metadata, 'SHOW CREATE TABLE `' . $schema . '`.`' . $this->table . '`' );
		$definition = $create['Create Table'] ?? null;
		if ( ! is_string( $definition ) || 65536 < strlen( $definition ) || 1 !== substr_count( $definition, "\n) ENGINE=InnoDB" ) ) {
			throw new RuntimeException( 'native_identity_unavailable' );
		}
		[ $columns, $options ] = explode( "\n) ENGINE=InnoDB", $definition, 2 );
		// Only the changing native table option is omitted; column defaults/comments stay pinned.
		$options = preg_replace( '/\A AUTO_INCREMENT=[1-9][0-9]*(?= |\z)/D', '', $options, 1 );
		if ( ! is_string( $options ) ) {
			throw new RuntimeException( 'native_identity_unavailable' );
		}
		return [ 'next_id' => self::integer( $status['Auto_increment'] ?? null, 1 ), 'identity' => [
			'server' => $server, 'table' => hash( 'sha256', $schema . '/' . $this->table . ':' . $native_id . ':' . $space ),
			'definition' => hash( 'sha256', $columns . "\n) ENGINE=InnoDB" . $options ),
		] ];
	}

	private static function one( mysqli $connection, string $query ): array {
		$result = $connection->query( $query );
		if ( false === $result || 1 !== $result->num_rows ) {
			throw new RuntimeException( 'native_read_failed' );
		}
		$row = $result->fetch_assoc();
		$result->free();
		if ( ! is_array( $row ) ) {
			throw new RuntimeException( 'native_read_failed' );
		}
		return $row;
	}

	private static function integer( mixed $value, int $minimum = 0 ): int {
		if ( ( ! is_int( $value ) && ! is_string( $value ) ) || 1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/D', (string) $value )
			|| strlen( (string) $value ) > strlen( (string) PHP_INT_MAX ) || ( strlen( (string) $value ) === strlen( (string) PHP_INT_MAX ) && strcmp( (string) $value, (string) PHP_INT_MAX ) > 0 )
			|| (int) $value < $minimum ) {
			throw new RuntimeException( 'native_identity_unavailable' );
		}
		return (int) $value;
	}
}
