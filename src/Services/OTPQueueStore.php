<?php

namespace Pinova\Services;

use Pinova\Exceptions\RateLimitUnavailableException;
use Pinova\Exceptions\OTPDeliveryDeferredException;
use Pinova\Helpers\JWT;
use Pinova\Logging\DiagnosticContext;
use Pinova\Logging\EventThrottle;

/** Durable OTP reservations and connection-owned delivery claims. */
final class OTPQueueStore {
	private const OTP_CLAIM_LEASE = 60;
	/** @var array<string,string> */
	private static array $held_claims = [];
	/** @var array<string,array{payload:string,updated_at:string,connection:string,deadline:int,attempted:bool,channel:?string,diagnostic:?DiagnosticContext}> */
	private static array $claim_snapshots = [];
	/** @var array{flow:string,token:string}|null */
	private static ?array $provider_scope           = null;
	private static bool $diagnostic_release_unknown = false;
	/** @var array<string,true> A flush gate only, never claim or provider permission. */
	private static array $diagnostic_flow_locks      = [];
	private static int $diagnostic_lock_acquisitions = 0;
	/** @var array<string,?string> At most the two site slots; null means acquisition ownership is unknown. */
	private static array $diagnostic_immediate_slots = [];

	/**
	 * Reserve a durable, opaque decoy flow for the same lifetime as a real OTP.
	 * The existing cleanup removes expired rows after one day.
	 *
	 * @return array{0:string,1:int} Flow ID and absolute UTC expiry.
	 */
	public static function decoy_flow( string $identifier, string $purpose, string $ip, ?int $user_id = null, bool $require_acknowledgement = false ): array {
		global $wpdb;
		$identifier = self::canonical_identifier( $identifier );

		if ( ! in_array( $purpose, [ 'authenticate', 'forget', 'verify_mobile' ], true ) || ! function_exists( 'openssl_encrypt' ) ) {
			throw new RateLimitUnavailableException( 'OTP delivery encryption is unavailable.' );
		}

		if ( ( 'verify_mobile' === $purpose ) !== ( null !== $user_id && $user_id > 0 ) ) {
			throw new RateLimitUnavailableException( 'Invalid OTP account binding.' );
		}

		$table      = $wpdb->prefix . 'pinova_rate_limits';
		$bucket_key = hash_hmac( 'sha256', 'otp_decoy:' . $purpose . ':' . ( null === $user_id ? $identifier : $user_id ), wp_salt( 'auth' ) );
		$flow_id    = bin2hex( random_bytes( 16 ) );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + JWT::DEFAULT_TTL );
		$existing   = $wpdb->get_row( $wpdb->prepare( 'SELECT `scope`, `payload`, `reset_at` FROM %i WHERE `bucket_key` = %s', $table, $bucket_key ) );
		if ( $wpdb->last_error ) {
			throw new RateLimitUnavailableException( 'The OTP delivery queue could not be read.' );
		}
		if ( null !== $user_id && ! $existing && ! MobileVerificationService::erase_pending( $user_id ) ) {
			throw new RateLimitUnavailableException( 'An earlier mobile delivery is still active.' );
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
		$written = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (`bucket_key`, `scope`, `hits`, `reset_at`, `updated_at`, `payload`)
				 VALUES (%s, %s, 1, %s, UTC_TIMESTAMP(), NULL)
				 ON DUPLICATE KEY UPDATE
				 `scope` = IF(`reset_at` <= UTC_TIMESTAMP() AND (`payload` IS NULL OR (LEFT(`payload`, 11) <> 'processing:' AND LEFT(`payload`, 10) <> 'cancelled:' AND `payload` <> 'processing')), VALUES(`scope`), `scope`),
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
		if ( null !== $user_id ) {
			MobileVerificationService::bind_flow( $user_id, $row->scope, new \Pinova\Objects\Identifier( $identifier ) );
		}
		$nonce      = random_bytes( 12 );
		$plain      = wp_json_encode( null === $user_id ? [ $identifier, $purpose, $ip ] : [ $identifier, $purpose, $ip, $user_id ] );
		$tag        = '';
		$ciphertext = is_string( $plain )
			? openssl_encrypt( $plain, 'aes-256-gcm', self::queue_key(), OPENSSL_RAW_DATA, $nonce, $tag, $row->scope )
			: false;
		if ( ! is_string( $ciphertext ) || 16 !== strlen( $tag ) ) {
			throw new RateLimitUnavailableException( 'OTP delivery encryption failed.' );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary authenticated ciphertext needs safe SQL transport.
		$payload = base64_encode( $nonce . $tag . $ciphertext );
		if ( $require_acknowledgement ) {
			$payload = 'reserved:' . $payload;
		}
		$queued = $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET `payload` = %s WHERE `bucket_key` = %s AND `scope` = %s AND `reset_at` > UTC_TIMESTAMP() AND `payload` IS NULL', $table, $payload, $bucket_key, $row->scope )
		);
		if ( false === $queued ) {
			throw new RateLimitUnavailableException( 'The OTP delivery queue could not be written.' );
		}
		if ( 1 === $queued && ! $require_acknowledgement ) {
			EventThrottle::log(
				'otp.queued',
				[
					'flow_id'           => $row->scope,
					'remaining_seconds' => max( 0, (int) strtotime( $row->reset_at . ' UTC' ) - time() ),
				]
			);
		}

		return [ $row->scope, (int) strtotime( $row->reset_at . ' UTC' ) ];
	}

