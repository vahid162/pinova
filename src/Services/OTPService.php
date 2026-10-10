<?php

namespace Pinova\Services;

use Carbon\Carbon;
use Exception;
use Pinova\Exceptions\AuthenticationPolicyException;
use Pinova\Exceptions\ExpiredTokenException;
use Pinova\Exceptions\BlockedException;
use Pinova\Exceptions\OTPCompletionException;
use Pinova\Exceptions\SendOTPException;
use Pinova\Helpers\IP;
use Pinova\Helpers\JWT;
use Pinova\Models\OTP;
use Pinova\Logging\Logger;
use Pinova\Logging\EventThrottle;
use Pinova\Objects\Identifier;
use Pinova\Pinova;
use Throwable;

class OTPService {

	/** Run only a small due OTP batch; the host runner must hold its site cron lock. */
	public static function run_due_delivery(): int {
		if ( is_multisite() ) {
			throw new Exception( 'The OTP runner requires a single-site installation.' );
		}
		$ready = wp_get_ready_cron_jobs();
		if ( ! is_array( $ready ) || false === has_action( 'pinova_otp_delivery', [ self::class, 'deliver_queued' ] ) ) {
			throw new Exception( 'The OTP queue or delivery callback is unavailable.' );
		}
		$started = microtime( true );
		$count   = 0;
		foreach ( $ready as $timestamp => $hooks ) {
			foreach ( $hooks['pinova_otp_delivery'] ?? [] as $event ) {
				if ( 3 <= $count || 20 <= microtime( true ) - $started ) {
					return $count;
				}
				$args = $event['args'] ?? [];
				if ( false !== ( $event['schedule'] ?? null ) || ! is_array( $args ) || 1 !== count( $args )
					|| ! is_string( $args[0] ?? null ) || ! preg_match( '/\A[a-f0-9]{32}\z/', $args[0] ) ) {
					continue;
				}
				if ( true !== wp_unschedule_event( (int) $timestamp, 'pinova_otp_delivery', $args, true ) ) {
					throw new Exception( 'The due OTP event could not be unscheduled.' );
				}
				do_action_ref_array( 'pinova_otp_delivery', $args );
				++$count;
			}
		}
		return $count;
	}

	/**
	 * @throws Exception
	 */
	public static function create( Identifier $identifier, array $channels, string $type, ?int $user_id = null, bool $rate_limit_consumed = false, ?int $state_deadline = null, ?string $flow_id = null, ?string $ip_address = null, ?string $queue_claim_token = null ): array {
		if ( ! $rate_limit_consumed ) {
			RateLimitService::otp( IP::get(), $identifier->get_value() );
		}

		$code = self::generate_code();

		/** @var OTP $otp */
		$otp = OTP::query()->create(
			[
				'user_id'    => $user_id,
				'flow_id'    => $flow_id ?? bin2hex( random_bytes( 16 ) ),
				'identifier' => $identifier->get_value(),
				'code'       => $code,
				'ip_address' => $ip_address,
				'type'       => $type,
				'channels'   => array_fill_keys( $channels, false ),
				'expires_at' => null === $state_deadline ? null : Carbon::createFromTimestampUTC( $state_deadline ),
			]
		);
		if ( null !== $queue_claim_token && ( null === $flow_id || ! RateLimitService::queued_otp_is_active( $flow_id, $queue_claim_token ) ) ) {
			$otp->delete();
			if ( null !== $flow_id ) {
				self::observe_delivery_skipped( $flow_id, 'claim_unavailable' );
			}
			throw new SendOTPException( 'Queued OTP delivery was cancelled.' );
		}

		try {
			$successful_channels = ChannelService::send( $otp, $code, $queue_claim_token );
		} catch ( Throwable $e ) {
			Logger::instance()->error(
				'otp.delivery_failed',
				[
					'user_id'                => $user_id,
					'flow_id'                => $otp->flow_id,
					'otp_type'               => $type,
					'identifier_type'        => $identifier->get_type(),
					'identifier_fingerprint' => Logger::instance()->fingerprint( $identifier->get_value(), $identifier->get_type() ),
					'channels'               => $channels,
					'exception'              => $e,
				]
			);

			$otp->delete();

			throw $e;
		}

		Logger::instance()->info(
			'otp.created',
			[
				'user_id'                => $user_id,
				'flow_id'                => $otp->flow_id,
				'otp_type'               => $type,
				'identifier_type'        => $identifier->get_type(),
				'identifier_fingerprint' => Logger::instance()->fingerprint( $identifier->get_value(), $identifier->get_type() ),
				'channels'               => $successful_channels,
				'queue_delay_seconds'    => null === $state_deadline ? null : max( 0, JWT::DEFAULT_TTL - ( $state_deadline - time() ) ),
				'remaining_seconds'      => null === $state_deadline ? null : max( 0, $state_deadline - time() ),
			]
		);

		$state_ttl = null === $state_deadline ? JWT::DEFAULT_TTL : max( 1, $state_deadline - time() );
		return [
			self::signed_state( $otp, $state_ttl ),
			$successful_channels,
			$state_ttl,
		];
	}

