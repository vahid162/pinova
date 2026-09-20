<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Integrations\Wordpress\NativeLoginGate;
use Pinova\Objects\Identifier;
use Pinova\Pinova;
use Pinova\Services\ChannelService;

final class LoginExperienceIntegrationTest extends \WP_UnitTestCase {
	public function test_otp_delivery_message_is_plain_text_with_bidi_isolation(): void {
		$message = ChannelService::get_message( [ 'sms' ], new Identifier( '09123456789' ) );

		self::assertStringContainsString( 'پیامک', $message );
		self::assertStringContainsString( "\u{2066}+989123456789\u{2069}", $message );
		self::assertStringNotContainsString( '<', $message );
		self::assertStringNotContainsString( '>', $message );
	}

	public function test_login_and_logout_return_urls_are_encoded_exactly_once(): void {
		$return_url = home_url( '/protected/?foo=bar&baz=qux' );
		$login_args = [];
		wp_parse_str(
			(string) wp_parse_url( Pinova::get_login_url( $return_url ), PHP_URL_QUERY ),
			$login_args
		);
		$reauth_args = [];
		wp_parse_str(
			(string) wp_parse_url( Pinova::get_login_url( $return_url, true ), PHP_URL_QUERY ),
			$reauth_args
		);

		$logout_args = [];
		wp_parse_str(
			(string) wp_parse_url(
				html_entity_decode( Pinova::get_logout_url( $return_url ), ENT_QUOTES, 'UTF-8' ),
				PHP_URL_QUERY
			),
			$logout_args
		);

		self::assertSame( $return_url, $login_args['back_url'] ?? null );
		self::assertSame( $return_url, $reauth_args['back_url'] ?? null );
		self::assertSame( '1', $reauth_args['reauth'] ?? null );
		self::assertSame( $return_url, $logout_args['back_url'] ?? null );
	}

	public function test_public_login_and_logout_routes_follow_the_home_url(): void {
		$home_filter = static function ( string $url, string $path ): string {
			unset( $url );

			return 'https://public.example' . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
		};
		$site_filter = static function ( string $url, string $path ): string {
			unset( $url );

			return 'https://public.example/wordpress' . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
		};

		add_filter( 'home_url', $home_filter, 99, 2 );
		add_filter( 'site_url', $site_filter, 99, 2 );

		try {
			$login_url  = Pinova::get_login_url();
			$logout_url = html_entity_decode( Pinova::get_logout_url(), ENT_QUOTES, 'UTF-8' );

			self::assertSame( 'public.example', wp_parse_url( $login_url, PHP_URL_HOST ) );
			self::assertSame( '/login', wp_parse_url( $login_url, PHP_URL_PATH ) );
			self::assertSame( '/logout/', wp_parse_url( $logout_url, PHP_URL_PATH ) );
			self::assertStringNotContainsString( '/wordpress/', $login_url );
			self::assertStringNotContainsString( '/wordpress/', $logout_url );
		} finally {
			remove_filter( 'home_url', $home_filter, 99 );
			remove_filter( 'site_url', $site_filter, 99 );
		}
	}

