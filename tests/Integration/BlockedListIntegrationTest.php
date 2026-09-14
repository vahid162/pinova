<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Carbon\Carbon;
use Pinova\Helpers\JWT;
use Pinova\Install;
use Pinova\Logging\LogRepository;
use Pinova\Models\Block;
use Pinova\Models\OTP;
use Pinova\Services\FirewallService;
use Pinova\Services\UserService;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class BlockedListIntegrationTest extends WP_UnitTestCase {
	private int $administrator_id;

	private bool $had_remote_address;

	private string $remote_address = '';

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();

		if ( ! did_action( 'rest_api_init' ) ) {
			do_action( 'rest_api_init', rest_get_server() );
		}
	}

	public function set_up(): void {
		parent::set_up();

		$this->had_remote_address = isset( $_SERVER['REMOTE_ADDR'] );
		$this->remote_address     = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$_SERVER['REMOTE_ADDR']   = '192.0.2.10';

		Block::query()->delete();
		OTP::query()->delete();
		LogRepository::delete_all();
		update_option(
			'pinova_logging',
			[
				'minimum_level'    => 'info',
				'retention_days'   => 14,
				'diagnostic_until' => 0,
			]
		);

		$this->administrator_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		Block::query()->delete();
		OTP::query()->delete();
		LogRepository::delete_all();
		delete_option( 'pinova_logging' );
		wp_set_current_user( 0 );

		if ( $this->had_remote_address ) {
			$_SERVER['REMOTE_ADDR'] = $this->remote_address;
		} else {
			unset( $_SERVER['REMOTE_ADDR'] );
		}

		parent::tear_down();
	}

	/**
	 * @dataProvider identifier_provider
	 */
	public function test_administrator_can_add_each_typed_identifier(
		string $type,
		string $raw_identifier,
		string $normalized_identifier
	): void {
		$response = $this->admin_request(
			'/pinova/admin/blocks/add',
			[
				'blocked_type' => $type,
				'identifier'   => $raw_identifier,
			]
		);

		self::assertSame( 200, $response->get_status() );
		self::assertTrue( $response->get_data()['success'] );
		self::assertSame( $normalized_identifier, $response->get_data()['data']['block']['identifier'] );
		self::assertSame( $type, $response->get_data()['data']['block']['identifier_type'] );

		$block = Block::query()->where( 'identifier', $normalized_identifier )->first();
		self::assertInstanceOf( Block::class, $block );
		self::assertSame( $this->administrator_id, (int) $block->blocked_by );
		self::assertNull( $block->blocked_until );
		self::assertInstanceOf( Block::class, FirewallService::is_blocked( $normalized_identifier ) );
	}

	/** @return array<string, array{string, string, string}> */
	public static function identifier_provider(): array {
		return [
			'mobile'   => [ 'mobile', '۰۹۱۲۱۲۳۴۵۶۷', '+989121234567' ],
			'email'    => [ 'email', 'Person@Example.test', 'person@example.test' ],
			'ip'       => [ 'ip', '2001:0db8:0:0:0:0:0:1', '2001:db8::1' ],
			'username' => [ 'username', 'phase3_blocked_user', 'phase3_blocked_user' ],
		];
	}

	public function test_type_mismatch_returns_a_field_error_without_writing(): void {
		$response = $this->admin_request(
			'/pinova/admin/blocks/add',
			[
				'blocked_type' => 'email',
				'identifier'   => '09121234567',
			]
		);

		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		self::assertArrayHasKey( 'identifier', $response->get_data()['data']['params'] );
		self::assertSame( 0, Block::query()->count() );
	}

	public function test_block_routes_require_manage_options(): void {
		$params = [
			'blocked_type' => 'username',
			'identifier'   => 'permission_probe',
		];

		$anonymous = $this->request( '/pinova/admin/blocks/add', $params, 0 );
		$subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$subscriber = $this->request( '/pinova/admin/blocks/add', $params, $subscriber_id );

		self::assertGreaterThanOrEqual( 400, $anonymous->get_status() );
		self::assertSame( 403, $subscriber->get_status() );
		self::assertSame( 0, Block::query()->count() );
	}

	public function test_readding_an_identifier_updates_one_row_and_can_make_it_permanent(): void {
		$temporary = $this->admin_request(
			'/pinova/admin/blocks/add',
			[
				'blocked_type'  => 'email',
				'identifier'    => 'DUPLICATE@example.test',
				'blocked_until' => time() + ( 2 * DAY_IN_SECONDS ),
			]
		);
		$block_id = (int) $temporary->get_data()['data']['block_id'];

		$permanent = $this->admin_request(
			'/pinova/admin/blocks/add',
			[
				'blocked_type' => 'email',
				'identifier'   => 'duplicate@example.test',
			]
		);

		self::assertSame( 200, $temporary->get_status() );
		self::assertSame( 200, $permanent->get_status() );
		self::assertSame( $block_id, (int) $permanent->get_data()['data']['block_id'] );
		self::assertSame( 1, Block::query()->count() );
		self::assertNull( Block::query()->findOrFail( $block_id )->blocked_until );
	}

	public function test_expired_cleanup_removes_only_temporary_blocks(): void {
		$temporary = $this->admin_request(
			'/pinova/admin/blocks/add',
			[
				'blocked_type'  => 'username',
				'identifier'    => 'expires_later',
				'blocked_until' => time() + ( 2 * DAY_IN_SECONDS ),
			]
		);
		$permanent = $this->admin_request(
			'/pinova/admin/blocks/add',
			[
				'blocked_type' => 'username',
				'identifier'   => 'stays_permanent',
			]
		);
		$temporary_id = (int) $temporary->get_data()['data']['block_id'];
		$permanent_id = (int) $permanent->get_data()['data']['block_id'];
		$blocked = $this->request(
			'/pinova/user/authenticate',
			[ 'identifier' => 'expires_later' ],
			0
		);
		self::assertSame( 400, $blocked->get_status() );

		Block::query()->whereKey( $temporary_id )->update( [ 'blocked_until' => Carbon::now()->subMinute() ] );

		self::assertNull( FirewallService::is_blocked( 'expires_later' ) );
		$after_expiry = $this->request(
			'/pinova/user/authenticate',
			[ 'identifier' => 'expires_later' ],
			0
		);
		self::assertSame( 200, $after_expiry->get_status() );
		self::assertTrue( $after_expiry->get_data()['success'] );
		FirewallService::delete_expired();
		self::assertNull( Block::query()->find( $temporary_id ) );
		self::assertInstanceOf( Block::class, Block::query()->find( $permanent_id ) );
	}

	public function test_temporary_block_requires_a_future_date(): void {
		$response = $this->admin_request(
			'/pinova/admin/blocks/add',
			[
				'blocked_type'  => 'username',
				'identifier'    => 'past_block',
				'blocked_until' => time() - ( 2 * DAY_IN_SECONDS ),
			]
		);

		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'blocked_until', $response->get_data()['data']['field'] );
		self::assertSame( 0, Block::query()->count() );
	}

	public function test_system_blocks_cannot_be_updated_or_removed_from_the_admin_api(): void {
		$block = Block::query()->create(
			[
				'identifier'    => 'system_block',
				'blocked_by'    => null,
				'blocked_until' => Carbon::now()->addHour(),
			]
		);

		$update = $this->admin_request(
			'/pinova/admin/blocks/add',
			[
				'blocked_type' => 'username',
				'identifier'   => 'system_block',
			]
		);
		$delete = $this->admin_request(
			'/pinova/admin/blocks/delete',
			[ 'block_id' => $block->id ]
		);

		self::assertSame( 403, $update->get_status() );
		self::assertSame( 403, $delete->get_status() );
		$block->refresh();
		self::assertNull( $block->blocked_by );
		self::assertNotNull( $block->blocked_until );
	}

	public function test_filters_and_index_use_stable_shapes_and_declared_json_parameters(): void {
		$first_admin = $this->administrator_id;
		wp_update_user( [ 'ID' => $first_admin, 'display_name' => 'Phase Three Alpha' ] );
		$second_admin = self::factory()->user->create(
			[
				'role'         => 'administrator',
				'display_name' => 'Phase Three Beta',
			]
		);

		$this->request(
			'/pinova/admin/blocks/add',
			[ 'blocked_type' => 'username', 'identifier' => 'alpha_block' ],
			$first_admin
		);
		$this->request(
			'/pinova/admin/blocks/add',
			[ 'blocked_type' => 'username', 'identifier' => 'beta_block' ],
			$second_admin
		);

		$filters = $this->request(
			'/pinova/admin/blocks/filters',
			[ 'blocked_by' => 'Phase Three Alpha' ],
			$first_admin
		);
		$index = $this->request(
			'/pinova/admin/blocks/index',
			[ 'blocked_by' => $first_admin ],
			$first_admin
		);

		self::assertTrue( array_is_list( $filters->get_data()['data']['users'] ) );
		self::assertSame( $first_admin, $filters->get_data()['data']['users'][0]['id'] );
		self::assertSame( 'Phase Three Alpha', $filters->get_data()['data']['users'][0]['name'] );
		self::assertSame( [ 'alpha_block' ], array_column( $index->get_data()['data']['blocks'], 'identifier' ) );
	}

	public function test_index_returns_ceiling_page_count_and_clamps_an_empty_page(): void {
		foreach ( [ 'page_one', 'page_two' ] as $identifier ) {
			$this->admin_request(
				'/pinova/admin/blocks/add',
				[ 'blocked_type' => 'username', 'identifier' => $identifier ]
			);
		}

		$response = $this->admin_request(
			'/pinova/admin/blocks/index',
			[ 'page' => 4, 'per_page' => 1 ]
		);
		$data = $response->get_data()['data'];

		self::assertSame( 2, $data['total_pages'] );
		self::assertSame( 2, $data['current_page'] );
		self::assertCount( 1, $data['blocks'] );
	}

	public function test_empty_index_has_one_stable_page_and_deleted_blocking_admin_has_a_safe_label(): void {
		$empty = $this->admin_request(
			'/pinova/admin/blocks/index',
			[ 'page' => 8, 'per_page' => 20 ]
		);
		$empty_data = $empty->get_data()['data'];

		self::assertSame( 1, $empty_data['total_pages'] );
		self::assertSame( 1, $empty_data['current_page'] );
		self::assertSame( [], $empty_data['blocks'] );

		$add = $this->admin_request(
			'/pinova/admin/blocks/add',
			[ 'blocked_type' => 'username', 'identifier' => 'orphaned_admin_block' ]
		);
		wp_delete_user( $this->administrator_id );
		$replacement_admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$index = $this->request(
			'/pinova/admin/blocks/index',
			[],
			$replacement_admin
		);

		self::assertSame( 200, $add->get_status() );
		self::assertSame( 200, $index->get_status() );
		self::assertSame( 'کاربر حذف‌شده', $index->get_data()['data']['blocks'][0]['blocked_by'] );
	}

	public function test_add_and_remove_events_are_privacy_safe(): void {
		global $wpdb;

		$raw_identifier = 'private-block@example.test';
		$add = $this->admin_request(
			'/pinova/admin/blocks/add',
			[ 'blocked_type' => 'email', 'identifier' => $raw_identifier ]
		);
		$block_id = (int) $add->get_data()['data']['block_id'];
		$added = $wpdb->get_row(
			$wpdb->prepare( 'SELECT `event`, `context` FROM %i ORDER BY `id` DESC LIMIT 1', $wpdb->prefix . 'pinova_logs' ),
			ARRAY_A
		);

		self::assertSame( 'security.block_added', $added['event'] );
		self::assertStringNotContainsString( $raw_identifier, $added['context'] );
		self::assertStringContainsString( 'identifier_fingerprint', $added['context'] );

		$this->admin_request( '/pinova/admin/blocks/delete', [ 'block_id' => $block_id ] );
		$removed = $wpdb->get_row(
			$wpdb->prepare( 'SELECT `event`, `context` FROM %i ORDER BY `id` DESC LIMIT 1', $wpdb->prefix . 'pinova_logs' ),
			ARRAY_A
		);

		self::assertSame( 'security.block_removed', $removed['event'] );
		self::assertStringNotContainsString( $raw_identifier, $removed['context'] );
	}

	public function test_mobile_block_stops_authentication_before_otp_or_user_creation(): void {
		$mobile = '09125550101';
		$this->admin_request(
			'/pinova/admin/blocks/add',
			[ 'blocked_type' => 'mobile', 'identifier' => $mobile ]
		);
		$user_count = count_users()['total_users'];

		$response = $this->request(
			'/pinova/user/authenticate',
			[ 'identifier' => $mobile ],
			0
		);

		self::assertSame( 400, $response->get_status() );
		self::assertStringContainsString( 'مسدود', $response->get_data()['data']['params']['identifier'] );
		self::assertSame( 0, OTP::query()->count() );
		self::assertSame( $user_count, count_users()['total_users'] );
		self::assertNull( UserService::get_by_mobile( $mobile ) );
	}

	public function test_ip_block_stops_every_public_authentication_request(): void {
		$this->admin_request(
			'/pinova/admin/blocks/add',
			[ 'blocked_type' => 'ip', 'identifier' => '192.0.2.10' ]
		);

		$response = $this->request(
			'/pinova/user/authenticate',
			[ 'identifier' => 'ip_block_probe' ],
			0
		);

		self::assertSame( 403, $response->get_status() );
		self::assertSame( 'pinova_ip_blocked', $response->get_data()['code'] );
		self::assertSame( 0, OTP::query()->count() );
	}

	public function test_username_block_stops_password_authentication_before_wordpress_signon(): void {
		$user_id = self::factory()->user->create(
			[
				'user_login' => 'blocked_password_user',
				'user_pass'  => 'correct-password',
			]
		);
		$this->admin_request(
			'/pinova/admin/blocks/add',
			[ 'blocked_type' => 'username', 'identifier' => 'blocked_password_user' ]
		);

		$response = $this->request(
			'/pinova/user/login/password',
			[
				'identifier' => 'blocked_password_user',
				'password'   => 'correct-password',
			],
			0
		);

		self::assertSame( 400, $response->get_status() );
		self::assertStringContainsString( 'مسدود', $response->get_data()['data']['params']['identifier'] );
		self::assertSame( 0, get_current_user_id() );
		self::assertSame( '', get_user_meta( $user_id, 'pinova_login_method', true ) );
	}

	public function test_block_added_after_otp_issue_stops_verification_before_login(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$otp = OTP::query()->create(
			[
				'user_id'    => $user_id,
				'identifier' => 'late-block@example.test',
				'code'       => '1234',
				'type'       => OTP::TYPE_LOGIN,
				'channels'   => [],
			]
		);

		$this->admin_request(
			'/pinova/admin/blocks/add',
			[ 'blocked_type' => 'email', 'identifier' => 'late-block@example.test' ]
		);
		wp_set_current_user( 0 );

		$response = $this->request(
			'/pinova/user/login/otp',
			[
				'jwt'  => JWT::encode( [ 'otp_id' => $otp->id ] ),
				'code' => '1234',
			],
			0
		);

		self::assertSame( 403, $response->get_status() );
		self::assertStringContainsString( 'مسدود', $response->get_data()['message'] );
		self::assertSame( 0, get_current_user_id() );
		self::assertNull( $otp->fresh()->verified_at );
	}

	public function test_blocked_response_does_not_reveal_account_or_native_role_existence(): void {
		self::factory()->user->create(
			[
				'user_email' => 'blocked-admin@example.test',
				'role'       => 'administrator',
			]
		);
		foreach ( [ 'blocked-admin@example.test', 'blocked-unknown@example.test' ] as $email ) {
			$this->admin_request(
				'/pinova/admin/blocks/add',
				[ 'blocked_type' => 'email', 'identifier' => $email ]
			);
		}

		$known = $this->request(
			'/pinova/user/authenticate',
			[ 'identifier' => 'blocked-admin@example.test' ],
			0
		);
		$unknown = $this->request(
			'/pinova/user/authenticate',
			[ 'identifier' => 'blocked-unknown@example.test' ],
			0
		);

		self::assertSame( $unknown->get_status(), $known->get_status() );
		self::assertSame(
			$unknown->get_data()['data']['params']['identifier'],
			$known->get_data()['data']['params']['identifier']
		);
	}

	private function admin_request( string $route, array $params ): WP_REST_Response {
		return $this->request( $route, $params, $this->administrator_id );
	}

	private function request( string $route, array $params, int $user_id ): WP_REST_Response {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_body_params( $params );

		return rest_get_server()->dispatch( $request );
	}
}
