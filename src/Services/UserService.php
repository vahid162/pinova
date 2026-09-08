<?php

namespace Pinova\Services;

use Exception;
use Pinova\Helpers\JWT;
use Pinova\Identity\IdentityConflictException;
use Pinova\Identity\IdentityRegistrationService;
use Pinova\Identity\IdentityRepository;
use Pinova\Identity\IdentityResolver;
use Pinova\Models\OTP;
use Pinova\Objects\Identifier;
use Pinova\Objects\Mobile;
use Pinova\Pinova;
use WP_Error;
use WP_User;

class UserService {

	/**
	 * @param string $email
	 *
	 * @return int|null
	 */
	public static function get_by_email( string $email ): ?int {
		if ( IdentityRepository::is_ready() ) {
			try {
				return IdentityResolver::resolve( new Identifier( $email ) );
			} catch ( IdentityConflictException $conflict ) {
				return null;
			}
		}

		return email_exists( $email ) ?? null;
	}

	/**
	 * @param string|Mobile $mobile
	 *
	 * @return int|null
	 */
	public static function get_by_mobile( $mobile ): ?int {
		if ( IdentityRepository::is_ready() ) {
			try {
				$value = $mobile instanceof Mobile ? $mobile->get_formatted() : (string) $mobile;

				return IdentityResolver::resolve( new Identifier( $value ) );
			} catch ( IdentityConflictException $conflict ) {
				return null;
			}
		}

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
		if ( IdentityRepository::is_ready() ) {
			try {
				return IdentityResolver::resolve( new Identifier( $username ) );
			} catch ( IdentityConflictException $conflict ) {
				return null;
			}
		}

		return username_exists( $username ) ?? null;
	}

