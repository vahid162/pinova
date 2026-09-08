<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Identity\IdentityConflictException;
use Pinova\Identity\IdentityMergeService;
use Pinova\Identity\IdentityMigrationService;
use Pinova\Identity\IdentityRepository;
use Pinova\Identity\IdentityResolver;
use Pinova\Install;
use Pinova\Objects\Identifier;
use Pinova\Services\UserService;
use WP_UnitTestCase;

final class IdentityTest extends WP_UnitTestCase {
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		if ( class_exists( '\\WC_Install' ) ) {
			\WC_Install::create_tables();
		}

		Install::create_wordpress_tables();
		Install::grant_capabilities();
	}

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		foreach ( [
			'pinova_identities',
			'pinova_identity_conflicts',
			'pinova_identity_merge_items',
			'pinova_identity_merge_runs',
		] as $suffix ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . $suffix ) );
		}

		delete_option( 'pinova_identity_migration_checkpoint' );
		delete_option( 'pinova_identity_maintenance' );
	}

	public function test_username_email_and_mobile_resolve_to_one_administrator(): void {
		$user_id = self::factory()->user->create( [
			'user_login' => 'pinova_test_admin',
			'user_email' => 'pinova.admin@example.com',
			'role'       => 'administrator',
		] );
		update_user_meta( $user_id, 'billing_phone', '09120000000' );

		IdentityMigrationService::apply( 20 );
		global $wpdb;
		$identity_table = $wpdb->prefix . 'pinova_identities';
		$first_count    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $identity_table ) );
		IdentityMigrationService::apply( 20 );

		self::assertSame( $user_id, IdentityResolver::resolve( new Identifier( 'pinova_test_admin' ) ) );
		self::assertSame( $user_id, IdentityResolver::resolve( new Identifier( 'PINOVA.ADMIN@EXAMPLE.COM' ) ) );
		self::assertSame( $user_id, IdentityResolver::resolve( new Identifier( '09120000000' ) ) );
		self::assertContains( 'administrator', get_userdata( $user_id )->roles );
		self::assertSame( $first_count, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $identity_table ) ) );
	}

	public function test_dry_run_does_not_write_and_conflict_stops_resolution(): void {
		$first = self::factory()->user->create();
		$second = self::factory()->user->create();
		update_user_meta( $first, 'billing_phone', '09120000000' );
		update_user_meta( $second, 'shipping_phone', '+989120000000' );

		global $wpdb;
		$table  = $wpdb->prefix . 'pinova_identities';
		$before = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		IdentityMigrationService::dry_run();
		self::assertSame( $before, (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) );

		$this->expectException( IdentityConflictException::class );
		IdentityResolver::resolve( new Identifier( '09120000000' ), false );
	}

	public function test_virtual_mobile_meta_reads_legacy_storage_without_recursion(): void {
		$user_id = self::factory()->user->create();
		update_metadata( 'user', $user_id, 'pinova_mobile', '09120000000' );

		self::assertSame( '+989120000000', UserService::get_mobile( $user_id ) );
		self::assertSame( '+989120000000', get_user_meta( $user_id, 'pinova_mobile', true ) );
	}

	public function test_repeated_registration_creates_only_one_user(): void {
		$before  = count_users()['total_users'];
		$user_id = UserService::create( '09120000000', '', [ 'role' => 'subscriber' ], true );

		self::assertStringStartsWith( 'pinova_', get_userdata( $user_id )->user_login );
		self::assertSame( $user_id, IdentityResolver::resolve( new Identifier( '09120000000' ) ) );

		try {
			UserService::create( '+989120000000', '', [ 'role' => 'subscriber' ], true );
			self::fail( 'The duplicate identity registration should fail.' );
		} catch ( \Throwable $throwable ) {
			self::assertNotEmpty( $throwable->getMessage() );
		}

		self::assertSame( $before + 1, count_users()['total_users'] );
	}

	public function test_concurrent_registration_creates_exactly_one_user(): void {
		if ( ! function_exists( 'proc_open' ) ) {
			self::markTestSkipped( 'proc_open is required for the concurrency integration test.' );
		}

		global $wpdb, $table_prefix;
		$mobile = '09351234567';
		$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->users ) );
		// Child connections must see the fixture state and must not be blocked by WP_UnitTestCase's transaction.
		$wpdb->query( 'COMMIT' );
		$env    = [
			'PATH'                        => (string) getenv( 'PATH' ),
			'PINOVA_TEST_WORDPRESS_PATH' => ABSPATH,
			'PINOVA_TEST_PLUGIN_PATH'    => dirname( __DIR__, 2 ),
			'PINOVA_TEST_DB_NAME'        => DB_NAME,
			'PINOVA_TEST_DB_USER'        => DB_USER,
			'PINOVA_TEST_DB_PASSWORD'    => DB_PASSWORD,
			'PINOVA_TEST_DB_HOST'        => DB_HOST,
			'PINOVA_TEST_TABLE_PREFIX'   => $table_prefix,
		];
		$processes = [];

		for ( $index = 0; $index < 2; ++$index ) {
			$pipes   = [];
			$process = proc_open(
				[ PHP_BINARY, dirname( __DIR__ ) . '/fixtures/concurrent-registration-worker.php', $mobile ],
				[
					0 => [ 'pipe', 'r' ],
					1 => [ 'pipe', 'w' ],
					2 => [ 'pipe', 'w' ],
				],
				$pipes,
				null,
				$env
			);

			self::assertIsResource( $process );
			fclose( $pipes[0] );
			$processes[] = [ $process, $pipes ];
		}

		$results = [];

		foreach ( $processes as [ $process, $pipes ] ) {
			$stdout = stream_get_contents( $pipes[1] );
			$stderr = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			self::assertSame( 0, proc_close( $process ), $stderr ?: $stdout );
			self::assertMatchesRegularExpression( '/PINOVA_RESULT=(\{.*\})/', $stdout );
			preg_match( '/PINOVA_RESULT=(\{.*\})/', $stdout, $matches );
			$results[] = json_decode( $matches[1], true, 512, JSON_THROW_ON_ERROR );
		}

		$successes = array_values( array_filter( $results, static fn( array $result ): bool => true === $result['success'] ) );
		$failures  = array_values( array_filter( $results, static fn( array $result ): bool => false === $result['success'] ) );
		$user_id   = (int) ( $successes[0]['user_id'] ?? 0 );

		try {
			self::assertCount( 1, $successes, (string) wp_json_encode( $results ) );
			self::assertCount( 1, $failures );
			self::assertSame( $before + 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->users ) ) );
			self::assertSame( $user_id, IdentityResolver::resolve( new Identifier( $mobile ) ) );
		} finally {
			if ( $user_id > 0 ) {
				wp_delete_user( $user_id );
				$wpdb->delete( $wpdb->prefix . 'pinova_identities', [ 'user_id' => $user_id ], [ '%d' ] );
			}
		}
	}

	public function test_merge_and_rollback_preserve_target_security_and_new_activity(): void {
		global $wpdb;

		$source = self::factory()->user->create(
			[
				'role'      => 'subscriber',
				'user_pass' => 'source-test-password',
			]
		);
		$target = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$post   = self::factory()->post->create( [ 'post_author' => $source ] );
		$comment = self::factory()->comment->create(
			[
				'comment_post_ID' => $post,
				'user_id'         => $source,
			]
		);
		update_user_meta( $source, 'billing_city', 'Tehran' );

		IdentityRepository::add( $source, 'mobile', '+989120000000', null, 'test', true );
		$target_password_hash = get_userdata( $target )->user_pass;
		$target_capabilities  = get_user_meta( $target, $wpdb->prefix . 'capabilities', true );
		$source_session       = \WP_Session_Tokens::get_instance( $source )->create( time() + HOUR_IN_SECONDS );
		$result               = IdentityMergeService::apply( $source, $target );

		self::assertSame( $target, (int) get_post( $post )->post_author );
		self::assertSame( $target, (int) get_comment( $comment )->user_id );
		self::assertSame( $target, IdentityResolver::resolve( new Identifier( '09120000000' ) ) );
		self::assertSame( $target, (int) get_user_meta( $source, 'pinova_merged_into', true ) );
		self::assertSame( 'Tehran', get_user_meta( $target, 'billing_city', true ) );
		self::assertSame( $target_password_hash, get_userdata( $target )->user_pass );
		self::assertSame( $target_capabilities, get_user_meta( $target, $wpdb->prefix . 'capabilities', true ) );
		self::assertContains( 'administrator', get_userdata( $target )->roles );
		self::assertInstanceOf( \WP_User::class, get_userdata( $source ) );
		self::assertFalse( \WP_Session_Tokens::get_instance( $source )->verify( $source_session ) );
		self::assertWPError( wp_authenticate( get_userdata( $source )->user_login, 'source-test-password' ) );

		$new_post = self::factory()->post->create( [ 'post_author' => $target ] );
		$rollback = IdentityMergeService::rollback( (int) $result['run_id'], true );

		self::assertSame( 'rollback_partial', $rollback['status'] );
		self::assertSame( $source, (int) get_post( $post )->post_author );
		self::assertSame( $source, (int) get_comment( $comment )->user_id );
		self::assertSame( $target, (int) get_post( $new_post )->post_author );
		self::assertSame( '', get_user_meta( $target, 'billing_city', true ) );
		self::assertSame( '', get_user_meta( $source, 'pinova_merged_into', true ) );
		self::assertSame( $source, IdentityResolver::resolve( new Identifier( '09120000000' ) ) );
		self::assertFalse( \WP_Session_Tokens::get_instance( $source )->verify( $source_session ) );
	}

	public function test_resume_reconciles_a_change_made_before_journal_status_update(): void {
		$source = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$target = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$post   = self::factory()->post->create( [ 'post_author' => $source ] );

		global $wpdb;
		$run_table  = $wpdb->prefix . 'pinova_identity_merge_runs';
		$item_table = $wpdb->prefix . 'pinova_identity_merge_items';
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (`source_user_id`, `target_user_id`, `started_by`, `status`, `context`, `started_at`) VALUES (%d, %d, NULL, 'failed', '{}', UTC_TIMESTAMP())",
				$run_table,
				$source,
				$target
			)
		);
		$run_id = (int) $wpdb->insert_id;
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (`run_id`, `object_type`, `object_id`, `old_owner`, `new_owner`, `payload`, `status`, `created_at`) VALUES (%d, 'post', %d, %d, %d, NULL, 'planned', UTC_TIMESTAMP())",
				$item_table,
				$run_id,
				$post,
				$source,
				$target
			)
		);
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET `post_author` = %d WHERE `ID` = %d', $wpdb->posts, $target, $post ) );
		clean_post_cache( $post );

		IdentityMergeService::apply( $source, $target, $run_id );
		self::assertSame( 'applied', $wpdb->get_var( $wpdb->prepare( 'SELECT `status` FROM %i WHERE `run_id` = %d AND `object_type` = %s', $item_table, $run_id, 'post' ) ) );
		self::assertSame( $target, (int) get_post( $post )->post_author );

		IdentityMergeService::rollback( $run_id, true );
		self::assertSame( $source, (int) get_post( $post )->post_author );
	}

	public function test_merge_and_rollback_move_woocommerce_orders_and_downloads(): void {
		if ( ! function_exists( 'wc_create_order' ) ) {
			self::markTestSkipped( 'WooCommerce is not active in the integration environment.' );
		}

		$source = self::factory()->user->create( [ 'role' => 'customer' ] );
		$target = self::factory()->user->create( [ 'role' => 'customer' ] );
		$order  = wc_create_order( [ 'customer_id' => $source ] );

		self::assertNotWPError( $order );
		$order_id = $order->get_id();

		global $wpdb;
		$download_table = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';
		$wpdb->insert(
			$download_table,
			[
				'download_id'         => wp_generate_uuid4(),
				'product_id'          => 1,
				'order_id'            => $order_id,
				'order_key'           => $order->get_order_key(),
				'user_email'          => 'customer@example.test',
				'user_id'             => $source,
				'downloads_remaining' => 1,
				'access_granted'      => current_time( 'mysql' ),
			],
			[ '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%s' ]
		);
		$permission_id = (int) $wpdb->insert_id;

		$result = IdentityMergeService::apply( $source, $target );
		$order  = wc_get_order( $order_id );
		self::assertSame( $target, (int) $order->get_customer_id() );
		self::assertSame( $target, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT `user_id` FROM %i WHERE `permission_id` = %d', $download_table, $permission_id ) ) );

		IdentityMergeService::rollback( (int) $result['run_id'], true );
		$order = wc_get_order( $order_id );
		self::assertSame( $source, (int) $order->get_customer_id() );
		self::assertSame( $source, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT `user_id` FROM %i WHERE `permission_id` = %d', $download_table, $permission_id ) ) );
	}
}
