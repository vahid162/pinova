<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\API\UserAPI;
use Pinova\Install;
use Pinova\Models\Block;
use Pinova\Models\OTP;
use Pinova\Objects\Identifier;
use Pinova\Services\OTPService;
use Pinova\Services\UserService;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

final class IntegrationAuthPolicyTest extends WP_UnitTestCase {
	private int $cookies = 0;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 0 );
		add_action( 'set_auth_cookie', [ $this, 'observe_cookie' ] );
	}

	public function tear_down(): void {
		remove_action( 'set_auth_cookie', [ $this, 'observe_cookie' ] );
		OTP::query()->delete();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function observe_cookie(): void {
		++$this->cookies;
	}

	public function test_native_upstream_error_is_preserved_and_scoped_hooks_are_balanced(): void {
		$id = self::factory()->user->create( [ 'role' => 'subscriber', 'user_pass' => 'policy-test-password' ] );
		$error = new WP_Error( 'second_factor_required', 'Second factor required.' );
		$deny = static fn() => $error;
		$events = [];
		$start = static function () use ( &$events ): void { $events[] = 'start'; };
		$end = static function () use ( &$events ): void { $events[] = 'end'; };
		add_filter( 'authenticate', $deny, 100 );
		add_action( 'pinova/authentication_start', $start );
		add_action( 'pinova/authentication_end', $end );
		try {
			self::assertSame( $error, UserService::authenticate_password( $id, 'policy-test-password' ) );
			self::assertSame( [ 'start', 'end' ], $events );
			self::assertSame( 0, $this->cookies );
		} finally {
			remove_filter( 'authenticate', $deny, 100 );
			remove_action( 'pinova/authentication_start', $start );
			remove_action( 'pinova/authentication_end', $end );
		}
	}

	public function test_native_pipeline_rechecks_role_changes_before_cookie(): void {
		$id = self::factory()->user->create( [ 'role' => 'subscriber', 'user_pass' => 'policy-test-password' ] );
		$promote = static function ( $user ) {
			if ( $user instanceof \WP_User ) {
				( new \WP_User( $user->ID ) )->set_role( 'administrator' );
			}
			return $user;
		};
		add_filter( 'authenticate', $promote, 100 );
		try {
			self::assertWPError( UserService::authenticate_password( $id, 'policy-test-password' ) );
			self::assertSame( 0, $this->cookies );
		} finally {
			remove_filter( 'authenticate', $promote, 100 );
		}
	}

	public function test_identifier_block_added_by_native_hook_is_enforced_before_cookie(): void {
		$email = 'late-block-policy@example.test';
		$id = self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => $email, 'user_pass' => 'policy-test-password' ] );
		$block = static function ( $user ) use ( $email ) {
			Block::query()->create( [ 'identifier' => $email, 'blocked_until' => null ] );
			return $user;
		};
		add_filter( 'authenticate', $block, 100 );
		try {
			self::assertWPError( UserService::authenticate_password( $id, 'policy-test-password', true, new Identifier( $email ) ) );
			self::assertSame( 0, $this->cookies );
		} finally {
			remove_filter( 'authenticate', $block, 100 );
			Block::query()->where( 'identifier', $email )->delete();
		}
	}

	public function test_obsolete_mobile_username_is_not_a_block_alias_after_override(): void {
		$id = self::factory()->user->create( [ 'role' => 'subscriber', 'user_login' => '09351234986', 'user_pass' => 'policy-test-password' ] );
		update_user_meta( $id, 'pinova_mobile', '+989351234987' );
		Block::query()->create( [ 'identifier' => '+989351234986', 'blocked_until' => null ] );
		try {
			$result = UserService::authenticate_password( $id, 'policy-test-password', true, new Identifier( '+989351234987' ) );
			self::assertInstanceOf( \WP_User::class, $result );
			self::assertSame( $id, $result->ID );
			self::assertSame( '09351234986', $result->user_login );
		} finally {
			Block::query()->where( 'identifier', '+989351234986' )->delete();
		}
	}

	public function test_scoped_hooks_restore_on_native_authentication_throwable(): void {
		$id = self::factory()->user->create( [ 'role' => 'subscriber', 'user_pass' => 'policy-test-password' ] );
		$ended = false;
		$throw = static function (): void { throw new \RuntimeException( 'Synthetic authentication failure.' ); };
		$end = static function () use ( &$ended ): void { $ended = true; };
		add_filter( 'authenticate', $throw, 100 );
		add_action( 'pinova/authentication_end', $end );
		try {
			try {
				UserService::authenticate_password( $id, 'policy-test-password' );
				self::fail( 'Expected the synthetic native exception.' );
			} catch ( \RuntimeException $exception ) {
				self::assertTrue( $ended );
				self::assertSame( 0, $this->cookies );
			}
		} finally {
			remove_filter( 'authenticate', $throw, 100 );
			remove_action( 'pinova/authentication_end', $end );
		}
		// The temporary final filter must not restrict unrelated native logins later in the request.
		$other_id = self::factory()->user->create( [ 'role' => 'subscriber', 'user_pass' => 'other-test-password' ] );
		$other = get_userdata( $other_id );
		$result = wp_signon( [ 'user_login' => $other->user_login, 'user_password' => 'other-test-password' ] );
		self::assertInstanceOf( \WP_User::class, $result );
		self::assertSame( $other_id, $result->ID );
	}

	public function test_policy_denial_cannot_create_an_otp_session_or_be_overridden_by_return_url(): void {
		$id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$deny = static fn(): bool => false;
		add_filter( 'pinova/authentication_policy', $deny );
		try {
			foreach ( [ '', '/community/', 'https://example.invalid/' ] as $return_url ) {
				$_GET['back_url'] = $return_url;
				self::assertWPError( UserService::login( $id, 'otp' ) );
			}
			self::assertSame( 0, $this->cookies );
			self::assertSame( 0, get_current_user_id() );
		} finally {
			unset( $_GET['back_url'] );
			remove_filter( 'pinova/authentication_policy', $deny );
		}
	}

	public function test_registration_policy_change_denies_before_consumption_or_account_creation(): void {
		$otp = $this->otp( null, '09351234981', OTP::TYPE_REGISTER );
		update_option( 'pinova_general', [ 'wordpress_users_can_register' => '0' ] );
		$count = count_users()['total_users'];
		$response = $this->login_otp( $otp );
		self::assertSame( 401, $response->get_status() );
		self::assertSame( $count, count_users()['total_users'] );
		self::assertNull( $otp->fresh()->verified_at );
		self::assertSame( 0, $this->cookies );
	}

	public function test_mobile_changed_after_issuance_cannot_authenticate_old_binding(): void {
		$id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $id, 'pinova_mobile', '+989351234982' );
		$otp = $this->otp( $id, '+989351234982', OTP::TYPE_LOGIN );
		update_user_meta( $id, 'pinova_mobile', '+989351234983' );
		$response = $this->login_otp( $otp );
		self::assertSame( 401, $response->get_status() );
		self::assertNull( $otp->fresh()->verified_at );
		self::assertSame( 0, $this->cookies );
	}

	public function test_native_only_user_cannot_consume_public_otp(): void {
		$id = self::factory()->user->create( [ 'role' => 'administrator', 'user_email' => 'native-policy@example.test' ] );
		$otp = $this->otp( $id, 'native-policy@example.test', OTP::TYPE_LOGIN );
		$response = $this->login_otp( $otp );
		self::assertSame( 401, $response->get_status() );
		self::assertNull( $otp->fresh()->verified_at );
		self::assertSame( 0, $this->cookies );
	}

	public function test_password_reset_rechecks_policy_before_automatic_login(): void {
		$id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$user = get_userdata( $id );
		$request = new WP_REST_Request( 'POST', '/pinova/auth/forgot/change' );
		$request->set_param( 'jwt', UserService::generate_jwt( $id ) );
		$request->set_param( 'reset_key', get_password_reset_key( $user ) );
		$request->set_param( 'password_1', 'replacement-test-password' );
		$request->set_param( 'password_2', 'replacement-test-password' );
		$promote = static function ( $reset_user ): void { $reset_user->set_role( 'administrator' ); };
		add_action( 'after_password_reset', $promote, 100 );
		try {
			$response = ( new UserAPI() )->forgot_change( $request );
			self::assertFalse( $response->get_data()['success'] );
			self::assertSame( 0, $this->cookies );
			self::assertSame( 0, get_current_user_id() );
		} finally {
			remove_action( 'after_password_reset', $promote, 100 );
		}
	}

	public function test_native_success_preserves_real_username_and_cookie_pipeline(): void {
		$id = self::factory()->user->create( [ 'role' => 'subscriber', 'user_login' => 'policy-original-login', 'user_pass' => 'policy-test-password' ] );
		update_user_meta( $id, 'pinova_mobile', '+989351234984' );
		$user = UserService::authenticate_password( $id, 'policy-test-password', true, new Identifier( '+989351234984' ) );
		self::assertInstanceOf( \WP_User::class, $user );
		self::assertSame( $id, $user->ID );
		self::assertSame( 'policy-original-login', $user->user_login );
		self::assertSame( 1, $this->cookies );
	}

	public function test_ordinary_registration_cannot_adopt_filtered_seller_role(): void {
		$had_seller = null !== get_role( 'seller' );
		add_role( 'seller', 'Seller', [ 'read' => true ] );
		update_option( 'default_role', 'seller' );
		$inject = static fn(): array => [ 'seller' => 'Seller', 'subscriber' => 'Subscriber' ];
		add_filter( 'pinova/allowed_registration_roles', $inject );
		try {
			self::assertArrayNotHasKey( 'seller', UserService::allowed_registration_roles() );
			$id = UserService::create( '09351234985' );
			self::assertSame( [ 'subscriber' ], get_userdata( $id )->roles );
		} finally {
			remove_filter( 'pinova/allowed_registration_roles', $inject );
			if ( ! $had_seller ) {
				remove_role( 'seller' );
			}
		}
	}

	public function test_authenticated_user_is_rejected_at_the_public_api_boundary(): void {
		$id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $id );
		$result = ( new UserAPI() )->permission_callback( new WP_REST_Request( 'POST', '/pinova/user/login/otp' ) );
		self::assertWPError( $result );
		self::assertSame( 'pinova_already_logged_in', $result->get_error_code() );
		self::assertSame( 0, $this->cookies );
	}

	private function otp( ?int $id, string $identifier, string $type ): OTP {
		return OTP::query()->create( [ 'user_id' => $id, 'identifier' => $identifier, 'type' => $type, 'code' => '1234', 'channels' => [ 'sms' => true ] ] );
	}

	private function login_otp( OTP $otp ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/pinova/user/login/otp' );
		$request->set_param( 'jwt', OTPService::signed_state( $otp ) );
		$request->set_param( 'code', '1234' );
		return ( new UserAPI() )->login_otp( $request );
	}
}
