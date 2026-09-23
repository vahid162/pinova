<?php

namespace Pinova\Services;

use Carbon\Carbon;
use Exception;
use Pinova\Exceptions\BlockedException;
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

	/**
	 * @throws Exception
	 */
	public static function create( Identifier $identifier, array $channels, string $type, ?int $user_id = null, bool $rate_limit_consumed = false, ?int $state_deadline = null, ?string $flow_id = null, ?string $ip_address = null ): array {
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

		try {
			$successful_channels = ChannelService::send( $otp, $code );
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
		try {
			$queued = RateLimitService::claim_queued_otp( $flow_id );
			if ( null === $queued || $queued['deadline'] <= time() ) {
				return;
			}

			// A repeated public request retains this flow; a successful delivery
			// must never send a second OTP for it.
			if ( OTP::query()->where( 'flow_id', $flow_id )->exists() ) {
				return;
			}
			$identifier = new Identifier( $queued['identifier'] );
			if ( ! $identifier->is_valid() || $identifier->is_username() ) {
				return;
			}
			[ $user_id, $mobile_unclaimed ] = UserService::match_with_registration_policy( $identifier );
			$user = $user_id ? get_userdata( $user_id ) : false;
			if ( $user instanceof \WP_User && UserService::is_native_only( $user ) ) {
				return;
			}
			if ( ! $user_id && ( 'forget' === $queued['purpose'] || ! Pinova::users_can_register() || $identifier->is_email() || ! $mobile_unclaimed ) ) {
				return;
			}

			$type = 'forget' === $queued['purpose'] ? OTP::TYPE_FORGET : ( $user_id ? OTP::TYPE_LOGIN : OTP::TYPE_REGISTER );
			if ( $queued['deadline'] <= time() ) {
				return;
			}
			self::create( $identifier, ChannelService::get_channels( $identifier ), $type, $user_id, true, $queued['deadline'], $flow_id, $queued['ip'] );
		} catch ( SendOTPException $exception ) {
			// ChannelService already recorded the privacy-safe provider failure.
			RateLimitService::release_queued_otp( $flow_id );
			unset( $exception );
		} catch ( Throwable $throwable ) {
			RateLimitService::release_queued_otp( $flow_id );
			EventThrottle::log( 'auth.request_failed', [ 'operation' => 'queued_otp', 'reason' => 'delivery_worker_error' ] );
			// A queued delivery cannot change the response already sent to the caller.
			unset( $throwable );
		}
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
	 * @return array{0:\WP_User,1:?string}
	 * @throws Exception
	 */
	public static function verify_with_flow( string $jwt, string $code, array $expected_types ): array {

		try {
			$payload = JWT::decode( $jwt );
		} catch ( Exception $e ) {
			EventThrottle::log(
				'otp.verify_failed',
				[
					'reason'    => 'invalid_token',
					'exception' => $e,
				]
			);
			throw new Exception( __( 'کد تایید معتبر نمی‌باشد.', 'pinova' ) );
		}

		try {
			/** @var OTP $otp */
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
		} catch ( \Throwable $e ) {
			EventThrottle::log(
				'otp.verify_failed',
				[
					'reason'    => 'record_not_found',
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

		if ( ! $otp->markVerified() ) {
			EventThrottle::log( 'otp.verify_failed', $log_context + [ 'reason' => 'claim_rejected' ] );
			throw new Exception( __( 'کد تایید معتبر نمی‌باشد.', 'pinova' ) );
		}
		Logger::instance()->info(
			'otp.verified',
			$log_context
		);

		return [ UserService::get_or_create( $otp ), $record_flow ];
	}

	public static function signed_state( OTP $otp, ?int $ttl = null ): string {
		if ( is_string( $otp->flow_id ) && preg_match( '/\A[a-f0-9]{32}\z/', $otp->flow_id ) ) {
			$payload = [ 'flow_id' => $otp->flow_id ];
		} else {
			$payload = [ 'otp_id' => $otp->id ];
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
