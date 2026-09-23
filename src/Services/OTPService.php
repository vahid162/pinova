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
use Pinova\Objects\Identifier;
use Pinova\Pinova;

class OTPService {

	/**
	 * @throws Exception
	 */
	public static function create( Identifier $identifier, array $channels, string $type, ?int $user_id = null ): array {
		RateLimitService::otp( IP::get(), $identifier->get_value() );

		$code = self::generate_code();

		/** @var OTP $otp */
		$otp = OTP::query()->create(
			[
				'user_id'    => $user_id,
				'flow_id'    => bin2hex( random_bytes( 16 ) ),
				'identifier' => $identifier->get_value(),
				'code'       => $code,
				'type'       => $type,
				'channels'   => array_fill_keys( $channels, false ),
			]
		);

		try {
			$successful_channels = ChannelService::send( $otp, $code );
		} catch ( Exception $e ) {
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

		return [
			self::signed_state( $otp ),
			$successful_channels,
		];
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
			Logger::instance()->notice(
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
			$otp = OTP::query()->findOrFail( absint( $payload['otp_id'] ?? 0 ) );
		} catch ( \Throwable $e ) {
			Logger::instance()->notice(
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
			Logger::instance()->notice( 'otp.verify_failed', $log_context + [ 'reason' => 'flow_mismatch' ] );
			throw new Exception( __( 'کد تایید معتبر نمی‌باشد.', 'pinova' ) );
		}

		if ( ! $otp->hasType( $expected_types ) ) {
			Logger::instance()->notice(
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
			Logger::instance()->notice(
				'otp.verify_failed',
				$log_context + [
					'attempts' => $otp->attempts,
					'reason'   => $otp->isExpired() ? 'expired' : ( $otp->isVerified() ? 'already_verified' : 'attempt_limit' ),
				]
			);
			throw new Exception( __( 'کد تایید معتبر نمی‌باشد.', 'pinova' ) );
		}

		if ( IP::get() !== $otp->ip_address ) {
			Logger::instance()->warning(
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
			Logger::instance()->notice(
				'otp.verify_failed',
				$log_context + [
					'attempts' => $otp->attempts,
					'reason'   => 'invalid_code',
				]
			);

			throw new Exception( __( 'کد تایید معتبر نمی‌باشد.', 'pinova' ) );
		}

		$otp->markVerified();
		Logger::instance()->info(
			'otp.verified',
			$log_context
		);

		return [ UserService::get_or_create( $otp ), $record_flow ];
	}

	public static function signed_state( OTP $otp, ?int $ttl = null ): string {
		$payload = [ 'otp_id' => $otp->id ];
		if ( is_string( $otp->flow_id ) && preg_match( '/\A[a-f0-9]{32}\z/', $otp->flow_id ) ) {
			$payload['flow_id'] = $otp->flow_id;
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
