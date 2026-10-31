<?php

namespace Pinova\Logging;

use Pinova\Helpers\IP;
use Throwable;

/** Bounded observational events; never an authentication access decision. */
final class EventThrottle {

	private const WINDOW_SECONDS          = 900;
	private const SITE_LIMIT              = 100;
	private const SOURCE_SLOTS            = 1024;
	private const DIAGNOSTIC_SITE_LIMIT   = 1000;
	private const DIAGNOSTIC_FLOW_LIMIT   = 12;
	private const DIAGNOSTIC_SCOPE        = 'log_diagnostics';
	private const DIAGNOSTIC_MAX_BYTES    = 65536;
	private const DIAGNOSTIC_FLOW_SECONDS = 180;

	private const EVENTS = [
		'identity.legacy_contact_overlap' => [
			'scope' => 'log_legacy_contact_overlap',
			'level' => 'warning',
		],
		'identity.account_link_failed'    => [
			'scope' => 'log_account_link_failed',
			'level' => 'warning',
		],
		'integration.operation_failed'    => [
			'scope' => 'log_integration_failed',
			'level' => 'error',
		],
		'auth.session_failed'             => [
			'scope' => 'log_session_failed',
			'level' => 'error',
		],
		'otp.queued'                      => [
			'scope' => 'log_otp_queued',
			'level' => 'info',
		],
		'otp.queue_recovered'             => [
			'scope' => 'log_otp_queue_recovered',
			'level' => 'info',
		],
		'otp.queue_deferred'              => [
			'scope' => 'log_otp_queue_deferred',
			'level' => 'warning',
		],
		'otp.delivery_skipped'            => [
			'scope' => 'log_otp_delivery_skipped',
			'level' => 'info',
		],
		'auth.request_failed'             => [
			'scope' => 'log_auth_request_failed',
			'level' => 'error',
		],
		'auth.logout_rejected'            => [
			'scope' => 'log_logout_rejected',
			'level' => 'notice',
		],
		'auth.password_reset_failed'      => [
			'scope' => 'log_reset_failed',
			'level' => 'notice',
		],
		'auth.password_reset_succeeded'   => [
			'scope' => 'log_reset_succeeded',
			'level' => 'info',
		],
		'otp.verify_failed'               => [
			'scope' => 'log_otp_verify_failed',
			'level' => 'notice',
		],
	];

	/** @param array<string, mixed> $context */
	public static function log( string $event, array $context = [] ): void {
		if ( ! isset( self::EVENTS[ $event ] ) ) {
			return;
		}

		try {
			$config = self::EVENTS[ $event ];
			$logger = Logger::instance();
			$level  = 'otp.verify_failed' === $event && 'ip_mismatch' === ( $context['reason'] ?? '' ) ? 'warning' : $config['level'];
			if ( 'auth.session_failed' === $event && 'policy_rejected' === ( $context['reason'] ?? '' ) ) {
				$level = 'notice';
			}
			if ( ! $logger->is_enabled( $level ) ) {
				return;
			}

			$ip     = IP::get();
			$source = '' === $ip ? 'unknown' : inet_pton( $ip );
			if ( in_array( $event, [ 'otp.verify_failed', 'auth.request_failed', 'auth.session_failed', 'integration.operation_failed', 'identity.account_link_failed' ], true ) ) {
				$source .= '|' . (string) ( $context['reason'] ?? 'unknown' );
				if ( 'integration.operation_failed' === $event ) {
					$source .= '|' . (string) ( $context['operation'] ?? 'unknown' );
				}
			} elseif ( in_array( $event, [ 'otp.queued', 'otp.delivery_skipped', 'otp.queue_recovered', 'otp.queue_deferred' ], true ) ) {
				$source .= '|' . (string) ( $context['flow_id'] ?? '' );
			}
			$hash = hash_hmac( 'sha256', (string) $source, wp_salt( 'auth' ) );
			$slot = hexdec( substr( $hash, 0, 4 ) ) % self::SOURCE_SLOTS;

			// Fixed slots bound storage even if cleanup stops. Collisions only suppress
			// observations; they never deny authentication or increase the log budget.
			if ( ! self::consume( $config['scope'], (string) $slot, 1 )
				|| ! self::consume( $config['scope'], 'site', self::SITE_LIMIT ) ) {
				return;
			}

			$event_context = in_array( $event, [ 'otp.verify_failed', 'otp.queued', 'otp.delivery_skipped', 'otp.queue_recovered', 'otp.queue_deferred' ], true )
				? $context
				: $context + [ 'ip_fingerprint' => $logger->fingerprint( $ip, 'ip' ) ];
			$logger->log( $level, $event, $event_context );
		} catch ( Throwable $throwable ) {
			// Fail closed for emission, not for the user operation being observed.
			unset( $throwable );
		}
	}

