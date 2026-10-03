<?php

namespace Pinova\API;

use Pinova\Exceptions\RateLimitException;
use Pinova\Helpers\IP;
use Pinova\Helpers\JWT;
use Pinova\Models\OTP;
use Pinova\Objects\Identifier;
use Pinova\Services\FirewallService;
use Pinova\Services\MobileVerificationService;
use Pinova\Services\OTPService;
use Pinova\Services\RateLimitService;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class MobileVerificationAPI extends RestAPI {
	public function register_routes(): void {
		$routes = [
			'request' => 'initiate',
			'verify'  => 'verify',
		];
		foreach ( $routes as $route => $callback ) {
			register_rest_route(
				'pinova',
				'/mobile/' . $route,
				[
					'methods'             => 'POST',
					'callback'            => [ $this, $callback ],
					'permission_callback' => [ $this, 'permission_callback' ],
				]
			);
		}
	}

	public function permission_callback( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() || ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) || FirewallService::is_ip_blocked() ) {
			return new WP_Error( 'pinova_mobile_forbidden', __( 'درخواست معتبر نمی‌باشد. دوباره وارد حساب شوید.', 'pinova' ), [ 'status' => 403 ] );
		}
		return true;
	}

	public function initiate( WP_REST_Request $request ): WP_REST_Response {
		if ( is_wp_error( $this->permission_callback( $request ) ) ) {
			return self::response( false, __( 'درخواست معتبر نمی‌باشد.', 'pinova' ), [], 403 );
		}
		$raw        = $request->get_param( 'mobile' );
		$identifier = new Identifier( is_string( $raw ) ? $raw : '' );
		if ( ! $identifier->is_mobile() ) {
			return self::response( false, __( 'تلفن همراه معتبر وارد کنید.', 'pinova' ), [], 400 );
		}
		try {
			$user_id = get_current_user_id();
			$flow    = MobileVerificationService::with_lock(
				$user_id,
				static function () use ( $identifier, $user_id ) {
					RateLimitService::otp( IP::get(), $identifier->get_value() );
					return RateLimitService::decoy_flow( $identifier->get_value(), OTP::TYPE_VERIFY_MOBILE, IP::get(), $user_id );
				}
			);
			$args    = [ $flow[0] ];
			if ( ! wp_next_scheduled( 'pinova_otp_delivery', $args ) && true !== wp_schedule_single_event( time(), 'pinova_otp_delivery', $args ) ) {
				throw new \Exception( 'OTP scheduling failed.' );
			}
			add_action( 'shutdown', [ OTPService::class, 'spawn_queued_delivery' ], 100 );
			$ttl = max( 1, $flow[1] - time() );
			return self::response(
				true,
				__( 'اگر ادامه با این شماره مجاز باشد، کد تأیید ارسال می‌شود.', 'pinova' ),
				[
					'jwt' => JWT::encode(
						[
							'flow_id' => $flow[0],
							'user_id' => $user_id,
							'purpose' => OTP::TYPE_VERIFY_MOBILE,
						],
						$ttl
					),
					'ttl' => $ttl,
				]
			);
		} catch ( RateLimitException $exception ) {
			return self::response( false, __( 'تعداد درخواست‌ها بیش از حد مجاز است. کمی بعد دوباره تلاش کنید.', 'pinova' ), [], 429, [ 'Retry-After' => $exception->get_retry_after() ] );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return self::response( false, __( 'درخواست انجام نشد. کمی بعد دوباره تلاش کنید. اگر مشکل ادامه داشت، کد پیگیری را به پشتیبانی بدهید.', 'pinova' ), [], 503 );
		}
	}

	public function verify( WP_REST_Request $request ): WP_REST_Response {
		if ( is_wp_error( $this->permission_callback( $request ) ) ) {
			return self::response( false, __( 'درخواست معتبر نمی‌باشد.', 'pinova' ), [], 403 );
		}
		$jwt  = $request->get_param( 'jwt' );
		$code = $request->get_param( 'code' );
		if ( ! is_string( $jwt ) || strlen( $jwt ) > 2048 || ! is_string( $code )
			|| ! preg_match( '/\A[0-9]{' . OTPService::code_length() . '}\z/', $code ) || null !== $request->get_param( 'flow_id' ) ) {
			return self::response( false, self::otp_failure_message(), [], 400 );
		}
		try {
			MobileVerificationService::with_lock(
				get_current_user_id(),
				static function () use ( $jwt, $code ): void {
					OTPService::verify_with_flow( $jwt, $code, [ OTP::TYPE_VERIFY_MOBILE ] );
				}
			);
			return self::response( true, __( 'تلفن همراه این حساب تأیید شد.', 'pinova' ) );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return self::response( false, self::otp_failure_message(), [], 401 );
		}
	}
}
