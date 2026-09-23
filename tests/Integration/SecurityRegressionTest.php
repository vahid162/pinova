<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Pinova\API\UserAPI;
use Pinova\Exceptions\RateLimitUnavailableException;
use Pinova\Helper;
use Pinova\Helpers\JWT;
use Pinova\Install;
use Pinova\Integrations\Woocommerce\API as WooCommerceAPI;
use Pinova\Integrations\Woocommerce\Account;
use Pinova\Integrations\Wordpress\Exports\Excel;
use Pinova\Integrations\Wordpress\Exports\VCF;
use Pinova\Models\Block;
use Pinova\Models\OTP;
use Pinova\Objects\Identifier;
use Pinova\Services\FirewallService;
use Pinova\Services\RateLimitService;
use Pinova\Services\OTPService;
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
		wp_unschedule_hook( 'pinova_otp_delivery' );
		remove_action( 'shutdown', [ OTPService::class, 'spawn_queued_delivery' ], 100 );
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
		self::factory()->user->create(
			[
				'user_email' => 'subscriber@example.test',
				'role'       => 'subscriber',
			]
		);

		$api                = new UserAPI();
		$admin_request      = new WP_REST_Request( 'POST', '/pinova/user/authenticate' );
		$unknown_request    = new WP_REST_Request( 'POST', '/pinova/user/authenticate' );
		$subscriber_request = new WP_REST_Request( 'POST', '/pinova/user/authenticate' );
		$admin_request->set_param( 'identifier', new Identifier( 'administrator@example.test' ) );
		$admin_request->set_param( 'force_otp', true );
		$unknown_request->set_param( 'identifier', new Identifier( 'unknown@example.test' ) );
		$unknown_request->set_param( 'force_otp', true );
		$subscriber_request->set_param( 'identifier', new Identifier( 'subscriber@example.test' ) );
		$subscriber_request->set_param( 'force_otp', true );
		$sent = 0;
		$mail = static function () use ( &$sent ): bool {
			++$sent;
			return true;
		};
		add_filter( 'pre_wp_mail', $mail );
		try {
			$admin_response      = $api->authenticate( $admin_request );
			$unknown_response    = $api->authenticate( $unknown_request );
			$subscriber_response = $api->authenticate( $subscriber_request );
			self::assertSame( 0, $sent );
			foreach ( [ $admin_response, $unknown_response, $subscriber_response ] as $response ) {
				$this->run_queued_otp( JWT::decode( $response->get_data()['data']['jwt'] )['flow_id'] );
			}
			self::assertSame( 1, $sent );
		} finally {
			remove_filter( 'pre_wp_mail', $mail );
		}
		$admin_data      = $admin_response->get_data();
		$unknown_data    = $unknown_response->get_data();
		$subscriber_data = $subscriber_response->get_data();

		self::assertSame( $unknown_response->get_status(), $admin_response->get_status() );
		self::assertSame( $unknown_response->get_status(), $subscriber_response->get_status() );
		self::assertSame( $unknown_data['success'], $admin_data['success'] );
		self::assertSame( $unknown_data['success'], $subscriber_data['success'] );
		self::assertSame( $unknown_data['message'], $admin_data['message'] );
		self::assertSame( $unknown_data['message'], $subscriber_data['message'] );
		self::assertSame( $unknown_data['data']['login_method'], $admin_data['data']['login_method'] );
		self::assertSame( $unknown_data['data']['login_method'], $subscriber_data['data']['login_method'] );
		self::assertArrayNotHasKey( 'native_login_url', $admin_data['data'] );
		foreach ( [ $admin_data, $unknown_data, $subscriber_data ] as $response_data ) {
			$payload = JWT::decode( $response_data['data']['jwt'] );
			self::assertSame( [ 'flow_id', 'exp' ], array_keys( $payload ) );
			self::assertMatchesRegularExpression( '/\A[a-f0-9]{32}\z/', $payload['flow_id'] );
		}
	}

	public function test_email_delivery_failure_does_not_reveal_account_existence(): void {
		self::factory()->user->create( [ 'user_email' => 'mail-failure@example.test', 'role' => 'subscriber' ] );
		$api = new UserAPI();
		$existing = new WP_REST_Request( 'POST', '/pinova/user/authenticate' );
		$missing  = new WP_REST_Request( 'POST', '/pinova/user/authenticate' );
		$existing->set_param( 'identifier', new Identifier( 'mail-failure@example.test' ) );
		$existing->set_param( 'force_otp', true );
		$missing->set_param( 'identifier', new Identifier( 'missing-mail-failure@example.test' ) );
		$missing->set_param( 'force_otp', true );
		$fail_mail = static fn(): bool => false;
		add_filter( 'pre_wp_mail', $fail_mail );
		try {
			$existing_response = $api->authenticate( $existing );
			$missing_response  = $api->authenticate( $missing );
			$this->run_queued_otp( JWT::decode( $existing_response->get_data()['data']['jwt'] )['flow_id'] );
			$this->run_queued_otp( JWT::decode( $missing_response->get_data()['data']['jwt'] )['flow_id'] );
		} finally {
			remove_filter( 'pre_wp_mail', $fail_mail );
		}

		self::assertSame( $missing_response->get_status(), $existing_response->get_status() );
		$normalize = static function ( array $data ): array {
			$claims = JWT::decode( $data['data']['jwt'] );
			self::assertSame( [ 'flow_id', 'exp' ], array_keys( $claims ) );
			self::assertMatchesRegularExpression( '/\A[a-f0-9]{32}\z/', $claims['flow_id'] );
			$data['data']['jwt'] = array_keys( $claims );
			return $data;
		};
		self::assertSame( $normalize( $missing_response->get_data() ), $normalize( $existing_response->get_data() ) );
	}

	public function test_repeated_otp_initiations_reuse_a_flow_for_real_and_decoy_accounts(): void {
		global $wpdb;

		self::factory()->user->create( [ 'user_email' => 'repeat-admin@example.test', 'role' => 'administrator' ] );
		self::factory()->user->create( [ 'user_email' => 'repeat-subscriber@example.test', 'role' => 'subscriber' ] );
		self::factory()->user->create( [ 'user_email' => 'repeat-failure@example.test', 'role' => 'subscriber' ] );
		$api = new UserAPI();

		foreach ( [ 'repeat-admin', 'repeat-unknown', 'repeat-subscriber', 'repeat-failure' ] as $account ) {
			$request = new WP_REST_Request( 'POST', '/pinova/user/authenticate' );
			$request->set_param( 'identifier', new Identifier( $account . '@example.test' ) );
			$request->set_param( 'force_otp', true );
			$mail = static fn(): bool => 'repeat-failure' !== $account;
			add_filter( 'pre_wp_mail', $mail );
			try {
				$first = $api->authenticate( $request );
				$this->run_queued_otp( JWT::decode( $first->get_data()['data']['jwt'] )['flow_id'] );
				wp_cache_flush();
				$second = $api->authenticate( $request );
			} finally {
				remove_filter( 'pre_wp_mail', $mail );
			}
			self::assertSame( 200, $first->get_status(), $account );
			self::assertSame( 200, $second->get_status(), $account );
			$first_data  = $first->get_data();
			$second_data = $second->get_data();
			self::assertSame( $first_data['message'], $second_data['message'], $account );
			self::assertSame( JWT::decode( $first_data['data']['jwt'] )['flow_id'], JWT::decode( $second_data['data']['jwt'] )['flow_id'], $account );
			$key = hash_hmac( 'sha256', 'otp_decoy:authenticate:' . $account . '@example.test', wp_salt( 'auth' ) );
			$stored_flow = $wpdb->get_var( $wpdb->prepare( 'SELECT `scope` FROM %i WHERE `bucket_key` = %s', $wpdb->prefix . 'pinova_rate_limits', $key ) );
			self::assertSame( 32, strlen( (string) $stored_flow ), $account );
		}
	}

	public function test_public_otp_fails_closed_when_durable_decoy_state_cannot_be_written(): void {
		global $wpdb;

		self::factory()->user->create( [ 'user_email' => 'storage-existing@example.test', 'role' => 'subscriber' ] );
		$fail_decoy = static function ( string $query ): string {
			return str_contains( $query, 'INSERT INTO' ) && str_contains( $query, '`scope` = IF(' )
				? 'SELECT `pinova_missing_column`'
				: $query;
		};
		$previous_errors = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail_decoy );
		try {
			$responses = [];
			foreach ( [ 'storage-existing', 'storage-unknown' ] as $account ) {
				$request = new WP_REST_Request( 'POST', '/pinova/user/authenticate' );
				$request->set_param( 'identifier', new Identifier( $account . '@example.test' ) );
				$request->set_param( 'force_otp', true );
				$responses[] = ( new UserAPI() )->authenticate( $request );
			}
		} finally {
			remove_filter( 'query', $fail_decoy );
			$wpdb->suppress_errors( $previous_errors );
		}

		self::assertSame( 503, $responses[0]->get_status() );
		self::assertSame( $responses[0]->get_data(), $responses[1]->get_data() );
		self::assertArrayNotHasKey( 'jwt', $responses[0]->get_data()['data'] );
	}

	public function test_slow_delivery_never_blocks_or_classifies_the_public_otp_response(): void {
		global $wpdb;

		$email = 'slow-delivery@example.test';
		self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );
		$had_ip = array_key_exists( 'REMOTE_ADDR', $_SERVER );
		$previous_ip = $_SERVER['REMOTE_ADDR'] ?? null;
		$_SERVER['REMOTE_ADDR'] = '192.0.2.60';
		$request = new WP_REST_Request( 'POST', '/pinova/user/authenticate' );
		$request->set_param( 'identifier', new Identifier( $email ) );
		$request->set_param( 'force_otp', true );
		$send_count = 0;
		$slow_mail = static function () use ( &$send_count ): bool {
			++$send_count;
			usleep( 1100000 );
			return true;
		};
		add_filter( 'pre_wp_mail', $slow_mail );
		try {
			$first  = ( new UserAPI() )->authenticate( $request );
			$second = ( new UserAPI() )->authenticate( $request );
			self::assertSame( 0, $send_count );
			$flow_id = JWT::decode( $first->get_data()['data']['jwt'] )['flow_id'];
			self::assertFalse( OTP::query()->where( 'flow_id', $flow_id )->exists() );
			$_SERVER['REMOTE_ADDR'] = '192.0.2.61';
			$this->run_queued_otp( $flow_id );
			self::assertSame( 1, $send_count );
			self::assertSame( '192.0.2.60', OTP::query()->where( 'flow_id', $flow_id )->firstOrFail()->ip_address );
		} finally {
			remove_filter( 'pre_wp_mail', $slow_mail );
			if ( $had_ip ) {
				$_SERVER['REMOTE_ADDR'] = $previous_ip;
			} else {
				unset( $_SERVER['REMOTE_ADDR'] );
			}
		}

		self::assertSame( 200, $first->get_status() );
		self::assertSame( 200, $second->get_status() );
		$key = hash_hmac( 'sha256', 'otp_decoy:authenticate:' . $email, wp_salt( 'auth' ) );
		$reset_at = $wpdb->get_var( $wpdb->prepare( 'SELECT `reset_at` FROM %i WHERE `bucket_key` = %s', $wpdb->prefix . 'pinova_rate_limits', $key ) );
		$deadline = strtotime( $reset_at . ' UTC' );
		foreach ( [ $first, $second ] as $response ) {
			$data    = $response->get_data()['data'];
			$payload = JWT::decode( $data['jwt'] );
			self::assertLessThanOrEqual( 1, abs( $deadline - $payload['exp'] ) );
			self::assertLessThanOrEqual( JWT::DEFAULT_TTL, $data['ttl'] );
		}

		$unknown = 'slow-unknown@example.test';
		$request->set_param( 'identifier', new Identifier( $unknown ) );
		$decoy = ( new UserAPI() )->authenticate( $request );
		$unknown_key = hash_hmac( 'sha256', 'otp_decoy:authenticate:' . $unknown, wp_salt( 'auth' ) );
		$unknown_reset = $wpdb->get_var( $wpdb->prepare( 'SELECT `reset_at` FROM %i WHERE `bucket_key` = %s', $wpdb->prefix . 'pinova_rate_limits', $unknown_key ) );
		self::assertSame( 200, $decoy->get_status() );
		self::assertLessThanOrEqual( 1, abs( strtotime( $unknown_reset . ' UTC' ) - JWT::decode( $decoy->get_data()['data']['jwt'] )['exp'] ) );
	}

	public function test_queued_otp_payload_is_encrypted_and_claimed_only_once(): void {
		global $wpdb;

		$email   = 'queued-secret@example.test';
		$request = new WP_REST_Request( 'POST', '/pinova/user/authenticate' );
		$request->set_param( 'identifier', new Identifier( $email ) );
		$request->set_param( 'force_otp', true );
		$response = ( new UserAPI() )->authenticate( $request );
		$flow_id  = JWT::decode( $response->get_data()['data']['jwt'] )['flow_id'];
		$payload  = $wpdb->get_var( $wpdb->prepare( 'SELECT `payload` FROM %i WHERE `scope` = %s', $wpdb->prefix . 'pinova_rate_limits', $flow_id ) );

		self::assertSame( 200, $response->get_status() );
		self::assertIsString( $payload );
		self::assertStringNotContainsString( $email, $payload );
		self::assertSame( $email, RateLimitService::claim_queued_otp( $flow_id )['identifier'] );
		self::assertNull( RateLimitService::claim_queued_otp( $flow_id ) );
	}

	public function test_in_progress_delivery_cannot_be_claimed_twice(): void {
		$first = RateLimitService::decoy_flow( 'in-progress@example.test', 'authenticate', '192.0.2.50' );
		self::assertSame( 'in-progress@example.test', RateLimitService::claim_queued_otp( $first[0] )['identifier'] );

		$repeated = RateLimitService::decoy_flow( 'in-progress@example.test', 'authenticate', '192.0.2.50' );
		self::assertSame( $first, $repeated );
		self::assertNull( RateLimitService::claim_queued_otp( $first[0] ) );

		self::assertTrue( RateLimitService::release_queued_otp( $first[0] ) );
		RateLimitService::decoy_flow( 'in-progress@example.test', 'authenticate', '192.0.2.50' );
		self::assertSame( 'in-progress@example.test', RateLimitService::claim_queued_otp( $first[0] )['identifier'] );
	}

	public function test_expired_queued_flow_does_not_send_a_code(): void {
		global $wpdb;

		$email = 'expired-queue@example.test';
		self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );
		$request = new WP_REST_Request( 'POST', '/pinova/user/authenticate' );
		$request->set_param( 'identifier', new Identifier( $email ) );
		$request->set_param( 'force_otp', true );
		$response = ( new UserAPI() )->authenticate( $request );
		$flow_id  = JWT::decode( $response->get_data()['data']['jwt'] )['flow_id'];
		$wpdb->update( $wpdb->prefix . 'pinova_rate_limits', [ 'reset_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ], [ 'scope' => $flow_id ] );

		$sent = 0;
		$mail = static function () use ( &$sent ): bool {
			++$sent;
			return true;
		};
		add_filter( 'pre_wp_mail', $mail );
		try {
			$this->run_queued_otp( $flow_id );
		} finally {
			remove_filter( 'pre_wp_mail', $mail );
		}
		self::assertSame( 0, $sent );
		self::assertFalse( OTP::query()->where( 'flow_id', $flow_id )->exists() );
	}

	public function test_delivery_failure_can_retry_without_changing_the_public_flow(): void {
		$email = 'queued-retry@example.test';
		self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );
		$request = new WP_REST_Request( 'POST', '/pinova/user/authenticate' );
		$request->set_param( 'identifier', new Identifier( $email ) );
		$request->set_param( 'force_otp', true );
		$send_count = 0;
		$mail = static function () use ( &$send_count ): bool {
			++$send_count;
			return $send_count > 1;
		};
		add_filter( 'pre_wp_mail', $mail );
		try {
			$first = ( new UserAPI() )->authenticate( $request );
			$flow_id = JWT::decode( $first->get_data()['data']['jwt'] )['flow_id'];
			$this->run_queued_otp( $flow_id );
			self::assertNull( OTP::query()->where( 'flow_id', $flow_id )->first() );

			$second = ( new UserAPI() )->authenticate( $request );
			self::assertSame( $flow_id, JWT::decode( $second->get_data()['data']['jwt'] )['flow_id'] );
			$this->run_queued_otp( $flow_id );
			self::assertNotNull( OTP::query()->where( 'flow_id', $flow_id )->first() );

			$third = ( new UserAPI() )->authenticate( $request );
			self::assertSame( $flow_id, JWT::decode( $third->get_data()['data']['jwt'] )['flow_id'] );
			$this->run_queued_otp( $flow_id );
			self::assertSame( 2, $send_count );
		} finally {
			remove_filter( 'pre_wp_mail', $mail );
		}
	}

	public function test_scheduler_failure_is_uniform_before_account_lookup(): void {
		self::factory()->user->create( [ 'user_email' => 'schedule-existing@example.test', 'role' => 'subscriber' ] );
		$deny = static function ( $result, $event ) {
			return 'pinova_otp_delivery' === $event->hook ? false : $result;
		};
		add_filter( 'pre_schedule_event', $deny, 10, 2 );
		try {
			$responses = [];
			foreach ( [ 'schedule-existing', 'schedule-unknown' ] as $account ) {
				$request = new WP_REST_Request( 'POST', '/pinova/user/authenticate' );
				$request->set_param( 'identifier', new Identifier( $account . '@example.test' ) );
				$request->set_param( 'force_otp', true );
				$responses[] = ( new UserAPI() )->authenticate( $request );
			}
		} finally {
			remove_filter( 'pre_schedule_event', $deny, 10 );
		}
		self::assertSame( 503, $responses[0]->get_status() );
		self::assertSame( $responses[0]->get_data(), $responses[1]->get_data() );
	}

	public function test_scheduler_exception_is_not_exposed_in_an_otp_response(): void {
		$throw = static function ( $result, $event ) {
			if ( 'pinova_otp_delivery' === $event->hook ) {
				throw new \RuntimeException( 'secret provider configuration must not appear in the response' );
			}
			return $result;
		};
		add_filter( 'pre_schedule_event', $throw, 10, 2 );
		try {
			$request = new WP_REST_Request( 'POST', '/pinova/user/authenticate' );
			$request->set_param( 'identifier', new Identifier( 'scheduler-exception@example.test' ) );
			$request->set_param( 'force_otp', true );
			$response = ( new UserAPI() )->authenticate( $request );
		} finally {
			remove_filter( 'pre_schedule_event', $throw, 10 );
		}

		self::assertSame( 503, $response->get_status() );
		self::assertStringNotContainsString( 'secret provider configuration', wp_json_encode( $response->get_data() ) );
	}

	public function test_email_case_cannot_rotate_the_queued_flow_or_otp_rate_limit(): void {
		$upper = RateLimitService::decoy_flow( 'Mixed-Case@Example.test', 'authenticate', '192.0.2.44' );
		$lower = RateLimitService::decoy_flow( 'mixed-case@example.test', 'authenticate', '192.0.2.44' );
		self::assertSame( $upper[0], $lower[0] );
		self::assertSame( 'mixed-case@example.test', RateLimitService::claim_queued_otp( $upper[0] )['identifier'] );

		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			RateLimitService::otp( '192.0.2.45', 0 === $attempt % 2 ? 'Mixed-Case@Example.test' : 'mixed-case@example.test' );
		}
		$this->expectException( \Pinova\Exceptions\RateLimitException::class );
		RateLimitService::otp( '192.0.2.45', 'MIXED-CASE@EXAMPLE.TEST' );
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

	public function test_rate_limit_backend_write_failure_fails_closed(): void {
		global $wpdb;

		$fail_write = static function ( string $query ): string {
			return str_contains( $query, 'INSERT INTO' ) && str_contains( $query, 'pinova_rate_limits' )
				? 'SELECT `pinova_missing_column`'
				: $query;
		};
		$previous_errors = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail_write );
		try {
			$this->expectException( RateLimitUnavailableException::class );
			RateLimitService::consume( 'integration_test', 'failed-write-subject', 2, 60 );
		} finally {
			remove_filter( 'query', $fail_write );
			$wpdb->suppress_errors( $previous_errors );
		}
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
		self::assertSame( [ null, false ], UserService::match_with_registration_policy( new Identifier( '09121111111' ) ) );
		self::assertFalse( UserService::mobile_is_available_for_user( '09121111111', $first_user_id ) );
		self::assertFalse( UserService::mobile_is_available_for_user( '09121111111', $second_user_id ) );
	}

	public function test_two_legacy_username_formats_for_one_mobile_fail_closed(): void {
		$first_user_id  = self::factory()->user->create( [ 'user_login' => '9121111111' ] );
		$second_user_id = self::factory()->user->create( [ 'user_login' => '989121111111' ] );

		self::assertNotSame( $first_user_id, $second_user_id );
		self::assertNull( UserService::get_by_mobile( '09121111111' ) );
		self::assertFalse( UserService::mobile_is_available_for_user( '09121111111', $first_user_id ) );
		self::assertFalse( UserService::mobile_is_available_for_user( '09121111111', $second_user_id ) );

		$request = new WP_REST_Request( 'POST', '/pinova/user/authenticate' );
		$request->set_param( 'identifier', new Identifier( '09121111111' ) );
		$response = ( new UserAPI() )->authenticate( $request );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( [ 'flow_id', 'exp' ], array_keys( JWT::decode( $response->get_data()['data']['jwt'] ) ) );
		$this->run_queued_otp( JWT::decode( $response->get_data()['data']['jwt'] )['flow_id'] );
		self::assertSame( 0, OTP::query()->where( 'identifier', '+989121111111' )->count() );
	}

	public function test_mobile_resolution_fails_closed_when_explicit_override_cannot_be_read(): void {
		global $wpdb;

		self::factory()->user->create( [ 'user_login' => '989121111112' ] );
		$break_override_read = static function ( string $query ): string {
			return str_contains( $query, 'pinova_mobile' ) && str_contains( $query, 'SELECT `meta_value`' )
				? 'SELECT FROM pinova_missing_table'
				: $query;
		};
		add_filter( 'query', $break_override_read );
		$was_suppressed = $wpdb->suppress_errors( true );
		try {
			self::assertNull( UserService::get_by_mobile( '09121111112' ) );
			self::assertSame( [ null, false ], UserService::match_with_registration_policy( new Identifier( '09121111112' ) ) );
			self::assertFalse( UserService::mobile_is_available_for_user( '09121111112', 0 ) );
		} finally {
			$wpdb->suppress_errors( $was_suppressed );
			remove_filter( 'query', $break_override_read );
		}
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

	private function run_queued_otp( string $flow_id ): void {
		wp_clear_scheduled_hook( 'pinova_otp_delivery', [ $flow_id ] );
		do_action( 'pinova_otp_delivery', $flow_id );
	}
}