	/** Make a prepared, successfully scheduled public response recoverable. */
	public static function acknowledge_queued_otp( string $flow_id ): bool {
		if ( ! preg_match( '/\A[a-f0-9]{32}\z/', $flow_id ) ) {
			return false;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'pinova_rate_limits';
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT `payload`, `reset_at` FROM %i WHERE `scope` = %s AND `reset_at` > UTC_TIMESTAMP() LIMIT 2', $table, $flow_id ), ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $rows ) || 1 !== count( $rows ) ) {
			return false;
		}
		$stored = (string) $rows[0]['payload'];
		if ( self::accepted_queue_payload( $stored ) ) {
			return true;
		}
		$cipher = str_starts_with( $stored, 'reserved:' ) ? substr( $stored, 9 ) : $stored;
		if ( ! preg_match( '/\A[A-Za-z0-9+\/]+=*\z/', $cipher ) ) {
			return false;
		}
		$written = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET `payload` = %s WHERE `scope` = %s AND `payload` = %s AND `reset_at` > UTC_TIMESTAMP()', $table, 'ready:' . $cipher, $flow_id, $stored ) );
		if ( false === $written ) {
			return false;
		}
		if ( 1 !== $written ) {
			// Another accepted initiation can win the same atomic transition.
			return self::queued_otp_is_acknowledged( $flow_id );
		}
		$diagnostic = OTPService::diagnostic_context( $flow_id, (int) strtotime( $rows[0]['reset_at'] . ' UTC' ) );
		if ( null !== $diagnostic ) {
			$diagnostic->observe(
				'queue_accepted',
				[
					'result'         => 'accepted',
					'error_code'     => 'none',
					'execution_mode' => 'request',
				]
			);
			OTPService::retain_diagnostic( $diagnostic );
		}
		EventThrottle::log(
			'otp.queued',
			[
				'flow_id'           => $flow_id,
				'remaining_seconds' => max( 0, (int) strtotime( $rows[0]['reset_at'] . ' UTC' ) - time() ),
			]
		);
		return true;
	}

	/** Read the competitor's accepted transition without hiding a read failure. */
	private static function queued_otp_is_acknowledged( string $flow_id ): bool {
		global $wpdb;
		$current = $wpdb->get_var( $wpdb->prepare( 'SELECT `payload` FROM %i WHERE `scope` = %s AND `reset_at` > UTC_TIMESTAMP() LIMIT 1', $wpdb->prefix . 'pinova_rate_limits', $flow_id ) );
		return ! $wpdb->last_error && is_string( $current ) && self::accepted_queue_payload( $current );
	}

	private static function accepted_queue_payload( string $payload ): bool {
		return str_starts_with( $payload, 'ready:' ) || str_starts_with( $payload, 'processing:' ) || 'delivered' === $payload;
	}

