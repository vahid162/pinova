<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Integrations\Dokan\Load;
use Pinova\Integrations\IntegrationSettings;
use Pinova\Pinova;

/** Missing-dependency regressions; the exact-plugin fixture exercises native conversion. */
final class DokanIntegrationTest extends \WP_UnitTestCase {
	public function tear_down(): void {
		Load::clear_attempt();
		$_POST = [];
		$_GET = [];
		wp_set_current_user( 0 );
		delete_option( IntegrationSettings::OPTION );
		parent::tear_down();
	}

	public function test_missing_dependency_preserves_unowned_accounts_and_native_surfaces(): void {
		self::assertFalse( function_exists( 'dokan' ) );
		update_option( IntegrationSettings::OPTION, [ 'dokan_enabled' => '1' ] );
		$id = self::factory()->user->create( [ 'role' => 'customer' ] );
		wp_set_current_user( $id );
		self::assertFalse( Load::can_convert( $id ) );
		self::assertFalse( Load::denied( $id ) );
		self::assertFalse( Load::shortcode( false, 'dokan-vendor-registration' ) );
		self::assertSame( 'native', Load::migration_redirect( 'native' ) );
		do_action( 'pinova/mobile_verified', $id );
		self::assertSame( '', get_user_meta( $id, Load::STATE_META, true ) );
		self::assertSame( [ 'customer' ], get_userdata( $id )->roles );
	}

	public function test_owned_pending_remains_guarded_with_default_off_or_missing_dependencies(): void {
		$id = self::factory()->user->create( [ 'role' => 'customer' ] );
		wp_set_current_user( $id );
		update_user_meta( $id, Load::STATE_META, 'pending' );
		foreach ( [ [], [ 'dokan_enabled' => '0' ], [ 'dokan_enabled' => '1' ] ] as $settings ) {
			update_option( IntegrationSettings::OPTION, $settings );
			self::assertTrue( Load::denied( $id ) );
			self::assertFalse( Load::can_convert( $id ) );
			$_POST['role'] = 'seller';
			$errors = apply_filters( 'woocommerce_registration_errors', new \WP_Error() );
			self::assertContains( 'pinova_vendor_login_required', $errors->get_error_codes() );
			self::assertSame( 'customer', Load::customer_data( [ 'role' => 'seller' ] )['role'] );
			self::assertSame( 'pending', get_user_meta( $id, Load::STATE_META, true ) );
		}
	}

	public function test_pending_migration_nonce_does_not_bypass_missing_dependency(): void {
		$id = self::factory()->user->create( [ 'role' => 'customer' ] );
		wp_set_current_user( $id );
		update_user_meta( $id, Load::STATE_META, 'pending' );
		$_POST = [ 'dokan_migration' => '1', 'dokan_nonce' => 'invalid' ];
		Load::guard_migration();
		self::assertSame( [ 'customer' ], get_userdata( $id )->roles );
		$_POST['dokan_nonce'] = wp_create_nonce( 'account_migration' );
		$this->expectException( \WPDieException::class );
		Load::guard_migration();
	}

	public function test_continuation_retains_checkout_and_nested_deep_links_without_double_decoding(): void {
		$target = home_url( '/checkout/?next=%2Fshop%3Fx%3D1&name=a%2Bb#payment' );
		self::assertSame( $target, Load::continuation( $target ) );
		parse_str( wp_parse_url( Pinova::get_login_url( Load::continuation( $target ) ), PHP_URL_QUERY ), $query );
		self::assertSame( $target, $query['back_url'] );
		foreach ( [ '', [], 'https://outside.invalid/', home_url( '/login/' ), site_url( '/wp-login.php' ) ] as $unsafe ) {
			self::assertSame( home_url( '/' ), Load::continuation( $unsafe ) );
		}
	}

	public function test_both_registration_shortcodes_are_excluded_as_return_targets(): void {
		foreach ( [ 'dokan-vendor-registration', 'dokan-vendor-onboarding-registration' ] as $shortcode ) {
			add_shortcode( $shortcode, '__return_empty_string' );
			try {
				$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[' . $shortcode . ']' ] );
				self::assertSame( home_url( '/' ), Load::continuation( get_permalink( $page ) ) );
			} finally {
				remove_shortcode( $shortcode );
			}
		}
	}

	public function test_completion_hook_without_authorized_native_request_cannot_change_state(): void {
		$id = self::factory()->user->create( [ 'role' => 'customer' ] );
		update_user_meta( $id, Load::STATE_META, 'pending' );
		do_action( 'dokan_new_seller_created', $id );
		self::assertSame( 'pending', get_user_meta( $id, Load::STATE_META, true ) );
		self::assertSame( [ 'customer' ], get_userdata( $id )->roles );
	}
}
