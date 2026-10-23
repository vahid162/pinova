<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Services\AccountLinkService as Link;
use Pinova\Services\AuthenticationPolicy;
use Pinova\Services\MobileVerificationService as Proof;
use Pinova\Services\UserService;
use WP_UnitTestCase;

final class AccountLinkIntegrationTest extends WP_UnitTestCase {
	private int $canonical = 0;
	private int $source = 0;
	private int $administrator = 0;
	private int $autocommit = 1;
	private string $mobile = '09124445566';

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		\Pinova\Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->autocommit = (int) $wpdb->get_var( 'SELECT @@autocommit' );
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET autocommit = 1' ); // Commit fixtures before the service opens its independent transaction.
		$this->administrator = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->administrator );
		$this->canonical = self::factory()->user->create( [ 'role' => 'customer' ] );
		$this->source = UserService::create( $this->mobile );
		update_user_meta( $this->canonical, 'billing_phone', $this->mobile );
	}

	public function tear_down(): void {
		global $wpdb;
		wp_set_current_user( $this->administrator );
		foreach ( array_filter( [ $this->canonical, $this->source ] ) as $id ) {
			foreach ( wc_get_orders( [ 'customer_id' => $id, 'limit' => -1 ] ) as $order ) {
				$order->delete( true );
			}
			wp_delete_user( $id );
		}
		wp_set_current_user( 0 );
		wp_delete_user( $this->administrator );
		$wpdb->query( $this->autocommit ? 'SET autocommit = 1' : 'SET autocommit = 0' );
		parent::tear_down();
	}

	public function test_contact_match_is_not_authority_and_plan_is_read_only(): void {
		global $wpdb;
		$before = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id IN (%d,%d) ORDER BY umeta_id', $wpdb->usermeta, $this->canonical, $this->source ), ARRAY_A );
		$plan = Link::plan( $this->canonical, $this->source );
		self::assertSame( 'ready', $plan['state'] );
		self::assertSame( $this->source, UserService::get_by_mobile( $this->mobile ) );
		self::assertSame( $before, $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id IN (%d,%d) ORDER BY umeta_id', $wpdb->usermeta, $this->canonical, $this->source ), ARRAY_A ) );
		self::assertStringNotContainsString( $this->mobile, wp_json_encode( $plan ) );
	}

	public function test_reconciliation_preserves_identity_and_requires_fresh_mobile_proof(): void {
		$usernames = [ get_userdata( $this->canonical )->user_login, get_userdata( $this->source )->user_login ];
		$plan = Link::plan( $this->canonical, $this->source );
		Link::apply( $this->canonical, $this->source, $plan['fingerprint'], true );
		self::assertSame( $this->canonical, UserService::get_by_mobile( $this->mobile ) );
		self::assertNull( UserService::get_mobile( $this->source ) );
		self::assertFalse( Proof::is_verified( $this->canonical ) );
		self::assertFalse( Proof::is_verified( $this->source ) );
		self::assertTrue( is_wp_error( AuthenticationPolicy::session( $this->source, 'otp' ) ) );
		self::assertSame( $usernames, [ get_userdata( $this->canonical )->user_login, get_userdata( $this->source )->user_login ] );
		Link::apply( $this->canonical, $this->source, $plan['fingerprint'], true );
		self::assertSame( 'applied', Link::plan( $this->canonical, $this->source )['state'] );
		$rollback = Link::plan( $this->canonical, $this->source );
		Link::rollback( $this->canonical, $this->source, $rollback['fingerprint'] );
		self::assertSame( $this->source, UserService::get_by_mobile( $this->mobile ) );
		self::assertNull( UserService::get_persisted_mobile( $this->canonical ) );
	}

	public function test_unverified_ownership_is_rejected_before_identity_changes(): void {
		$plan = Link::plan( $this->canonical, $this->source );
		try {
			Link::apply( $this->canonical, $this->source, $plan['fingerprint'], false );
			self::fail( 'Ownership attestation is required.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( $this->source, UserService::get_by_mobile( $this->mobile ) );
		}
	}

	public function test_stale_plan_cannot_reconcile_accounts(): void {
		$plan = Link::plan( $this->canonical, $this->source );
		wp_update_user( [ 'ID' => $this->canonical, 'user_email' => 'changed@example.test' ] );
		$this->expectException( \RuntimeException::class );
		Link::apply( $this->canonical, $this->source, $plan['fingerprint'], true );
	}

	public function test_public_user_cannot_discover_or_apply_a_link_plan(): void {
		wp_set_current_user( $this->source );
		$this->expectException( \RuntimeException::class );
		Link::plan( $this->canonical, $this->source );
	}

	public function test_existing_source_orders_require_a_separate_migration(): void {
		$order = wc_create_order( [ 'customer_id' => $this->source ] );
		try {
			$this->expectException( \RuntimeException::class );
			Link::plan( $this->canonical, $this->source );
		} finally {
			$order->delete( true );
		}
	}

	public function test_trashed_source_orders_are_still_customer_history(): void {
		$order = wc_create_order( [ 'customer_id' => $this->source ] );
		$id = $order->get_id();
		$order->delete( false );
		try {
			$this->expectException( \RuntimeException::class );
			Link::plan( $this->canonical, $this->source );
		} finally {
			$trashed = wc_get_order( $id );
			if ( $trashed ) {
				$trashed->delete( true );
			}
		}
	}

	public function test_contact_fields_cannot_be_reintroduced_by_alias_filter(): void {
		$mobile = '09124445577';
		update_user_meta( $this->canonical, 'billing_phone', $mobile );
		$filter = static fn( array $keys ): array => array_merge( $keys, [ 'billing_phone', 'shipping_phone', 'Billing_Phone', 'SHIPPING_PHONE', 'billing_phone ', ' Shipping_Phone ', 'bílling_phone', 'custom_phone' ] );
		add_filter( 'pinova/mobile_possible_meta_keys', $filter );
		try {
			self::assertNotContains( 'billing_phone', UserService::mobile_possible_meta_keys() );
			self::assertNotContains( 'shipping_phone', UserService::mobile_possible_meta_keys() );
			self::assertContains( 'custom_phone', UserService::mobile_possible_meta_keys() );
			self::assertNull( UserService::get_by_mobile( $mobile ) );
			self::assertTrue( UserService::mobile_is_available_for_user( $mobile, 0 ) );
		} finally {
			remove_filter( 'pinova/mobile_possible_meta_keys', $filter );
		}
	}

	public function test_failed_journal_write_rolls_back_both_identity_rows(): void {
		$plan = Link::plan( $this->canonical, $this->source );
		$pdo = \Pinova\Models\OTP::resolveConnection()->getPdo();
		$previous = $pdo->getAttribute( \PDO::ATTR_STATEMENT_CLASS );
		$pdo->setAttribute( \PDO::ATTR_STATEMENT_CLASS, [ AccountLinkFailingStatement::class ] );
		try {
			Link::apply( $this->canonical, $this->source, $plan['fingerprint'], true );
			self::fail( 'Journal failure must abort the operation.' );
		} catch ( \RuntimeException $exception ) {
			self::assertNull( UserService::get_persisted_mobile( $this->canonical ) );
			self::assertFalse( Link::retired_state( $this->source ) );
			self::assertSame( $this->source, UserService::get_by_mobile( $this->mobile ) );
		} finally {
			$pdo->setAttribute( \PDO::ATTR_STATEMENT_CLASS, $previous );
		}
	}

	public function test_orders_and_permission_fields_are_byte_preserved(): void {
		global $wpdb;
		$order = wc_create_order( [ 'customer_id' => $this->canonical ] );
		$order->set_billing_email( get_userdata( $this->canonical )->user_email );
		$order->set_status( 'completed' );
		$order->set_transaction_id( 'synthetic-transaction' );
		$order->save();
		$permission = new \WC_Customer_Download();
		$permission->set_order_id( $order->get_id() );
		$permission->set_order_key( $order->get_order_key() );
		$permission->set_user_id( $this->canonical );
		$permission->set_user_email( get_userdata( $this->canonical )->user_email );
		$permission->set_product_id( 123 );
		$permission->set_download_id( str_repeat( 'a', 32 ) );
		$permission->set_downloads_remaining( '3' );
		$permission->set_download_count( 7 );
		$permission->set_access_expires( time() + DAY_IN_SECONDS );
		$permission->save();
		$before_order = wc_get_order( $order->get_id() )->get_data();
		$sql = $wpdb->prepare( 'SELECT * FROM %i WHERE permission_id = %d', $wpdb->prefix . 'woocommerce_downloadable_product_permissions', $permission->get_id() );
		$before_permission = $wpdb->get_row( $sql, ARRAY_A );
		$plan = Link::plan( $this->canonical, $this->source );
		self::assertSame( 1, $plan['permissions_preserved'] );
		Link::apply( $this->canonical, $this->source, $plan['fingerprint'], true );
		self::assertEquals( $before_order, wc_get_order( $order->get_id() )->get_data() );
		self::assertSame( $before_permission, $wpdb->get_row( $sql, ARRAY_A ) );
		$rollback = Link::plan( $this->canonical, $this->source );
		Link::rollback( $this->canonical, $this->source, $rollback['fingerprint'] );
		self::assertSame( $before_permission, $wpdb->get_row( $sql, ARRAY_A ) );
	}

	public function test_retired_alias_survives_key_rotation_and_cannot_reuse_a_cookie(): void {
		$plan = Link::plan( $this->canonical, $this->source );
		Link::apply( $this->canonical, $this->source, $plan['fingerprint'], true );
		$salt = static fn(): string => 'rotated-synthetic-key';
		add_filter( 'salt', $salt );
		try {
			self::assertSame( $this->canonical, UserService::get_by_mobile( $this->mobile ) );
			self::assertSame( 0, Link::current_user( $this->source ) );
			self::assertSame( $this->canonical, Link::current_user( $this->canonical ) );
		} finally {
			remove_filter( 'salt', $salt );
		}
	}

	public function test_conflicting_retirement_metadata_fails_closed(): void {
		add_user_meta( $this->source, Link::RETIRED_META, '1' );
		add_user_meta( $this->source, Link::RETIRED_META, 'invalid' );
		self::assertNull( Link::retired_state( $this->source ) );
		self::assertNull( UserService::get_by_mobile( $this->mobile ) );
		self::assertFalse( UserService::mobile_is_available_for_user( $this->mobile, $this->canonical ) );
	}
	public function test_new_contact_overlap_is_observed_without_claiming_the_legacy_account(): void {
		global $wpdb;
		$mobile = '09124445577';
		update_user_meta( $this->canonical, 'billing_phone', $mobile );
		$before = get_option( 'pinova_logging', [] );
		update_option( 'pinova_logging', [ 'minimum_level' => 'info', 'diagnostic_until' => 0 ] );
		$id = UserService::create( $mobile );
		try {
			self::assertSame( $id, UserService::get_by_mobile( $mobile ) );
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT user_id,context FROM %i WHERE event = %s AND user_id = %d ORDER BY id DESC LIMIT 1', $wpdb->prefix . 'pinova_logs', 'identity.legacy_contact_overlap', $id ), ARRAY_A );
			self::assertIsArray( $row );
			$context = json_decode( $row['context'], true );
			self::assertSame( 1, $context['candidate_count'] );
			self::assertStringNotContainsString( $mobile, $row['context'] );
		} finally {
			wp_delete_user( $id );
			update_option( 'pinova_logging', $before );
		}
	}

	public function test_a_released_number_can_register_without_rewriting_its_old_username(): void {
		$old_username = get_userdata( $this->source )->user_login;
		$plan = Link::plan( $this->canonical, $this->source );
		Link::apply( $this->canonical, $this->source, $plan['fingerprint'], true );
		update_user_meta( $this->canonical, 'pinova_mobile', '09124445588' );
		$id = UserService::create( $this->mobile );
		try {
			self::assertSame( $id, UserService::get_by_mobile( $this->mobile ) );
			self::assertNotSame( $old_username, get_userdata( $id )->user_login );
			self::assertSame( $old_username, get_userdata( $this->source )->user_login );
			$otp = \Pinova\Models\OTP::query()->create( [
				'user_id' => $id, 'identifier' => $this->mobile, 'type' => \Pinova\Models\OTP::TYPE_LOGIN,
				'code' => '4821', 'flow_id' => bin2hex( random_bytes( 16 ) ), 'channels' => [ 'sms' => true ],
			] );
			\Pinova\Services\OTPService::verify_with_flow( \Pinova\Services\OTPService::signed_state( $otp ), '4821', [ \Pinova\Models\OTP::TYPE_LOGIN ] );
			self::assertTrue( Proof::is_verified( $id ) );
		} finally {
			\Pinova\Models\OTP::query()->where( 'user_id', $id )->delete();
			wp_delete_user( $id );
		}
	}

	public function test_order_post_author_is_not_customer_ownership(): void {
		global $wpdb;
		$order = wc_create_order( [ 'customer_id' => $this->canonical ] );
		$order->save();
		// A synchronized legacy order post can retain the creating session's author.
		if ( get_post( $order->get_id() ) ) {
			$wpdb->update( $wpdb->posts, [ 'post_author' => $this->source ], [ 'ID' => $order->get_id() ] );
		} else {
			self::assertSame( $order->get_id(), wp_insert_post( [ 'import_id' => $order->get_id(), 'post_author' => $this->source, 'post_type' => 'shop_order', 'post_status' => 'wc-pending' ] ) );
		}
		$plan = Link::plan( $this->canonical, $this->source );
		self::assertSame( 'ready', $plan['state'] );
		self::assertSame( 0, $plan['source_orders'] );
		self::assertSame( 1, $plan['orders_preserved'] );
	}

	public function test_rollback_restores_source_access_without_changing_later_wallet_history(): void {
		global $wpdb;
		$table = $wpdb->base_prefix . 'woo_wallet_transactions';
		self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) );
		self::assertNotFalse( $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (user_id BIGINT UNSIGNED NOT NULL)', $table ) ) );
		try {
			$plan = Link::plan( $this->canonical, $this->source );
			Link::apply( $this->canonical, $this->source, $plan['fingerprint'], true );
			// A separate ownership adapter can append history after cutover.
			self::assertSame( 1, $wpdb->insert( $table, [ 'user_id' => $this->source ] ) );
			$rollback = Link::plan( $this->canonical, $this->source );
			Link::rollback( $this->canonical, $this->source, $rollback['fingerprint'] );
			self::assertSame( $this->source, UserService::get_by_mobile( $this->mobile ) );
			self::assertSame( (string) $this->source, $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM %i', $table ) ) );
		} finally {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) );
		}
	}

	public function test_source_wallet_transactions_require_a_separate_migration(): void {
		global $wpdb;
		$this->assert_owned_adapter_rejected( $wpdb->base_prefix . 'woo_wallet_transactions', 'user_id', 'source_has_wallet_activity' );
	}

	public function test_source_forum_posts_require_a_separate_migration(): void {
		global $wpdb;
		$this->assert_owned_adapter_rejected( $wpdb->prefix . 'wpforo_link_test_posts', 'userid', 'source_has_forum_activity' );
	}

	private function assert_owned_adapter_rejected( string $table, string $column, string $reason ): void {
		global $wpdb;
		self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) );
		self::assertNotFalse( $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (%i BIGINT UNSIGNED NOT NULL)', $table, $column ) ) );
		try {
			self::assertSame( 1, $wpdb->insert( $table, [ $column => $this->source ] ) );
			try {
				Link::plan( $this->canonical, $this->source );
				self::fail( 'Source-owned activity cannot be stranded.' );
			} catch ( \RuntimeException $exception ) {
				self::assertSame( $reason, Link::failure_reason( $exception ) );
			}
			self::assertSame( $this->source, UserService::get_by_mobile( $this->mobile ) );
		} finally {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) );
		}
	}

	public function test_source_authored_content_requires_a_separate_migration(): void {
		$id = self::factory()->post->create( [ 'post_author' => $this->source, 'post_status' => 'draft' ] );
		try {
			$this->expectException( \RuntimeException::class );
			Link::plan( $this->canonical, $this->source );
		} finally {
			wp_delete_post( $id, true );
		}
	}

}


final class AccountLinkFailingStatement extends \PDOStatement {
	protected function __construct() {}

	public function execute( ?array $params = null ): bool {
		return in_array( Link::JOURNAL_META, $params ?? [], true ) ? false : parent::execute( $params );
	}
}
