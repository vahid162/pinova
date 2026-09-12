<?php

namespace Pinova\Services;

use Carbon\Carbon;
use Exception;
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
		$otp = OTP::query()->create( [
			'user_id'    => $user_id,
			'identifier' => $identifier->get_value(),
			'code'       => $code,
			'type'       => $type,
			'channels'   => array_fill_keys( $channels, false ),
		] );

		try {
			$successful_channels = ChannelService::send( $otp, $code );
		} catch ( Exception $e ) {
			Logger::instance()->error(
				'otp.delivery_failed',
				[
					'user_id'                => $user_id,
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
				'otp_type'               => $type,
				'identifier_type'        => $identifier->get_type(),
				'identifier_fingerprint' => Logger::instance()->fingerprint( $identifier->get_value(), $identifier->get_type() ),
				'channels'               => $successful_channels,
			]
		);

		return [
			JWT::encode( [ 'otp_id' => $otp->id, ] ),
			$successful_channels,
		];
	}

	/**
	 * @throws Exception
	 */
	public static function verify( string $jwt, string $code ): \WP_User {

		try {
			$payload = JWT::decode( $jwt );
		} catch ( Exception $e ) {
			Logger::instance()->notice( 'otp.verify_failed', [ 'reason' => 'invalid_token', 'exception' => $e ] );
			throw new Exception( __( 'کد تایید معتبر نمی‌باشد.', 'pinova' ) );
		}

		try {
			/** @var OTP $otp */
			$otp = OTP::query()->findOrFail( absint( $payload['otp_id'] ?? 0 ) );
		} catch ( \Throwable $e ) {
			Logger::instance()->notice( 'otp.verify_failed', [ 'reason' => 'record_not_found', 'exception' => $e ] );
			throw new Exception( __( 'کد تایید معتبر نمی‌باشد.', 'pinova' ) );
		}

		if ( $otp->isExpired() || $otp->isVerified() || $otp->attempts >= 5 ) {
			Logger::instance()->notice(
				'otp.verify_failed',
				[
					'user_id' => $otp->user_id,
					'otp_type' => $otp->type,
					'attempts' => $otp->attempts,
					'reason'   => $otp->isExpired() ? 'expired' : ( $otp->isVerified() ? 'already_verified' : 'attempt_limit' ),
				]
			);
			throw new Exception( __( 'کد تایید معتبر نمی‌باشد.', 'pinova' ) );
		}

		if ( $otp->ip_address != IP::get() ) {
			Logger::instance()->warning(
				'otp.verify_failed',
				[
					'user_id'       => $otp->user_id,
					'otp_type'      => $otp->type,
					'ip_fingerprint' => Logger::instance()->fingerprint( IP::get(), 'ip' ),
					'reason'        => 'ip_mismatch',
				]
			);
			throw new Exception( __( 'شما به این کد دسترسی ندارید.', 'pinova' ) );
		}

		if ( ! wp_check_password( $code, $otp->code ) ) {

			$otp->incrementAttempts();
			Logger::instance()->notice(
				'otp.verify_failed',
				[
					'user_id' => $otp->user_id,
					'otp_type' => $otp->type,
					'attempts' => $otp->attempts,
					'reason'   => 'invalid_code',
				]
			);

			throw new Exception( __( 'کد تایید معتبر نمی‌باشد.', 'pinova' ) );
		}

		$otp->markVerified();
		Logger::instance()->info(
			'otp.verified',
			[
				'user_id'  => $otp->user_id,
				'otp_type' => $otp->type,
			]
		);

		return UserService::get_or_create( $otp );
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
