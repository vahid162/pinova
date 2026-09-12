<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Pinova\API\UserAPI;
use Pinova\Helper;
use Pinova\Install;
use Pinova\Integrations\Woocommerce\API as WooCommerceAPI;
use Pinova\Integrations\Woocommerce\Account;
use Pinova\Integrations\Wordpress\Exports\Excel;
use Pinova\Integrations\Wordpress\Exports\VCF;
use Pinova\Models\Block;
use Pinova\Objects\Identifier;
use Pinova\Services\FirewallService;
use Pinova\Services\RateLimitService;
use Pinova\Services\UserService;
use Pinova\Version;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

final class SecurityRegressionTest extends WP_UnitTestCase {
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'pinova_rate_limits' ) );
		$_GET  = [];
		$_POST = [];
	}

	public function tear_down(): void {
		$_GET  = [];
		$_POST = [];
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_password_authentication_runs_through_security_filters(): void {
		$user_id       = self::factory()->user->create(
			[
				'user_login' => 'second_factor_user',
				'user_pass'  => 'correct-test-password',
			]
		);
		$second_factor = static function ( $user, string $username ) {
			return 'second_factor_user' === $username
				? new WP_Error( 'second_factor_required', 'Second factor required.' )
				: $user;
		};
		add_filter( 'authenticate', $second_factor, 30, 2 );

		try {
			$result = UserService::authenticate_password( $user_id, 'correct-test-password' );
			self::assertWPError( $result );
			self::assertSame( 'second_factor_required', $result->get_error_code() );
		} finally {
			remove_filter( 'authenticate', $second_factor, 30 );
		}
	}

	public function test_native_only_and_unknown_accounts_have_uniform_public_responses(): void {
		self::factory()->user->create(
			[
				'user_email' => 'administrator@example.test',
				'role'       => 'administrator',
			]
		);

		$api             = new UserAPI();
		$admin_request   = new WP_REST_Request( 'POST', '/pinova/user/authenticate' );
		$unknown_request = new WP_REST_Request( 'POST', '/pinova/user/authenticate' );
		$admin_request->set_param( 'identifier', new Identifier( 'administrator@example.test' ) );
		$admin_request->set_param( 'force_otp', true );
		$unknown_request->set_param( 'identifier', new Identifier( 'unknown@example.test' ) );
		$unknown_request->set_param( 'force_otp', true );
		$admin_response   = $api->authenticate( $admin_request );
		$unknown_response = $api->authenticate( $unknown_request );
		$admin_data       = $admin_response->get_data();
		$unknown_data     = $unknown_response->get_data();

		self::assertSame( $unknown_response->get_status(), $admin_response->get_status() );
		self::assertSame( $unknown_data['success'], $admin_data['success'] );
		self::assertSame( $unknown_data['message'], $admin_data['message'] );
		self::assertSame( $unknown_data['data']['login_method'], $admin_data['data']['login_method'] );
		self::assertArrayNotHasKey( 'native_login_url', $admin_data['data'] );
	}

	public function test_native_only_password_endpoint_does_not_disclose_the_account(): void {
		self::factory()->user->create(
			[
				'user_email' => 'native-admin@example.test',
				'role'       => 'administrator',
			]
		);

		$api             = new UserAPI();
		$admin_request   = new WP_REST_Request( 'POST', '/pinova/user/login/password' );
		$unknown_request = new WP_REST_Request( 'POST', '/pinova/user/login/password' );
		$admin_request->set_param( 'identifier', new Identifier( 'native-admin@example.test' ) );
		$admin_request->set_param( 'password', 'incorrect-password' );
		$unknown_request->set_param( 'identifier', new Identifier( 'unknown-admin@example.test' ) );
		$unknown_request->set_param( 'password', 'incorrect-password' );
		$admin_response   = $api->login_password( $admin_request );
		$unknown_response = $api->login_password( $unknown_request );

		self::assertSame( 401, $admin_response->get_status() );
		self::assertSame( $unknown_response->get_status(), $admin_response->get_status() );
		self::assertSame( $unknown_response->get_data(), $admin_response->get_data() );
		self::assertArrayNotHasKey( 'native_login_url', $admin_response->get_data()['data'] );
	}

	public function test_woocommerce_customer_creation_rejects_privileged_fields(): void {
		$api     = new WooCommerceAPI();
		$request = new WP_REST_Request( 'POST', '/pinova/woocommerce/customer/create' );
		$request->set_param( 'mobile', new Identifier( '09351234568' ) );
		$request->set_param( 'email', '' );
		$request->set_param( 'first_name', 'Test' );
		$request->set_param( 'last_name', 'Customer' );
		$request->set_param(
			'order_data',
			[
				'role'         => 'administrator',
				'capabilities' => [ 'manage_options' => true ],
			]
		);

		$response = $api->create_customer( $request );
		self::assertSame( 400, $response->get_status() );
		self::assertFalse( $response->get_data()['success'] );
		self::assertNull( UserService::get_by_mobile( '09351234568' ) );
	}

	public function test_hpos_matrix_configuration_reaches_the_test_database(): void {
		$expected = getenv( 'PINOVA_TEST_HPOS' );

		self::assertContains( $expected, [ 'yes', 'no' ] );
		self::assertSame( $expected, get_option( 'woocommerce_custom_orders_table_enabled' ) );
	}

	public function test_spreadsheet_and_vcard_treat_user_content_as_data(): void {
		// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Regression guard deliberately caps export memory.
		ini_set( 'memory_limit', '256M' );

		$user_id = self::factory()->user->create(
			[
				'first_name'   => '=HYPERLINK("https://example.test")',
				'display_name' => "Safe\r\nBEGIN:VCARD",
			]
		);
		update_user_meta( $user_id, 'description', "Safe\r\nBEGIN:VCARD" );
		$user        = get_userdata( $user_id );
		$excel       = new class() extends Excel {
			public function generate_for_test( array $users ): \PhpOffice\PhpSpreadsheet\Spreadsheet {
				return $this->generate( $users );
			}
		};
		$spreadsheet = $excel->generate_for_test( [ $user ] );
		$cell        = $spreadsheet->getActiveSheet()->getCell( 'C8' );

		self::assertSame( '=HYPERLINK("https://example.test")', $cell->getValue() );
		self::assertSame( DataType::TYPE_STRING, $cell->getDataType() );

		$vcard = ( new VCF() )->generate( [ $user ] );
		self::assertStringNotContainsString( "\r\nBEGIN:VCARD\r\nBEGIN:VCARD", $vcard );
		self::assertStringContainsString( 'Safe\\nBEGIN:VCARD', $vcard );
	}

	public function test_external_login_redirect_is_rejected(): void {
		self::assertSame( site_url(), Helper::get_login_back_url( 'https://attacker.example/path' ) );
	}

	public function test_rate_limit_throws_after_the_atomic_limit(): void {
		RateLimitService::consume( 'integration_test', 'masked-subject', 2, 60 );
		RateLimitService::consume( 'integration_test', 'masked-subject', 2, 60 );

		$this->expectException( \Pinova\Exceptions\RateLimitException::class );
		RateLimitService::consume( 'integration_test', 'masked-subject', 2, 60 );
	}

	public function test_mobile_edit_is_persisted_without_rewriting_username(): void {
		$user_id        = self::factory()->user->create(
			[
				'user_login' => '989121234567',
				'role'       => 'administrator',
			]
		);
		$original_login = get_userdata( $user_id )->user_login;
		update_user_meta( $user_id, 'digits_phone', '+989121234567' );

		wp_set_current_user( $user_id );
		$_POST['pinova_mobile'] = '09351234567';
		( new Account() )->save_mobile( $user_id );

		self::assertSame( $original_login, get_userdata( $user_id )->user_login );
		self::assertSame( '+989351234567', UserService::get_mobile( $user_id ) );
		self::assertSame( '+989351234567', get_user_meta( $user_id, 'pinova_mobile', true ) );
		self::assertSame( $user_id, UserService::get_by_mobile( '09351234567' ) );
		self::assertNull( UserService::get_by_mobile( '09121234567' ) );
		self::assertNull( UserService::get_by_mobile( new \Pinova\Objects\Mobile( 'invalid-mobile' ) ) );
	}

	public function test_conflicting_legacy_mobile_matches_fail_closed(): void {
		$first_user_id  = self::factory()->user->create( [ 'user_login' => '989121111111' ] );
		$second_user_id = self::factory()->user->create( [ 'user_login' => 'mobile_conflict_user' ] );
		update_user_meta( $second_user_id, 'digits_phone', '+989121111111' );

		self::assertNotSame( $first_user_id, $second_user_id );
		self::assertNull( UserService::get_by_mobile( '09121111111' ) );
		self::assertFalse( UserService::mobile_is_available_for_user( '09121111111', $first_user_id ) );
		self::assertFalse( UserService::mobile_is_available_for_user( '09121111111', $second_user_id ) );
	}

	public function test_deprecated_username_mutator_is_a_noop(): void {
		$user_id = self::factory()->user->create( [ 'user_login' => 'immutable_login' ] );
		$this->setExpectedDeprecated( UserService::class . '::update_username' );

		self::assertFalse( UserService::update_username( $user_id, 'changed_login' ) );
		self::assertSame( 'immutable_login', get_userdata( $user_id )->user_login );
	}

	public function test_legacy_115_upgrade_is_expand_only(): void {
		global $wpdb;

		$email   = 'nomail-security-test@' . wp_parse_url( site_url(), PHP_URL_HOST );
		$user_id = self::factory()->user->create( [ 'user_email' => 'upgrade-user@example.test' ] );
		$wpdb->update( $wpdb->users, [ 'user_email' => $email ], [ 'ID' => $user_id ] );
		clean_user_cache( $user_id );
		$column = $wpdb->get_row(
			$wpdb->prepare( 'SHOW FULL COLUMNS FROM %i LIKE %s', $wpdb->users, 'user_email' ),
			ARRAY_A
		);
		self::assertSame( $email, get_userdata( $user_id )->user_email );

		( new Version() )->update_115();

		self::assertSame( $email, get_userdata( $user_id )->user_email );
		self::assertSame(
			$column,
			$wpdb->get_row(
				$wpdb->prepare( 'SHOW FULL COLUMNS FROM %i LIKE %s', $wpdb->users, 'user_email' ),
				ARRAY_A
			)
		);
	}

	public function test_filtered_registration_roles_are_revalidated_by_capability(): void {
		$inject_privileged_role = static fn(): array => [
			'administrator' => 'Administrator',
			'subscriber'    => 'Subscriber',
		];
		add_filter( 'pinova/allowed_registration_roles', $inject_privileged_role );

		try {
			$roles = UserService::allowed_registration_roles();
			self::assertArrayNotHasKey( 'administrator', $roles );
			self::assertArrayHasKey( 'subscriber', $roles );
		} finally {
			remove_filter( 'pinova/allowed_registration_roles', $inject_privileged_role );
		}
	}

	public function test_permanent_blocks_are_enforced(): void {
		$identifier = 'pinova-permanent-block@example.test';
		Block::query()->where( 'identifier', $identifier )->delete();

		try {
			Block::query()->create(
				[
					'identifier'    => $identifier,
					'blocked_until' => null,
				]
			);

			self::assertInstanceOf( Block::class, FirewallService::is_blocked( $identifier ) );
		} finally {
			Block::query()->where( 'identifier', $identifier )->delete();
		}
	}
}