	/** Deliver only after the public initiation response has been prepared. */
	public static function deliver_queued( string $flow_id ): void {
		$queued = null;
		try {
			$queued = RateLimitService::claim_queued_otp( $flow_id );
			if ( null === $queued || $queued['deadline'] <= time() ) {
				RateLimitService::observe_expired_queued_otp( $flow_id );
				if ( null !== $queued ) {
					self::observe_delivery_skipped( $flow_id, 'expired' );
				}
				return;
			}

			// A repeated public request retains this flow; a successful delivery
			// must never send a second OTP for it.
			$existing = OTP::query()->where( 'flow_id', $flow_id )->limit( 2 )->get();
			if ( $existing->count() > 1 ) {
				self::observe_delivery_skipped( $flow_id, 'duplicate_records' );
				return;
			}
			if ( 1 === $existing->count() ) {
				$previous = $existing->first();
				if ( $previous->isVerified() || in_array( true, (array) $previous->channels, true ) ) {
					self::observe_delivery_skipped( $flow_id, 'already_completed' );
					return;
				}
				// Provider acceptance before a crash is unknowable. Keep the old
				// code valid and let a later public request open a fresh flow.
				RateLimitService::retire_queued_otp( $flow_id );
				self::observe_delivery_skipped( $flow_id, 'provider_outcome_unknown' );
				return;
			}
			$identifier = new Identifier( $queued['identifier'] );
			if ( ! $identifier->is_valid() || $identifier->is_username() ) {
				self::observe_delivery_skipped( $flow_id, 'invalid_payload' );
				return;
			}
			if ( OTP::TYPE_VERIFY_MOBILE === $queued['purpose'] ) {
				$user_id = (int) $queued['user_id'];
				if ( ! MobileVerificationService::can_assign( $user_id, $identifier ) ) {
					self::observe_delivery_skipped( $flow_id, 'policy_rejected' );
					return;
				}
				$mobile_unclaimed = false;
			} else {
				[ $user_id, $mobile_unclaimed ] = UserService::match_with_registration_policy( $identifier );
			}

			$user = $user_id ? get_userdata( $user_id ) : false;
			if ( $user instanceof \WP_User && UserService::is_native_only( $user ) ) {
				self::observe_delivery_skipped( $flow_id, 'policy_rejected' );
				return;
			}
			if ( ! $user_id && ( 'forget' === $queued['purpose'] || ! Pinova::users_can_register() || $identifier->is_email() || ! $mobile_unclaimed ) ) {
				self::observe_delivery_skipped( $flow_id, 'policy_rejected' );
				return;
			}

			if ( OTP::TYPE_VERIFY_MOBILE === $queued['purpose'] ) {
				$type = OTP::TYPE_VERIFY_MOBILE;
			} elseif ( 'forget' === $queued['purpose'] ) {
				$type = OTP::TYPE_FORGET;
			} else {
				$type = $user_id ? OTP::TYPE_LOGIN : OTP::TYPE_REGISTER;
			}
			if ( $queued['deadline'] <= time() ) {
				self::observe_delivery_skipped( $flow_id, 'expired' );
				return;
			}
			if ( FirewallService::is_blocked( $identifier->get_value() ) || FirewallService::is_blocked( $queued['ip'] ) ) {
				self::observe_delivery_skipped( $flow_id, 'policy_rejected' );
				return;
			}
			if ( ! RateLimitService::queued_otp_is_active( $flow_id, $queued['claim_token'] ) ) {
				self::observe_delivery_skipped( $flow_id, 'claim_unavailable' );
				return;
			}
			self::create( $identifier, ChannelService::get_channels( $identifier ), $type, $user_id, true, $queued['deadline'], $flow_id, $queued['ip'], $queued['claim_token'] );
		} catch ( SendOTPException $exception ) {
			// ChannelService already recorded the privacy-safe provider failure.
			RateLimitService::release_queued_otp( $flow_id, $queued['claim_token'] );
			unset( $exception );
		} catch ( Throwable $throwable ) {
			if ( null !== $queued ) {
				RateLimitService::release_queued_otp( $flow_id, $queued['claim_token'] );
			}
			EventThrottle::log(
				'auth.request_failed',
				[
					'operation' => 'queued_otp',
					'reason'    => 'delivery_worker_error',
					'flow_id'   => $flow_id,
				]
			);
			// A queued delivery cannot change the response already sent to the caller.
			unset( $throwable );
		} finally {
			if ( null !== $queued ) {
				RateLimitService::finish_queued_otp( $flow_id, $queued['claim_token'] );
			}
		}
	}

