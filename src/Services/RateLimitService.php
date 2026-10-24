<?php

namespace Pinova\Services;

use Pinova\Exceptions\RateLimitException;
use Pinova\Exceptions\RateLimitUnavailableException;
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
			throw new RateLimitUnavailableException( 'The authentication rate-limit table is unavailable.' );
		}

		$now        = time();
		$reset_at   = gmdate( 'Y-m-d H:i:s', $now + $window );
		$bucket_key = hash_hmac( 'sha256', $scope . ':' . $subject, wp_salt( 'auth' ) );

		$written = $wpdb->query(
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
		if ( false === $written ) {
			throw new RateLimitUnavailableException( 'The authentication rate-limit write failed.' );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT `hits`, `reset_at` FROM %i WHERE `bucket_key` = %s',
				$table,
				$bucket_key
			)
		);

		if ( ! $row || $wpdb->last_error ) {
			throw new RateLimitUnavailableException( 'The authentication rate-limit read failed.' );
		}

		if ( (int) $row->hits > $limit ) {
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

	// Compatibility facade: authentication callers retain their public API.
	/** @return array{0:string,1:int} Flow ID and absolute UTC expiry. */
	public static function decoy_flow( string $identifier, string $purpose, string $ip, ?int $user_id = null, bool $require_acknowledgement = false ): array {
		return OTPQueueStore::decoy_flow( $identifier, $purpose, $ip, $user_id, $require_acknowledgement );
	}

	public static function acknowledge_queued_otp( string $flow_id ): bool {
		return OTPQueueStore::acknowledge_queued_otp( $flow_id );
	}

	/** @return string[] */
	public static function recoverable_queued_otp(): array {
		return OTPQueueStore::recoverable_queued_otp();
	}

	public static function queue_health(): array {
		return OTPQueueStore::queue_health();
	}

	public static function observe_deferred_queued_otp( string $flow_id ): void {
		OTPQueueStore::observe_deferred_queued_otp( $flow_id );
	}

	public static function observe_expired_acknowledged_queue(): void {
		OTPQueueStore::observe_expired_acknowledged_queue();
	}

	public static function observe_expired_queued_otp( string $flow_id ): void {
		OTPQueueStore::observe_expired_queued_otp( $flow_id );
	}

	/** @return array{identifier:string,purpose:string,ip:string,deadline:int,claim_token:string,user_id:?int}|null */
	public static function claim_queued_otp( string $flow_id ): ?array {
		return OTPQueueStore::claim_queued_otp( $flow_id );
	}

	public static function release_queued_otp( string $flow_id, ?string $claim_token = null ): bool {
		return OTPQueueStore::release_queued_otp( $flow_id, $claim_token );
	}

	public static function finish_queued_otp( string $flow_id, string $claim_token ): bool {
		return OTPQueueStore::finish_queued_otp( $flow_id, $claim_token );
	}

	public static function queued_otp_is_active( string $flow_id, string $claim_token ): bool {
		return OTPQueueStore::queued_otp_is_active( $flow_id, $claim_token );
	}

	public static function retire_queued_otp( string $flow_id ): bool {
		return OTPQueueStore::retire_queued_otp( $flow_id );
	}

	/** @param string[] $identifiers */
	public static function delete_queued_for_identifiers( array $identifiers ): bool {
		return OTPQueueStore::delete_queued_for_identifiers( $identifiers );
	}

	public static function delete_queued_for_user( int $user_id, ?string $flow = null ): bool {
		return OTPQueueStore::delete_queued_for_user( $user_id, $flow );
	}

	private static function canonical_identifier( string $identifier ): string {
		return is_email( $identifier ) ? strtolower( $identifier ) : $identifier;
	}

	public static function password( string $ip, string $identifier ): void {
		$identifier = self::canonical_identifier( $identifier );
		self::consume( 'password_ip', $ip, 20, 15 * MINUTE_IN_SECONDS );
		self::consume( 'password_pair', $ip . '|' . $identifier, 5, 15 * MINUTE_IN_SECONDS );
	}

	public static function otp( string $ip, string $identifier ): void {
		$identifier = self::canonical_identifier( $identifier );
		self::consume( 'otp_ip', $ip, 8, HOUR_IN_SECONDS );
		self::consume( 'otp_identifier', $identifier, 5, HOUR_IN_SECONDS );
	}

	public static function cleanup(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'pinova_rate_limits';
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE `reset_at` < (UTC_TIMESTAMP() - INTERVAL 1 DAY)', $table ) );
	}
}