	/**
	 * Authorized first activation only. Emission and report reads never call this.
	 * INSERT IGNORE preserves every existing counter, including corrupt state.
	 */
	public static function initialize_diagnostics(): bool {
		return (bool) self::diagnostic_operation(
			static function ( \wpdb $database ): bool {
				$payload = wp_json_encode( [ 'v' => 1, 'slots' => [], 'limited' => false ] );
				if ( false === $payload || false === $database->query(
					$database->prepare(
						'INSERT IGNORE INTO %i (bucket_key, scope, hits, reset_at, updated_at, payload)
						VALUES (%s, %s, 0, DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND), UTC_TIMESTAMP(), %s)',
						$database->prefix . 'pinova_rate_limits',
						self::diagnostic_bucket_key(),
						self::DIAGNOSTIC_SCOPE,
						self::WINDOW_SECONDS,
						$payload
					)
				) ) {
					return false;
				}
				return null !== self::diagnostic_state( $database );
			},
			false
		);
	}

	/** Shared, best-effort observation admission; never an authentication decision. */
	public static function admit_diagnostic( string $flow, int $original_deadline ): bool {
		if ( ! preg_match( '/\A[a-f0-9]{32}\z/D', $flow ) || $original_deadline <= time() || $original_deadline > time() + self::DIAGNOSTIC_FLOW_SECONDS ) {
			return false;
		}
		return (bool) self::diagnostic_operation(
			static function ( \wpdb $database ) use ( $flow, $original_deadline ): bool {
				$state = self::diagnostic_state( $database );
				if ( null === $state || $original_deadline <= $state['now'] || $original_deadline > $state['now'] + self::DIAGNOSTIC_FLOW_SECONDS ) {
					return false;
				}
				$slot    = (int) ( hexdec( substr( hash_hmac( 'sha256', 'pinova:diagnostic-flow:v1:' . $flow, wp_salt( 'auth' ) ), 0, 4 ) ) % self::SOURCE_SLOTS );
				$payload = $state['decoded'];
				foreach ( $payload['slots'] as $index => $counter ) {
					if ( $counter[1] <= $state['now'] ) {
						unset( $payload['slots'][ $index ] );
					}
				}
				$rollover = $state['reset'] <= $state['now'];
				$hits     = $rollover ? 0 : $state['hits'];
				$reset    = $rollover ? $state['now'] + self::WINDOW_SECONDS : $state['reset'];
				if ( $rollover ) {
					$payload['limited'] = false;
				}
				$counter = $payload['slots'][ $slot ] ?? [ 0, $original_deadline ];
				if ( self::DIAGNOSTIC_SITE_LIMIT <= $hits || self::DIAGNOSTIC_FLOW_LIMIT <= $counter[0] ) {
					// At most one persisted coverage bit per window; never a drop counter.
					if ( ! $payload['limited'] ) {
						$payload['limited'] = true;
						self::diagnostic_cas( $database, $state, $hits, $reset, $payload );
					}
					return false;
				}
				// Colliding live flows share the same conservative lifetime allowance.
				$payload['slots'][ $slot ] = [ $counter[0] + 1, max( $counter[1], $original_deadline ) ];
				return self::diagnostic_cas( $database, $state, $hits + 1, $reset, $payload, $original_deadline );
			},
			false
		);
	}

	/** @return array<string, bool|int|string|null> */
	public static function diagnostics_snapshot(): array {
		$unknown = [
			'read_success'   => false,
			'coverage'       => 'unknown',
			'admitted'       => null,
			'site_limit'     => self::DIAGNOSTIC_SITE_LIMIT,
			'flow_limit'     => self::DIAGNOSTIC_FLOW_LIMIT,
			'window_seconds' => self::WINDOW_SECONDS,
			'active_slots'   => null,
		];
		return (array) self::diagnostic_operation(
			static function ( \wpdb $database ) use ( $unknown ): array {
				$state = self::diagnostic_state( $database );
				if ( null === $state ) {
					return $unknown;
				}
				$active = 0;
				foreach ( $state['decoded']['slots'] as $counter ) {
					$active += $counter[1] > $state['now'] ? 1 : 0;
				}
				$current = $state['reset'] > $state['now'];
				return array_merge(
					$unknown,
					[
						'read_success' => true,
						'coverage'     => $current && $state['decoded']['limited'] ? 'incomplete' : 'sampled',
						'admitted'     => $current ? $state['hits'] : 0,
						'active_slots' => $active,
					]
				);
			},
			$unknown
		);
	}

	/**
	 * @param callable(\wpdb):mixed $operation
	 * @param mixed $fallback
	 * @return mixed
	 */
	private static function diagnostic_operation( callable $operation, $fallback ) {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return $fallback;
		}
		$database = $wpdb;
		$previous = null;
		try {
			$previous = $database->suppress_errors();
			return $operation( $database );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return $fallback;
		} finally {
			try {
				if ( null !== $previous ) {
					$database->suppress_errors( $previous );
				}
			} catch ( Throwable $throwable ) {
				unset( $throwable );
			}
		}
	}

	/** Bounded canonical state; malformed or lost counters are never repaired. */
	private static function diagnostic_state( \wpdb $database ): ?array {
		$row = $database->get_row(
			$database->prepare(
				"SELECT hits, reset_at, updated_at, CASE WHEN OCTET_LENGTH(payload) <= %d THEN payload ELSE NULL END AS payload,
				TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', UTC_TIMESTAMP()) AS now_epoch,
				TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', reset_at) AS reset_epoch,
				TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', updated_at) AS updated_epoch
				FROM %i WHERE bucket_key = %s AND scope = %s LIMIT 1",
				self::DIAGNOSTIC_MAX_BYTES,
				$database->prefix . 'pinova_rate_limits',
				self::diagnostic_bucket_key(),
				self::DIAGNOSTIC_SCOPE
			),
			ARRAY_A
		);
		if ( $database->last_error || ! is_array( $row ) || ! isset( $row['payload'], $row['reset_at'], $row['updated_at'] ) || ! is_string( $row['payload'] ) || strlen( $row['payload'] ) > self::DIAGNOSTIC_MAX_BYTES ) {
			return null;
		}
		foreach ( [ 'hits', 'now_epoch', 'reset_epoch', 'updated_epoch' ] as $key ) {
			if ( ! isset( $row[ $key ] ) || ! preg_match( '/\A(?:0|[1-9][0-9]{0,10})\z/D', (string) $row[ $key ] ) ) {
				return null;
			}
		}
		$now     = (int) $row['now_epoch'];
		$reset   = (int) $row['reset_epoch'];
		$updated = (int) $row['updated_epoch'];
		$hits    = (int) $row['hits'];
		if ( abs( $now - time() ) > 2 || 0 >= $updated || 0 >= $reset || $updated > $now || $reset < $updated || $reset > $now + self::WINDOW_SECONDS || self::DIAGNOSTIC_SITE_LIMIT < $hits
			|| gmdate( 'Y-m-d H:i:s', $reset ) !== $row['reset_at'] || gmdate( 'Y-m-d H:i:s', $updated ) !== $row['updated_at'] ) {
			return null;
		}
		$decoded = json_decode( $row['payload'], true, 8 );
		if ( ! is_array( $decoded ) || [ 'v', 'slots', 'limited' ] !== array_keys( $decoded ) || 1 !== $decoded['v'] || ! is_array( $decoded['slots'] )
			|| count( $decoded['slots'] ) > self::SOURCE_SLOTS || ! is_bool( $decoded['limited'] ) || wp_json_encode( $decoded ) !== $row['payload'] ) {
			return null;
		}
		foreach ( $decoded['slots'] as $slot => $counter ) {
			if ( ! is_int( $slot ) || 0 > $slot || self::SOURCE_SLOTS <= $slot || ! is_array( $counter ) || [ 0, 1 ] !== array_keys( $counter )
				|| ! is_int( $counter[0] ) || 1 > $counter[0] || self::DIAGNOSTIC_FLOW_LIMIT < $counter[0]
				|| ! is_int( $counter[1] ) || 1 > $counter[1] || $counter[1] > $now + self::DIAGNOSTIC_FLOW_SECONDS ) {
				return null;
			}
		}
		return [ 'row' => $row, 'hits' => $hits, 'now' => $now, 'reset' => $reset, 'decoded' => $decoded ];
	}

	/** Exactly one affected row admits; a reconnect/replay cannot win a stale CAS. */
	private static function diagnostic_cas( \wpdb $database, array $state, int $hits, int $reset, array $decoded, ?int $deadline = null ): bool {
		$payload = wp_json_encode( $decoded );
		if ( false === $payload || strlen( $payload ) > self::DIAGNOSTIC_MAX_BYTES ) {
			return false;
		}
		return 1 === $database->query(
			$database->prepare(
				"UPDATE %i SET hits = %d, reset_at = %s, updated_at = UTC_TIMESTAMP(), payload = %s
				WHERE bucket_key = %s AND scope = %s AND hits = %d AND reset_at = %s AND updated_at = %s AND BINARY payload = BINARY %s
				AND TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', UTC_TIMESTAMP()) BETWEEN %d AND %d
				AND %d > TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', UTC_TIMESTAMP())",
				$database->prefix . 'pinova_rate_limits',
				$hits,
				gmdate( 'Y-m-d H:i:s', $reset ),
				$payload,
				self::diagnostic_bucket_key(),
				self::DIAGNOSTIC_SCOPE,
				$state['hits'],
				$state['row']['reset_at'],
				$state['row']['updated_at'],
				$state['row']['payload'],
				$state['now'],
				$state['now'] + 2,
				min( $deadline ?? $state['now'] + 3, $reset )
			)
		);
	}

	/** Stable reserved identity; no configuration or database access. */
	public static function diagnostic_bucket_key(): string {
		return hash( 'sha256', 'pinova:diagnostics-budget:v1' );
	}

	private static function consume( string $scope, string $slot, int $limit ): bool {
		global $wpdb;

		$table      = $wpdb->prefix . 'pinova_rate_limits';
		$bucket_key = hash( 'sha256', 'pinova:event-throttle:' . $scope . ':' . $slot );
		$suppressed = $wpdb->suppress_errors();

		try {
			$inserted = $wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO %i (bucket_key, scope, hits, reset_at, updated_at)
					VALUES (%s, %s, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
					$table,
					$bucket_key,
					$scope
				)
			);

			if ( false === $inserted ) {
				return false;
			}

			// Admission is this atomic write's affected-row count, never a racy readback.
			return 1 === $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET
					hits = IF(reset_at <= UTC_TIMESTAMP(), 1, hits + 1),
					reset_at = IF(reset_at <= UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND), reset_at),
					updated_at = UTC_TIMESTAMP()
					WHERE bucket_key = %s AND (reset_at <= UTC_TIMESTAMP() OR hits < %d)',
					$table,
					self::WINDOW_SECONDS,
					$bucket_key,
					$limit
				)
			);
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}
}
