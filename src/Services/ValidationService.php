<?php

namespace Pinova\Services;

use Pinova\Helpers\Number;
use Pinova\Helpers\Password;
use Pinova\Objects\Identifier;
use Pinova\Objects\Mobile;
use WP_REST_Request;
use WP_Error;

class ValidationService {

	/** @return bool|WP_Error */
	public static function identifier( string $param, WP_REST_Request $request, string $key ) {
		$identifier = new Identifier( $param );

		if ( ! $identifier->is_valid() ) {
			return new WP_Error( 'pinova_invalid_identifier', __( 'ایمیل، تلفن همراه یا نام کاربری معتبر نمی‌باشد.', 'pinova' ) );
		}

		$is_blocked = FirewallService::is_blocked( $identifier->get_value() );

		if ( $is_blocked ) {
			return new WP_Error( 'pinova_blocked_identifier', __( 'ایمیل یا تلفن همراه مسدود شده است.', 'pinova' ) );
		}

		$request->set_param( 'identifier', $identifier );

		return true;
	}

	/** @return bool|WP_Error */
	public static function mobile( string $param, WP_REST_Request $request, string $key ) {
		$mobile = new Identifier( $param );

		if ( ! $mobile->is_mobile() ) {
			return new WP_Error( 'pinova_invalid_mobile', __( 'تلفن همراه معتبر نمی‌باشد.', 'pinova' ) );
		}

		$is_blocked = FirewallService::is_blocked( $mobile->get_value() );

		if ( $is_blocked ) {
			return new WP_Error( 'pinova_blocked_mobile', __( 'تلفن همراه مسدود شده است.', 'pinova' ) );
		}

		$request->set_param( 'mobile', $mobile );

		return true;
	}

	/** @return bool|WP_Error */
	public static function email( string $param, WP_REST_Request $request, string $key ) {
		// Email is optional with default value of '', empty string skips conversion validation
		if ( $param === '' ) {
			return true;
		}

		$email = new Identifier( $param );

		if ( ! $email->is_email() ) {
			return new WP_Error( 'pinova_invalid_email', __( 'ایمیل معتبر نمی‌باشد.', 'pinova' ) );
		}

		$is_blocked = FirewallService::is_blocked( $email->get_value() );

		if ( $is_blocked ) {
			return new WP_Error( 'pinova_blocked_email', __( 'ایمیل مسدود شده است.', 'pinova' ) );
		}

		$request->set_param( 'email', $email );

		return true;
	}

	/** @return bool|WP_Error */
	public static function password( string $param, WP_REST_Request $request, string $key ) {

		if ( empty( $param ) ) {
			return new WP_Error( 'pinova_empty_password', __( 'لطفا رمز عبور خود را وارد نمایید.', 'pinova' ) );
		}

		return true;
	}

	/** @return bool|WP_Error */
	public static function code( string $param, WP_REST_Request $request, string $key ) {
		$code = Number::en( $param );

		if ( ! preg_match( '/^[0-9]+$/', $code ) ) {
			return new WP_Error( 'pinova_invalid_code', __( 'کد وارد شده نامعتبر است.', 'pinova' ) );
		}

		$request->set_param( 'code', $code );

		return true;
	}

	/** @return bool|WP_Error */
	public static function reset_key( string $param, WP_REST_Request $request, string $key ) {

		if ( empty( $param ) ) {
			return new WP_Error( 'pinova_invalid_reset_key', __( 'کلید بازنشانی نامعتبر است.', 'pinova' ) );
		}

		return true;
	}

	/** @return bool|WP_Error */
	public static function new_password( string $param, WP_REST_Request $request, string $key ) {
		$validation = Password::validate( $param );

		if ( ! $validation['valid'] ) {
			return new WP_Error( 'pinova_weak_password', __( 'رمزعبور ضعیف است.', 'pinova' ), [ 'errors' => $validation['errors'] ] );
		}

		return true;
	}

	public static function not_empty( string $param, WP_REST_Request $request, string $key ): bool {
		return ! empty( trim( $param ) );
	}
}
