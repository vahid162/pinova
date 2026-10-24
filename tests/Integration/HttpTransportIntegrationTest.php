<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Helpers\Curl;
use WP_Error;
use WP_UnitTestCase;

final class HttpTransportIntegrationTest extends WP_UnitTestCase {

	public function test_post_uses_wordpress_http_api_with_bounded_arguments(): void {
		$observed = [];
		$filter   = static function ( $preempt, array $args, string $url ) use ( &$observed ) {
			unset( $preempt );
			$observed = [
				'args' => $args,
				'url'  => $url,
			];

			return [
				'headers'  => [],
				'body'     => '{"ok":true}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
				'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		try {
			$response = Curl::post(
				'https://provider.example/v1/send',
				'{"message":"test"}',
				[ 'Authorization: Bearer test-secret', 'Content-Type: application/json' ]
			);
		} finally {
			remove_filter( 'pre_http_request', $filter, 10 );
		}

		self::assertSame( [ 'ok' => true ], $response );
		self::assertSame( 'https://provider.example/v1/send', $observed['url'] );
		self::assertSame( 8, $observed['args']['timeout'] );
		self::assertSame( 0, $observed['args']['redirection'] );
		self::assertSame( 'body', $observed['args']['data_format'] );
		self::assertSame( 'Bearer test-secret', $observed['args']['headers']['Authorization'] );
		self::assertSame( 'application/json', $observed['args']['headers']['Content-Type'] );
	}

	public function test_transport_errors_do_not_expose_credentials_or_provider_details(): void {
		$filter = static fn() => new WP_Error(
			'http_request_failed',
			'https://provider.example/?api_key=provider-secret could not connect'
		);
		add_filter( 'pre_http_request', $filter );

		try {
			Curl::post(
				'https://provider.example/v1/send',
				'{}',
				[ 'Authorization: Bearer provider-secret' ]
			);
			self::fail( 'Expected a sanitized transport exception.' );
		} catch ( \Exception $exception ) {
			self::assertStringNotContainsString( 'provider-secret', $exception->getMessage() );
			self::assertStringNotContainsString( 'provider.example', $exception->getMessage() );
		} finally {
			remove_filter( 'pre_http_request', $filter );
		}
	}

	public function test_transport_preserves_protocol_fields_before_adapter_interpretation(): void {
		$filter = static fn() => [
			'headers'  => [],
			'body'     => '{"Value":"111","detail":"provider-secret rejected","code":401}',
			'response' => [
				'code'    => 401,
				'message' => 'Unauthorized',
			],
			'cookies'  => [],
			'filename' => null,
		];
		add_filter( 'pre_http_request', $filter );

		try {
			$response = Curl::post(
				'https://provider.example/v1/send',
				'{"username":"1","password":"provider-secret"}',
				[ 'Authorization: Bearer provider-secret' ]
			);
		} finally {
			remove_filter( 'pre_http_request', $filter );
		}

		self::assertSame( 401, $response['code'] );
		self::assertSame( '111', $response['Value'] );
		self::assertSame( 'provider-secret rejected', $response['detail'] );
	}

	public function test_diagnostic_redaction_replaces_overlapping_credentials_longest_first(): void {
		$exception = Curl::provider_error(
			'account provider-account-secret token provider-secret short xy rejected',
			[ 'provider-account', 'provider-account-secret', ' Bearer provider-secret ', 'xy', '', null ],
			401
		);

		self::assertSame( 401, $exception->getCode() );
		self::assertSame( 'account [redacted] token [redacted] short [redacted] rejected', $exception->getMessage() );
	}

	public function test_melipayamak_failure_cannot_become_success_when_credentials_overlap_value(): void {
		$filter = static fn() => [
			'body'     => '{"Value":"111"}',
			'response' => [ 'code' => 200 ],
		];
		add_filter( 'pre_http_request', $filter );

		try {
			foreach ( [ '1', 'unrelated-username' ] as $username ) {
				$gateway          = new \Pinova\Gateways\MeliPayamak();
				$gateway->options = [ 'username' => $username, 'password' => 'provider-secret' ];
				try {
					$gateway->send( '09120000000', '123456' );
					self::fail( 'A short provider error value must not be accepted.' );
				} catch ( \Exception $exception ) {
					self::assertStringContainsString( 'خطای', $exception->getMessage() );
					if ( '1' === $username ) {
						self::assertStringNotContainsString( '1', $exception->getMessage() );
					}
				}
			}
		} finally {
			remove_filter( 'pre_http_request', $filter );
		}
	}

	public function test_melipayamak_success_value_is_interpreted_without_credential_replacement(): void {
		$filter = static fn() => [
			'body'     => '{"Value":"1111111111111111"}',
			'response' => [ 'code' => 200 ],
		];
		add_filter( 'pre_http_request', $filter );

		try {
			$gateway          = new \Pinova\Gateways\MeliPayamak();
			$gateway->options = [ 'username' => '1111111111111111', 'password' => 'provider-secret' ];
			self::assertTrue( $gateway->send( '09120000000', '123456' ) );
		} finally {
			remove_filter( 'pre_http_request', $filter );
		}
	}

	/** @dataProvider gateway_diagnostic_responses */
	public function test_real_gateway_diagnostics_are_sanitized( string $gateway_class, array $body ): void {
		$filter = static fn() => [
			'body'     => wp_json_encode( $body ),
			'response' => [ 'code' => 200 ],
		];
		add_filter( 'pre_http_request', $filter );

		try {
			$gateway          = new $gateway_class();
			$gateway->options = [ 'username' => 'provider', 'password' => 'provider-secret', 'api_key' => 'provider-secret' ];
			$gateway->send( '09120000000', '123456' );
			self::fail( 'Expected a sanitized provider failure.' );
		} catch ( \Exception $exception ) {
			self::assertStringNotContainsString( 'provider-secret', $exception->getMessage() );
			self::assertStringContainsString( '[redacted]', $exception->getMessage() );
		} finally {
			remove_filter( 'pre_http_request', $filter );
		}
	}

	public static function gateway_diagnostic_responses(): array {
		return [
			'melipayamak status' => [ \Pinova\Gateways\MeliPayamak::class, [ 'StrRetStatus' => 'provider-secret rejected' ] ],
			'maxsms message'     => [ \Pinova\Gateways\MaxSMS::class, [ 'meta' => [ 'status' => false, 'message' => 'provider-secret rejected' ] ] ],
			'panelchi detail'    => [ \Pinova\Gateways\PanelChi::class, [ 'detail' => 'provider-secret rejected', 'code' => 401 ] ],
			'panelchi title'     => [ \Pinova\Gateways\PanelChi::class, [ 'title' => 'provider-secret rejected', 'code' => 401 ] ],
		];
	}

	/** @dataProvider gateway_protocol_responses */
	public function test_gateway_success_fields_keep_their_original_values( string $gateway_class, array $body, string $api_key, bool $success ): void {
		$filter = static fn() => [
			'body'     => wp_json_encode( $body ),
			'response' => [ 'code' => 200 ],
		];
		add_filter( 'pre_http_request', $filter );

		try {
			$gateway          = new $gateway_class();
			$gateway->options = [ 'api_key' => $api_key ];
			if ( ! $success ) {
				$this->expectException( \Exception::class );
			}
			self::assertTrue( $gateway->send( '09120000000', '123456' ) );
		} finally {
			remove_filter( 'pre_http_request', $filter );
		}
	}

	public static function gateway_protocol_responses(): array {
		return [
			'maxsms false string' => [ \Pinova\Gateways\MaxSMS::class, [ 'meta' => [ 'status' => '0', 'message' => 'rejected' ] ], '0', false ],
			'maxsms true string'  => [ \Pinova\Gateways\MaxSMS::class, [ 'meta' => [ 'status' => '1' ] ], '1', true ],
			'panelchi identifier' => [ \Pinova\Gateways\PanelChi::class, [ 'data' => [ 'uid' => 'provider-secret' ] ], 'provider-secret', true ],
		];
	}

	/** @dataProvider channel_responses */
	public function test_channels_interpret_protocol_and_redact_only_failure_text( string $channel, string $method, $body, string $api_key, bool $success ): void {
		$filter = static fn() => [
			'body'     => wp_json_encode( $body ),
			'response' => [ 'code' => 200 ],
		];
		$options = static fn() => [ 'api_key' => $api_key, 'bale_api_token' => $api_key, 'bale_bot_id' => 123 ];
		add_filter( 'pre_http_request', $filter );
		add_filter( 'pre_option_pinova_zohal', $options );
		add_filter( 'pre_option_pinova_messengers', $options );

		try {
			$result = $channel::$method( '09120000000', 'send_message' === $method ? 'test' : 123456 );
			self::assertTrue( $success, 'Expected a provider failure.' );
			self::assertTrue( $result );
		} catch ( \Exception $exception ) {
			self::assertFalse( $success, 'Expected a provider success.' );
			self::assertStringNotContainsString( $api_key, $exception->getMessage() );
			self::assertStringContainsString( '[redacted]', $exception->getMessage() );
		} finally {
			remove_filter( 'pre_http_request', $filter );
			remove_filter( 'pre_option_pinova_zohal', $options );
			remove_filter( 'pre_option_pinova_messengers', $options );
		}
	}

	public static function channel_responses(): array {
		return [
			'voice success'     => [ \Pinova\Channels\Call::class, 'send_code', [ 'result' => '1' ], '1', true ],
			'voice failure'     => [ \Pinova\Channels\Call::class, 'send_code', [ 'result' => 0, 'response_body' => [ 'message' => 'provider-secret rejected' ] ], 'provider-secret', false ],
			'bale otp success'  => [ \Pinova\Channels\Bale::class, 'send_v3', [ 'message_id' => 'provider-secret' ], 'provider-secret', true ],
			'bale text success' => [ \Pinova\Channels\Bale::class, 'send_message', [ 'message_id' => 'provider-secret' ], 'provider-secret', true ],
			'bale otp failure'  => [ \Pinova\Channels\Bale::class, 'send_v3', [ 'error_data' => [ [ 'description' => 'provider-secret rejected' ] ] ], 'provider-secret', false ],
			'bale text failure' => [ \Pinova\Channels\Bale::class, 'send_message', 'provider-secret rejected', 'provider-secret', false ],
		];
	}

	public function test_malformed_provider_diagnostics_return_a_controlled_exception(): void {
		$filter = static fn() => [
			'body'     => '{"Value":["provider-secret"]}',
			'response' => [ 'code' => 200 ],
		];
		add_filter( 'pre_http_request', $filter );

		try {
			$gateway          = new \Pinova\Gateways\MeliPayamak();
			$gateway->options = [ 'username' => 'provider', 'password' => 'provider-secret' ];
			$gateway->send( '09120000000', '123456' );
			self::fail( 'Expected a controlled provider failure.' );
		} catch ( \Exception $exception ) {
			self::assertStringNotContainsString( 'provider-secret', $exception->getMessage() );
			self::assertStringContainsString( 'معتبر نیست', $exception->getMessage() );
		} finally {
			remove_filter( 'pre_http_request', $filter );
		}
	}

	public function test_real_gateway_failure_keeps_admin_response_and_audit_export_private(): void {
		global $wpdb;

		\Pinova\Install::create_tables();
		$table  = $wpdb->prefix . 'pinova_logs';
		$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', $table ) );
		$http   = static fn() => [
			'body'     => '{"StrRetStatus":"provider-secret provider-payload-text 09120000000 rejected"}',
			'response' => [ 'code' => 401 ],
		];
		$sms = static fn() => [ 'gateway' => \Pinova\Gateways\MeliPayamak::class, 'message_code' => '{{otp}}' ];
		$credentials = static fn() => [ 'username' => 'provider', 'password' => 'provider-secret' ];
		add_filter( 'pre_http_request', $http );
		add_filter( 'pre_option_pinova_sms', $sms );
		add_filter( 'pre_option_pinova_gateway_melipayamak', $credentials );

		try {
			$request = new \WP_REST_Request( 'POST', '/pinova/admin/test/sms' );
			$request->set_param( 'identifier', new \Pinova\Objects\Identifier( '09120000000' ) );
			$response = ( new \Pinova\API\AdminAPI() )->test_sms( $request );
		} finally {
			remove_filter( 'pre_http_request', $http );
			remove_filter( 'pre_option_pinova_sms', $sms );
			remove_filter( 'pre_option_pinova_gateway_melipayamak', $credentials );
		}

		self::assertSame( 503, $response->get_status() );
		self::assertFalse( $response->get_data()['success'] );
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id > %d AND event = %s ORDER BY id DESC LIMIT 1', $table, $before, 'admin.sms_test_failed' ),
			ARRAY_A
		);
		self::assertIsArray( $row );
		$exported = \Pinova\Admin\Logs::redact_incident_row( $row );
		$evidence = wp_json_encode( [ $response->get_data(), $row, $exported ] );
		foreach ( [ 'provider-secret', 'provider-payload-text', '09120000000', '+989120000000' ] as $private ) {
			self::assertStringNotContainsString( $private, $evidence );
		}
	}
}
