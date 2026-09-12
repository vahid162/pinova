<?php

namespace Pinova\Logging;

use Throwable;

final class SafeContext {

	private const FINGERPRINT_KEYS = [
		'identifier_fingerprint',
		'ip_fingerprint',
		'subject_fingerprint',
	];

	private const INTEGER_KEYS = [
		'attempt',
		'attempts',
		'candidate_count',
		'count',
		'duration_ms',
		'http_status',
		'resource_id',
		'retry_after',
		'user_id',
	];

	private const CODE_KEYS = [
		'auth_method',
		'channel',
		'identifier_type',
		'operation',
		'otp_type',
		'provider',
		'reason',
		'result',
		'scope',
		'source',
		'status',
	];

	/** @var callable */
	private $salt_provider;

	public function __construct( ?callable $salt_provider = null ) {
		$this->salt_provider = $salt_provider ?? static function (): string {
			return function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'pinova-test-log-salt';
		};
	}

	/**
	 * Reduce arbitrary PSR-3 context to a small, documented allowlist.
	 *
	 * @param array<string, mixed> $context
	 * @return array<string, bool|int|string|string[]>
	 */
	public function sanitize( array $context ): array {
		$safe = [];

		foreach ( self::INTEGER_KEYS as $key ) {
			if ( ! isset( $context[ $key ] ) || ! is_numeric( $context[ $key ] ) ) {
				continue;
			}

			$value = (int) $context[ $key ];
			if ( $value >= 0 ) {
				$safe[ $key ] = $value;
			}
		}

		foreach ( self::CODE_KEYS as $key ) {
			if ( ! isset( $context[ $key ] ) || ! is_scalar( $context[ $key ] ) ) {
				continue;
			}

			$value = $this->sanitize_code( (string) $context[ $key ] );
			if ( '' !== $value ) {
				$safe[ $key ] = $value;
			}
		}

		foreach ( self::FINGERPRINT_KEYS as $key ) {
			if ( ! isset( $context[ $key ] ) || ! is_string( $context[ $key ] ) ) {
				continue;
			}

			$value = strtolower( trim( $context[ $key ] ) );
			if ( preg_match( '/\A[a-f0-9]{32}\z/', $value ) ) {
				$safe[ $key ] = $value;
			}
		}

		if ( isset( $context['channels'] ) && is_array( $context['channels'] ) ) {
			$channels = [];
			foreach ( array_slice( $context['channels'], 0, 10 ) as $channel ) {
				if ( ! is_scalar( $channel ) ) {
					continue;
				}

				$channel = $this->sanitize_code( (string) $channel );
				if ( '' !== $channel ) {
					$channels[] = $channel;
				}
			}

			if ( $channels ) {
				$safe['channels'] = array_values( array_unique( $channels ) );
			}
		}

		if ( isset( $context['exception'] ) && $context['exception'] instanceof Throwable ) {
			$safe['exception_class'] = get_class( $context['exception'] );
			$code                    = $this->sanitize_exception_code( $context['exception']->getCode() );
			if ( '' !== $code ) {
				$safe['exception_code'] = $code;
			}
		} else {
			if ( isset( $context['exception_class'] ) && is_string( $context['exception_class'] ) ) {
				$class = preg_replace( '/[^A-Za-z0-9_\\\\]/', '', $context['exception_class'] );
				if ( is_string( $class ) && '' !== $class ) {
					$safe['exception_class'] = substr( $class, 0, 160 );
				}
			}

			if ( isset( $context['exception_code'] ) && is_scalar( $context['exception_code'] ) ) {
				$code = $this->sanitize_exception_code( $context['exception_code'] );
				if ( '' !== $code ) {
					$safe['exception_code'] = $code;
				}
			}
		}

		return $safe;
	}

	public function fingerprint( string $value, string $purpose = 'identifier' ): string {
		$salt = (string) call_user_func( $this->salt_provider );

		return substr( hash_hmac( 'sha256', $purpose . ':' . $value, $salt ), 0, 32 );
	}

	private function sanitize_code( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '/[^a-z0-9_.-]+/', '_', $value );
		$value = is_string( $value ) ? trim( $value, '_.-' ) : '';

		return substr( $value, 0, 100 );
	}

	/** @param mixed $code */
	private function sanitize_exception_code( $code ): string {
		if ( ! is_scalar( $code ) ) {
			return '';
		}

		return substr( preg_replace( '/[^A-Za-z0-9_.-]+/', '_', (string) $code ) ?? '', 0, 64 );
	}
}
