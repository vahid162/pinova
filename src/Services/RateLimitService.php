<?php

namespace Pinova\Services;

use Pinova\Exceptions\RateLimitException;
use Pinova\Exceptions\RateLimitUnavailableException;
use Pinova\Helpers\JWT;
use Pinova\Logging\Logger;

class RateLimitService {
	private const OTP_CLAIM_LEASE = 60;
	/** @var array<string,string> */
	private static array $held_claims = [];

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

	/**
	 * Reserve a durable, opaque decoy flow for the same lifetime as a real OTP.
	 * The existing cleanup removes expired rows after one day.
	 *
	 * @return array{0:string,1:int} Flow ID and absolute UTC expiry.
	 */
	public static function decoy_flow( string $identifier, string $purpose, string $ip ): array {
		global $wpdb;
		$identifier = self::canonical_identifier( $identifier );

		if ( ! in_array( $purpose, [ 'authenticate', 'forget' ], true ) || ! function_exists( 'openssl_encrypt' ) ) {
			throw new RateLimitUnavailableException( 'OTP delivery encryption is unavailable.' );
		}

		$table      = $wpdb->prefix . 'pinova_rate_limits';
		$bucket_key = hash_hmac( 'sha256', 'otp_decoy:' . $purpose . ':' . $identifier, wp_salt( 'auth' ) );
		$flow_id    = bin2hex( random_bytes( 16 ) );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + JWT::DEFAULT_TTL );
		$existing   = $wpdb->get_row( $wpdb->prepare( 'SELECT `scope`, `payload`, `reset_at` FROM %i WHERE `bucket_key` = %s', $table, $bucket_key ) );
		if ( $wpdb->last_error ) {
			throw new RateLimitUnavailableException( 'The OTP delivery queue could not be read.' );
		}
		if ( $existing && ( str_starts_with( (string) $existing->payload, 'cancelled:' ) ||
			( strtotime( $existing->reset_at . ' UTC' ) <= time() && str_starts_with( (string) $existing->payload, 'processing:' ) ) ) ) {
			if ( ! preg_match( '/\A[a-f0-9]{32}\z/', (string) $existing->scope ) || ! self::lock_queued_otp( $existing->scope ) ) {
				throw new RateLimitUnavailableException( 'An OTP delivery is still active.' );
			}
			try {
				$retired = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE `bucket_key` = %s AND `scope` = %s AND `payload` = %s', $table, $bucket_key, $existing->scope, $existing->payload ) );
				if ( false === $retired ) {
					throw new RateLimitUnavailableException( 'The stale OTP delivery could not be retired.' );
				}
			} finally {
				self::unlock_queued_otp( $existing->scope );
			}
		}
		// RC4 did not hold a database lock. Its opaque marker cannot be safely
		// distinguished from a live sender until the flow and grace period pass.
		$legacy_cleared = $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE `bucket_key` = %s AND `payload` = %s AND `reset_at` <= UTC_TIMESTAMP() - INTERVAL %d SECOND', $table, $bucket_key, 'processing', self::OTP_CLAIM_LEASE )
		);
		if ( false === $legacy_cleared ) {
			throw new RateLimitUnavailableException( 'The legacy OTP queue could not be checked.' );
		}
		$written    = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (`bucket_key`, `scope`, `hits`, `reset_at`, `updated_at`, `payload`)
				 VALUES (%s, %s, 1, %s, UTC_TIMESTAMP(), NULL)
				 ON DUPLICATE KEY UPDATE
				 `scope` = IF(`reset_at` <= UTC_TIMESTAMP() AND (`payload` IS NULL OR (`payload` NOT LIKE 'processing:%%' AND `payload` NOT LIKE 'cancelled:%%' AND `payload` <> 'processing')), VALUES(`scope`), `scope`),
				 `payload` = IF(`scope` = VALUES(`scope`), NULL, `payload`),
				 `reset_at` = IF(`scope` = VALUES(`scope`), VALUES(`reset_at`), `reset_at`),
				 `updated_at` = IF(`payload` IS NULL, UTC_TIMESTAMP(), `updated_at`)",
				$table,
				$bucket_key,
				$flow_id,
				$expires_at
			)
		);
		if ( false === $written ) {
			throw new RateLimitUnavailableException( 'The decoy flow could not be persisted.' );
		}

		$row = $wpdb->get_row(
		$wpdb->prepare( 'SELECT `scope`, `reset_at`, `payload` FROM %i WHERE `bucket_key` = %s', $table, $bucket_key )
		);
		if ( ! $row || ! preg_match( '/\A[a-f0-9]{32}\z/', (string) $row->scope ) || strtotime( $row->reset_at . ' UTC' ) <= time() || str_starts_with( (string) $row->payload, 'cancelled:' ) ) {
			throw new RateLimitUnavailableException( 'The decoy flow could not be read.' );
		}
		$nonce      = random_bytes( 12 );
		$plain      = wp_json_encode( [ $identifier, $purpose, $ip ] );
		$tag        = '';
		$ciphertext = is_string( $plain )
			? openssl_encrypt( $plain, 'aes-256-gcm', self::queue_key(), OPENSSL_RAW_DATA, $nonce, $tag, $row->scope )
			: false;
		if ( ! is_string( $ciphertext ) || 16 !== strlen( $tag ) ) {
			throw new RateLimitUnavailableException( 'OTP delivery encryption failed.' );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary authenticated ciphertext needs safe SQL transport.
		$payload = base64_encode( $nonce . $tag . $ciphertext );
		$queued  = $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET `payload` = %s WHERE `bucket_key` = %s AND `scope` = %s AND `reset_at` > UTC_TIMESTAMP() AND `payload` IS NULL', $table, $payload, $bucket_key, $row->scope )
		);
		if ( false === $queued ) {
			throw new RateLimitUnavailableException( 'The OTP delivery queue could not be written.' );
		}

		return [ $row->scope, (int) strtotime( $row->reset_at . ' UTC' ) ];
	}

	/** @return array{identifier:string,purpose:string,ip:string,deadline:int,claim_token:string}|null */
	public static function claim_queued_otp( string $flow_id ): ?array {
		if ( ! preg_match( '/\A[a-f0-9]{32}\z/', $flow_id ) || ! self::lock_queued_otp( $flow_id ) ) {
			return null;
		}
		$keep_lock = false;
		try {
			$result = self::claim_queued_otp_locked( $flow_id );
			if ( null !== $result ) {
				self::$held_claims[ $flow_id ] = $result['claim_token'];
				$keep_lock = true;
			}
			return $result;
		} finally {
			if ( ! $keep_lock ) {
				self::unlock_queued_otp( $flow_id );
			}
		}
	}

	/** @return array{identifier:string,purpose:string,ip:string,deadline:int,claim_token:string}|null */
	private static function claim_queued_otp_locked( string $flow_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'pinova_rate_limits';
		$rows  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT `bucket_key`, `payload`, `reset_at`, `updated_at` FROM %i WHERE `scope` = %s AND `reset_at` > UTC_TIMESTAMP() AND `payload` IS NOT NULL LIMIT 2', $table, $flow_id ),
			ARRAY_A
		);
		if ( $wpdb->last_error || ! is_array( $rows ) || 1 !== count( $rows ) ) {
			return null;
		}
		$row     = $rows[0];
		$stored  = (string) $row['payload'];
		$payload = $stored;
		if ( str_starts_with( $stored, 'processing:' ) ) {
			if ( strtotime( $row['updated_at'] . ' UTC' ) > time() - self::OTP_CLAIM_LEASE ) {
				return null;
			}
			$parts = explode( ':', $stored, 3 );
			if ( 3 !== count( $parts ) || ! preg_match( '/\A[a-f0-9]{16}\z/', $parts[1] ) ) {
				return null;
			}
			$payload = $parts[2];
		} elseif ( 'processing' === $stored || str_starts_with( $stored, 'cancelled:' ) || 'delivered' === $stored ) {
			return null;
		}

		$claim_token = bin2hex( random_bytes( 8 ) );
		$claimed     = $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET `payload` = %s, `updated_at` = UTC_TIMESTAMP() WHERE `bucket_key` = %s AND `payload` = %s AND `reset_at` > UTC_TIMESTAMP()', $table, 'processing:' . $claim_token . ':' . $payload, $row['bucket_key'], $stored )
		);
		if ( 1 !== $claimed ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode authenticated ciphertext before AES-GCM verification.
		$binary = base64_decode( $payload, true );
		if ( ! is_string( $binary ) || strlen( $binary ) < 29 || ! function_exists( 'openssl_decrypt' ) ) {
			self::finish_queued_otp( $flow_id, $claim_token );
			return null;
		}
		$plain = openssl_decrypt( substr( $binary, 28 ), 'aes-256-gcm', self::queue_key(), OPENSSL_RAW_DATA, substr( $binary, 0, 12 ), substr( $binary, 12, 16 ), $flow_id );
		$data  = is_string( $plain ) ? json_decode( $plain, true ) : null;
		if ( ! is_array( $data ) || 3 !== count( $data ) || ! is_string( $data[0] ?? null ) || ! is_string( $data[1] ?? null ) || ! is_string( $data[2] ?? null ) || ! in_array( $data[1], [ 'authenticate', 'forget' ], true ) ) {
			self::finish_queued_otp( $flow_id, $claim_token );
			return null;
		}

		return [
			'identifier'  => $data[0],
			'purpose'     => $data[1],
			'ip'          => $data[2],
			'deadline'    => (int) strtotime( $row['reset_at'] . ' UTC' ),
			'claim_token' => $claim_token,
		];
	}

	/** Allow another initiation to retry a failed delivery without changing its signed flow. */
	public static function release_queued_otp( string $flow_id, ?string $claim_token = null ): bool {
		global $wpdb;

		if ( ! preg_match( '/\A[a-f0-9]{32}\z/', $flow_id ) || ( null !== $claim_token && ! preg_match( '/\A[a-f0-9]{16}\z/', $claim_token ) ) ) {
			return false;
		}
		$table = $wpdb->prefix . 'pinova_rate_limits';
		$pattern = null === $claim_token ? 'processing:%' : 'processing:' . $claim_token . ':%';
		$released = $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET `payload` = NULL WHERE `scope` = %s AND `payload` LIKE %s AND `reset_at` > UTC_TIMESTAMP()', $table, $flow_id, $pattern )
		);
		self::unlock_claim( $flow_id, $claim_token );
		return false !== $released;
	}

	/** Finish a claim, or remove a cancellation after its worker exits. */
	public static function finish_queued_otp( string $flow_id, string $claim_token ): bool {
		global $wpdb;

		if ( ! preg_match( '/\A[a-f0-9]{32}\z/', $flow_id ) || ! preg_match( '/\A[a-f0-9]{16}\z/', $claim_token ) ) {
			return false;
		}
		$table = $wpdb->prefix . 'pinova_rate_limits';
		$pattern        = 'processing:' . $claim_token . ':%';
		$cancel_pattern = 'cancelled:' . $claim_token;
		$finished = $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET `payload` = %s WHERE `scope` = %s AND `payload` LIKE %s', $table, 'delivered', $flow_id, $pattern )
		);
		$cancelled = $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE `scope` = %s AND `payload` LIKE %s', $table, $flow_id, $cancel_pattern )
		);
		self::unlock_claim( $flow_id, $claim_token );
		return false !== $finished && false !== $cancelled;
	}

	/** A cancelled or missing claim must fail closed before provider delivery. */
	public static function queued_otp_is_active( string $flow_id, string $claim_token ): bool {
		global $wpdb;

		if ( ! preg_match( '/\A[a-f0-9]{32}\z/', $flow_id ) || ! preg_match( '/\A[a-f0-9]{16}\z/', $claim_token ) ) {
			return false;
		}
		$payload = $wpdb->get_var(
			$wpdb->prepare( 'SELECT `payload` FROM %i WHERE `scope` = %s AND `reset_at` > UTC_TIMESTAMP() LIMIT 1', $wpdb->prefix . 'pinova_rate_limits', $flow_id )
		);
		$prefix = 'processing:' . $claim_token . ':';
		return ! $wpdb->last_error && is_string( $payload ) && str_starts_with( $payload, $prefix );
	}

	/** Retire a consumed flow without dropping an in-flight delivery barrier. */
	public static function retire_queued_otp( string $flow_id ): bool {
		global $wpdb;

		if ( ! preg_match( '/\A[a-f0-9]{32}\z/', $flow_id ) ) {
			return false;
		}
		$table = $wpdb->prefix . 'pinova_rate_limits';
		$cancelled = $wpdb->query(
			$wpdb->prepare( "UPDATE %i SET `payload` = CONCAT('cancelled:', SUBSTRING_INDEX(SUBSTRING_INDEX(`payload`, ':', 2), ':', -1)) WHERE `scope` = %s AND `payload` LIKE 'processing:%%'", $table, $flow_id )
		);
		if ( false === $cancelled ) {
			return false;
		}
		$deleted = $wpdb->query(
			$wpdb->prepare( "DELETE FROM %i WHERE `scope` = %s AND (`payload` IS NULL OR (`payload` NOT LIKE 'cancelled:%%' AND `payload` NOT LIKE 'processing:%%' AND `payload` <> 'processing'))", $table, $flow_id )
		);
		return false !== $deleted;
	}

	/** @param string[] $identifiers */
	public static function delete_queued_for_identifiers( array $identifiers ): bool {
		global $wpdb;

		$keys = [];
		foreach ( $identifiers as $identifier ) {
			$identifier = self::canonical_identifier( $identifier );
			foreach ( [ 'authenticate', 'forget' ] as $purpose ) {
				$keys[] = hash_hmac( 'sha256', 'otp_decoy:' . $purpose . ':' . $identifier, wp_salt( 'auth' ) );
			}
		}
		if ( ! $keys ) {
			return true;
		}
		$table        = $wpdb->prefix . 'pinova_rate_limits';
		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$params       = array_merge( [ $table ], $keys );
		$cancel_sql   = "UPDATE %i SET `payload` = CONCAT('cancelled:', SUBSTRING_INDEX(SUBSTRING_INDEX(`payload`, ':', 2), ':', -1)) WHERE `bucket_key` IN ({$placeholders}) AND `payload` LIKE 'processing:%%'";
		$delete_sql   = "DELETE FROM %i WHERE `bucket_key` IN ({$placeholders}) AND (`payload` IS NULL OR (`payload` NOT LIKE 'processing:%%' AND `payload` NOT LIKE 'cancelled:%%' AND `payload` <> 'processing'))";
		$pending_sql  = "SELECT COUNT(*) FROM %i WHERE `bucket_key` IN ({$placeholders}) AND (`payload` LIKE 'cancelled:%%' OR `payload` = 'processing')";
		$expired_sql  = "DELETE FROM %i WHERE `bucket_key` IN ({$placeholders}) AND `reset_at` <= UTC_TIMESTAMP() AND `payload` NOT LIKE 'cancelled:%%' AND (`payload` <> 'processing' OR `reset_at` <= UTC_TIMESTAMP() - INTERVAL %d SECOND)";
		$cancelled    = $wpdb->query( $wpdb->prepare( $cancel_sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only fixed placeholders are generated.
		$deleted      = $wpdb->query( $wpdb->prepare( $delete_sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only fixed placeholders are generated.
		$cancelled_late = $wpdb->query( $wpdb->prepare( $cancel_sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Catch claims racing the first pass.
		$stale = $wpdb->get_results( $wpdb->prepare( "SELECT `bucket_key`, `scope`, `payload` FROM %i WHERE `bucket_key` IN ({$placeholders}) AND `payload` LIKE 'cancelled:%%'", $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only fixed placeholders are generated.
		if ( ! is_array( $stale ) || $wpdb->last_error ) {
			return false;
		}
		foreach ( $stale as $row ) {
			if ( ! preg_match( '/\A[a-f0-9]{32}\z/', (string) $row['scope'] ) || ! self::lock_queued_otp( $row['scope'] ) ) {
				continue;
			}
			try {
				$retired = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE `bucket_key` = %s AND `scope` = %s AND `payload` = %s', $table, $row['bucket_key'], $row['scope'], $row['payload'] ) );
				if ( false === $retired ) {
					return false;
				}
			} finally {
				self::unlock_queued_otp( $row['scope'] );
			}
		}
		$expired      = $wpdb->query( $wpdb->prepare( $expired_sql, array_merge( $params, [ self::OTP_CLAIM_LEASE ] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only fixed placeholders are generated.
		$pending      = $wpdb->get_var( $wpdb->prepare( $pending_sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only fixed placeholders are generated.
		return false !== $cancelled && false !== $deleted && false !== $cancelled_late && false !== $expired && '0' === (string) $pending;
	}

	private static function queue_key(): string {
		return hash_hmac( 'sha256', 'pinova-otp-delivery', wp_salt( 'auth' ), true );
	}

	/** A database connection owns the claim until its worker finishes or crashes. */
	private static function lock_queued_otp( string $flow_id ): bool {
		global $wpdb;

		$name = 'pinova_otp_' . $flow_id;
		$holder = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $name ) );
		if ( $wpdb->last_error || null !== $holder ) {
			return false;
		}
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
		return '1' === (string) $acquired;
	}

	private static function unlock_queued_otp( string $flow_id ): void {
		global $wpdb;

		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'pinova_otp_' . $flow_id ) );
	}

	private static function unlock_claim( string $flow_id, ?string $claim_token ): void {
		if ( ! isset( self::$held_claims[ $flow_id ] ) || ( null !== $claim_token && self::$held_claims[ $flow_id ] !== $claim_token ) ) {
			return;
		}
		unset( self::$held_claims[ $flow_id ] );
		self::unlock_queued_otp( $flow_id );
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
