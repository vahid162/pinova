<?php

namespace Pinova\Services;

use Exception;
use Nabik_Net_Database;
use Pinova\Helpers\JWT;
use Pinova\Models\OTP;
use Pinova\Objects\Identifier;
use Pinova\Objects\Mobile;
use Pinova\Pinova;
use WP_User;

class UserService {

	/**
	 * @param string $email
	 *
	 * @return int|null
	 */
	public static function get_by_email( string $email ): ?int {
		return email_exists( $email ) ?? null;
	}

	/**
	 * @param string|Mobile $mobile
	 *
	 * @return int|null
	 */
	public static function get_by_mobile( $mobile ): ?int {
		global $wpdb;

		if ( ! is_a( $mobile, Mobile::class ) ) {

			$mobile = new Mobile( $mobile );

			if ( ! $mobile->is_valid() ) {
				return null;
			}
		}

		$possible_formats = $mobile->possible_formats();

		$query = sprintf( "SELECT
									ID 
								FROM
									`%s` 
								WHERE
									`user_login` IN ( '%s' )
								ORDER BY `ID` 
								LIMIT 1;",
			$wpdb->users,
			implode( "','", $possible_formats ) );

		$user_id = intval( $wpdb->get_var( $query ) );

		if ( $user_id ) {
			return $user_id;
		}

		$query = sprintf( "SELECT
										`user_id` 
									FROM
										`%s` 
									WHERE
										`meta_key` IN ( '%s' ) 
										AND 
										`meta_value` IN ( '%s' )
									ORDER BY `user_id` 
									LIMIT 1;",
			$wpdb->usermeta,
			implode( "','", self::mobile_possible_meta_keys() ),
			implode( "','", $possible_formats )
		);

		$user_id = intval( $wpdb->get_var( $query ) );

		if ( $user_id ) {
			return $user_id;
		}

		return null;
	}

	public static function get_by_username( string $username ): ?int {
		return username_exists( $username ) ?? null;
	}

	/**
	 * @param int $user_id
	 *
	 * @return string|null
	 */
	public static function get_mobile( int $user_id ): ?string {

		$user = get_userdata( $user_id );

		if ( $user === false ) {
			return null;
		}

		$mobile = new Mobile( $user->user_login );

		if ( $mobile->is_valid() ) {
			return $mobile->get_formatted();
		}

		foreach ( self::mobile_possible_meta_keys() as $possible_meta_key ) {

			$mobile = new Mobile( strval( $user->get( $possible_meta_key ) ) );

			if ( $mobile->is_valid() ) {
				return $mobile->get_formatted();
			}

		}

		return null;
	}

	public static function match( Identifier $identifier ): ?int {

		if ( $identifier->is_email() ) {
			return UserService::get_by_email( $identifier->get_value() );
		}

		if ( $identifier->is_mobile() ) {
			return UserService::get_by_mobile( $identifier->get_value() );
		}

		if ( $identifier->is_username() ) {
			return UserService::get_by_username( $identifier->get_value() );
		}

		return null;
	}

	public static function update_username( int $user_id, string $username ): bool {

		$username = sanitize_user( $username, true );

		try {
			$username_updated = (bool) Nabik_Net_Database::DB()
			                                             ->table( 'users' )
			                                             ->where( 'ID', $user_id )
			                                             ->update( [ 'user_login' => $username ] );
		} catch ( Exception $e ) {
			return false;
		}

		if ( $username_updated ) {
			do_action( 'pinova/username_updated', $user_id );
		}

		return $username_updated;
	}

	public static function login( int $user_id, ?string $login_method = null ) {
		clean_user_cache( $user_id );
		wp_clear_auth_cookie();
		wp_set_auth_cookie( $user_id, true );
		wp_set_current_user( $user_id );

		if ( $login_method ) {
			update_user_meta( $user_id, 'pinova_login_method', $login_method );
		}

		do_action( 'pinova/user_logged_in', $user_id );
	}

	public static function logout() {

		$user_id = get_current_user_id();

		clean_user_cache( $user_id );
		wp_logout();

		do_action( 'pinova/user_logged_out', $user_id );
	}