	public function test_enabled_native_login_gate_rewrites_generated_core_login_urls(): void {
		$previous      = get_option( 'pinova_advanced', null );
		$options       = is_array( $previous ) ? $previous : [];
		$administrator = get_userdata( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$previous_user = get_current_user_id();

		$options['block_native_login'] = '0';
		$options['native_login_slug']  = 'pinova-admin-safe1234';
		$options['native_only_roles']  = [ 'administrator' ];
		update_option( 'pinova_advanced', $options );
		$this->arm_with_private_login( $administrator );

		try {
			wp_set_current_user( $administrator->ID );
			$options                        = get_option( 'pinova_advanced', [] );
			$options['block_native_login'] = '1';
			update_option( 'pinova_advanced', $options );
		} finally {
			wp_set_current_user( $previous_user );
		}

		$gate = new NativeLoginGate();

		try {
			$public_url  = wp_login_url( home_url( '/wp-admin/' ) );
			$reauth_url  = wp_login_url( home_url( '/sensitive-action/' ), true );
			$reauth_args = [];
			wp_parse_str( (string) wp_parse_url( $reauth_url, PHP_URL_QUERY ), $reauth_args );
			$private_url = $gate->rewrite_site_url(
				'https://example.test/wordpress/wp-login.php?action=login#form',
				'wp-login.php',
				'login',
				null
			);

			self::assertStringContainsString( '/login', $public_url );
			self::assertStringNotContainsString( 'wp-login.php', $public_url );
			self::assertStringContainsString( '/login', $reauth_url );
			self::assertSame( '1', $reauth_args['reauth'] ?? null );
			self::assertSame( home_url( '/sensitive-action/' ), $reauth_args['back_url'] ?? null );
			self::assertSame(
				NativeLoginGate::url() . '?action=login#form',
				$private_url
			);
			self::assertSame( '/pinova-admin-safe1234/', wp_parse_url( $private_url, PHP_URL_PATH ) );

			$customer      = get_userdata( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
			$previous_user = get_current_user_id();

			try {
				wp_set_current_user( $administrator->ID );
				$native_reauth_url = wp_login_url( home_url( '/sensitive-admin-action/' ), true );
			} finally {
				wp_set_current_user( $previous_user );
			}

			$native_reauth_args = [];
			wp_parse_str( (string) wp_parse_url( $native_reauth_url, PHP_URL_QUERY ), $native_reauth_args );
			self::assertSame(
				wp_parse_url( NativeLoginGate::url(), PHP_URL_PATH ),
				wp_parse_url( $native_reauth_url, PHP_URL_PATH )
			);
			self::assertSame( '1', $native_reauth_args['reauth'] ?? null );
			self::assertSame( home_url( '/sensitive-admin-action/' ), $native_reauth_args['redirect_to'] ?? null );

			self::assertSame( $administrator, $gate->enforce_native_only_role( $administrator ) );
			self::assertInstanceOf( \WP_Error::class, $gate->enforce_native_only_role( $customer ) );
			self::assertTrue( $gate->allow_native_only_password_reset( true, $administrator->ID ) );
			self::assertFalse( $gate->allow_native_only_password_reset( true, $customer->ID ) );
			$security_denial = new \WP_Error( 'security_plugin_denied_reset' );
			self::assertSame(
				$security_denial,
				$gate->allow_native_only_password_reset( $security_denial, $administrator->ID )
			);
			$reset_errors = new \WP_Error();
			$gate->validate_native_only_password_reset( $reset_errors, $customer );
			self::assertTrue( $reset_errors->has_errors() );
			self::assertStringContainsString(
				'/pinova-admin-safe1234',
				$gate->rewrite_native_reset_message(
					home_url( '/wp-login.php?action=rp' ),
					'reset-key',
					$administrator->user_login,
					$administrator
				)
			);

			$privacy_content = $gate->rewrite_privacy_request_email_content(
				'Confirm: ###CONFIRM_URL###',
				[
					'confirm_url' => add_query_arg(
						[
							'action'      => 'confirmaction',
							'request_id'  => 42,
							'confirm_key' => 'test-confirm-key',
						],
						Pinova::get_login_url()
					),
				]
			);
			$privacy_url     = substr( $privacy_content, strlen( 'Confirm: ' ) );
			$privacy_query   = [];
			wp_parse_str( (string) wp_parse_url( $privacy_url, PHP_URL_QUERY ), $privacy_query );

			self::assertSame( 'wp-login.php', basename( (string) wp_parse_url( $privacy_url, PHP_URL_PATH ) ) );
			self::assertSame( 'confirmaction', $privacy_query['action'] ?? null );
			self::assertSame( '42', $privacy_query['request_id'] ?? null );
			self::assertSame( 'test-confirm-key', $privacy_query['confirm_key'] ?? null );
			self::assertStringNotContainsString( '###CONFIRM_URL###', $privacy_content );

			$had_action       = array_key_exists( 'action', $GLOBALS );
			$previous_action  = $GLOBALS['action'] ?? null;
			$previous_user_id = get_current_user_id();

			try {
				$GLOBALS['action'] = 'confirm_admin_email';
				wp_set_current_user( 0 );
				$logged_out_confirmation_url = $gate->rewrite_public_login_url(
					site_url( 'wp-login.php', 'login' ),
					'',
					false
				);
				wp_set_current_user( $administrator->ID );
				$confirmation_base = $gate->rewrite_public_login_url(
					site_url( 'wp-login.php', 'login' ),
					home_url( '/wp-admin/' ),
					false
				);
				$confirm_admin_url = add_query_arg( 'action', 'confirm_admin_email', $confirmation_base );
			} finally {
				wp_set_current_user( $previous_user_id );
				if ( $had_action ) {
					$GLOBALS['action'] = $previous_action;
				} else {
					unset( $GLOBALS['action'] );
				}
			}

			self::assertSame( '/login', wp_parse_url( $logged_out_confirmation_url, PHP_URL_PATH ) );
			self::assertStringNotContainsString( NativeLoginGate::slug(), $logged_out_confirmation_url );
			self::assertSame(
				'wp-login.php',
				basename( (string) wp_parse_url( $confirm_admin_url, PHP_URL_PATH ) )
			);
			$confirm_admin_args = [];
			wp_parse_str( (string) wp_parse_url( $confirm_admin_url, PHP_URL_QUERY ), $confirm_admin_args );
			self::assertSame( 'confirm_admin_email', $confirm_admin_args['action'] ?? null );

			$previous_user_id = get_current_user_id();
			$had_reauth       = array_key_exists( 'reauth', $_REQUEST );
			$previous_reauth  = $_REQUEST['reauth'] ?? null;

			try {
				wp_set_current_user( $customer->ID );
				unset( $_REQUEST['reauth'] );
				self::assertFalse( Pinova::prepare_force_reauthentication() );
				self::assertSame( $customer->ID, get_current_user_id() );
				$_REQUEST['reauth'] = '1';
				self::assertTrue( Pinova::prepare_force_reauthentication() );
				self::assertSame( 0, get_current_user_id() );
			} finally {
				wp_set_current_user( $previous_user_id );
				if ( $had_reauth ) {
					$_REQUEST['reauth'] = $previous_reauth;
				} else {
					unset( $_REQUEST['reauth'] );
				}
			}
		} finally {
			self::remove_gate_hooks( $gate );

			if ( null === $previous ) {
				delete_option( 'pinova_advanced' );
			} else {
				update_option( 'pinova_advanced', $previous );
			}
		}
	}

	public function test_private_native_flow_rewrites_admin_email_confirmation_at_construction(): void {
		$previous_options    = get_option( 'pinova_advanced', null );
		$previous_request    = $_SERVER['REQUEST_URI'] ?? null;
		$previous_script     = $_SERVER['SCRIPT_NAME'] ?? null;
		$had_pagenow         = array_key_exists( 'pagenow', $GLOBALS );
		$previous_pagenow    = $GLOBALS['pagenow'] ?? null;
		$options             = is_array( $previous_options ) ? $previous_options : [];
		$options['block_native_login'] = '0';
		$options['native_login_slug']  = 'pinova-admin-safe1234';
		update_option( 'pinova_advanced', $options );

		$_SERVER['REQUEST_URI'] = (string) wp_parse_url( NativeLoginGate::url(), PHP_URL_PATH );
		$_SERVER['SCRIPT_NAME'] = '/index.php';
		$gate                   = new NativeLoginGate();

		try {
			$redirect_url = home_url( '/wp-admin/' );
			$login_url    = wp_login_url( $redirect_url );
			$confirm_url  = add_query_arg(
				[
					'action'  => 'confirm_admin_email',
					'wp_lang' => 'fa_IR',
				],
				$login_url
			);
			$query        = [];
			wp_parse_str( (string) wp_parse_url( $confirm_url, PHP_URL_QUERY ), $query );

			self::assertSame(
				wp_parse_url( NativeLoginGate::url(), PHP_URL_PATH ),
				wp_parse_url( $confirm_url, PHP_URL_PATH )
			);
			self::assertSame( 'confirm_admin_email', $query['action'] ?? null );
			self::assertSame( 'fa_IR', $query['wp_lang'] ?? null );
			self::assertSame( $redirect_url, $query['redirect_to'] ?? null );
		} finally {
			self::remove_gate_hooks( $gate );

			if ( null === $previous_request ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $previous_request;
			}

			if ( null === $previous_script ) {
				unset( $_SERVER['SCRIPT_NAME'] );
			} else {
				$_SERVER['SCRIPT_NAME'] = $previous_script;
			}

			if ( $had_pagenow ) {
				$GLOBALS['pagenow'] = $previous_pagenow;
			} else {
				unset( $GLOBALS['pagenow'] );
			}

			if ( null === $previous_options ) {
				delete_option( 'pinova_advanced' );
			} else {
				update_option( 'pinova_advanced', $previous_options );
			}
		}
	}

	private static function remove_gate_hooks( NativeLoginGate $gate ): void {
		remove_action( 'template_redirect', [ $gate, 'serve_private_login' ], 0 );
		remove_action( 'login_init', [ $gate, 'block_canonical_login' ], 0 );
		remove_filter( 'site_url', [ $gate, 'rewrite_site_url' ], 10 );
		remove_filter( 'network_site_url', [ $gate, 'rewrite_network_site_url' ], 10 );
		remove_filter( 'wp_redirect', [ $gate, 'rewrite_redirect' ], 10 );
		remove_filter( 'authenticate', [ $gate, 'enforce_native_only_role' ], PHP_INT_MAX );
		remove_filter( 'allow_password_reset', [ $gate, 'allow_native_only_password_reset' ], 99 );
		remove_action( 'validate_password_reset', [ $gate, 'validate_native_only_password_reset' ], PHP_INT_MAX );
		remove_action( 'login_form_register', [ $gate, 'redirect_public_registration' ], 0 );
		remove_action( 'wp_login', [ $gate, 'arm_after_private_login' ], PHP_INT_MAX );
		remove_filter( 'login_url', [ $gate, 'rewrite_public_login_url' ], 20 );
		remove_filter( 'register_url', [ $gate, 'rewrite_public_register_url' ], 20 );
		remove_filter( 'lostpassword_url', [ $gate, 'rewrite_public_lost_password_url' ], 20 );
		remove_filter( 'retrieve_password_message', [ $gate, 'rewrite_native_reset_message' ], 99 );
		remove_filter( 'recovery_mode_email', [ $gate, 'rewrite_recovery_mode_email' ], 99 );
		remove_filter( 'user_request_action_email_content', [ $gate, 'rewrite_privacy_request_email_content' ], 99 );
	}

	private function arm_with_private_login( \WP_User $user ): void {
		$previous_request = $_SERVER['REQUEST_URI'] ?? null;
		$previous_script  = $_SERVER['SCRIPT_NAME'] ?? null;
		$had_pagenow      = array_key_exists( 'pagenow', $GLOBALS );
		$previous_pagenow = $GLOBALS['pagenow'] ?? null;

		$_SERVER['REQUEST_URI'] = (string) wp_parse_url( NativeLoginGate::url(), PHP_URL_PATH );
		$_SERVER['SCRIPT_NAME'] = '/index.php';
		$gate                   = new NativeLoginGate();

		try {
			$gate->arm_after_private_login( $user->user_login, $user );
		} finally {
			self::remove_gate_hooks( $gate );

			if ( null === $previous_request ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $previous_request;
			}

			if ( null === $previous_script ) {
				unset( $_SERVER['SCRIPT_NAME'] );
			} else {
				$_SERVER['SCRIPT_NAME'] = $previous_script;
			}

			if ( $had_pagenow ) {
				$GLOBALS['pagenow'] = $previous_pagenow;
			} else {
				unset( $GLOBALS['pagenow'] );
			}
		}
	}
}
