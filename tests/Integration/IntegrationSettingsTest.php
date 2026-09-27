<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Admin\Settings;
use Pinova\Install;
use Pinova\Integrations\IntegrationSettings;

final class IntegrationSettingsTest extends \WP_UnitTestCase {

	private Settings $settings;

	public function set_up(): void {
		parent::set_up();
		$this->settings = new Settings();
		delete_option( IntegrationSettings::OPTION );
	}

	public function tear_down(): void {
		delete_option( IntegrationSettings::OPTION );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_defaults_disable_both_adapters_and_register_in_existing_settings_api(): void {
		self::assertFalse( IntegrationSettings::enabled( 'wpforo' ) );
		self::assertFalse( IntegrationSettings::enabled( 'dokan' ) );
		self::assertFalse( IntegrationSettings::enabled( 'unknown' ) );
		$registered = get_registered_settings();
		self::assertSame( IntegrationSettings::OPTION, $registered[ IntegrationSettings::OPTION ]['group'] );
		self::assertSame( [ $this->settings, 'sanitize_options' ], $registered[ IntegrationSettings::OPTION ]['sanitize_callback'] );
	}

	public function test_integration_form_uses_settings_api_nonce_and_endpoint(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$previous = $_GET;
		try {
			$_GET['tab'] = IntegrationSettings::OPTION;
			$this->settings->admin_init();
			ob_start();
			$this->settings->show_forms();
			$html = (string) ob_get_clean();
		} finally {
			$_GET = $previous;
		}
		self::assertStringContainsString( 'action="options.php"', $html );
		self::assertMatchesRegularExpression( '/name=[\'"]option_page[\'"] value=[\'"]pinova_integrations[\'"]/', $html );
		self::assertStringContainsString( 'name="pinova_integrations[wpforo_enabled]"', $html );
		self::assertStringContainsString( 'name="pinova_integrations[dokan_enabled]"', $html );
		self::assertMatchesRegularExpression( '/name="_wpnonce" value="([^"]+)"/', $html );
		preg_match( '/name="_wpnonce" value="([^"]+)"/', $html, $matches );
		self::assertNotFalse( wp_verify_nonce( $matches[1], 'pinova_integrations-options' ) );
		self::assertFalse( wp_verify_nonce( $matches[1], 'pinova_advanced-options' ) );
	}

	public function test_unloaded_dependencies_disable_saved_switches_without_erasing_them(): void {
		if ( function_exists( 'WPF' ) || function_exists( 'dokan' ) ) {
			self::markTestSkipped( 'This case targets the standard WooCommerce-only test profile.' );
		}
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		update_option( IntegrationSettings::OPTION, [ 'wpforo_enabled' => '1', 'dokan_enabled' => '1' ] );
		foreach ( [ 'wpforo', 'dokan' ] as $slug ) {
			self::assertSame( 'missing', IntegrationSettings::status( $slug ) );
			self::assertFalse( IntegrationSettings::enabled( $slug ) );
		}
		self::assertSame( [ 'wpforo_enabled' => '1', 'dokan_enabled' => '1' ], get_option( IntegrationSettings::OPTION ) );
	}

	public function test_settings_api_rejects_non_administrator_changes(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		update_option( IntegrationSettings::OPTION, [ 'wpforo_enabled' => '1', 'dokan_enabled' => '0' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		update_option( IntegrationSettings::OPTION, [ 'wpforo_enabled' => '0', 'dokan_enabled' => '1' ] );
		self::assertSame( [ 'wpforo_enabled' => '1', 'dokan_enabled' => '0' ], get_option( IntegrationSettings::OPTION ) );
	}

	public function test_malformed_settings_fail_closed_without_affecting_accounts(): void {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );
		update_user_meta( $user, '_pinova_integration_test_state', 'pending' );
		update_option( IntegrationSettings::OPTION, [ 'wpforo_enabled' => [ '1' ], 'dokan_enabled' => 'yes', 'unknown' => 'discard' ] );
		self::assertSame( [ 'wpforo_enabled' => '0', 'dokan_enabled' => '0' ], get_option( IntegrationSettings::OPTION ) );
		self::assertSame( 'pending', get_user_meta( $user, '_pinova_integration_test_state', true ) );
	}

	public function test_deactivation_retains_settings_and_user_data(): void {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );
		update_option( IntegrationSettings::OPTION, [ 'wpforo_enabled' => '1', 'dokan_enabled' => '1' ] );
		update_user_meta( $user, '_pinova_integration_test_state', 'pending' );
		Install::deactivate();
		self::assertSame( [ 'wpforo_enabled' => '1', 'dokan_enabled' => '1' ], get_option( IntegrationSettings::OPTION ) );
		self::assertSame( 'pending', get_user_meta( $user, '_pinova_integration_test_state', true ) );
	}
}
