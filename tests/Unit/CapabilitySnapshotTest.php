<?php

declare(strict_types=1);

namespace Pinova\Tests\Unit;

use Pinova\Integrations\Dokan\Load;
use Pinova\Integrations\IntegrationSettings;
use PHPUnit\Framework\TestCase;

/**
 * Snapshot contracts need disposable globals, never fake plugins in a shared WordPress process.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class CapabilitySnapshotTest extends TestCase {
	protected function setUp(): void {
		require __DIR__ . '/CapabilitySnapshotFixture.php';
		$handler = new class() implements \Pinova\Logging\HandlerInterface { public function write( array $record ): void {} };
		$instance = new \ReflectionProperty( \Pinova\Logging\Logger::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, new \Pinova\Logging\Logger( $handler, null, static fn(): array => [ 'minimum_level' => 'info' ] ) );
	}

	public function test_unlisted_versions_keep_working_apis_active_without_instantiating_services(): void {
		define( 'WC_VERSION', '999.1.2' );
		define( 'WPFORO_VERSION', '999.3.4' );
		define( 'DOKAN_PLUGIN_VERSION', '999.5.6' );
		$snapshot = IntegrationSettings::snapshot();
		self::assertSame( 'active', $snapshot['wpforo']['state'] );
		self::assertSame( 'active', $snapshot['dokan']['state'] );
		self::assertTrue( IntegrationSettings::enabled( 'wpforo' ) );
		self::assertTrue( IntegrationSettings::enabled( 'dokan' ) );
		self::assertSame( '999.5.6', $snapshot['dokan']['versions']['dokan'] );
		self::assertSame( 0, WPF()->member->writes );
		self::assertSame( 0, WPF()->initializations );
		self::assertSame( [], $GLOBALS['wp_filter']['template_redirect']->callbacks ?? [] );
		self::assertFalse( IntegrationSettings::conversion_boundary(), 'A declared API is not an actual mutation callback.' );
	}

	public function test_forum_capabilities_do_not_require_woocommerce_or_a_woocommerce_version(): void {
		self::assertFalse( function_exists( 'WC' ) );
		self::assertFalse( defined( 'WC_VERSION' ) );
		self::assertTrue( IntegrationSettings::enabled( 'wpforo' ) );
		self::assertTrue( IntegrationSettings::capable( 'wpforo', 'membership' ) );
	}

	public function test_early_forum_absence_is_not_cached_or_mistaken_for_no_forum_during_conversion(): void {
		$forum = WPF();
		$GLOBALS['pinova_capability_forum'] = null;
		self::assertFalse( IntegrationSettings::enabled( 'wpforo' ) );
		self::assertTrue( IntegrationSettings::enabled( 'dokan' ) );
		self::assertFalse( IntegrationSettings::capable( 'dokan', 'conversion' ), 'A declared but uninitialized forum cannot safely preserve groups.' );
		$GLOBALS['pinova_capability_forum'] = $forum;
		self::assertTrue( IntegrationSettings::enabled( 'wpforo' ) );
		self::assertTrue( IntegrationSettings::capable( 'dokan', 'conversion' ) );
		self::assertSame( 0, $forum->initializations );
	}

	public function test_changed_member_signature_disables_only_membership(): void {
		WPF()->member = new class() {
			public function get_status( $id, $required ): string { return 'inactive'; }
		};
		$snapshot = IntegrationSettings::snapshot();
		self::assertSame( 'partial', $snapshot['wpforo']['state'] );
		self::assertSame( 'missing_membership_api', $snapshot['wpforo']['reason'] );
		self::assertTrue( IntegrationSettings::enabled( 'wpforo' ) );
		self::assertFalse( IntegrationSettings::capable( 'wpforo', 'membership' ) );
	}

	public function test_new_requested_account_remains_pending_through_capability_loss_and_recovery(): void {
		$member = WPF()->member;
		WPF()->member = new \stdClass();
		\Pinova\Integrations\WpForo\Load::registered( 47 );
		self::assertSame( 'pending', get_user_meta( 47, \Pinova\Integrations\WpForo\Load::STATE_META, true ) );
		self::assertTrue( \Pinova\Integrations\WpForo\Load::denied( 47 ) );
		WPF()->member = $member;
		self::assertTrue( IntegrationSettings::capable( 'wpforo', 'membership' ) );
		\Pinova\Integrations\WpForo\Load::registered( 47 );
		self::assertSame( 0, $member->writes, 'Recovery cannot silently reenroll or approve an existing pending account.' );
		self::assertTrue( \Pinova\Integrations\WpForo\Load::denied( 47 ) );
	}

	public function test_missing_forum_role_hook_keeps_routing_and_blocks_new_public_seller_role(): void {
		unset( $GLOBALS['wp_filter']['set_user_role'] );
		$snapshot = IntegrationSettings::snapshot();
		self::assertSame( 'partial', $snapshot['dokan']['state'] );
		self::assertSame( 'missing_forum_role_api', $snapshot['dokan']['reason'] );
		self::assertTrue( IntegrationSettings::enabled( 'dokan' ) );
		self::assertFalse( IntegrationSettings::capable( 'dokan', 'conversion' ) );
		self::assertSame( [ 'role' => 'customer' ], Load::customer_data( [ 'role' => 'seller' ] ) );
	}

	public function test_off_switches_preserve_observable_capabilities_without_activating_routing(): void {
		$GLOBALS['pinova_capability_options']['pinova_integrations'] = [];
		$snapshot = IntegrationSettings::snapshot();
		self::assertSame( 'off', $snapshot['wpforo']['state'] );
		self::assertFalse( IntegrationSettings::enabled( 'wpforo' ) );
		self::assertTrue( $snapshot['wpforo']['features']['rest'] );
		self::assertTrue( $snapshot['dokan']['features']['conversion'] );
	}

	public function test_native_boundary_priority_and_duplicate_registration_fail_closed(): void {
		$handler = ( new \ReflectionClass( \WeDevs\Dokan\Frontend\MyAccount\BecomeAVendor::class ) )->newInstanceWithoutConstructor();
		$entry = [ 'function' => [ $handler, 'become_a_seller_form_handler' ], 'accepted_args' => 1 ];
		foreach ( [ 0 => true, 10 => true, 11 => false, 50 => false ] as $priority => $allowed ) {
			$GLOBALS['wp_filter']['template_redirect'] = (object) [ 'callbacks' => [ $priority => [ 'native' => $entry ] ] ];
			self::assertSame( $allowed, IntegrationSettings::conversion_boundary() );
		}
		$GLOBALS['wp_filter']['template_redirect']->callbacks = [ 10 => [ 'first' => $entry, 'second' => $entry ] ];
		self::assertFalse( IntegrationSettings::conversion_boundary() );
	}

	public function test_failed_accessor_is_observable_and_does_not_hide_dokan_routing(): void {
		$GLOBALS['pinova_capability_accessor_failure'] = true;
		$snapshot = IntegrationSettings::snapshot();
		self::assertSame( 'unavailable', $snapshot['wpforo']['state'] );
		self::assertSame( 'inspection_failed', $snapshot['wpforo']['reason'] );
		self::assertTrue( $snapshot['dokan']['features']['routing'] );
		self::assertFalse( $snapshot['dokan']['features']['conversion'] );
		self::assertStringNotContainsString( 'Private diagnostic payload', json_encode( $snapshot ) );
	}

	public function test_noop_rest_initializer_records_failure_and_preserves_authentication_result(): void {
		$handler = new class() implements \Pinova\Logging\HandlerInterface {
			public array $records = [];
			public function write( array $record ): void { $this->records[] = $record; }
		};
		$logger = new \Pinova\Logging\Logger( $handler, null, static fn(): array => [ 'minimum_level' => 'info' ] );
		$instance = new \ReflectionProperty( \Pinova\Logging\Logger::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, $logger );
		$result = new \stdClass();
		self::assertSame( $result, \Pinova\Integrations\WpForo\Load::rest_initialize( $result ) );
		self::assertSame( 1, WPF()->initializations );
		self::assertNull( WPF()->topic ?? null );
		self::assertSame( 'integration.operation_failed', $handler->records[0]['event'] );
		self::assertSame( 'rest_initialization_failed', $handler->records[0]['context']['reason'] );
	}

	public function test_missing_status_does_not_confirm_an_address_changed_during_email_reset(): void {
		add_user_meta( 47, \Pinova\Integrations\WpForo\Load::STATE_META, 'pending', true );
		$GLOBALS['pinova_capability_users'][47] = (object) [ 'user_email' => 'old@example.test' ];
		\Pinova\Integrations\WpForo\Load::reset_start( 47, null, 'email' );
		$GLOBALS['pinova_capability_users'][47]->user_email = 'new@example.test';
		WPF()->member = new class() {
			public function get_status( $id ): string { throw new \RuntimeException( 'Private status failure.' ); }
		};
		try {
			$fields = \Pinova\Integrations\WpForo\Load::profile_fields( [ 'status' => 'active', 'is_email_confirmed' => 1 ], 47 );
			self::assertArrayNotHasKey( 'status', $fields );
			self::assertSame( 0, $fields['is_email_confirmed'] );
		} finally {
			\Pinova\Integrations\WpForo\Load::profile_written( 47 );
			\Pinova\Integrations\WpForo\Load::reset_end();
		}
	}

	public function test_unknown_native_profile_update_shapes_and_retries_cannot_overwrite_admin_hold(): void {
		$write = new \ReflectionMethod( \Pinova\Integrations\WpForo\Load::class, 'write_status' );
		$write->setAccessible( true );
		$exact = "UPDATE `wp_wpforo_profiles` SET `status` = 'active' WHERE `userid` = 47";
		foreach ( [
			"UPDATE wp_wpforo_profiles SET status = 'active' WHERE userid = 47",
			"UPDATE `wp_wpforo_profiles` AS p SET p.status = 'active' WHERE p.userid = 47",
			" \n update /* native change */ `wp_wpforo_profiles` \n SET status = 'active' WHERE userid = 47",
			"UPDATE --\n `wp_wpforo_profiles` SET status = 'active' WHERE userid = 47",
			"UPDATE other_table JOIN `wp_wpforo_profiles` p ON p.userid = 47 SET p.status = 'active'",
			"UPDATE other_table,wp_wpforo_profiles SET wp_wpforo_profiles.status = 'active' WHERE wp_wpforo_profiles.userid = 47",
		] as $query ) {
			WPF()->member->queries = [ $query, $exact ];
			$GLOBALS['wpdb']->queries = [];
			self::assertFalse( $write->invoke( null, 47, 'active', 'inactive', 'pending' ) );
			self::assertSame( 'inactive-admin-held', $GLOBALS['wpdb']->profile_status );
			self::assertSame( 2, count( array_filter( $GLOBALS['wpdb']->queries, static fn( $sql ): bool => 'SELECT 0' === $sql ) ) );
		}
	}

	public function test_scoped_profile_recognizer_preserves_other_tables_comments_and_literals(): void {
		$recognize = new \ReflectionMethod( \Pinova\Integrations\WpForo\Load::class, 'profile_write' );
		$recognize->setAccessible( true );
		foreach ( [
			"UPDATE wp_wpforo_profiles_archive SET status = 'active'",
			"SELECT 'UPDATE wp_wpforo_profiles SET status = active'",
			"UPDATE other_table SET value = 'UPDATE wp_wpforo_profiles SET status = active'",
			"UPDATE other_table /* JOIN wp_wpforo_profiles */ SET status = 'active'",
		] as $query ) {
			self::assertFalse( $recognize->invoke( null, $query, 'wp_wpforo_profiles' ) );
		}
	}

	public function test_exact_native_retry_after_zero_conditional_rows_cannot_overwrite_admin_hold(): void {
		$write = new \ReflectionMethod( \Pinova\Integrations\WpForo\Load::class, 'write_status' );
		$write->setAccessible( true );
		$exact = "UPDATE `wp_wpforo_profiles` SET `status` = 'active' WHERE `userid` = 47";
		WPF()->member->queries = [ $exact, $exact ];
		WPF()->member->complete_each_write = true;
		self::assertFalse( $write->invoke( null, 47, 'active', 'inactive', 'pending' ) );
		self::assertSame( 'inactive-admin-held', $GLOBALS['wpdb']->profile_status );
		self::assertNotContains( $exact, $GLOBALS['wpdb']->queries );
		self::assertSame( 2, count( array_filter( $GLOBALS['wpdb']->queries, static fn( $sql ): bool => str_contains( $sql, 'INNER JOIN' ) ) ) );
	}

	public function test_known_successful_write_releases_independent_later_admin_hooks(): void {
		$write = new \ReflectionMethod( \Pinova\Integrations\WpForo\Load::class, 'write_status' );
		$write->setAccessible( true );
		$exact = "UPDATE `wp_wpforo_profiles` SET `status` = 'active' WHERE `userid` = 47";
		$admin = "UPDATE wp_wpforo_profiles SET status = 'inactive' WHERE userid = 47";
		$GLOBALS['wpdb']->profile_status = 'inactive';
		WPF()->member->queries = [ $exact, $admin ];
		WPF()->member->complete_each_write = true;
		self::assertTrue( $write->invoke( null, 47, 'active', 'inactive', 'pending' ) );
		self::assertContains( $admin, $GLOBALS['wpdb']->queries );
		self::assertNotContains( $exact, $GLOBALS['wpdb']->queries );
	}
}
