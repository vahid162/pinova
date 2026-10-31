<?php

namespace Pinova\Helpers;

use Exception;
use JsonException;
use WP_Error;
use Pinova\Services\OTPImmediateDelivery;
use Pinova\Services\OTPQueueStore;
use Pinova\Services\OTPService;

class Curl {

	/**
	 * @param string $url
	 * @param        $data
	 * @param array  $headers
	 *
	 * @return array|string
	 * @throws Exception
	 */
	public static function post( string $url, $data = null, array $headers = [] ) {
		if ( empty( $headers ) ) {
			$headers[] = 'Accept: application/json';
		}
		$request_headers = self::normalize_headers( $headers );

		$arguments = [
			'timeout'     => 8,
			'redirection' => 0,
			'headers'     => $request_headers,
			'body'        => $data,
			'data_format' => 'body',
		];
		// This is the final connection/flow/slot observation and attempt mark.
		// Only pure clocks and bounded memory observations precede the handoff.
		$arguments['timeout'] = OTPImmediateDelivery::provider_timeout();
		$started              = OTPService::diagnostic_clock();
		OTPQueueStore::observe_provider(
			'provider_started',
			[
				'result'     => 'started',
				'error_code' => 'none',
			]
		);
		$response = null;
		try {
			$response = wp_remote_post( $url, $arguments );
		} finally {
			$details = [
				'result'     => is_array( $response ) ? 'completed' : 'unknown',
				'error_code' => is_array( $response ) ? 'none' : 'provider_outcome_unknown',
			];
			$duration = OTPService::diagnostic_duration( $started );
			if ( null !== $duration ) {
				$details['provider_duration_ms'] = $duration;
			}
			if ( is_array( $response ) ) {
				$details['http_status'] = (int) ( $response['response']['code'] ?? 0 );
			}
			OTPQueueStore::observe_provider( 'provider_completed', $details );
		}

		if ( $response instanceof WP_Error ) {
			throw new Exception( 'ارتباط امن با سرویس‌دهنده برقرار نشد. لطفاً دوباره تلاش کنید.' );
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$body      = (string) wp_remote_retrieve_body( $response );

		if ( '' === trim( $body ) ) {
			throw new Exception( sprintf( 'کد %d: پاسخی از سرویس‌دهنده دریافت نشد.', $http_code ) );
		}

		try {
			$decoded = json_decode( $body, true, 512, JSON_THROW_ON_ERROR );

			return $decoded;
		} catch ( JsonException $exception ) {
			unset( $exception );
			throw new Exception( sprintf( 'کد %d: پاسخ دریافت‌شده معتبر نیست.', $http_code ) );
		}
	}

	/**
	 * Convert the legacy cURL-style header list to WordPress HTTP API headers.
	 *
	 * @param string[] $headers
	 * @return array<string, string>
	 */
	private static function normalize_headers( array $headers ): array {
		$normalized = [];

		foreach ( $headers as $header ) {
			if ( ! is_string( $header ) || ! str_contains( $header, ':' ) ) {
				continue;
			}

			[ $name, $value ] = array_map( 'trim', explode( ':', $header, 2 ) );
			if ( '' !== $name ) {
				$normalized[ $name ] = $value;
			}
		}

		return $normalized;
	}

	/**
	 * Redact diagnostic text only, after the adapter has interpreted protocol fields.
	 *
	 * @param mixed $message
	 * @param array $credentials
	 * @param mixed $code
	 */
	public static function provider_error( $message, array $credentials, $code = 0 ): Exception {
		if ( ! is_string( $message ) && ! is_numeric( $message ) ) {
			$message = 'پاسخ دریافت‌شده از سرویس‌دهنده معتبر نیست.';
		}

		$values = [];
		foreach ( $credentials as $credential ) {
			if ( ! is_scalar( $credential ) ) {
				continue;
			}
			$value = trim( (string) $credential );
			$values[] = $value;
			$values[] = preg_replace( '/^(?:bearer|basic)\s+/i', '', $value ) ?? '';
		}

		$credentials = array_values(
			array_unique(
				array_filter(
					$values,
					static fn( string $value ): bool => '' !== $value
				)
			)
		);
		usort(
			$credentials,
			static function ( string $left, string $right ): int {
				$length_comparison = strlen( $right ) <=> strlen( $left );

				return 0 !== $length_comparison ? $length_comparison : strcmp( $left, $right );
			}
		);

		return new Exception(
			str_replace( $credentials, '[redacted]', (string) $message ),
			is_numeric( $code ) ? (int) $code : 0
		);
	}
}