	/** Recovery never admits a legacy or unacknowledged reservation. @return string[] */
	public static function recoverable_queued_otp(): array {
		global $wpdb;
		$cron = _get_cron_array();
		if ( ! is_array( $cron ) ) {
			throw new RateLimitUnavailableException( 'The native OTP schedule could not be read.' );
		}
		$scheduled = [];
		foreach ( $cron as $hooks ) {
			foreach ( $hooks['pinova_otp_delivery'] ?? [] as $event ) {
				$args = $event['args'] ?? [];
				if ( is_array( $args ) && 1 === count( $args ) && is_string( $args[0] ?? null ) && preg_match( '/\A[a-f0-9]{32}\z/', $args[0] ) ) {
					$scheduled[ $args[0] ] = $args[0];
				}
			}
		}
		// Exclude native schedules and held claims before the finite candidate
		// window, so unrelated orphaned flows cannot be hidden behind them.
		$exclude = $scheduled ? ' AND `scope` NOT IN (' . implode( ', ', array_fill( 0, count( $scheduled ), '%s' ) ) . ')' : '';
		$params  = array_merge( [ $wpdb->prefix . 'pinova_rate_limits', 'processing:%:ready:%', self::OTP_CLAIM_LEASE ], array_values( $scheduled ) );
		$rows    = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The prepared array supplies three fixed values and one per generated %s placeholder.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only fixed placeholders are generated; flow IDs are prepared.
				"SELECT `scope` FROM %i WHERE `scope` REGEXP '^[a-f0-9]{32}$' AND `reset_at` > UTC_TIMESTAMP() AND (LEFT(`payload`, 6) = 'ready:' OR (LEFT(`payload`, 11) = 'processing:' AND `payload` LIKE %s AND `updated_at` <= UTC_TIMESTAMP() - INTERVAL %d SECOND)) AND IS_FREE_LOCK(CONCAT('pinova_otp_', `scope`)) = 1{$exclude} ORDER BY `reset_at`, `scope` LIMIT 32",
				$params
			)
		);
		if ( $wpdb->last_error || ! is_array( $rows ) ) {
			throw new RateLimitUnavailableException( 'The acknowledged OTP queue could not be read.' );
		}
		return array_values( array_unique( $rows ) );
	}

	/** Read-only bounded state facts; never decode or return queued identities. */
	public static function queue_health(): array {
		$health  = [
			'read_success'                       => false,
			'truncated'                          => false,
			'pending'                            => 0,
			'expired'                            => 0,
			'reserved'                           => 0,
			'processing'                         => 0,
			'legacy'                             => 0,
			'timing_unknown'                     => null,
			'oldest_pending_age_seconds'         => null,
			'earliest_pending_remaining_seconds' => null,
			'processing_held'                    => null,
			'processing_free_stale'              => null,
			'processing_free_recent'             => null,
			'processing_lock_unknown'            => null,
			'processing_age_unknown'             => null,
			'oldest_processing_age_seconds'      => null,
		];
		$unknown = $health;
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return $health;
		}
		$suppressed = $wpdb->suppress_errors();
		try {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT CASE WHEN LEFT(`payload`, 9) = 'reserved:' THEN 'reserved' WHEN LEFT(`payload`, 11) = 'processing:' THEN 'processing' WHEN LEFT(`payload`, 6) = 'ready:' THEN IF(`reset_at` <= UTC_TIMESTAMP(), 'expired', 'pending') ELSE 'legacy' END AS queue_state,
					TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), `reset_at`) AS remaining_seconds,
					TIMESTAMPDIFF(SECOND, `updated_at`, UTC_TIMESTAMP()) AS claim_age_seconds,
					IS_USED_LOCK(CONCAT('pinova_otp_', `scope`)) AS flow_holder
					FROM %i WHERE `scope` REGEXP '^[a-f0-9]{32}$' AND `payload` IS NOT NULL AND `payload` <> 'delivered' AND LEFT(`payload`, 10) <> 'cancelled:' ORDER BY `reset_at`, `scope` LIMIT 1001",
					$wpdb->prefix . 'pinova_rate_limits'
				),
				ARRAY_A
			);
			if ( $wpdb->last_error || ! is_array( $rows ) ) {
				return $health;
			}
			$health['read_success'] = true;
			$health['truncated']    = 1000 < count( $rows );
			foreach ( [ 'timing_unknown', 'processing_held', 'processing_free_stale', 'processing_free_recent', 'processing_lock_unknown', 'processing_age_unknown' ] as $key ) {
				$health[ $key ] = 0;
			}
			foreach ( array_slice( $rows, 0, 1000 ) as $row ) {
				$state = $row['queue_state'];
				if ( ! in_array( $state, [ 'pending', 'expired', 'reserved', 'processing', 'legacy' ], true ) ) {
					continue;
				}
				++$health[ $state ];
				if ( 'pending' === $state ) {
					$remaining = self::bounded_sql_seconds( $row['remaining_seconds'], JWT::DEFAULT_TTL );
					if ( null === $remaining ) {
						++$health['timing_unknown'];
						continue;
					}
					$age                                  = JWT::DEFAULT_TTL - $remaining;
					$health['oldest_pending_age_seconds'] = null === $health['oldest_pending_age_seconds'] ? $age : max( $health['oldest_pending_age_seconds'], $age );
					$health['earliest_pending_remaining_seconds'] = null === $health['earliest_pending_remaining_seconds'] ? $remaining : min( $health['earliest_pending_remaining_seconds'], $remaining );
				} elseif ( 'processing' === $state ) {
					// updated_at is only the current claim age, never request or acknowledgement time.
					$age = self::bounded_sql_seconds( $row['claim_age_seconds'], 7 * DAY_IN_SECONDS );
					if ( null === $age ) {
						++$health['timing_unknown'];
						++$health['processing_age_unknown'];
					} else {
						$health['oldest_processing_age_seconds'] = null === $health['oldest_processing_age_seconds'] ? $age : max( $health['oldest_processing_age_seconds'], $age );
					}
					$holder = $row['flow_holder'];
					if ( null === $holder ) {
						if ( null !== $age ) {
							++$health[ self::OTP_CLAIM_LEASE <= $age ? 'processing_free_stale' : 'processing_free_recent' ];
						}
					} elseif ( is_numeric( $holder ) && 0 < $holder ) {
						++$health['processing_held'];
					} else {
						++$health['processing_lock_unknown'];
					}
				}
			}
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			$health = $unknown;
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
		return $health;
	}

	/** SQL-projected relative UTC intervals; invalid clocks stay unknown, never clamped. */
	private static function bounded_sql_seconds( mixed $value, int $maximum ): ?int {
		return ( is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/\A[0-9]{1,7}\z/D', $value ) ) )
			&& 0 <= (int) $value && $maximum >= (int) $value ? (int) $value : null;
	}

	/** A failed claim is only a deferral while an acknowledged flow remains active. */
	public static function observe_deferred_queued_otp( string $flow_id ): void {
		global $wpdb;
		$suppressed = $wpdb->suppress_errors();
		try {
			$pending = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM %i WHERE `scope` = %s AND `reset_at` > UTC_TIMESTAMP() AND (LEFT(`payload`, 6) = 'ready:' OR `payload` LIKE %s) LIMIT 1", $wpdb->prefix . 'pinova_rate_limits', $flow_id, 'processing:%:ready:%' ) );
			if ( ! $wpdb->last_error && null !== $pending ) {
				EventThrottle::log(
					'otp.queue_deferred',
					[
						'operation' => 'queued_otp',
						'reason'    => 'claim_unavailable',
						'flow_id'   => $flow_id,
					]
				);
			}
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/** Keep a sampled missed-deadline fact even when its cron entry was lost. */
	public static function observe_expired_acknowledged_queue(): void {
		global $wpdb;
		$suppressed = $wpdb->suppress_errors();
		try {
			$flows = $wpdb->get_col( $wpdb->prepare( "SELECT `scope` FROM %i WHERE `scope` REGEXP '^[a-f0-9]{32}$' AND `reset_at` <= UTC_TIMESTAMP() AND LEFT(`payload`, 6) = 'ready:' ORDER BY `reset_at`, `scope` LIMIT 3", $wpdb->prefix . 'pinova_rate_limits' ) );
			if ( ! $wpdb->last_error && is_array( $flows ) ) {
				foreach ( $flows as $flow_id ) {
					self::observe_expired_queued_otp( $flow_id );
				}
			}
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/** Observe only an expired, unclaimed encrypted payload; never decode its identity. */
	public static function observe_expired_queued_otp( string $flow_id ): void {
		if ( ! preg_match( '/\A[a-f0-9]{32}\z/', $flow_id ) ) {
			return;
		}
		global $wpdb;
		$suppressed = $wpdb->suppress_errors();
		try {
			$expired = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1 FROM %i WHERE `scope` = %s AND `reset_at` <= UTC_TIMESTAMP() AND `payload` IS NOT NULL AND `payload` NOT IN ('delivered', 'processing') AND LEFT(`payload`, 9) <> 'reserved:' AND `payload` NOT LIKE %s AND `payload` NOT LIKE %s LIMIT 1",
					$wpdb->prefix . 'pinova_rate_limits',
					$flow_id,
					'processing:%',
					'cancelled:%'
				)
			);
			if ( ! $wpdb->last_error && null !== $expired ) {
				EventThrottle::log(
					'auth.request_failed',
					[
						'operation' => 'queued_otp',
						'reason'    => 'queue_expired',
						'flow_id'   => $flow_id,
					]
				);
			}
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/**
	 * @return array{identifier:string,purpose:string,ip:string,deadline:int,claim_token:string,user_id:?int}|null
	 * @throws \Pinova\Exceptions\OTPImmediateDeliveryDeferredException Admission callbacks may defer before the claim is returned.
	 */
	public static function claim_queued_otp( string $flow_id, ?callable $admission = null ): ?array {
		if ( ! preg_match( '/\A[a-f0-9]{32}\z/', $flow_id ) || ! self::lock_queued_otp( $flow_id ) ) {
			return null;
		}
		$keep_lock = false;
		try {
			$result = self::claim_queued_otp_locked( $flow_id, $admission );
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

	/** @return array{identifier:string,purpose:string,ip:string,deadline:int,claim_token:string,user_id:?int}|null */
	private static function claim_queued_otp_locked( string $flow_id, ?callable $admission ): ?array {
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
		// Immediate admission only accepts durable acknowledgement. Inspect under
		// the existing flow lock, without changing bytes or timestamps on denial.
		if ( null !== $admission && ! str_starts_with( $stored, 'ready:' ) ) {
			return null;
		}
		if ( str_starts_with( $stored, 'processing:' ) ) {
			if ( strtotime( $row['updated_at'] . ' UTC' ) > time() - self::OTP_CLAIM_LEASE ) {
				return null;
			}
			$parts = explode( ':', $stored, 3 );
			if ( 3 !== count( $parts ) || ! preg_match( '/\A[a-f0-9]{16}\z/', $parts[1] ) ) {
				return null;
			}
			$payload = $parts[2];
		} elseif ( 'processing' === $stored || str_starts_with( $stored, 'reserved:' ) || str_starts_with( $stored, 'cancelled:' ) || 'delivered' === $stored ) {
			return null;
		}
		$cipher  = str_starts_with( $payload, 'ready:' ) ? substr( $payload, 6 ) : $payload;
		$decoded = null;
		if ( null !== $admission ) {
			$decoded = self::decode_queued_payload( $cipher, $flow_id, $row['reset_at'] );
			if ( null === $decoded || true !== $admission( $decoded ) ) {
				return null;
			}
		}
		$connection = (string) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
		if ( self::database_read_failed() || '' === $connection ) {
			return null;
		}

		$claim_token = bin2hex( random_bytes( 8 ) );
		$claimed     = $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET `payload` = %s, `updated_at` = UTC_TIMESTAMP() WHERE `bucket_key` = %s AND `payload` = %s AND `reset_at` > UTC_TIMESTAMP()', $table, 'processing:' . $claim_token . ':' . $payload, $row['bucket_key'], $stored )
		);
		if ( 1 !== $claimed ) {
			return null;
		}
		self::$claim_snapshots[ $flow_id ] = [
			'payload'    => $stored,
			'updated_at' => $row['updated_at'],
			'connection' => $connection,
			'deadline'   => (int) strtotime( $row['reset_at'] . ' UTC' ),
			'attempted'  => false,
			'channel'    => null,
			'diagnostic' => null,
		];
		$decoded                           = $decoded ?? self::decode_queued_payload( $cipher, $flow_id, $row['reset_at'] );
		if ( null === $decoded ) {
			self::finish_queued_otp( $flow_id, $claim_token );
			unset( self::$claim_snapshots[ $flow_id ] );
			return null;
		}
		$decoded['claim_token'] = $claim_token;
		return $decoded;
	}

	/** @return array{identifier:string,purpose:string,ip:string,deadline:int,user_id:?int}|null */
	private static function decode_queued_payload( string $cipher, string $flow_id, string $reset_at ): ?array {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode authenticated ciphertext before AES-GCM verification.
		$binary = base64_decode( $cipher, true );
		if ( ! is_string( $binary ) || strlen( $binary ) < 29 || ! function_exists( 'openssl_decrypt' ) ) {
			return null;
		}
		$plain = openssl_decrypt( substr( $binary, 28 ), 'aes-256-gcm', self::queue_key(), OPENSSL_RAW_DATA, substr( $binary, 0, 12 ), substr( $binary, 12, 16 ), $flow_id );
		$data  = is_string( $plain ) ? json_decode( $plain, true ) : null;
		if ( ! is_array( $data ) || ! in_array( count( $data ), [ 3, 4 ], true ) || ! is_string( $data[0] ?? null ) || ! is_string( $data[1] ?? null ) || ! is_string( $data[2] ?? null ) || ! in_array( $data[1], [ 'authenticate', 'forget', 'verify_mobile' ], true ) ) {
			return null;
		}

		if ( ( 'verify_mobile' === $data[1] && ( 4 !== count( $data ) || ! is_int( $data[3] ) || $data[3] < 1 ) )
			|| ( 'verify_mobile' !== $data[1] && 3 !== count( $data ) ) ) {
			return null;
		}

		return [
			'user_id'    => $data[3] ?? null,
			'identifier' => $data[0],
			'purpose'    => $data[1],
			'ip'         => $data[2],
			'deadline'   => (int) strtotime( $reset_at . ' UTC' ),
		];
	}

	/** Restore only an untouched acknowledged claim while this connection owns it. */
	public static function defer_queued_otp( string $flow_id, string $claim_token ): bool {
		global $wpdb;
		try {
			$snapshot = self::$claim_snapshots[ $flow_id ] ?? null;
			if ( null === $snapshot || ! str_starts_with( $snapshot['payload'], 'ready:' ) || ! self::owns_queued_claim( $flow_id, $claim_token ) ) {
				return false;
			}
			$written = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET `payload` = %s, `updated_at` = %s WHERE `scope` = %s AND `payload` = %s',
					$wpdb->prefix . 'pinova_rate_limits',
					$snapshot['payload'],
					$snapshot['updated_at'],
					$flow_id,
					'processing:' . $claim_token . ':' . $snapshot['payload']
				)
			);
			return 1 === $written;
		} finally {
			self::unlock_claim( $flow_id, $claim_token );
		}
	}

	/** Row tokens alone do not survive a database reconnect as ownership proof. */
	public static function owns_queued_claim( string $flow_id, string $claim_token ): bool {
		global $wpdb;
		$connection = self::$claim_snapshots[ $flow_id ]['connection'] ?? '';
		if ( '' === $connection || ( self::$held_claims[ $flow_id ] ?? null ) !== $claim_token ) {
			return false;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT CONNECTION_ID() AS connection_id, IS_USED_LOCK(%s) AS holder', 'pinova_otp_' . $flow_id ), ARRAY_A );
		return ! $wpdb->last_error && is_array( $row ) && $connection === (string) $row['connection_id'] && $connection === (string) $row['holder'];
	}

	/** Observe token, expiry and both original connection leases in one final read. */
	public static function immediate_claim_is_active( string $flow_id, string $claim_token, array $lease ): bool {
		return self::active_claim( $flow_id, $claim_token, $lease );
	}

	/** One compound observation prevents reconnect/replay of an otherwise live token. */
	private static function active_claim( string $flow_id, string $claim_token, ?array $lease = null ): bool {
		return 'active' === self::claim_state( $flow_id, $claim_token, $lease );
	}

	/**
	 * Retain ownership evidence even when the bounded row window is empty or expired.
	 * Unavailable observations never establish a terminal cancellation or a permission.
	 *
	 * @return 'active'|'inactive'|'unavailable'
	 */
	private static function claim_state( string $flow_id, string $claim_token, ?array $lease = null ): string {
		global $wpdb;
		$connection = self::$claim_snapshots[ $flow_id ]['connection'] ?? '';
		if ( '' === $connection || ( self::$held_claims[ $flow_id ] ?? null ) !== $claim_token
			|| ( null !== $lease && ( ( $lease['connection'] ?? null ) !== $connection || ! is_string( $lease['name'] ?? null )
				|| 1 !== preg_match( '/\Apinova_otp_fast_[a-f0-9]{24}_[01]\z/D', $lease['name'] ) ) ) ) {
			return 'unavailable';
		}
		try {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT CONNECTION_ID() AS connection_id, IS_USED_LOCK(%s) AS immediate_slot_holder,
					IS_USED_LOCK(%s) AS flow_holder, queued.bucket_key, queued.payload,
					IF(queued.reset_at > UTC_TIMESTAMP(), 1, 0) AS unexpired
					FROM (SELECT 1) AS delivery_guard
					LEFT JOIN (SELECT bucket_key, LEFT(payload, 28) AS payload, reset_at FROM %i WHERE scope = %s LIMIT 2) AS queued ON 1 = 1',
					$lease['name'] ?? 'pinova_otp_' . $flow_id,
					'pinova_otp_' . $flow_id,
					$wpdb->prefix . 'pinova_rate_limits',
					$flow_id
				),
				ARRAY_A
			);
			if ( self::database_read_failed() || ! is_array( $rows ) || 1 !== count( $rows ) ) {
				return 'unavailable';
			}
			$row = $rows[0];
			if ( (string) ( $row['connection_id'] ?? '' ) !== $connection || (string) ( $row['immediate_slot_holder'] ?? '' ) !== $connection
				|| (string) ( $row['flow_holder'] ?? '' ) !== $connection || ! array_key_exists( 'bucket_key', $row ) ) {
				return 'unavailable';
			}
			if ( null === $row['bucket_key'] ) {
				return 'inactive';
			}
			if ( ! in_array( (string) ( $row['unexpired'] ?? '' ), [ '0', '1' ], true ) || ! array_key_exists( 'payload', $row ) ) {
				return 'unavailable';
			}
			return '1' === (string) $row['unexpired'] && is_string( $row['payload'] ) && 'processing:' . $claim_token . ':' === $row['payload'] ? 'active' : 'inactive';
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return 'unavailable';
		}
	}

	/** Pure request-local evidence; an absent/mismatched claim is unknown, not unattempted. */
	public static function provider_was_attempted( string $flow_id, string $claim_token ): ?bool {
		if ( ( self::$held_claims[ $flow_id ] ?? null ) !== $claim_token || ! isset( self::$claim_snapshots[ $flow_id ] ) ) {
			return null;
		}
		return self::$claim_snapshots[ $flow_id ]['attempted'];
	}

	/** @return array{flow:string,token:string}|null */
	public static function provider_scope(): ?array {
		return self::$provider_scope;
	}

	/**
	 * Scope is restored by ChannelService even for nested nonqueued sends.
	 *
	 * @param array{flow:string,token:string}|null $scope
	 * @return array{flow:string,token:string}|null
	 */
	public static function set_provider_scope( ?array $scope ): ?array {
		$previous             = self::$provider_scope;
		self::$provider_scope = $scope;
		return $previous;
	}

	/** Final owned handoff check; callers may only do pure preparation afterwards. */
	public static function guard_provider(): void {
		$scope = self::$provider_scope;
		if ( null === $scope ) {
			return;
		}
		if ( ! self::active_claim( $scope['flow'], $scope['token'] )
			|| time() >= ( self::$claim_snapshots[ $scope['flow'] ]['deadline'] ?? 0 ) ) {
			throw new OTPDeliveryDeferredException( 'Queued delivery permission ended.' );
		}
	}

	/** Mark immediately before handing control to a transport or opaque extension. */
	public static function mark_provider_attempted(): void {
		$scope = self::$provider_scope;
		if ( null !== $scope && null !== self::provider_was_attempted( $scope['flow'], $scope['token'] ) ) {
			self::$claim_snapshots[ $scope['flow'] ]['attempted'] = true;
		}
	}

	/** The channel is a finite code owned by the current ChannelService loop. */
	public static function set_provider_channel( string $channel ): void {
		$scope = self::$provider_scope;
		if ( null !== $scope && null !== self::provider_was_attempted( $scope['flow'], $scope['token'] ) ) {
			self::$claim_snapshots[ $scope['flow'] ]['channel'] = in_array( $channel, [ 'sms', 'bale', 'call', 'email' ], true ) ? $channel : null;
		}
	}

	/**
	 * A conservative in-memory flush gate, including unknown release outcomes.
	 *
	 * @phpstan-impure
	 */
	public static function has_held_claims(): bool {
		return self::$diagnostic_release_unknown || 0 < self::$diagnostic_lock_acquisitions
			|| [] !== self::$diagnostic_flow_locks || [] !== self::$diagnostic_immediate_slots || [] !== self::$held_claims;
	}

	/** Pure attachment to the exact existing in-memory claim. */
	public static function attach_diagnostic( string $flow_id, string $claim_token, DiagnosticContext $diagnostic ): void {
		if ( null !== self::provider_was_attempted( $flow_id, $claim_token ) ) {
			self::$claim_snapshots[ $flow_id ]['diagnostic'] = $diagnostic;
		}
	}

	/** @param array<string,int|string> $details */
	public static function observe_provider( string $stage, array $details = [] ): void {
		$scope = self::$provider_scope;
		if ( null !== $scope && null !== self::provider_was_attempted( $scope['flow'], $scope['token'] ) ) {
			$diagnostic = self::$claim_snapshots[ $scope['flow'] ]['diagnostic'];
			if ( null !== $diagnostic ) {
				$details['execution_mode'] = OTPService::diagnostic_mode( $scope['flow'] );
				$channel                   = self::$claim_snapshots[ $scope['flow'] ]['channel'];
				if ( null !== $channel ) {
					$details['channel'] = $channel;
				}
				$diagnostic->observe( $stage, $details );
			}
		}
	}

	/** Two nonblocking site slots; refuse recursive acquisition by this connection. */
	public static function acquire_immediate_slot(): ?array {
		global $wpdb;
		if ( 2 <= count( self::$diagnostic_immediate_slots ) ) {
			return null;
		}
		$connection = (string) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
		if ( $wpdb->last_error || '' === $connection ) {
			return null;
		}
		$prefix = 'pinova_otp_fast_' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix ), 0, 24 );
		$names  = [ $prefix . '_0', $prefix . '_1' ];
		foreach ( $names as $name ) {
			$holder = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $name ) );
			if ( self::database_read_failed() || $connection === (string) $holder ) {
				return null;
			}
		}
		++self::$diagnostic_lock_acquisitions;
		try {
			foreach ( $names as $name ) {
				$tracked  = array_key_exists( $name, self::$diagnostic_immediate_slots );
				$previous = self::$diagnostic_immediate_slots[ $name ] ?? null;
				// wpdb can reenter after GET_LOCK succeeds but before a lease is returned.
				self::$diagnostic_immediate_slots[ $name ] = null;
				$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
				if ( self::database_read_failed() ) {
					return null;
				}
				if ( '0' === (string) $acquired ) {
					if ( $tracked ) {
						self::$diagnostic_immediate_slots[ $name ] = $previous;
					} else {
						unset( self::$diagnostic_immediate_slots[ $name ] );
					}
					continue;
				}
				if ( '1' === (string) $acquired ) {
					$lease = [
						'name'       => $name,
						'connection' => $connection,
					];
					if ( self::owns_immediate_slot( $lease ) ) {
						unset( self::$diagnostic_immediate_slots[ $name ] );
						return $lease;
					}
					self::release_immediate_slot( $lease );
				}
				return null;
			}
		} finally {
			--self::$diagnostic_lock_acquisitions;
		}
		return null;
	}

	public static function owns_immediate_slot( array $lease ): bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT CONNECTION_ID() AS connection_id, IS_USED_LOCK(%s) AS holder', $lease['name'] ), ARRAY_A );
		return ! $wpdb->last_error && is_array( $row ) && $lease['connection'] === (string) $row['connection_id'] && $lease['connection'] === (string) $row['holder'];
	}

	public static function release_immediate_slot( array $lease ): void {
		self::release_immediate_slot_observed( $lease );
	}

	/** Positive release evidence is required before diagnostic flushing, never for sending. */
	public static function release_immediate_slot_observed( array $lease ): bool {
		if ( ! is_string( $lease['name'] ?? null ) || ! is_string( $lease['connection'] ?? null )
			|| 1 !== preg_match( '/\Apinova_otp_fast_[a-f0-9]{24}_[01]\z/D', $lease['name'] ) || '' === $lease['connection'] ) {
			return false;
		}
		$connection = array_key_exists( $lease['name'], self::$diagnostic_immediate_slots )
			? self::$diagnostic_immediate_slots[ $lease['name'] ] : $lease['connection'];
		self::$diagnostic_immediate_slots[ $lease['name'] ] = $connection;
		if ( ! self::release_named_lock_observed( $lease['name'], $connection ) ) {
			return false;
		}
		unset( self::$diagnostic_immediate_slots[ $lease['name'] ] );
		return true;
	}

	/** Never infer original release merely because the current connection changed. */
	private static function release_named_lock_observed( string $name, ?string $connection ): bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT CONNECTION_ID() AS connection_id, IS_USED_LOCK(%s) AS holder', $name ), ARRAY_A );
		if ( self::database_read_failed() || ! is_array( $row ) || ! array_key_exists( 'holder', $row )
			|| ! isset( $row['connection_id'] ) || ! ctype_digit( (string) $row['connection_id'] )
			|| ( null !== $row['holder'] && ( ! ctype_digit( (string) $row['holder'] ) || 0 >= (int) $row['holder'] ) ) ) {
			return false;
		}
		if ( null === $row['holder'] ) {
			return true;
		}
		if ( null === $connection ) {
			// Pre-claim flow locks have no claim snapshot. An unrelated holder
			// cannot prove that a prior, possibly swapped connection released it.
			if ( (string) $row['connection_id'] !== (string) $row['holder'] ) {
				return false;
			}
			$connection = (string) $row['connection_id'];
		}
		if ( $connection !== (string) $row['holder'] ) {
			return true;
		}
		if ( $connection !== (string) $row['connection_id'] ) {
			return false;
		}
		$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		if ( self::database_read_failed() || '1' !== (string) $released ) {
			return false;
		}
		$holder = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $name ) );
		return ! self::database_read_failed() && ( null === $holder || ( ctype_digit( (string) $holder ) && 0 < (int) $holder && $connection !== (string) $holder ) );
	}

	/** Allow another initiation to retry a failed delivery without changing its signed flow. */
	public static function release_queued_otp( string $flow_id, ?string $claim_token = null ): bool {
		global $wpdb;

		if ( ! preg_match( '/\A[a-f0-9]{32}\z/', $flow_id ) || ( null !== $claim_token && ! preg_match( '/\A[a-f0-9]{16}\z/', $claim_token ) ) ) {
			return false;
		}
		$table    = $wpdb->prefix . 'pinova_rate_limits';
		$pattern  = null === $claim_token ? 'processing:%' : 'processing:' . $claim_token . ':%';
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
		$table          = $wpdb->prefix . 'pinova_rate_limits';
		$pattern        = 'processing:' . $claim_token . ':%';
		$cancel_pattern = 'cancelled:' . $claim_token;
		$finished       = $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET `payload` = %s WHERE `scope` = %s AND `payload` LIKE %s', $table, 'delivered', $flow_id, $pattern )
		);
		$cancelled      = $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE `scope` = %s AND `payload` LIKE %s', $table, $flow_id, $cancel_pattern )
		);
		self::unlock_claim( $flow_id, $claim_token );
		return false !== $finished && false !== $cancelled;
	}

	/** A cancelled, missing, expired or unowned claim must fail closed before delivery. */
	public static function queued_otp_is_active( string $flow_id, string $claim_token ): bool {
		return 'active' === self::queued_claim_state( $flow_id, $claim_token );
	}

	/**
	 * Internal outcome for safe pre-provider deferral; the public bool API remains.
	 *
	 * @return 'active'|'inactive'|'unavailable'
	 */
	public static function queued_claim_state( string $flow_id, string $claim_token ): string {
		if ( ! preg_match( '/\A[a-f0-9]{32}\z/', $flow_id ) || ! preg_match( '/\A[a-f0-9]{16}\z/', $claim_token ) ) {
			return 'unavailable';
		}
		return self::claim_state( $flow_id, $claim_token );
	}

	/** Retire a consumed flow without dropping an in-flight delivery barrier. */
	public static function retire_queued_otp( string $flow_id ): bool {
		global $wpdb;

		if ( ! preg_match( '/\A[a-f0-9]{32}\z/', $flow_id ) ) {
			return false;
		}
		$table     = $wpdb->prefix . 'pinova_rate_limits';
		$cancelled = $wpdb->query(
			$wpdb->prepare( "UPDATE %i SET `payload` = CONCAT('cancelled:', SUBSTRING_INDEX(SUBSTRING_INDEX(`payload`, ':', 2), ':', -1)) WHERE `scope` = %s AND LEFT(`payload`, 11) = 'processing:'", $table, $flow_id )
		);
		if ( false === $cancelled ) {
			return false;
		}
		$deleted = $wpdb->query(
			$wpdb->prepare( "DELETE FROM %i WHERE `scope` = %s AND (`payload` IS NULL OR (LEFT(`payload`, 10) <> 'cancelled:' AND LEFT(`payload`, 11) <> 'processing:' AND `payload` <> 'processing'))", $table, $flow_id )
		);
		return false !== $deleted;
	}

	/** @param string[] $identifiers */
	public static function delete_queued_for_identifiers( array $identifiers ): bool {

		$keys = [];
		foreach ( $identifiers as $identifier ) {
			$identifier = self::canonical_identifier( $identifier );
			foreach ( [ 'authenticate', 'forget' ] as $purpose ) {
				$keys[] = hash_hmac( 'sha256', 'otp_decoy:' . $purpose . ':' . $identifier, wp_salt( 'auth' ) );
			}
		}
		return self::delete_queued_keys( $keys );
	}

	/** Cancel the one account-bound proof queue, including an unassigned new number. */
	public static function delete_queued_for_user( int $user_id, ?string $flow = null ): bool {
		global $wpdb;
		$keys = [ hash_hmac( 'sha256', 'otp_decoy:verify_mobile:' . $user_id, wp_salt( 'auth' ) ) ];
		// The opaque account binding survives salt rotation, unlike the bucket HMAC.
		if ( null !== $flow ) {
			if ( ! preg_match( '/\A[a-f0-9]{32}\z/', $flow ) ) {
				return false;
			}
			$previous = $wpdb->get_col( $wpdb->prepare( 'SELECT bucket_key FROM %i WHERE scope = %s LIMIT 2', $wpdb->prefix . 'pinova_rate_limits', $flow ) );
			if ( $wpdb->last_error || ! is_array( $previous ) || count( $previous ) > 1 ) {
				return false;
			}
			$keys = array_unique( array_merge( $keys, $previous ) );
		}
		return self::delete_queued_keys( $keys );
	}

	private static function delete_queued_keys( array $keys ): bool {
		global $wpdb;
		if ( ! $keys ) {
			return true;
		}
		$table          = $wpdb->prefix . 'pinova_rate_limits';
		$placeholders   = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$params         = array_merge( [ $table ], $keys );
		$cancel_sql     = "UPDATE %i SET `payload` = CONCAT('cancelled:', SUBSTRING_INDEX(SUBSTRING_INDEX(`payload`, ':', 2), ':', -1)) WHERE `bucket_key` IN ({$placeholders}) AND LEFT(`payload`, 11) = 'processing:'";
		$delete_sql     = "DELETE FROM %i WHERE `bucket_key` IN ({$placeholders}) AND (`payload` IS NULL OR (LEFT(`payload`, 11) <> 'processing:' AND LEFT(`payload`, 10) <> 'cancelled:' AND `payload` <> 'processing'))";
		$pending_sql    = "SELECT COUNT(*) FROM %i WHERE `bucket_key` IN ({$placeholders}) AND (LEFT(`payload`, 10) = 'cancelled:' OR `payload` = 'processing')";
		$expired_sql    = "DELETE FROM %i WHERE `bucket_key` IN ({$placeholders}) AND `reset_at` <= UTC_TIMESTAMP() AND LEFT(`payload`, 10) <> 'cancelled:' AND (`payload` <> 'processing' OR `reset_at` <= UTC_TIMESTAMP() - INTERVAL %d SECOND)";
		$cancelled      = $wpdb->query( $wpdb->prepare( $cancel_sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only fixed placeholders are generated.
		$deleted        = $wpdb->query( $wpdb->prepare( $delete_sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only fixed placeholders are generated.
		$cancelled_late = $wpdb->query( $wpdb->prepare( $cancel_sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Catch claims racing the first pass.
		$stale          = $wpdb->get_results( $wpdb->prepare( "SELECT `bucket_key`, `scope`, `payload` FROM %i WHERE `bucket_key` IN ({$placeholders}) AND LEFT(`payload`, 10) = 'cancelled:'", $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only fixed placeholders are generated.
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
		$expired = $wpdb->query( $wpdb->prepare( $expired_sql, array_merge( $params, [ self::OTP_CLAIM_LEASE ] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only fixed placeholders are generated.
		$pending = $wpdb->get_var( $wpdb->prepare( $pending_sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only fixed placeholders are generated.
		return false !== $cancelled && false !== $deleted && false !== $cancelled_late && false !== $expired && '0' === (string) $pending;
	}

	/**
	 * wpdb calls mutate this global between connection and ownership observations.
	 *
	 * @phpstan-impure
	 */
	private static function database_read_failed(): bool {
		global $wpdb;
		return '' !== (string) $wpdb->last_error;
	}

	private static function queue_key(): string {
		return hash_hmac( 'sha256', 'pinova-otp-delivery', wp_salt( 'auth' ), true );
	}

	/** A database connection owns the claim until its worker finishes or crashes. */
	private static function lock_queued_otp( string $flow_id ): bool {
		global $wpdb;

		$name   = 'pinova_otp_' . $flow_id;
		$holder = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $name ) );
		if ( $wpdb->last_error || null !== $holder ) {
			return false;
		}
		// Cover the native query's own post-query hooks before a return value or
		// claim exists, then retain the gate through CAS, decryption and release.
		++self::$diagnostic_lock_acquisitions;
		try {
			$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
			if ( ! self::database_read_failed() && '1' === (string) $acquired ) {
				self::$diagnostic_flow_locks[ $flow_id ] = true;
				return true;
			}
			if ( self::database_read_failed() || '0' !== (string) $acquired ) {
				self::$diagnostic_release_unknown        = true;
				self::$diagnostic_flow_locks[ $flow_id ] = true;
			}
			return false;
		} catch ( \Throwable $throwable ) {
			self::$diagnostic_release_unknown        = true;
			self::$diagnostic_flow_locks[ $flow_id ] = true;
			throw $throwable;
		} finally {
			--self::$diagnostic_lock_acquisitions;
		}
	}

	private static function unlock_queued_otp( string $flow_id ): bool {
		$released = false;
		try {
			$released = self::release_named_lock_observed( 'pinova_otp_' . $flow_id, self::$claim_snapshots[ $flow_id ]['connection'] ?? null );
			if ( $released ) {
				unset( self::$diagnostic_flow_locks[ $flow_id ] );
			}
			return $released;
		} finally {
			if ( ! $released ) {
				self::$diagnostic_release_unknown = true;
			}
		}
	}

	private static function unlock_claim( string $flow_id, ?string $claim_token ): void {
		if ( ! isset( self::$held_claims[ $flow_id ] ) || ( null !== $claim_token && self::$held_claims[ $flow_id ] !== $claim_token ) ) {
			return;
		}
		if ( self::unlock_queued_otp( $flow_id ) ) {
			unset( self::$held_claims[ $flow_id ] );
			unset( self::$claim_snapshots[ $flow_id ] );
		}
	}

	private static function canonical_identifier( string $identifier ): string {
		return is_email( $identifier ) ? strtolower( $identifier ) : $identifier;
	}
}
