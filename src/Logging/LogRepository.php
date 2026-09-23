<?php

namespace Pinova\Logging;

use Pinova\Pinova;

final class LogRepository {

	public const DEFAULT_RETENTION_DAYS = 14;
	private const LEVELS                = [ 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency' ];

	public static function cleanup(): int {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$days  = self::retention_days();
		$query = $wpdb->prepare(
			'DELETE FROM %i WHERE `created_at` < (UTC_TIMESTAMP() - INTERVAL %d DAY) LIMIT 5000',
			self::table_name(),
			$days
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query is prepared immediately above.
		return max( 0, (int) $wpdb->query( $query ) );
	}

	public static function delete_all(): int {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		return max( 0, (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::table_name() ) ) );
	}

	/**
	 * Return the non-sensitive audit facts owned by one WordPress user.
	 *
	 * Context is deliberately excluded because it can contain keyed fingerprints.
	 *
	 * @return array{rows:array<int, array{id:int,created_at:string,event:string,correlation_id:string}>,success:bool}
	 */
	public static function export_for_user( int $user_id, int $page = 1, int $per_page = 100 ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return [
				'rows'    => [],
				'success' => true,
			];
		}

		$table       = self::table_name();
		$table_found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( self::database_error_present() ) {
			return [
				'rows'    => [],
				'success' => false,
			];
		}

		if ( $table_found !== $table ) {
			return [
				'rows'    => [],
				'success' => true,
			];
		}

		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT `id`, `created_at`, `event`, `correlation_id` FROM %i WHERE `user_id` = %d ORDER BY `id` ASC LIMIT %d OFFSET %d',
				self::table_name(),
				$user_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);
		if ( self::database_error_present() ) {
			return [
				'rows'    => [],
				'success' => false,
			];
		}

