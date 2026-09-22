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

	public function test_provider_error_mapping_redacts_overlapping_credentials_longest_first(): void {
		$filter = static fn() => [
			'headers'  => [],
			'body'     => '{"detail":"account provider-account-secret token provider-secret rejected","code":401}',
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
				'{"username":"provider-account","password":"provider-account-secret","api_key":"provider-secret"}',
				[ 'Authorization: Bearer provider-secret' ]
			);
		} finally {
			remove_filter( 'pre_http_request', $filter );
		}

		self::assertSame( 401, $response['code'] );
		self::assertSame( 'account [redacted] token [redacted] rejected', $response['detail'] );
		self::assertStringNotContainsString( '-secret', $response['detail'] );
	}
}