	/**
	 * @param string|Mobile $mobile
	 * @param string        $email
	 * @param array         $userdata
	 *
	 * @return int
	 * @throws Exception
	 */
	public static function create( $mobile, string $email = '', array $userdata = [] ): int {
		global $wpdb;

		if ( ! is_a( $mobile, Mobile::class ) ) {

			$mobile = new Mobile( $mobile );

			if ( ! $mobile->is_valid() ) {
				throw new Exception( 'تلفن همراه برای ثبت نام معتبر نمی‌باشد.' );
			}
		}

		$userdata = wp_parse_args( $userdata, [
			'user_email' => '',
			'first_name' => __( 'کاربر', 'pinova' ),
			'last_name'  => '',
			'meta_input' => [],
		] );

		$username = $mobile->get_sanitized_username();

		if ( is_email( $email ) ) {
			$userdata['user_email'] = $email;
		}

		$userdata['user_login']               = $username;
		$userdata['user_pass']                = wp_generate_password();
		$userdata['user_nicename']            = wp_generate_password( 12, false ); // @todo edit nicename in profile
		$userdata['meta_input']['created_by'] = 'pinova';

		$user_id = wp_insert_user( $userdata );

		if ( is_wp_error( $user_id ) ) {

			error_log( $user_id->get_error_message() );

			throw new Exception( 'خطایی در زمان ایجاد کاربر رخ داده است.' );
		} elseif ( empty ( $user_id ) ) {
			throw new Exception( 'خطایی در زمان ایجاد کاربر رخ داده است!' );
		}

		$wpdb->update( $wpdb->users, [
			'user_pass' => 'NO_PASSWORD_' . wp_generate_password(),
		], [
			'ID' => $user_id,
		] );

		do_action( 'pinova/user_registered', $user_id );

		return $user_id;
	}

	public static function mobile_possible_meta_keys(): array {

		$mobile_possible_meta_keys = Pinova::get_option( 'advanced.mobile_possible_meta_keys' );
		$mobile_possible_meta_keys = explode( PHP_EOL, strval( $mobile_possible_meta_keys ) );
		$mobile_possible_meta_keys = array_map( 'trim', $mobile_possible_meta_keys );

		// Digits
		$mobile_possible_meta_keys[] = 'digits_phone';
		$mobile_possible_meta_keys[] = 'digits_phone_no';

		$mobile_possible_meta_keys = array_diff( $mobile_possible_meta_keys, [ 'billing_phone', 'shipping_phone' ] );

		$mobile_possible_meta_keys = apply_filters( 'pinova/mobile_possible_meta_keys', $mobile_possible_meta_keys );

		return array_unique( array_filter( $mobile_possible_meta_keys ) );
	}

	/**
	 * @throws Exception
	 */
	public static function create_by_otp( OTP $otp ): int {
		return self::create( $otp->identifier );
	}

	/**
	 * @throws Exception
	 */
	public static function get_or_create( OTP $otp ): WP_User {

		$user_id = $otp->user_id;

		if ( is_null( $user_id ) ) {
			$user_id = self::create_by_otp( $otp );
		}

		return new WP_User( $user_id );
	}

	/**
	 * @param int $user_id
	 *
	 * @return string
	 */
	public static function generate_jwt( int $user_id ): string {
		return JWT::encode( [
			'user_id' => $user_id,
		], HOUR_IN_SECONDS );
	}

	/**
	 * @param string $jwt
	 *
	 * @return int
	 * @throws Exception
	 */
	public static function parse_jwt( string $jwt ): int {

		try {
			$payload = JWT::decode( $jwt );
		} catch ( Exception $e ) {
			throw new Exception( __( 'حساب کاربری معتبر نمی‌باشد.', 'pinova' ) );
		}

		if ( isset( $payload['user_id'] ) ) {
			return intval( $payload['user_id'] );
		}

		throw new Exception( __( 'حساب کاربری معتبر نمی‌باشد.', 'pinova' ) );
	}

}