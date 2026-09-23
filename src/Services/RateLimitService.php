<?php

namespace Pinova\Services;

use Pinova\Exceptions\RateLimitException;
use Pinova\Exceptions\RateLimitUnavailableException;
use Pinova\Helpers\JWT;
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
		$written    = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (`bucket_key`, `scope`, `hits`, `reset_at`, `updated_at`, `payload`)
				 VALUES (%s, %s, 1, %s, UTC_TIMESTAMP(), NULL)
				 ON DUPLICATE KEY UPDATE
				 `scope` = IF(`reset_at` <= UTC_TIMESTAMP(), VALUES(`scope`), `scope`),
				 `payload` = IF(`reset_at` <= UTC_TIMESTAMP(), NULL, `payload`),
				 `reset_at` = IF(`reset_at` <= UTC_TIMESTAMP(), VALUES(`reset_at`), `reset_at`),
				 `updated_at` = UTC_TIMESTAMP()',
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
			$wpdb->prepare( 'SELECT `scope`, `reset_at` FROM %i WHERE `bucket_key` = %s', $table, $bucket_key )
		);
		if ( ! $row || $wpdb->last_error || ! preg_match( '/\A[a-f0-9]{32}\z/', (string) $row->scope ) || strtotime( $row->reset_at . ' UTC' ) <= time() ) {
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

	/** @return array{identifier:string,purpose:string,ip:string,deadline:int}|null */
	public static function claim_queued_otp( string $flow_id ): ?array {
		global $wpdb;

		if ( ! preg_match( '/\A[a-f0-9]{32}\z/', $flow_id ) ) {
			return null;
		}
		$table = $wpdb->prefix . 'pinova_rate_limits';
		$rows  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT `bucket_key`, `payload`, `reset_at` FROM %i WHERE `scope` = %s AND `reset_at` > UTC_TIMESTAMP() AND `payload` IS NOT NULL AND `payload` <> %s LIMIT 2', $table, $flow_id, 'processing' ),
			ARRAY_A
		);
		if ( $wpdb->last_error || ! is_array( $rows ) || 1 !== count( $rows ) ) {
			return null;
		}
		$row = $rows[0];
		$claimed = $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET `payload` = %s WHERE `bucket_key` = %s AND `payload` = %s AND `reset_at` > UTC_TIMESTAMP()', $table, 'processing', $row['bucket_key'], $row['payload'] )
		);
		if ( 1 !== $claimed ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode authenticated ciphertext before AES-GCM verification.
		$binary = base64_decode( (string) $row['payload'], true );
		if ( ! is_string( $binary ) || strlen( $binary ) < 29 || ! function_exists( 'openssl_decrypt' ) ) {
			return null;
		}
		$plain = openssl_decrypt( substr( $binary, 28 ), 'aes-256-gcm', self::queue_key(), OPENSSL_RAW_DATA, substr( $binary, 0, 12 ), substr( $binary, 12, 16 ), $flow_id );
		$data  = is_string( $plain ) ? json_decode( $plain, true ) : null;
		if ( ! is_array( $data ) || 3 !== count( $data ) || ! is_string( $data[0] ?? null ) || ! is_string( $data[1] ?? null ) || ! is_string( $data[2] ?? null ) || ! in_array( $data[1], [ 'authenticate', 'forget' ], true ) ) {
			return null;
		}

		return [
			'identifier' => $data[0],
			'purpose'    => $data[1],
			'ip'         => $data[2],
			'deadline'   => (int) strtotime( $row['reset_at'] . ' UTC' ),
		];
	}

	/** Allow another initiation to retry a failed delivery without changing its signed flow. */
	public static function release_queued_otp( string $flow_id ): bool {
		global $wpdb;

		if ( ! preg_match( '/\A[a-f0-9]{32}\z/', $flow_id ) ) {
			return false;
		}
		$table = $wpdb->prefix . 'pinova_rate_limits';
		return false !== $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET `payload` = NULL WHERE `scope` = %s AND `payload` = %s AND `reset_at` > UTC_TIMESTAMP()', $table, $flow_id, 'processing' )
		);
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
		$query = 'DELETE FROM %i WHERE `bucket_key` IN (' . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ')';
		return false !== $wpdb->query( $wpdb->prepare( $query, array_merge( [ $wpdb->prefix . 'pinova_rate_limits' ], $keys ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only fixed placeholders are generated.
	}

	private static function queue_key(): string {
		return hash_hmac( 'sha256', 'pinova-otp-delivery', wp_salt( 'auth' ), true );
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
