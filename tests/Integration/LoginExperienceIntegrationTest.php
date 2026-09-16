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

	public function test_enabled_native_login_gate_rewrites_generated_core_login_urls(): void {
		$previous = get_option( 'pinova_advanced', null );
		$options  = is_array( $previous ) ? $previous : [];

		$options['block_native_login'] = '1';
		$options['native_login_slug']  = 'pinova-admin-safe1234';
		$options['native_only_roles']  = [ 'administrator' ];
		update_option( 'pinova_advanced', $options );

		$gate = new NativeLoginGate();

		try {
			$public_url  = wp_login_url( home_url( '/wp-admin/' ) );
			$private_url = $gate->rewrite_site_url(
				'https://example.test/wordpress/wp-login.php?action=login#form',
				'wp-login.php',
				'login',
				null
			);

			self::assertStringContainsString( '/login', $public_url );
			self::assertStringNotContainsString( 'wp-login.php', $public_url );
			self::assertSame(
				NativeLoginGate::url() . '?action=login#form',
				$private_url
			);
			self::assertSame( '/pinova-admin-safe1234/', wp_parse_url( $private_url, PHP_URL_PATH ) );

			$administrator = get_userdata( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
			$customer      = get_userdata( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

			self::assertSame( $administrator, $gate->enforce_native_only_role( $administrator ) );
			self::assertInstanceOf( \WP_Error::class, $gate->enforce_native_only_role( $customer ) );
			self::assertTrue( $gate->allow_native_only_password_reset( true, $administrator->ID ) );
			self::assertFalse( $gate->allow_native_only_password_reset( true, $customer->ID ) );
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
		} finally {
			remove_action( 'template_redirect', [ $gate, 'serve_private_login' ], 0 );
			remove_action( 'login_init', [ $gate, 'block_canonical_login' ], 0 );
			remove_filter( 'site_url', [ $gate, 'rewrite_site_url' ], 10 );
			remove_filter( 'network_site_url', [ $gate, 'rewrite_network_site_url' ], 10 );
			remove_filter( 'wp_redirect', [ $gate, 'rewrite_redirect' ], 10 );
			remove_filter( 'authenticate', [ $gate, 'enforce_native_only_role' ], PHP_INT_MAX );
			remove_filter( 'allow_password_reset', [ $gate, 'allow_native_only_password_reset' ], 99 );
			remove_action( 'validate_password_reset', [ $gate, 'validate_native_only_password_reset' ], PHP_INT_MAX );
			remove_action( 'login_form_register', [ $gate, 'redirect_public_registration' ], 0 );
			remove_filter( 'login_url', [ $gate, 'rewrite_public_login_url' ], 20 );
			remove_filter( 'register_url', [ $gate, 'rewrite_public_register_url' ], 20 );
			remove_filter( 'lostpassword_url', [ $gate, 'rewrite_public_lost_password_url' ], 20 );
			remove_filter( 'retrieve_password_message', [ $gate, 'rewrite_native_reset_message' ], 99 );
			remove_filter( 'recovery_mode_email', [ $gate, 'rewrite_recovery_mode_email' ], 99 );
			remove_filter( 'user_request_action_email_content', [ $gate, 'rewrite_privacy_request_email_content' ], 99 );

			if ( null === $previous ) {
				delete_option( 'pinova_advanced' );
			} else {
				update_option( 'pinova_advanced', $previous );
			}
		}
	}
}
