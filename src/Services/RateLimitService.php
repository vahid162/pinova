<?php

namespace Pinova\Services;

use Pinova\Exceptions\RateLimitException;
use Pinova\Logging\Logger;

class RateLimitService {

	/**
	 * Atomically consume one attempt from a fixed-window bucket.
	 *
	 * @throws RateLimitException
	 */
	public static function consume( string $scope, string $subject, int $limit, int $window ): void {
		global $wpdb;

		if ( '' === $subject || $limit < 1 || $window < 1 ) {
			return;
		}

		$table = $wpdb->prefix . 'pinova_rate_limits';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		$now        = time();
		$reset_at   = gmdate( 'Y-m-d H:i:s', $now + $window );
		$bucket_key = hash_hmac( 'sha256', $scope . ':' . $subject, wp_salt( 'auth' ) );

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (`bucket_key`, `scope`, `hits`, `reset_at`, `updated_at`)
			 VALUES (%s, %s, 1, %s, UTC_TIMESTAMP())
			 ON DUPLICATE KEY UPDATE
			 `hits` = IF(`reset_at` <= UTC_TIMESTAMP(), 1, `hits` + 1),
			 `reset_at` = IF(`reset_at` <= UTC_TIMESTAMP(), VALUES(`reset_at`), `reset_at`),
			 `updated_at` = UTC_TIMESTAMP()',
				$table,
				$bucket_key,
				$scope,
				$reset_at
			)
		);

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT `hits`, `reset_at` FROM %i WHERE `bucket_key` = %s',
				$table,
				$bucket_key
			)
		);

		if ( $row && (int) $row->hits > $limit ) {
			$retry_after = max( 1, strtotime( $row->reset_at . ' UTC' ) - $now );

			// Record the transition into a blocked state once per window. Logging
			// every rejected request would let an attacker amplify the log table.
			if ( (int) $row->hits === $limit + 1 ) {
				Logger::instance()->warning(
					'security.rate_limited',
					[
						'scope'               => $scope,
						'subject_fingerprint' => Logger::instance()->fingerprint( $subject, 'rate_limit_subject' ),
						'attempts'            => (int) $row->hits,
						'retry_after'         => $retry_after,
					]
				);
			}
			throw new RateLimitException( $retry_after );
		}
	}

	public static function password( string $ip, string $identifier ): void {
		self::consume( 'password_ip', $ip, 20, 15 * MINUTE_IN_SECONDS );
		self::consume( 'password_pair', $ip . '|' . $identifier, 5, 15 * MINUTE_IN_SECONDS );
	}

	public static function otp( string $ip, string $identifier ): void {
		self::consume( 'otp_ip', $ip, 8, HOUR_IN_SECONDS );
		self::consume( 'otp_identifier', $identifier, 5, HOUR_IN_SECONDS );
	}

	public static function cleanup(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'pinova_rate_limits';
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE `reset_at` < (UTC_TIMESTAMP() - INTERVAL 1 DAY)', $table ) );
	}
}
