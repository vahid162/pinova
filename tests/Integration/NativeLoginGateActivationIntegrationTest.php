<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Install;
use Pinova\Integrations\Wordpress\NativeLoginGate;
use Pinova\Logging\LogRepository;
use WP_User;

final class NativeLoginGateActivationIntegrationTest extends \WP_UnitTestCase {
	private const SLUG = 'private-admin-route-2026';
	private const OTHER_SLUG = 'other-private-route-2026';

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();

		delete_option( 'pinova_native_login_arm' );
		delete_option( 'pinova_advanced' );
		update_option(
			'pinova_advanced',
			[
				'native_login_slug' => self::SLUG,
				'native_only_roles' => [ 'administrator' ],
				'block_native_login' => '0',
			]
		);
		update_option(
			'pinova_logging',
			[
				'minimum_level'    => 'info',
				'retention_days'   => 14,
				'diagnostic_until' => 0,
			]
		);
		LogRepository::delete_all();
	}

	public function tear_down(): void {
		delete_option( 'pinova_native_login_arm' );
		delete_option( 'pinova_advanced' );
		delete_option( 'pinova_logging' );
		remove_role( 'pinova_gate_manager' );
		wp_set_current_user( 0 );
		LogRepository::delete_all();

		parent::tear_down();
	}

	public function test_private_admin_login_creates_single_use_arm_and_durable_activation(): void {
		$administrator = $this->create_user( 'administrator' );
		$issued_at     = time();

		$this->arm_with_private_login( $administrator );
		$arm = get_option( 'pinova_native_login_arm', null );

		self::assertIsArray( $arm );
		self::assertSame( $administrator->ID, $arm['user_id'] ?? null );
		self::assertSame( PINOVA_VERSION, $arm['plugin_version'] ?? null );
		self::assertGreaterThanOrEqual( $issued_at + 1799, (int) ( $arm['expires_at'] ?? 0 ) );
		self::assertLessThanOrEqual( $issued_at + 1801, (int) ( $arm['expires_at'] ?? 0 ) );
		self::assertStringNotContainsString( self::SLUG, (string) ( $arm['slug_hmac'] ?? '' ) );
		self::assertSame( $this->expected_slug_hmac( self::SLUG ), $arm['slug_hmac'] ?? null );

		$options = $this->request_enable( $administrator );

		self::assertSame( '1', $options['block_native_login'] ?? null );
		self::assertArrayHasKey( 'native_login_activation', $options );
		self::assertSame( $administrator->ID, $options['native_login_activation']['user_id'] ?? null );
		self::assertFalse( get_option( 'pinova_native_login_arm', false ) );
		self::assertTrue( NativeLoginGate::configuration_is_activated( $options ) );
		self::assertTrue( NativeLoginGate::is_enabled() );
		self::assertStringNotContainsString( self::SLUG, wp_json_encode( $options['native_login_activation'] ) );

		$options['block_native_login'] = '0';
		update_option( 'pinova_advanced', $options );
		$disabled = $this->advanced_options();

		self::assertSame( '0', $disabled['block_native_login'] ?? null );
		self::assertArrayNotHasKey( 'native_login_activation', $disabled );
		self::assertFalse( NativeLoginGate::is_enabled() );

		$disabled['block_native_login'] = '1';
		update_option( 'pinova_advanced', $disabled );
		$second_attempt = $this->advanced_options();

		self::assertSame( '0', $second_attempt['block_native_login'] ?? null );
		self::assertArrayNotHasKey( 'native_login_activation', $second_attempt );
		self::assertFalse( NativeLoginGate::is_enabled() );
	}

	public function test_expired_hmac_or_version_mismatched_arm_fails_closed_and_is_consumed(): void {
		$administrator = $this->create_user( 'administrator' );

		$this->arm_with_private_login( $administrator );
		$expired               = get_option( 'pinova_native_login_arm', [] );
		$expired['expires_at'] = time() - 1;
		update_option( 'pinova_native_login_arm', $expired, false );

		$options = $this->request_enable( $administrator );
		self::assertSame( '0', $options['block_native_login'] ?? null );
		self::assertArrayNotHasKey( 'native_login_activation', $options );
		self::assertFalse( get_option( 'pinova_native_login_arm', false ) );

		$this->arm_with_private_login( $administrator );
		$mismatched              = get_option( 'pinova_native_login_arm', [] );
		$mismatched['slug_hmac'] = str_repeat( '0', 64 );
		update_option( 'pinova_native_login_arm', $mismatched, false );

		$options = $this->request_enable( $administrator );
		self::assertSame( '0', $options['block_native_login'] ?? null );
		self::assertArrayNotHasKey( 'native_login_activation', $options );
		self::assertFalse( get_option( 'pinova_native_login_arm', false ) );

		$this->arm_with_private_login( $administrator );
		$mismatched_version                   = get_option( 'pinova_native_login_arm', [] );
		$mismatched_version['plugin_version'] = '0.0.0-test-mismatch';
		update_option( 'pinova_native_login_arm', $mismatched_version, false );

		$options = $this->request_enable( $administrator );
		self::assertSame( '0', $options['block_native_login'] ?? null );
		self::assertArrayNotHasKey( 'native_login_activation', $options );
		self::assertFalse( get_option( 'pinova_native_login_arm', false ) );
	}

	public function test_slug_change_clears_activation_and_requires_a_fresh_login(): void {
		$administrator = $this->create_user( 'administrator' );

		$this->arm_with_private_login( $administrator );
		$options = $this->request_enable( $administrator );
		self::assertTrue( NativeLoginGate::configuration_is_activated( $options ) );
		$this->arm_with_private_login( $administrator );
		self::assertIsArray( get_option( 'pinova_native_login_arm', null ) );

		$options['native_login_slug']  = self::OTHER_SLUG;
		$options['block_native_login'] = '1';
		update_option( 'pinova_advanced', $options );
		$changed = $this->advanced_options();

		self::assertSame( self::OTHER_SLUG, $changed['native_login_slug'] ?? null );
		self::assertSame( '0', $changed['block_native_login'] ?? null );
		self::assertArrayNotHasKey( 'native_login_activation', $changed );
		self::assertFalse( get_option( 'pinova_native_login_arm', false ) );
		self::assertFalse( NativeLoginGate::is_enabled() );
	}

	public function test_effective_slug_change_durably_invalidates_activation(): void {
		global $wpdb;

		$administrator = $this->create_user( 'administrator' );

		$this->arm_with_private_login( $administrator );
		$options = $this->request_enable( $administrator );
		self::assertTrue( NativeLoginGate::configuration_is_activated( $options ) );
		$this->arm_with_private_login( $administrator );
		self::assertIsArray( get_option( 'pinova_native_login_arm', null ) );

		self::assertTrue(
			NativeLoginGate::invalidate_runtime_activation_if_needed( $options, self::OTHER_SLUG )
		);

		$invalidated = $this->advanced_options();
		self::assertSame( '0', $invalidated['block_native_login'] ?? null );
		self::assertArrayNotHasKey( 'native_login_activation', $invalidated );
		self::assertFalse( get_option( 'pinova_native_login_arm', false ) );
		self::assertFalse( NativeLoginGate::is_enabled() );

		$context = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT `context` FROM %i WHERE `event` = %s ORDER BY `id` DESC LIMIT 1',
				$wpdb->prefix . 'pinova_logs',
				'security.native_login_gate_disabled'
			)
		);

		self::assertIsString( $context );
		self::assertSame( 'slug_changed', json_decode( $context, true )['reason'] ?? null );
	}

	public function test_legacy_enabled_setting_without_activation_is_durably_disabled(): void {
		global $wpdb;

		$legacy = [
			'native_login_slug' => self::SLUG,
			'native_only_roles' => [ 'administrator' ],
			'block_native_login' => '1',
		];

		// Simulate the value stored by the pre-arming implementation without
		// passing it through the new Settings API sanitizer.
		$wpdb->update(
			$wpdb->options,
			[ 'option_value' => maybe_serialize( $legacy ) ],
			[ 'option_name' => 'pinova_advanced' ],
			[ '%s' ],
			[ '%s' ]
		);
		wp_cache_delete( 'pinova_advanced', 'options' );

		self::assertSame( '1', $this->advanced_options()['block_native_login'] ?? null );
		self::assertFalse( NativeLoginGate::is_enabled() );

		$invalidated = $this->advanced_options();
		self::assertSame( '0', $invalidated['block_native_login'] ?? null );
		self::assertArrayNotHasKey( 'native_login_activation', $invalidated );
		self::assertFalse( NativeLoginGate::is_runtime_invalidation_update() );

		$context = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT `context` FROM %i WHERE `event` = %s ORDER BY `id` DESC LIMIT 1',
				$wpdb->prefix . 'pinova_logs',
				'security.native_login_gate_disabled'
			)
		);

		self::assertIsString( $context );
		self::assertSame( 'activation_invalid', json_decode( $context, true )['reason'] ?? null );
	}

	public function test_explicit_persisted_slug_and_manage_options_native_role_are_required(): void {
		delete_option( 'pinova_advanced' );
		update_option( 'pinova_advanced', [ 'block_native_login' => '1' ] );

		$options = $this->advanced_options();
		self::assertSame( '0', $options['block_native_login'] ?? null );
		self::assertFalse( NativeLoginGate::is_enabled() );

		update_option(
			'pinova_advanced',
			[
				'native_login_slug' => self::SLUG,
				'native_only_roles' => [ 'administrator' ],
				'block_native_login' => '0',
			]
		);
		$subscriber = $this->create_user( 'subscriber' );
		$this->arm_with_private_login( $subscriber );
		self::assertFalse( get_option( 'pinova_native_login_arm', false ) );

		add_role( 'pinova_gate_manager', 'Pinova Gate Manager', [ 'read' => true, 'manage_options' => true ] );
		$non_native_manager = $this->create_user( 'pinova_gate_manager' );
		$this->arm_with_private_login( $non_native_manager );
		self::assertFalse( get_option( 'pinova_native_login_arm', false ) );
	}

	public function test_arm_can_only_be_consumed_by_the_administrator_who_logged_in(): void {
		$armed_administrator = $this->create_user( 'administrator' );
		$other_administrator = $this->create_user( 'administrator' );

		$this->arm_with_private_login( $armed_administrator );
		$options = $this->request_enable( $other_administrator );

		self::assertSame( '0', $options['block_native_login'] ?? null );
		self::assertArrayNotHasKey( 'native_login_activation', $options );
		self::assertFalse( get_option( 'pinova_native_login_arm', false ) );

		$options = $this->request_enable( $armed_administrator );

		self::assertSame( '0', $options['block_native_login'] ?? null );
		self::assertFalse( NativeLoginGate::is_enabled() );
	}

	public function test_arm_cannot_be_consumed_after_the_arming_user_loses_manage_options(): void {
		$administrator = $this->create_user( 'administrator' );

		$this->arm_with_private_login( $administrator );
		$administrator->set_role( 'subscriber' );
		$options = $this->request_enable( $administrator );

		self::assertSame( '0', $options['block_native_login'] ?? null );
		self::assertArrayNotHasKey( 'native_login_activation', $options );
		self::assertFalse( get_option( 'pinova_native_login_arm', false ) );
		self::assertFalse( NativeLoginGate::is_enabled() );
	}

	public function test_arm_and_gate_transitions_are_logged_without_route_or_hmac(): void {
		global $wpdb;

		$administrator = $this->create_user( 'administrator' );
		wp_set_current_user( $administrator->ID );
		$this->arm_with_private_login( $administrator );
		$options = $this->request_enable( $administrator );
		$options['block_native_login'] = '0';
		update_option( 'pinova_advanced', $options );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT `event`, `context` FROM %i WHERE `event` LIKE %s ORDER BY `id` ASC',
				$wpdb->prefix . 'pinova_logs',
				'security.native_login_%'
			),
			ARRAY_A
		);
		$events = wp_list_pluck( $rows, 'event' );
		$encoded = wp_json_encode( $rows );

		self::assertContains( 'security.native_login_armed', $events );
		self::assertContains( 'security.native_login_gate_enabled', $events );
		self::assertContains( 'security.native_login_gate_disabled', $events );
		self::assertStringNotContainsString( self::SLUG, $encoded );
		self::assertStringNotContainsString( (string) ( $options['native_login_activation']['slug_hmac'] ?? '' ), $encoded );
	}

	private function create_user( string $role ): WP_User {
		$user = get_userdata( self::factory()->user->create( [ 'role' => $role ] ) );
		self::assertInstanceOf( WP_User::class, $user );

		return $user;
	}

	private function expected_slug_hmac( string $slug ): string {
		$auth_key  = defined( 'AUTH_KEY' ) ? (string) constant( 'AUTH_KEY' ) : '';
		$auth_salt = defined( 'AUTH_SALT' ) ? (string) constant( 'AUTH_SALT' ) : '';
		$secret    = $auth_key . '|' . $auth_salt;

		if ( '|' === $secret ) {
			$secret = NativeLoginGate::default_slug();
		}

		return hash_hmac( 'sha256', 'native-login-gate:' . $slug, $secret );
	}

	private function arm_with_private_login( WP_User $user ): void {
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

	/** @return array<string, mixed> */
	private function request_enable( WP_User $user ): array {
		$previous_user_id               = get_current_user_id();
		$options                        = $this->advanced_options();
		$options['block_native_login'] = '1';

		try {
			wp_set_current_user( $user->ID );
			update_option( 'pinova_advanced', $options );
		} finally {
			wp_set_current_user( $previous_user_id );
		}

		return $this->advanced_options();
	}

	/** @return array<string, mixed> */
	private function advanced_options(): array {
		$options = get_option( 'pinova_advanced', [] );

		return is_array( $options ) ? $options : [];
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
}
