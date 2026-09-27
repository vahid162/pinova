<?php

namespace Pinova\Services;

use Exception;
use Pinova\Models\OTP;
use Pinova\Objects\Identifier;
use Pinova\Pinova;
use Throwable;
use WP_Error;
use WP_User;

/** Eligibility is rechecked at the account and session mutation boundaries. */
final class AuthenticationPolicy {
	/** @return WP_User|WP_Error */
	public static function session( int $user_id, string $method, ?Identifier $identifier = null ) {
		try {
			clean_user_cache( $user_id );
			$user = get_userdata( $user_id );
			if ( ! $user instanceof WP_User || UserService::is_native_only( $user ) || FirewallService::is_ip_blocked() ) {
				return self::denied();
			}
			if ( null !== $identifier && ( ! $identifier->is_valid() || UserService::match( $identifier ) !== $user_id ) ) {
				return self::denied();
			}
			$mobile_result = UserService::get_persisted_mobile_result( $user_id );
			if ( ! $mobile_result['success'] ) {
				return self::denied();
			}
			if ( null !== $identifier ) {
				$identifiers = [ $identifier->get_value() ];
			} else {
				$identifiers = [ $user->user_email, UserService::get_mobile( $user_id ) ];
				// An immutable mobile-shaped username may be an obsolete alias after a profile override.
				if ( ! ( new Identifier( $user->user_login ) )->is_mobile() ) {
					$identifiers[] = $user->user_login;
				}
			}
			foreach ( array_unique( $identifiers ) as $value ) {
				if ( null === $value || '' === $value ) {
					continue;
				}
				if ( FirewallService::is_blocked( $value ) ) {
					return self::denied();
				}
			}
			// Extensions may deny an otherwise eligible request, never override core denials.
			return true === apply_filters( 'pinova/authentication_policy', true, $user, $method, $identifier )
				? $user : self::denied();
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return self::denied();
		}
	}

	/** Revalidate the exact account and purpose before consuming an OTP or creating a user. */
	public static function assert_otp( OTP $otp ): void {
		$identifier = new Identifier( $otp->identifier );
		if ( OTP::TYPE_VERIFY_MOBILE === $otp->type ) {
			$allowed = null !== $otp->user_id && get_current_user_id() === (int) $otp->user_id
				&& MobileVerificationService::can_assign( (int) $otp->user_id, $identifier );
		} elseif ( null !== $otp->user_id ) {
			$allowed = in_array( $otp->type, [ OTP::TYPE_LOGIN, OTP::TYPE_FORGET ], true )
				&& ! is_wp_error( self::session( (int) $otp->user_id, OTP::TYPE_FORGET === $otp->type ? 'recovery' : 'otp', $identifier ) );
		} else {
			try {
				[ $matched, $unclaimed ] = UserService::match_with_registration_policy( $identifier );
				$allowed                 = OTP::TYPE_REGISTER === $otp->type && $identifier->is_mobile()
					&& null === $matched && $unclaimed && Pinova::users_can_register()
					&& ! FirewallService::is_ip_blocked() && ! FirewallService::is_blocked( $identifier->get_value() )
					&& true === apply_filters( 'pinova/authentication_policy', true, null, 'register', $identifier );
			} catch ( Throwable $throwable ) {
				unset( $throwable );
				$allowed = false;
			}
		}
		if ( ! $allowed ) {
			throw new Exception( __( 'کد تأیید معتبر نمی‌باشد.', 'pinova' ) );
		}
	}

	public static function denied(): WP_Error {
		return new WP_Error( 'pinova_authentication_denied', __( 'اطلاعات ورود معتبر نمی‌باشد.', 'pinova' ) );
	}
}
