<?php

namespace Pinova\Logging;

use Pinova\Pinova;

final class LogRepository {

	public const DEFAULT_RETENTION_DAYS = 14;

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
	 * @return array<int, array{id:int,created_at:string,event:string,correlation_id:string}>
	 */
	public static function export_for_user( int $user_id, int $page = 1, int $per_page = 100 ): array {
		global $wpdb;

		if ( $user_id <= 0 || ! self::table_exists() ) {
			return [];
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

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Remove the user link and keyed fingerprints while retaining event facts.
	 *
	 * @return array{processed:int,done:bool,success:bool}
	 */
	public static function anonymize_user( int $user_id, int $limit = 100 ): array {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );
		if ( $user_id <= 0 ) {
			return [
				'processed' => 0,
				'done'      => true,
				'success'   => true,
			];
		}

		$table       = self::table_name();
		$table_found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( '' !== $wpdb->last_error ) {
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

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT `id`, `context` FROM %i WHERE `user_id` = %d ORDER BY `id` ASC LIMIT %d',
				self::table_name(),
				$user_id,
				$limit
			),
			ARRAY_A
		);
		if ( '' !== $wpdb->last_error ) {
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
					'context' => is_string( $encoded ) ? $encoded : '{}',
				],
				[ 'id' => (int) $row['id'] ],
				[ '%d', '%s' ],
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
	public static function paginate( int $page = 1, int $per_page = 50, string $level = '' ): array {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return [
				'rows'  => [],
				'total' => 0,
			];
		}

		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$allowed  = [ 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency' ];
		$level    = in_array( $level, $allowed, true ) ? $level : '';

		if ( '' !== $level ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE `level` = %s', self::table_name(), $level )
			);
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT `id`, `created_at`, `level`, `event`, `correlation_id`, `user_id`, `context` FROM %i WHERE `level` = %s ORDER BY `id` DESC LIMIT %d OFFSET %d',
					self::table_name(),
					$level,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		} else {
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table_name() ) );
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT `id`, `created_at`, `level`, `event`, `correlation_id`, `user_id`, `context` FROM %i ORDER BY `id` DESC LIMIT %d OFFSET %d',
					self::table_name(),
					$per_page,
					$offset
				),
				ARRAY_A
			);
		}

		return [
			'rows'  => is_array( $rows ) ? $rows : [],
			'total' => $total,
		];
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
}
