<?php

namespace Pinova\Identity;

use Exception;
use Pinova\Objects\Identifier;
use Pinova\Objects\Mobile;
use Pinova\Services\UserService;

class IdentityRegistrationService {
	public static function create( Mobile $mobile, string $email, array $userdata, bool $mobile_verified ): int {
		global $wpdb;

		$mobile_value = IdentityNormalizer::normalize( Identifier::TYPE_MOBILE, $mobile->get_formatted() );
		$email_value  = IdentityNormalizer::normalize( Identifier::TYPE_EMAIL, $email );

		if ( ! $mobile_value ) {
			throw new Exception( __( 'شماره موبایل معتبر نیست.', 'pinova' ) );
		}

		if ( '' !== trim( $email ) && ! $email_value ) {
			throw new Exception( __( 'ایمیل معتبر نیست.', 'pinova' ) );
		}

		$locks = [ self::lock_name( 'mobile', (string) $mobile_value ) ];

		if ( $email_value ) {
			$locks[] = self::lock_name( 'email', $email_value );
		}

		sort( $locks );
		self::acquire_locks( $locks );
		$wpdb->query( 'START TRANSACTION' );

		$created_user_id = 0;

		try {
			$allowed_roles  = UserService::allowed_registration_roles();
			$requested_role = sanitize_key( (string) ( $userdata['role'] ?? get_option( 'default_role', 'subscriber' ) ) );

			if ( ! isset( $allowed_roles[ $requested_role ] ) ) {
				throw new Exception( __( 'نقش درخواستی برای ثبت‌نام مجاز نیست.', 'pinova' ) );
			}

			$userdata          = array_intersect_key(
				$userdata,
				array_flip(
					[
						'first_name',
						'last_name',
						'display_name',
						'user_url',
						'locale',
					]
				)
			);
			$userdata['role']  = $requested_role;
			$mobile_identifier = new Identifier( (string) $mobile_value );

			if ( IdentityResolver::resolve( $mobile_identifier ) ) {
				throw new Exception( __( 'حساب کاربری با این شناسه از قبل وجود دارد.', 'pinova' ) );
			}

			if ( $email_value && IdentityResolver::resolve( new Identifier( $email_value ) ) ) {
				throw new Exception( __( 'حساب کاربری با این شناسه از قبل وجود دارد.', 'pinova' ) );
			}

			$username                             = self::generate_internal_username();
			$userdata                             = wp_parse_args(
				$userdata,
				[
					'user_email' => $email_value ?: '',
					'first_name' => __( 'کاربر', 'pinova' ),
					'last_name'  => '',
					'meta_input' => [],
				]
			);
			$userdata['user_login']               = $username;
			$userdata['user_email']               = $email_value ?: '';
			$userdata['user_pass']                = wp_generate_password( 32, true, true );
			$userdata['user_nicename']            = wp_generate_password( 20, false );
			$userdata['meta_input']['created_by'] = 'pinova';
			$user_id                              = wp_insert_user( $userdata );

			if ( is_wp_error( $user_id ) || ! $user_id ) {
				throw new Exception( __( 'خطایی در زمان ایجاد کاربر رخ داده است.', 'pinova' ) );
			}

			$created_user_id = (int) $user_id;
			IdentityRepository::add( (int) $user_id, 'username', strtolower( $username ), current_time( 'mysql', true ), 'system', true );
			IdentityRepository::add(
				(int) $user_id,
				'mobile',
				(string) $mobile_value,
				$mobile_verified ? current_time( 'mysql', true ) : null,
				$mobile_verified ? 'otp' : 'registration',
				true
			);

			if ( $email_value ) {
				IdentityRepository::add( (int) $user_id, 'email', $email_value, null, 'registration', true );
			}

			$wpdb->query( 'COMMIT' );
			clean_user_cache( (int) $user_id );
		} catch ( \Throwable $throwable ) {
			$wpdb->query( 'ROLLBACK' );

			if ( $created_user_id ) {
				clean_user_cache( $created_user_id );
			}

			throw $throwable;
		} finally {
			self::release_locks( $locks );
		}

		do_action( 'pinova/user_registered', $created_user_id );

		return $created_user_id;
	}

	private static function generate_internal_username(): string {
		do {
			$username = 'pinova_' . strtolower( wp_generate_password( 24, false, false ) );
			$username = sanitize_user( $username, true );
		} while ( username_exists( $username ) );

		return $username;
	}

	private static function lock_name( string $type, string $value ): string {
		return 'pinova:' . substr( hash( 'sha256', $type . ':' . $value ), 0, 48 );
	}

	private static function acquire_locks( array $locks ): void {
		global $wpdb;

		foreach ( $locks as $lock ) {
			$acquired = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) );

			if ( 1 !== $acquired ) {
				self::release_locks( $locks );
				throw new Exception( __( 'ثبت‌نام هم‌زمان دیگری در حال پردازش است. دوباره تلاش کنید.', 'pinova' ) );
			}
		}
	}

	private static function release_locks( array $locks ): void {
		global $wpdb;

		foreach ( array_reverse( $locks ) as $lock ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
}