		return [
			'rows'    => is_array( $rows ) ? $rows : [],
			'success' => true,
		];
	}

	/**
	 * Remove the user link and keyed fingerprints while retaining event facts.
	 *
	 * @param string[] $fingerprints
	 * @param string[] $legacy_unowned_identifier_types
	 * @return array{processed:int,done:bool,success:bool}
	 */
	public static function anonymize_user(
		int $user_id,
		int $limit = 100,
		array $fingerprints = [],
		array $legacy_unowned_identifier_types = []
	): array {
		global $wpdb;

		$limit                           = max( 1, min( 100, $limit ) );
		$fingerprints                    = array_values( array_unique( array_filter( array_map( 'strval', $fingerprints ) ) ) );
		$legacy_unowned_identifier_types = array_values(
			array_intersect(
				[ 'email', 'mobile', 'username' ],
				array_unique( array_map( 'sanitize_key', $legacy_unowned_identifier_types ) )
			)
		);
		if ( $user_id <= 0 && ! $fingerprints && ! $legacy_unowned_identifier_types ) {
			return [
				'processed' => 0,
				'done'      => true,
				'success'   => true,
			];
		}

		$table       = self::table_name();
		$table_found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( self::database_error_present() ) {
			return [
				'processed' => 0,
				'done'      => false,
				'success'   => false,
			];
		}

		if ( $table_found !== $table ) {
			return [
				'processed' => 0,
				'done'      => true,
				'success'   => true,
			];
		}

		$where   = [];
		$values  = [ self::table_name() ];
		$matches = [];
		if ( $user_id > 0 ) {
			$where[]  = '`user_id` = %d';
			$values[] = $user_id;
		}
		foreach ( $fingerprints as $fingerprint ) {
			$matches[] = '`context` LIKE %s';
			$values[]  = '%"' . $wpdb->esc_like( $fingerprint ) . '"%';
		}
		foreach ( $legacy_unowned_identifier_types as $identifier_type ) {
			$matches[] = '(`context` LIKE %s AND `context` LIKE %s)';
			$values[]  = '%"identifier_type":"' . $wpdb->esc_like( $identifier_type ) . '"%';
			$values[]  = '%"identifier_fingerprint":"%';
		}
		if ( $matches ) {
			$where[] = '(`user_id` IS NULL AND (' . implode( ' OR ', $matches ) . '))';
		}
		$values[] = $limit;
		$query    = 'SELECT `id`, `context` FROM %i WHERE (' . implode( ' OR ', $where ) . ') ORDER BY `id` ASC LIMIT %d';
		$rows     = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The dynamic fragment contains fixed placeholders only.
			$wpdb->prepare( $query, $values ),
			ARRAY_A
		);
		if ( self::database_error_present() ) {
			return [
				'processed' => 0,
				'done'      => false,
				'success'   => false,
			];
		}
		$rows = is_array( $rows ) ? $rows : [];

		$processed = 0;
		foreach ( $rows as $row ) {
			$context = json_decode( (string) ( $row['context'] ?? '{}' ), true );
			$context = is_array( $context ) ? $context : [];
			unset(
				$context['identifier_fingerprint'],
				$context['ip_fingerprint'],
				$context['subject_fingerprint'],
				$context['user_id']
			);

			$encoded = wp_json_encode( $context );
			$updated = $wpdb->update(
				self::table_name(),
				[
					'user_id' => null,
					'flow_id' => null,
					'context' => is_string( $encoded ) ? $encoded : '{}',
				],
				[ 'id' => (int) $row['id'] ],
				[ '%d', '%s', '%s' ],
				[ '%d' ]
			);

			if ( false === $updated ) {
				return [
					'processed' => $processed,
					'done'      => false,
					'success'   => false,
				];
			}

			++$processed;
		}

		return [
			'processed' => $processed,
			'done'      => count( $rows ) < $limit,
			'success'   => true,
		];
	}

	/**
	 * @return array{rows:array<int, array<string, mixed>>, total:int}
	 */
	public static function paginate( int $page = 1, int $per_page = 50, string $level = '', array $filters = [] ): array {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return [
				'rows'  => [],
				'total' => 0,
			];
		}

		$page     = max( 1, min( 1000, $page ) );
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$level    = in_array( $level, self::LEVELS, true ) ? $level : '';
		$filters  = self::filter_values( $filters );
		if ( ! $filters['valid'] ) {
			return [
				'rows'  => [],
				'total' => 0,
			];
		}

		[ $where, $values ] = self::where_clause( $filters, $level );
		$total              = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The WHERE fragment contains only fixed clauses and placeholders.
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i' . $where, array_merge( [ self::table_name() ], $values ) )
		);
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic fixed-clause WHERE values are assembled together.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The WHERE fragment contains only fixed clauses and placeholders.
				'SELECT `id`, `created_at`, `level`, `event`, `correlation_id`, `flow_id`, `user_id`, `context` FROM %i' . $where . ' ORDER BY `id` DESC LIMIT %d OFFSET %d',
				array_merge( [ self::table_name() ], $values, [ $per_page, $offset ] )
			),
			ARRAY_A
		);

		return [
			'rows'  => is_array( $rows ) ? $rows : [],
			'total' => $total,
		];
	}

	/**
	 * Read a small incident-export batch without OFFSET scans.
	 *
	 * @param array<string, mixed> $filters
	 * @return array{rows:array<int, array<string,mixed>>,success:bool}
	 */
	public static function incident_batch( array $filters, int $before_id = 0, int $limit = 100, string $level = '' ): array {
		global $wpdb;

		$filters = self::filter_values( $filters );
		if ( ! $filters['valid'] || ! self::table_exists() ) {
			return [
				'rows'    => [],
				'success' => false,
			];
		}

		if ( '' !== $level && ! in_array( $level, self::LEVELS, true ) ) {
			return [
				'rows'    => [],
				'success' => false,
			];
		}
		[ $where, $values ] = self::where_clause( $filters, $level );
		$where             .= '' === $where ? ' WHERE ' : ' AND ';
		$where             .= '`id` < %d';
		$values[]           = $before_id > 0 ? $before_id : PHP_INT_MAX;
		$values[]           = max( 1, min( 100, $limit ) );
		$rows               = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic fixed-clause WHERE values are assembled together.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The WHERE fragment contains only fixed clauses and placeholders.
				'SELECT `id`, `created_at`, `level`, `event`, `correlation_id`, `flow_id`, `user_id`, `context` FROM %i' . $where . ' ORDER BY `id` DESC LIMIT %d',
				array_merge( [ self::table_name() ], $values )
			),
			ARRAY_A
		);

		return [
			'rows'    => is_array( $rows ) ? $rows : [],
			'success' => ! self::database_error_present(),
		];
	}

	/** @param array<string,mixed> $filters */
	public static function valid_filters( array $filters ): bool {
		return self::filter_values( $filters )['valid'];
	}

	/** @return array{state:string,table_exists:bool,cleanup_scheduled:bool,last_event_id:int,last_event_at:string} */
	public static function health(): array {
		global $wpdb;

		$health = [
			'state'             => 'unavailable',
			'table_exists'      => false,
			'cleanup_scheduled' => false,
			'last_event_id'     => 0,
			'last_event_at'     => '',
		];
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return $health;
		}

		$health['cleanup_scheduled'] = (bool) wp_next_scheduled( 'pinova_logging_cleanup' );
		$health['table_exists']      = self::table_exists();
		if ( self::database_error_present() ) {
			$health['state'] = 'degraded';
		} elseif ( $health['table_exists'] ) {
			$latest = $wpdb->get_row(
				$wpdb->prepare( 'SELECT `id`, `created_at`, `level`, `event`, `correlation_id`, `user_id`, `context` FROM %i ORDER BY `id` DESC LIMIT 1', self::table_name() ),
				ARRAY_A
			);
			if ( self::database_error_present() ) {
				$health['state'] = 'degraded';
			} else {
				$health['state'] = $health['cleanup_scheduled'] ? 'database-backed' : 'degraded';
				if ( is_array( $latest ) ) {
					$health['last_event_id'] = (int) $latest['id'];
					$health['last_event_at'] = (string) $latest['created_at'];
				}
			}
		}

		if ( DatabaseHandler::did_fallback() ) {
			$health['state'] = 'fallback';
		}

		return $health;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{valid:bool,event:string,correlation_id:string,flow_id:string,user_id:int,created_from:string,created_to:string}
	 */
	private static function filter_values( array $input ): array {
		$filters = [
			'valid'          => true,
			'event'          => '',
			'correlation_id' => '',
			'flow_id'        => '',
			'user_id'        => 0,
			'created_from'   => '',
			'created_to'     => '',
		];
		foreach ( [
			'event'          => '/\A[a-z0-9_.-]{1,100}\z/',
			'correlation_id' => '/\A[A-Za-z0-9-]{1,64}\z/',
			'flow_id'        => '/\A[a-f0-9]{32}\z/',
		] as $key => $pattern ) {
			$value = $input[ $key ] ?? '';
			if ( '' === $value ) {
				continue;
			}
			if ( ! is_string( $value ) || ! preg_match( $pattern, $value ) ) {
				$filters['valid'] = false;
				continue;
			}
			$filters[ $key ] = $value;
		}
		if ( isset( $input['user_id'] ) && '' !== $input['user_id'] ) {
			if ( ! is_string( $input['user_id'] ) && ! is_int( $input['user_id'] ) ) {
				$filters['valid'] = false;
			} elseif ( ! ctype_digit( (string) $input['user_id'] ) || (int) $input['user_id'] <= 0 ) {
				$filters['valid'] = false;
			} else {
				$filters['user_id'] = (int) $input['user_id'];
			}
		}
		foreach ( [ 'created_from', 'created_to' ] as $key ) {
			$value = $input[ $key ] ?? '';
			if ( '' === $value ) {
				continue;
			}
			if ( ! is_string( $value ) || ! preg_match( '/\A\d{4}-\d{2}-\d{2}\z/', $value ) || false === strtotime( $value . ' UTC' ) || gmdate( 'Y-m-d', strtotime( $value . ' UTC' ) ) !== $value ) {
				$filters['valid'] = false;
				continue;
			}
			$filters[ $key ] = $value;
		}
		if ( ( '' === $filters['created_from'] ) !== ( '' === $filters['created_to'] ) ) {
			$filters['valid'] = false;
		} elseif ( '' !== $filters['created_from'] && '' !== $filters['created_to'] && ( $filters['created_from'] > $filters['created_to'] || strtotime( $filters['created_to'] . ' UTC' ) - strtotime( $filters['created_from'] . ' UTC' ) >= 90 * DAY_IN_SECONDS ) ) {
			$filters['valid'] = false;
		}

		return $filters;
	}

	/**
	 * @param array{valid:bool,event:string,correlation_id:string,flow_id:string,user_id:int,created_from:string,created_to:string} $filters
	 * @return array{string,array<int,int|string>}
	 */
	private static function where_clause( array $filters, string $level ): array {
		$clauses = [];
		$values  = [];
		foreach ( [
			'level'          => $level,
			'event'          => $filters['event'],
			'correlation_id' => $filters['correlation_id'],
			'flow_id'        => $filters['flow_id'],
		] as $column => $value ) {
			if ( '' !== $value ) {
				$clauses[] = '`' . $column . '` = %s';
				$values[]  = $value;
			}
		}
		if ( $filters['user_id'] > 0 ) {
			$clauses[] = '`user_id` = %d';
			$values[]  = $filters['user_id'];
		}
		if ( '' !== $filters['created_from'] ) {
			$clauses[] = '`created_at` >= %s';
			$values[]  = $filters['created_from'] . ' 00:00:00';
		}
		if ( '' !== $filters['created_to'] ) {
			$clauses[] = '`created_at` <= %s';
			$values[]  = $filters['created_to'] . ' 23:59:59';
		}

		return [ $clauses ? ' WHERE ' . implode( ' AND ', $clauses ) : '', $values ];
	}

	public static function retention_days(): int {
		$days = class_exists( Pinova::class )
			? (int) Pinova::get_option( 'logging.retention_days', self::DEFAULT_RETENTION_DAYS )
			: self::DEFAULT_RETENTION_DAYS;

		if ( function_exists( 'apply_filters' ) ) {
			$days = (int) apply_filters( 'pinova/logging_retention_days', $days );
		}

		return max( 1, min( 90, $days ) );
	}

	public static function table_exists(): bool {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}

		$table = self::table_name();
		$like  = $wpdb->esc_like( $table );

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) === $table;
	}

	private static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'pinova_logs';
	}

	/** @phpstan-impure */
	private static function database_error_present(): bool {
		global $wpdb;

		$properties = get_object_vars( $wpdb );
		$error      = $properties['last_error'] ?? '';

		return is_string( $error ) && '' !== $error;
	}
}
