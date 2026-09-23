<?php

namespace Pinova\Logging;

use Pinova\Helpers\IP;
use Throwable;

/** Bounded observational events; never an authentication access decision. */
final class EventThrottle {

	private const WINDOW_SECONDS = 900;
	private const SITE_LIMIT     = 100;
	private const SOURCE_SLOTS   = 1024;

	private const EVENTS = [
		'auth.logout_rejected'          => [
			'scope' => 'log_logout_rejected',
			'level' => 'notice',
		],
		'auth.password_reset_failed'    => [
			'scope' => 'log_reset_failed',
			'level' => 'notice',
		],
		'auth.password_reset_succeeded' => [
			'scope' => 'log_reset_succeeded',
			'level' => 'info',
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
			if ( ! $logger->is_enabled( $config['level'] ) ) {
				return;
			}

			$ip     = IP::get();
			$source = '' === $ip ? 'unknown' : inet_pton( $ip );
			$hash   = hash_hmac( 'sha256', (string) $source, wp_salt( 'auth' ) );
			$slot   = hexdec( substr( $hash, 0, 4 ) ) % self::SOURCE_SLOTS;

			// Fixed slots bound storage even if cleanup stops. Collisions only suppress
			// observations; they never deny authentication or increase the log budget.
			if ( ! self::consume( $config['scope'], (string) $slot, 1 )
				|| ! self::consume( $config['scope'], 'site', self::SITE_LIMIT ) ) {
				return;
			}

			$logger->log( $config['level'], $event, $context + [ 'ip_fingerprint' => $logger->fingerprint( $ip, 'ip' ) ] );
		} catch ( Throwable $throwable ) {
			// Fail closed for emission, not for the user operation being observed.
			unset( $throwable );
		}
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
