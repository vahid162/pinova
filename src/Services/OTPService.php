<?php

namespace Pinova\Services;

use Carbon\Carbon;
use Exception;
use Pinova\Exceptions\SendOTPException;
use Pinova\Helpers\IP;
use Pinova\Helpers\JWT;
use Pinova\Models\OTP;
use Pinova\Objects\Identifier;
use Pinova\Pinova;

class OTPService {

	/**
	 * @throws Exception
	 */
	public static function create( Identifier $identifier, array $channels, string $type, ?int $user_id = null ): array {

		$ip_requests = OTP::query()
		                  ->where( 'ip_address', IP::get() )
		                  ->where( 'expires_at', '>=', Carbon::now()->subHour() )
		                  ->count();

		if ( $ip_requests >= 8 ) {

			FirewallService::block( IP::get(), 60 );

			throw new SendOTPException( __( 'آدرس آی.پی شما مسدود شده است.', 'pinova' ) );
		}

		$ip_requests = OTP::query()
		                  ->where( 'ip_address', IP::get() )
		                  ->where( 'expires_at', '>=', Carbon::now()->subWeek() )
		                  ->whereNull( 'verified_at' )
		                  ->count();

		if ( $ip_requests >= 12 ) {

			FirewallService::block( IP::get(), 24 * 60 );

			throw new SendOTPException( __( 'آدرس آی.پی شما مسدود شده است.', 'pinova' ) );
		}

		$identifier_requests = OTP::query()
		                          ->where( 'identifier', $identifier->get_value() )
		                          ->where( 'expires_at', '>=', Carbon::now()->subHour() )
		                          ->count();

		if ( $identifier_requests >= 5 ) {

			FirewallService::block( $identifier->get_value(), 60 );

			throw new SendOTPException( __( 'ایمیل یا تلفن همراه مسدود شده است.', 'pinova' ) );
		}

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

			// @todo Shit! log it anyway

			$otp->delete();

			throw $e;
		}

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
			throw new Exception( __( 'کد تایید معتبر نمی‌باشد.', 'pinova' ) );
		}

		/** @var OTP $otp */
		$otp = OTP::query()->findOrFail( $payload['otp_id'] );

		if ( $otp->isExpired() || $otp->isVerified() || $otp->attempts >= 5 ) {
			throw new Exception( __( 'کد تایید معتبر نمی‌باشد.', 'pinova' ) );
		}

		if ( $otp->ip_address != IP::get() ) {
			throw new Exception( __( 'شما به این کد دسترسی ندارید.', 'pinova' ) );
		}

		if ( ! wp_check_password( $code, $otp->code ) ) {

			$otp->incrementAttempts();

			throw new Exception( __( 'کد تایید معتبر نمی‌باشد.', 'pinova' ) );
		}

		$otp->markVerified();

		$user       = UserService::get_or_create( $otp );
		$identifier = new Identifier( $otp->identifier );
		$username   = sanitize_user( $identifier->get_value(), true );

		if ( $identifier->is_mobile() && $user->user_login !== $username ) {
			UserService::update_username( $user->ID, $username );
		}

		return $user;
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
