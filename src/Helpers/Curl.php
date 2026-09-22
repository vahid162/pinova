<?php

namespace Pinova\Helpers;

use Exception;
use JsonException;
use WP_Error;

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

		$response = wp_remote_post(
			$url,
			[
				'timeout'     => 8,
				'redirection' => 0,
				'headers'     => $request_headers,
				'body'        => $data,
				'data_format' => 'body',
			]
		);

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

			return self::redact_credentials( $decoded, self::credential_values( $data, $request_headers ) );
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
	 * @param mixed                $data
	 * @param array<string,string> $headers
	 * @return string[]
	 */
	private static function credential_values( $data, array $headers ): array {
		$credentials = [];

		foreach ( $headers as $name => $value ) {
			$name = strtolower( $name );
			if ( ! str_contains( $name, 'authorization' ) && ! str_contains( $name, 'token' ) && ! str_contains( $name, 'key' ) ) {
				continue;
			}

			$credentials[] = trim( $value );
			$credentials[] = preg_replace( '/^(?:bearer|basic)\s+/i', '', trim( $value ) ) ?? '';
		}

		$payload = is_string( $data ) ? json_decode( $data, true ) : $data;
		self::collect_payload_credentials( $payload, $credentials );

		return array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $credentials ),
					static fn( string $value ): bool => strlen( $value ) >= 4
				)
			)
		);
	}

	/**
	 * @param mixed    $value
	 * @param string[] $credentials
	 */
	private static function collect_payload_credentials( $value, array &$credentials, string $key = '' ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as $child_key => $child_value ) {
				self::collect_payload_credentials( $child_value, $credentials, (string) $child_key );
			}
			return;
		}

		if ( ! is_scalar( $value ) || ! preg_match( '/(?:password|username|secret|api[_-]?key|token|authorization)/i', $key ) ) {
			return;
		}

		$credentials[] = (string) $value;
	}

	/**
	 * @param mixed    $value
	 * @param string[] $credentials
	 * @return mixed
	 */
	private static function redact_credentials( $value, array $credentials ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				$value[ $key ] = self::redact_credentials( $child, $credentials );
			}
			return $value;
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		return str_replace( $credentials, '[redacted]', $value );
	}
}