	/**
	 * @param int $user_id
	 *
	 * @return string|null
	 */
	public static function get_mobile( int $user_id ): ?string {
		if ( IdentityRepository::is_ready() ) {
			$user_id = IdentityResolver::canonical_user_id( $user_id );

			foreach ( IdentityRepository::for_user( $user_id ) as $identity ) {
				if ( 'mobile' === $identity['type'] && 'active' === $identity['status'] ) {
					return (string) $identity['normalized_value'];
				}
			}
		}

		$user = get_userdata( $user_id );

		if ( $user === false ) {
			return null;
		}

		$mobile = new Mobile( $user->user_login );

		if ( $mobile->is_valid() ) {
			return $mobile->get_formatted();
		}

		$meta_keys = array_values(
			array_unique(
				array_merge( self::mobile_possible_meta_keys(), [ 'pinova_mobile', 'billing_phone', 'shipping_phone' ] )
			)
		);
		global $wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );
		$parameters   = array_merge( [ $wpdb->usermeta, $user_id ], $meta_keys );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only placeholder tokens are constructed here.
		$query      = "SELECT `meta_value` FROM %i WHERE `user_id` = %d AND `meta_key` IN ({$placeholders}) ORDER BY `umeta_id` ASC";
		$raw_values = (array) $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The constructed query contains placeholders only.
				$query,
				...$parameters
			)
		);

		foreach ( $raw_values as $raw_value ) {
			$raw_value = maybe_unserialize( $raw_value );

			if ( ! is_scalar( $raw_value ) ) {
				continue;
			}

			$mobile = new Mobile( (string) $raw_value );

			if ( $mobile->is_valid() ) {
				return $mobile->get_formatted();
			}

		}

		return null;
	}

	/** @throws IdentityConflictException */
	public static function match( Identifier $identifier, bool $throw_on_conflict = false, bool $record_conflict = true ): ?int {
		if ( IdentityRepository::is_ready() ) {
			try {
				return IdentityResolver::resolve( $identifier, $record_conflict );
			} catch ( IdentityConflictException $conflict ) {
				if ( $throw_on_conflict ) {
					throw $conflict;
				}

				return null;
			}
		}

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
		unset( $user_id, $username );
		_deprecated_function( __METHOD__, '1.3.0', 'IdentityRepository::replace_user_type' );

		return false;
	}

	public static function login( int $user_id, ?string $login_method = null ) {
		if ( IdentityRepository::is_ready() ) {
			$user_id = IdentityResolver::canonical_user_id( $user_id );
		}

		if ( get_user_meta( $user_id, 'pinova_account_disabled', true ) ) {
			return;
		}

		clean_user_cache( $user_id );
		wp_clear_auth_cookie();
		wp_set_auth_cookie( $user_id, true );
		wp_set_current_user( $user_id );

		if ( $login_method ) {
			update_user_meta( $user_id, 'pinova_login_method', $login_method );
		}

		$user = get_userdata( $user_id );

		if ( $user instanceof WP_User ) {
			do_action( 'wp_login', $user->user_login, $user );
		}

		do_action( 'pinova/user_logged_in', $user_id );
	}

	/**
	 * Authenticate an already-resolved user through WordPress' native pipeline.
	 *
	 * @return WP_User|WP_Error
	 */
	public static function authenticate_password( ?int $user_id, string $password, bool $remember = true ) {
		$user = $user_id ? get_userdata( $user_id ) : false;

		$credentials = [
			'user_login'    => $user instanceof WP_User ? $user->user_login : 'pinova-invalid-user-' . wp_generate_password( 20, false ),
			'user_password' => $password,
			'remember'      => $remember,
		];

		return wp_signon( $credentials, is_ssl() );
	}

	public static function is_native_only( WP_User $user ): bool {
		$roles = Pinova::get_option( 'advanced.native_only_roles', [ 'administrator' ] );
		$roles = is_array( $roles ) ? array_map( 'sanitize_key', $roles ) : [ 'administrator' ];

		return (bool) array_intersect( $roles, (array) $user->roles );
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
	public static function create( $mobile, string $email = '', array $userdata = [], bool $mobile_verified = false ): int {
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

		$allowed_roles = self::allowed_registration_roles();
		$requested_role = sanitize_key( (string) ( $userdata['role'] ?? get_option( 'default_role', 'subscriber' ) ) );

		if ( ! isset( $allowed_roles[ $requested_role ] ) ) {
			$requested_role = isset( $allowed_roles['subscriber'] ) ? 'subscriber' : (string) array_key_first( $allowed_roles );
		}

		if ( '' === $requested_role ) {
			throw new Exception( __( 'هیچ نقش امنی برای ثبت‌نام پیکربندی نشده است.', 'pinova' ) );
		}

		$userdata['role'] = $requested_role;

		if ( IdentityRepository::is_ready() ) {
			return IdentityRegistrationService::create( $mobile, $email, $userdata, $mobile_verified );
		}

		$username = $mobile->get_sanitized_username();

		if ( is_email( $email ) ) {
			$userdata['user_email'] = $email;
		}

		$userdata['user_login']               = $username;
		$userdata['user_pass']                = wp_generate_password();
		$userdata['user_nicename']            = wp_generate_password( 12, false ); // @todo edit nicename in profile
		$userdata['role']                     = $requested_role;
		$userdata['meta_input']['created_by'] = 'pinova';

		$user_id = wp_insert_user( $userdata );

		if ( is_wp_error( $user_id ) ) {
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

	public static function allowed_registration_roles(): array {
		$allowed = [];
		$denied_capabilities = [
			'manage_options',
			'promote_users',
			'edit_users',
			'delete_users',
			'create_users',
			'install_plugins',
			'activate_plugins',
			'edit_plugins',
			'edit_theme_options',
			'manage_woocommerce',
			'edit_shop_orders',
		];

		foreach ( wp_roles()->roles as $slug => $role ) {
			$capabilities = array_keys( array_filter( (array) ( $role['capabilities'] ?? [] ) ) );

			if ( array_intersect( $denied_capabilities, $capabilities ) ) {
				continue;
			}

			$allowed[ $slug ] = $role['name'];
		}

		return apply_filters( 'pinova/allowed_registration_roles', $allowed );
	}

	/**
	 * @throws Exception
	 */
	public static function create_by_otp( OTP $otp ): int {
		return self::create( $otp->identifier, '', [], true );
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
