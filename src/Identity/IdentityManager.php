<?php

namespace Pinova\Identity;

use Pinova\CLI\IdentityCommand;
use Pinova\Objects\Identifier;
use stdClass;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

class IdentityManager {
	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		add_filter( 'authenticate', [ self::class, 'block_disabled_authentication' ], 99, 3 );
		add_filter( 'wp_authenticate_application_password_errors', [ self::class, 'block_disabled_application_password' ], 10, 4 );
		add_filter( 'option_users_can_register', [ self::class, 'registration_availability' ] );
		add_filter( 'registration_errors', [ self::class, 'validate_registration_identities' ], 10, 3 );
		add_action( 'user_profile_update_errors', [ self::class, 'validate_profile_identities' ], 5, 3 );
		add_action( 'user_register', [ self::class, 'sync_new_user' ], 20 );
		add_action( 'profile_update', [ self::class, 'sync_profile' ], 20, 2 );
		add_filter( 'rest_pre_dispatch', [ self::class, 'protect_during_maintenance' ], 10, 3 );
		add_filter( 'pinova/submenus', [ IdentityAdmin::class, 'add_submenu' ] );
		add_action( 'admin_post_pinova_identity_merge', [ IdentityAdmin::class, 'handle_merge' ] );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'pinova identity', IdentityCommand::class );
		}
	}

	/** @return WP_User|WP_Error|null */
	public static function block_disabled_authentication( $user, string $username, string $password ) {
		unset( $username, $password );

		if ( self::maintenance_enabled() ) {
			return new WP_Error(
				'pinova_identity_maintenance',
				__( 'ورود و ثبت‌نام برای نگهداری شناسه‌ها موقتاً متوقف شده است.', 'pinova' )
			);
		}

		if ( ! $user instanceof WP_User ) {
			return $user;
		}

		if (
			get_user_meta( $user->ID, 'pinova_merged_into', true )
			|| get_user_meta( $user->ID, 'pinova_account_disabled', true )
		) {
			return new WP_Error(
				'pinova_account_merged',
				__( 'این حساب غیرفعال شده است. برای بازیابی دسترسی با مدیر سایت تماس بگیرید.', 'pinova' )
			);
		}

		return $user;
	}

	/** @param mixed $value */
	public static function registration_availability( $value ) {
		return self::maintenance_enabled() ? 0 : $value;
	}

	public static function block_disabled_application_password( WP_Error $error, WP_User $user, array $item, string $password ): WP_Error {
		unset( $item, $password );

		if (
			get_user_meta( $user->ID, 'pinova_merged_into', true )
			|| get_user_meta( $user->ID, 'pinova_account_disabled', true )
		) {
			$error->add( 'pinova_account_merged', __( 'این حساب غیرفعال شده است.', 'pinova' ) );
		}

		return $error;
	}

	/** @param mixed $result */
	public static function protect_during_maintenance( $result, $server, WP_REST_Request $request ) {
		unset( $server );

		if ( ! self::maintenance_enabled() || ! preg_match( '#^/pinova/(user|auth)(/|$)#', $request->get_route() ) ) {
			return $result;
		}

		$response = new WP_REST_Response(
			[
				'success' => false,
				'message' => __( 'ورود و ثبت‌نام برای نگهداری شناسه‌ها موقتاً متوقف شده است.', 'pinova' ),
				'data'    => [],
			],
			503
		);
		$response->header( 'Retry-After', '300' );

		return $response;
	}

	public static function maintenance_enabled(): bool {
		return (bool) get_option( 'pinova_identity_maintenance', false );
	}

	public static function validate_registration_identities( WP_Error $errors, string $sanitized_user_login, string $user_email ): WP_Error {
		if ( ! IdentityRepository::is_ready() ) {
			return $errors;
		}

		self::validate_identifier_owner( $errors, new Identifier( $sanitized_user_login ), 0, 'username_exists' );

		if ( '' !== $user_email ) {
			self::validate_identifier_owner( $errors, new Identifier( $user_email ), 0, 'email_exists' );
		}

		return $errors;
	}

	public static function validate_profile_identities( WP_Error $errors, bool $update, stdClass $user ): void {
		if ( ! IdentityRepository::is_ready() ) {
			return;
		}

		$user_id = $update ? (int) ( $user->ID ?? 0 ) : 0;
		$email   = sanitize_email( (string) ( $user->user_email ?? '' ) );

		if ( '' !== $email ) {
			self::validate_identifier_owner( $errors, new Identifier( $email ), $user_id, 'email_exists' );
		}
	}

	public static function sync_new_user( int $user_id ): void {
		if ( ! IdentityRepository::is_ready() ) {
			return;
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return;
		}

		try {
			IdentityRepository::add( $user_id, 'username', strtolower( $user->user_login ), current_time( 'mysql', true ), 'user_register', true );

			if ( '' !== $user->user_email ) {
				IdentityRepository::add( $user_id, 'email', strtolower( $user->user_email ), null, 'user_register', true );
			}
		} catch ( IdentityConflictException $conflict ) {
			ConflictRepository::record( $conflict );
		}
	}

	public static function sync_profile( int $user_id, WP_User $old_user_data ): void {
		if ( ! IdentityRepository::is_ready() ) {
			return;
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return;
		}

		try {
			IdentityRepository::add( $user_id, 'username', strtolower( $user->user_login ), current_time( 'mysql', true ), 'profile_update', true );

			if ( '' !== $user->user_email && strtolower( $old_user_data->user_email ) !== strtolower( $user->user_email ) ) {
				IdentityRepository::replace_user_type( $user_id, 'email', strtolower( $user->user_email ), null, 'profile_update' );
			}
		} catch ( IdentityConflictException $conflict ) {
			ConflictRepository::record( $conflict );
		}
	}

	private static function validate_identifier_owner( WP_Error $errors, Identifier $identifier, int $user_id, string $code ): void {
		if ( ! $identifier->is_valid() ) {
			return;
		}

		try {
			$existing_user_id = IdentityResolver::resolve( $identifier, false );

			if ( $existing_user_id && $existing_user_id !== $user_id ) {
				$errors->add( $code, __( 'این شناسه قبلاً به حساب کاربری دیگری متصل شده است.', 'pinova' ) );
			}
		} catch ( IdentityConflictException $conflict ) {
			$errors->add( 'pinova_identity_conflict', __( 'این شناسه بین چند حساب تعارض دارد و باید توسط مدیر بررسی شود.', 'pinova' ) );
		}
	}
}
