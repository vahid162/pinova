<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Pinova\API\UserAPI;
use Pinova\Helper;
use Pinova\Identity\IdentityRepository;
use Pinova\Install;
use Pinova\Integrations\Woocommerce\API as WooCommerceAPI;
use Pinova\Integrations\Wordpress\Exports\Excel;
use Pinova\Integrations\Wordpress\Exports\VCF;
use Pinova\Objects\Identifier;
use Pinova\Services\RateLimitService;
use Pinova\Services\UserService;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

final class SecurityRegressionTest extends WP_UnitTestCase {
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_wordpress_tables();
	}

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'pinova_identities' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'pinova_rate_limits' ) );
	}

	public function test_password_authentication_runs_through_security_filters(): void {
		$user_id = self::factory()->user->create(
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

	public function test_public_authentication_does_not_disclose_account_or_role_state(): void {
		$administrator = self::factory()->user->create(
			[
				'user_email' => 'administrator@example.test',
				'role'       => 'administrator',
			]
		);
		IdentityRepository::add( $administrator, 'email', 'administrator@example.test', null, 'test', true );

		$api              = new UserAPI();
		$admin_request    = new WP_REST_Request( 'POST', '/pinova/user/authenticate' );
		$unknown_request  = new WP_REST_Request( 'POST', '/pinova/user/authenticate' );
		$admin_request->set_param( 'identifier', new Identifier( 'administrator@example.test' ) );
		$unknown_request->set_param( 'identifier', new Identifier( 'unknown@example.test' ) );
		$admin_response   = $api->authenticate( $admin_request );
		$unknown_response = $api->authenticate( $unknown_request );
		$admin_data       = $admin_response->get_data();
		$unknown_data     = $unknown_response->get_data();

		self::assertSame( $unknown_response->get_status(), $admin_response->get_status() );
		self::assertSame( $unknown_data['success'], $admin_data['success'] );
		self::assertSame( $unknown_data['data']['login_method'], $admin_data['data']['login_method'] );
		self::assertArrayNotHasKey( 'has_account', $admin_data['data'] );
		self::assertArrayNotHasKey( 'has_password', $admin_data['data'] );
		self::assertArrayNotHasKey( 'native_login_url', $admin_data['data'] );
	}

	public function test_woocommerce_customer_creation_rejects_role_and_capability_fields(): void {
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

	public function test_spreadsheet_and_vcard_treat_user_content_as_data(): void {
		$user_id = self::factory()->user->create(
			[
				'first_name'   => '=HYPERLINK("https://example.test")',
				'display_name' => "Safe\r\nBEGIN:VCARD",
			]
		);
		update_user_meta( $user_id, 'description', "Safe\r\nBEGIN:VCARD" );
		$user = get_userdata( $user_id );
		$excel = new class() extends Excel {
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
}
