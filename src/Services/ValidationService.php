<?php

namespace Pinova\Services;

use Pinova\API\RestAPI;
use Pinova\Helpers\Number;
use Pinova\Helpers\Password;
use Pinova\Objects\Identifier;
use Pinova\Objects\Mobile;
use WP_REST_Request;

class ValidationService {

	public static function identifier( string $param, WP_REST_Request $request, string $key ): bool {
		$identifier = new Identifier( $param );

		if ( ! $identifier->is_valid() ) {
			RestAPI::response( false, __( 'ایمیل یا تلفن همراه معتبر نمی‌باشد.', 'pinova' ) );
		}

		$is_blocked = FirewallService::is_blocked( $identifier->get_value() );

		if ( $is_blocked ) {
			RestAPI::response( false, __( 'ایمیل یا تلفن همراه مسدود شده است.', 'pinova' ) );
		}

		$request->set_param( 'identifier', $identifier );

		return true;
	}

	public static function mobile( string $param, WP_REST_Request $request, string $key ): bool {
		$mobile = new Identifier( $param );

		if ( ! $mobile->is_mobile() ) {
			RestAPI::response( false, __( 'تلفن همراه معتبر نمی‌باشد.', 'pinova' ) );
		}

		$is_blocked = FirewallService::is_blocked( $mobile->get_value() );

		if ( $is_blocked ) {
			RestAPI::response( false, __( 'تلفن همراه مسدود شده است.', 'pinova' ) );
		}

		$request->set_param( 'mobile', $mobile );

		return true;
	}

	public static function email( string $param, WP_REST_Request $request, string $key ): bool {
		// Email is optional with default value of '', empty string skips conversion validation
		if ( $param === '' ) {
			return true;
		}

		$email = new Identifier( $param );

		if ( ! $email->is_email() ) {
			RestAPI::response( false, __( 'ایمیل معتبر نمی‌باشد.', 'pinova' ) );
		}

		$is_blocked = FirewallService::is_blocked( $email->get_value() );

		if ( $is_blocked ) {
			RestAPI::response( false, __( 'ایمیل مسدود شده است.', 'pinova' ) );
		}

		$request->set_param( 'email', $email );

		return true;
	}

	public static function password( string $param, WP_REST_Request $request, string $key ): bool {

		if ( empty( $param ) ) {
			RestAPI::response( false, __( 'لطفا رمز عبور خود را وارد نمایید.', 'pinova' ) );
		}

		return true;
	}

	public static function code( string $param, WP_REST_Request $request, string $key ): bool {

		$code = intval( $param );

		if ( empty( $code ) ) {
			RestAPI::response( false, __( 'کد وارد شده نامعتبر است.', 'pinova' ) );
		}

		$request->set_param( 'code', Number::en( $code ) );

		return true;
	}

	public static function reset_key( string $param, WP_REST_Request $request, string $key ): bool {

		if ( empty( $param ) || ! is_string( $param ) ) {
			RestAPI::response( false, __( 'کلید بازنشانی نامعتبر است.', 'pinova' ) );
		}

		return true;
	}

	public static function new_password( string $param, WP_REST_Request $request, string $key ): bool {
		$validation = Password::validate( $param );

		if ( ! $validation['valid'] ) {
			RestAPI::response( false, __( 'رمزعبور ضعیف است.', 'pinova' ), [ 'errors' => $validation['errors'] ] );
		}

		return true;
	}

	public static function not_empty( string $param, WP_REST_Request $request, string $key ): bool {
		return ! empty( trim( $param ) );
	}
}