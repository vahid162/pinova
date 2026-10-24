<?php

namespace Pinova\Services;

use Pinova\Exceptions\BlockedException;
use Pinova\Helpers\Number;
use Pinova\Helpers\Password;
use Pinova\Objects\Identifier;
use Pinova\Objects\Mobile;
use WP_REST_Request;
use WP_Error;

class ValidationService {

	/** @return bool|WP_Error */
	public static function scalar( $param, WP_REST_Request $request, string $key ) {
		return is_scalar( $param ) ? true : self::invalid_type();
	}

	/** @return bool|WP_Error */
	public static function state( $param, WP_REST_Request $request, string $key ) {
		return is_string( $param ) && '' !== $param && strlen( $param ) <= 2048 ? true : self::invalid_type();
	}

	private static function invalid_type(): WP_Error {
		return new WP_Error( 'pinova_invalid_type', __( 'نوع مقدار ورودی معتبر نیست.', 'pinova' ) );
	}

	/** @return bool|WP_Error */
	public static function identifier( $param, WP_REST_Request $request, string $key ) {
		if ( ! is_scalar( $param ) ) {
			return self::invalid_type();
		}
		$param      = (string) $param;
		$identifier = new Identifier( $param );

		if ( ! $identifier->is_valid() ) {
			return new WP_Error( 'pinova_invalid_identifier', __( 'شمارهٔ موبایل، ایمیل یا نام کاربری را با فرمت درست وارد کنید.', 'pinova' ) );
		}

		$is_blocked = FirewallService::is_blocked( $identifier->get_value() );

		if ( $is_blocked ) {
			return new WP_Error( 'pinova_blocked_identifier', BlockedException::message_for( $identifier->get_type() ) );
		}

		$request->set_param( 'identifier', $identifier );

		return true;
	}

	/** @return bool|WP_Error */
	public static function mobile( $param, WP_REST_Request $request, string $key ) {
		if ( ! is_scalar( $param ) ) {
			return self::invalid_type();
		}
		$param  = (string) $param;
		$mobile = new Identifier( $param );

		if ( ! $mobile->is_mobile() ) {
			return new WP_Error( 'pinova_invalid_mobile', __( 'شمارهٔ موبایل را کامل و با فرمت درست وارد کنید.', 'pinova' ) );
		}

		$is_blocked = FirewallService::is_blocked( $mobile->get_value() );

		if ( $is_blocked ) {
			return new WP_Error( 'pinova_blocked_mobile', BlockedException::message_for( 'mobile' ) );
		}

		$request->set_param( 'mobile', $mobile );

		return true;
	}

	/** @return bool|WP_Error */
	public static function email( $param, WP_REST_Request $request, string $key ) {
		if ( ! is_scalar( $param ) ) {
			return self::invalid_type();
		}
		$param = (string) $param;
		// Email is optional with default value of '', empty string skips conversion validation
		if ( '' === $param ) {
			return true;
		}

		$email = new Identifier( $param );

		if ( ! $email->is_email() ) {
			return new WP_Error( 'pinova_invalid_email', __( 'نشانی ایمیل را با فرمت درست وارد کنید.', 'pinova' ) );
		}

		$is_blocked = FirewallService::is_blocked( $email->get_value() );

		if ( $is_blocked ) {
			return new WP_Error( 'pinova_blocked_email', BlockedException::message_for( 'email' ) );
		}

		$request->set_param( 'email', $email );

		return true;
	}

	/** @return bool|WP_Error */
	public static function password( $param, WP_REST_Request $request, string $key ) {
		if ( ! is_scalar( $param ) ) {
			return self::invalid_type();
		}
		$param = (string) $param;

		if ( empty( $param ) ) {
			return new WP_Error( 'pinova_empty_password', __( 'لطفا رمز عبور خود را وارد نمایید.', 'pinova' ) );
		}

		return true;
	}

	/** @return bool|WP_Error */
	public static function code( $param, WP_REST_Request $request, string $key ) {
		if ( ! is_scalar( $param ) ) {
			return self::invalid_type();
		}
		$param = (string) $param;
		$code  = Number::en( $param );

		if ( ! preg_match( '/^[0-9]+$/', $code ) ) {
			return new WP_Error( 'pinova_invalid_code', __( 'کد تأیید را فقط با رقم وارد کنید.', 'pinova' ) );
		}

		$request->set_param( 'code', $code );

		return true;
	}

	/** @return bool|WP_Error */
	public static function reset_key( $param, WP_REST_Request $request, string $key ) {
		if ( ! is_scalar( $param ) ) {
			return self::invalid_type();
		}
		$param = (string) $param;

		if ( empty( $param ) ) {
			return new WP_Error( 'pinova_invalid_reset_key', __( 'کلید بازنشانی نامعتبر است.', 'pinova' ) );
		}

		return true;
	}

	/** @return bool|WP_Error */
	public static function new_password( $param, WP_REST_Request $request, string $key ) {
		if ( ! is_scalar( $param ) ) {
			return self::invalid_type();
		}
		$param      = (string) $param;
		$validation = Password::validate( $param );

		if ( ! $validation['valid'] ) {
			return new WP_Error( 'pinova_weak_password', __( 'رمزعبور ضعیف است.', 'pinova' ), [ 'errors' => $validation['errors'] ] );
		}

		return true;
	}

	public static function not_empty( $param, WP_REST_Request $request, string $key ): bool {
		return is_scalar( $param ) && ! empty( trim( (string) $param ) );
	}
}