	/** Observe a known worker outcome without exposing identity or policy classification. */
	private static function observe_delivery_skipped( string $flow_id, string $reason ): void {
		EventThrottle::log(
			'otp.delivery_skipped',
			[
				'operation' => 'queued_otp',
				'flow_id'   => $flow_id,
				'reason'    => $reason,
			]
		);
	}

	/** WordPress 6.8 spawns cron before REST callbacks; schedule a non-blocking pass at shutdown. */
	public static function spawn_queued_delivery(): void {
		if ( ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * @param string[] $expected_types
	 *
	 * @throws Exception
	 */
	public static function verify( string $jwt, string $code, array $expected_types ): \WP_User {
		[ $user ] = self::verify_with_flow( $jwt, $code, $expected_types );
		return $user;
	}

	/**
	 * @param string[] $expected_types
	 * @return array{0:\WP_User,1:?string,2:Identifier}
	 * @throws Exception
	 */
	public static function verify_with_flow( string $jwt, string $code, array $expected_types ): array {

		try {
			$payload = JWT::decode( $jwt );
		} catch ( Exception $e ) {
			EventThrottle::log(
				'otp.verify_failed',
				[
					'reason'    => $e instanceof ExpiredTokenException ? 'token_expired' : 'invalid_token',
					'exception' => $e,
				]
			);
			throw new Exception( __( 'کد تایید معتبر نمی‌باشد.', 'pinova' ) );
		}

		try {
			if ( array_key_exists( 'otp_id', $payload ) ) {
				$otp = OTP::query()->findOrFail( absint( $payload['otp_id'] ) );
			} else {
				$flow_id = $payload['flow_id'] ?? null;
				if ( ! is_string( $flow_id ) || ! preg_match( '/\A[a-f0-9]{32}\z/', $flow_id ) ) {
					throw new Exception( 'Invalid OTP state.' );
				}
				$matches = OTP::query()->where( 'flow_id', $flow_id )->limit( 2 )->get();
				if ( 1 !== $matches->count() ) {
					throw new Exception( 'OTP state did not resolve uniquely.' );
				}
				$otp = $matches->first();
			}
			if ( ! $otp instanceof OTP ) {
				throw new Exception( 'OTP state did not resolve to a record.' );
			}
		} catch ( \Throwable $e ) {
			EventThrottle::log(
				'otp.verify_failed',
				[
					'reason'    => 'record_not_found',
					'flow_id'   => $payload['flow_id'] ?? null,
					'exception' => $e,
				]
			);
			throw new Exception( __( 'کد تایید معتبر نمی‌باشد.', 'pinova' ) );
		}

		$identifier  = new Identifier( (string) $otp->identifier );
		$log_context = [
			'user_id'                => $otp->user_id,
			'flow_id'                => $otp->flow_id,
			'otp_type'               => $otp->type,
			'identifier_type'        => $identifier->get_type(),
			'identifier_fingerprint' => Logger::instance()->fingerprint( $identifier->get_value(), $identifier->get_type() ),
		];

		$record_flow = $otp->flow_id;
		$token_flow  = $payload['flow_id'] ?? null;
		if ( ( null !== $record_flow && ( ! is_string( $record_flow ) || ! preg_match( '/\A[a-f0-9]{32}\z/', $record_flow ) ) )
			|| $token_flow !== $record_flow ) {
			EventThrottle::log( 'otp.verify_failed', $log_context + [ 'reason' => 'flow_mismatch' ] );
			throw new Exception( __( 'کد تایید معتبر نمی‌باشد.', 'pinova' ) );
		}

		if ( OTP::TYPE_VERIFY_MOBILE === $otp->type && ( get_current_user_id() !== (int) $otp->user_id
			|| ! isset( $payload['user_id'] ) || (int) $payload['user_id'] !== (int) $otp->user_id
			|| OTP::TYPE_VERIFY_MOBILE !== ( $payload['purpose'] ?? null ) ) ) {
			throw new Exception( __( 'کد تأیید معتبر نمی‌باشد.', 'pinova' ) );
		}

		if ( ! $otp->hasType( $expected_types ) ) {
			EventThrottle::log(
				'otp.verify_failed',
				$log_context + [ 'reason' => 'purpose_mismatch' ]
			);
			throw new Exception( __( 'کد تایید معتبر نمی‌باشد.', 'pinova' ) );
		}

		if ( FirewallService::is_ip_blocked() ) {
			throw new BlockedException( 'ip' );
		}

		if ( $identifier->is_valid() && FirewallService::is_blocked( $identifier->get_value() ) ) {
			throw new BlockedException( $identifier->get_type() );
		}

		if ( $otp->isExpired() || $otp->isVerified() || $otp->attempts >= 5 ) {
			EventThrottle::log(
				'otp.verify_failed',
				$log_context + [
					'attempts' => $otp->attempts,
					'reason'   => $otp->isExpired() ? 'expired' : ( $otp->isVerified() ? 'already_verified' : 'attempt_limit' ),
				]
			);
			throw new Exception( __( 'کد تایید معتبر نمی‌باشد.', 'pinova' ) );
		}

		if ( IP::get() !== $otp->ip_address ) {
			EventThrottle::log(
				'otp.verify_failed',
				$log_context + [
					'ip_fingerprint' => Logger::instance()->fingerprint( IP::get(), 'ip' ),
					'reason'         => 'ip_mismatch',
				]
			);
			throw new Exception( __( 'شما به این کد دسترسی ندارید.', 'pinova' ) );
		}

		if ( ! wp_check_password( $code, $otp->code ) ) {

			$otp->incrementAttempts();
			EventThrottle::log(
				'otp.verify_failed',
				$log_context + [
					'attempts' => $otp->attempts,
					'reason'   => 'invalid_code',
				]
			);

			throw new Exception( __( 'کد تایید معتبر نمی‌باشد.', 'pinova' ) );
		}

		try {
			AuthenticationPolicy::assert_otp( $otp );
		} catch ( Exception $exception ) {
			EventThrottle::log( 'otp.verify_failed', $log_context + [ 'reason' => 'policy_rejected' ] );
			throw $exception;
		}

		$proof_epoch = null;
		try {
			if ( $identifier->is_mobile() && null !== $otp->user_id && OTP::TYPE_FORGET !== $otp->type ) {
				$proof_epoch = MobileVerificationService::epoch( (int) $otp->user_id );
			}
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			throw self::completion_failure( $log_context, 'mobile_evidence_unavailable' );
		}

		if ( ! $otp->markVerified() ) {
			EventThrottle::log( 'otp.verify_failed', $log_context + [ 'reason' => 'claim_rejected' ] );
			throw new Exception( __( 'کد تایید معتبر نمی‌باشد.', 'pinova' ) );
		}
		if ( is_string( $record_flow ) ) {
			RateLimitService::retire_queued_otp( $record_flow );
		}
		Logger::instance()->info(
			'otp.verified',
			$log_context
		);

		try {
			if ( OTP::TYPE_VERIFY_MOBILE === $otp->type ) {
				MobileVerificationService::complete( (int) $otp->user_id, $identifier, (string) $proof_epoch );
				$user = new \WP_User( (int) $otp->user_id );
			} else {
				$user                   = UserService::get_or_create( $otp );
				$log_context['user_id'] = $user->ID;
				if ( $identifier->is_mobile() && in_array( $otp->type, [ OTP::TYPE_LOGIN, OTP::TYPE_REGISTER ], true )
					&& MobileVerificationService::current_mobile( $user->ID ) === $identifier->get_value() ) {
					MobileVerificationService::record( $user->ID, $identifier, $proof_epoch ?? MobileVerificationService::epoch( $user->ID ) );
				}
			}
		} catch ( AuthenticationPolicyException $exception ) {
			EventThrottle::log( 'otp.verify_failed', $log_context + [ 'reason' => 'policy_rejected' ] );
			throw $exception;
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			throw self::completion_failure( $log_context, 'otp_completion_failed' );
		}
		return [ $user, $record_flow, $identifier ];
	}

	/** Record an operational failure without retaining exception text or request secrets. */
	private static function completion_failure( array $context, string $reason ): OTPCompletionException {
		EventThrottle::log(
			'auth.request_failed',
			$context + [
				'operation' => 'verify_otp',
				'reason'    => $reason,
			]
		);
		return new OTPCompletionException();
	}

	public static function signed_state( OTP $otp, ?int $ttl = null ): string {
		if ( is_string( $otp->flow_id ) && preg_match( '/\A[a-f0-9]{32}\z/', $otp->flow_id ) ) {
			$payload = [ 'flow_id' => $otp->flow_id ];
		} else {
			$payload = [ 'otp_id' => $otp->id ];
		}

		if ( OTP::TYPE_VERIFY_MOBILE === $otp->type ) {
			$payload['user_id'] = (int) $otp->user_id;
			$payload['purpose'] = OTP::TYPE_VERIFY_MOBILE;
		}
		return JWT::encode( $payload, $ttl );
	}

	public static function generate_code(): int {

		$code_length = self::code_length();

		$min = (int) ( '1' . str_repeat( '0', $code_length - 1 ) );
		$max = (int) str_repeat( '9', $code_length );

		return random_int( $min, $max );
	}

	public static function code_length(): int {
		return Pinova::get_option( 'advanced.code_length', 4 );
	}
}
