<?php

namespace Pinova\Services;

use Exception;
use Pinova\Helpers\JWT;
use Pinova\Logging\Logger;
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
		return email_exists( $email ) ?? null;
	}

	/**
	 * @param string|Mobile $mobile
	 *
	 * @return int|null
	 */
	public static function get_by_mobile( $mobile ): ?int {
		if ( ! is_a( $mobile, Mobile::class ) ) {

			$mobile = new Mobile( $mobile );
		}

		if ( ! $mobile->is_valid() ) {
			return null;
		}

		$candidate_ids = self::get_mobile_candidate_ids( $mobile );

		if ( 1 === count( $candidate_ids ) ) {
			return $candidate_ids[0];
		}

		if ( count( $candidate_ids ) > 1 ) {
			Logger::instance()->warning(
				'identity.mobile_conflict',
				[
					'identifier_type'        => 'mobile',
					'identifier_fingerprint' => Logger::instance()->fingerprint( $mobile->get_formatted(), 'mobile' ),
					'candidate_count'        => count( $candidate_ids ),
				]
			);
			do_action( 'pinova/identity_conflict_detected', 'mobile', $candidate_ids );
		}

		return null;
	}

	/**
	 * @param string|Mobile $mobile
	 */
	public static function mobile_is_available_for_user( $mobile, int $user_id ): bool {
		if ( ! is_a( $mobile, Mobile::class ) ) {
			$mobile = new Mobile( $mobile );
		}

		if ( ! $mobile->is_valid() ) {
			return false;
		}

		$candidate_ids = self::get_mobile_candidate_ids( $mobile );

		return [] === array_values( array_diff( $candidate_ids, [ $user_id ] ) );
	}

	private static function get_mobile_candidate_ids( Mobile $mobile ): array {
		global $wpdb;

		$possible_formats = array_values( array_unique( array_map( 'strval', $mobile->possible_formats() ) ) );
		$login_tokens     = implode( ', ', array_fill( 0, count( $possible_formats ), '%s' ) );
		$login_query      = "SELECT `ID` FROM %i WHERE `user_login` IN ({$login_tokens}) ORDER BY `ID` LIMIT 1";
		$login_params     = array_merge( [ $wpdb->users ], $possible_formats );
		$login_user_id    = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The dynamic fragment contains placeholders only.
				$login_query,
				...$login_params
			)
		);

		$meta_keys     = self::mobile_possible_meta_keys();
		$key_tokens    = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );
		$value_tokens  = implode( ', ', array_fill( 0, count( $possible_formats ), '%s' ) );
		$meta_query    = "SELECT DISTINCT `user_id` FROM %i WHERE `meta_key` IN ({$key_tokens}) AND `meta_value` IN ({$value_tokens}) ORDER BY `user_id`";
		$meta_params   = array_merge( [ $wpdb->usermeta ], $meta_keys, $possible_formats );
		$meta_user_ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The dynamic fragments contain placeholders only.
					$meta_query,
					...$meta_params
				)
			)
		);
		$candidate_ids = array_values( array_unique( array_filter( array_merge( [ $login_user_id ], $meta_user_ids ) ) ) );
		$candidate_ids = array_values(
			array_filter(
				$candidate_ids,
				static function ( int $candidate_id ) use ( $possible_formats ): bool {
					$explicit_mobile = self::get_persisted_mobile( $candidate_id );

					return null === $explicit_mobile || in_array( $explicit_mobile, $possible_formats, true );
				}
			)
		);

		return $candidate_ids;
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

		$persisted_mobile = self::get_persisted_mobile( $user_id );

		if ( null !== $persisted_mobile ) {
			return $persisted_mobile;
		}

		$mobile = new Mobile( $user->user_login );

		if ( $mobile->is_valid() ) {
			return $mobile->get_formatted();
		}

		foreach ( array_diff( self::mobile_possible_meta_keys(), [ 'pinova_mobile' ] ) as $possible_meta_key ) {

			$raw_value = get_metadata_raw( 'user', $user_id, $possible_meta_key, true );
			$mobile    = new Mobile( is_scalar( $raw_value ) ? (string) $raw_value : '' );

			if ( $mobile->is_valid() ) {
				return $mobile->get_formatted();
			}

		}

		return null;
	}

	public static function match( Identifier $identifier ): ?int {
		$user_id = null;
		if ( $identifier->is_email() ) {
			$user_id = UserService::get_by_email( $identifier->get_value() );
		}

		if ( $identifier->is_mobile() ) {
			$user_id = UserService::get_by_mobile( $identifier->get_value() );
		}

		if ( $identifier->is_username() ) {
			$user_id = UserService::get_by_username( $identifier->get_value() );
		}

		Logger::instance()->debug(
			'identity.resolved',
			[
				'user_id'                => $user_id,
				'identifier_type'        => $identifier->get_type(),
				'identifier_fingerprint' => Logger::instance()->fingerprint( $identifier->get_value(), $identifier->get_type() ),
				'result'                 => $user_id ? 'matched' : 'unmatched',
			]
		);

		return $user_id;
	}

	public static function update_username( int $user_id, string $username ): bool {
		unset( $user_id, $username );
		_deprecated_function( __METHOD__, '1.2.3', 'update_user_meta( $user_id, "pinova_mobile", $mobile )' );

		return false;
	}

	public static function login( int $user_id, ?string $login_method = null ) {
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
		Logger::instance()->info(
			'auth.session_created',
			[
				'user_id'     => $user_id,
				'auth_method' => $login_method ?: 'unknown',
			]
		);
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
		Logger::instance()->info( 'auth.session_destroyed', [ 'user_id' => $user_id ] );
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

		$allowed_roles = self::allowed_registration_roles();
		$requested_role = sanitize_key( (string) ( $userdata['role'] ?? get_option( 'default_role', 'subscriber' ) ) );

		if ( ! isset( $allowed_roles[ $requested_role ] ) ) {
			$requested_role = isset( $allowed_roles['subscriber'] ) ? 'subscriber' : (string) array_key_first( $allowed_roles );
		}

		if ( '' === $requested_role ) {
			throw new Exception( __( 'هیچ نقش امنی برای ثبت‌نام پیکربندی نشده است.', 'pinova' ) );
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
		Logger::instance()->notice(
			'user.registered',
			[
				'user_id'        => $user_id,
				'identifier_type' => 'mobile',
				'auth_method'     => 'otp',
			]
		);

		return $user_id;
	}

	public static function mobile_possible_meta_keys(): array {

		$mobile_possible_meta_keys = Pinova::get_option( 'advanced.mobile_possible_meta_keys' );
		$mobile_possible_meta_keys = explode( PHP_EOL, strval( $mobile_possible_meta_keys ) );
		$mobile_possible_meta_keys = array_map( 'trim', $mobile_possible_meta_keys );

		// Pinova's persisted override and legacy Digits aliases.
		$mobile_possible_meta_keys[] = 'pinova_mobile';
		$mobile_possible_meta_keys[] = 'digits_phone';
		$mobile_possible_meta_keys[] = 'digits_phone_no';

		$mobile_possible_meta_keys = array_diff( $mobile_possible_meta_keys, [ 'billing_phone', 'shipping_phone' ] );

		$mobile_possible_meta_keys = apply_filters( 'pinova/mobile_possible_meta_keys', $mobile_possible_meta_keys );

		return array_values( array_unique( array_filter( $mobile_possible_meta_keys ) ) );
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

		$filtered = apply_filters( 'pinova/allowed_registration_roles', $allowed );
		$filtered = is_array( $filtered ) ? $filtered : [];
		$safe     = [];

		foreach ( $filtered as $slug => $label ) {
			$slug = sanitize_key( (string) $slug );

			if ( ! isset( wp_roles()->roles[ $slug ] ) ) {
				continue;
			}

			$capabilities = array_keys( array_filter( (array) wp_roles()->roles[ $slug ]['capabilities'] ) );

			if ( array_intersect( $denied_capabilities, $capabilities ) ) {
				continue;
			}

			$safe[ $slug ] = sanitize_text_field( (string) $label );
		}

		return $safe;
	}

	private static function get_persisted_mobile( int $user_id ): ?string {
		global $wpdb;

		$raw_value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT `meta_value` FROM %i WHERE `user_id` = %d AND `meta_key` = %s ORDER BY `umeta_id` DESC LIMIT 1',
				$wpdb->usermeta,
				$user_id,
				'pinova_mobile'
			)
		);
		$mobile    = new Mobile( is_scalar( $raw_value ) ? (string) $raw_value : '' );

		return $mobile->is_valid() ? $mobile->get_formatted() : null;
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
