<?php

namespace Pinova\Helpers;

use Exception;

class JWT {

	const ALGORITHM = 'sha256';

	const DEFAULT_TTL = 180;

	public static function encode( array $payload, ?int $ttl = null ): string {

		$payload['exp'] = time();

		if ( $ttl ) {
			$payload['exp'] += $ttl;
		} else {
			$payload['exp'] += self::DEFAULT_TTL;
		}

		$header = [
			'typ' => 'JWT',
			'alg' => self::ALGORITHM,
		];

		$header_encoded  = self::base64_encode( wp_json_encode( $header ) );
		$payload_encoded = self::base64_encode( wp_json_encode( $payload ) );

		$signature = hash_hmac(
			self::ALGORITHM,
			$header_encoded . '.' . $payload_encoded,
			self::get_secret(),
			true
		);

		$signature_encoded = self::base64_encode( $signature );

		return $header_encoded . '.' . $payload_encoded . '.' . $signature_encoded;
	}

	/**
	 * @param string $token
	 *
	 * @return array
	 *
	 * @throws Exception
	 */
	public static function decode( string $token ): array {

		$parts = explode( '.', $token );

		if ( count( $parts ) !== 3 ) {
			throw new Exception( 'Invalid JWT format' );
		}

		[ $header_encoded, $payload_encoded, $signature_encoded ] = $parts;

		$header  = json_decode( self::base64_decode( $header_encoded ), true );
		$payload = json_decode( self::base64_decode( $payload_encoded ), true );

		if ( ! is_array( $header ) || ! is_array( $payload ) ) {
			throw new Exception( 'Invalid JWT encoding' );
		}

		$expected_signature = hash_hmac(
			self::ALGORITHM,
			$header_encoded . '.' . $payload_encoded,
			self::get_secret(),
			true
		);

		$expected_signature_encoded = self::base64_encode( $expected_signature );

		if ( ! hash_equals( $expected_signature_encoded, $signature_encoded ) ) {
			throw new Exception( 'Invalid signature' );
		}

		if ( isset( $payload['exp'] ) && time() >= $payload['exp'] ) {
			throw new Exception( 'Token expired' );
		}

		return $payload;
	}

	private static function get_secret(): string {

		if ( defined( 'AUTH_KEY' ) && 'put your unique phrase here' !== AUTH_KEY ) {
			return AUTH_KEY;
		}

		return sha1( DB_HOST . DB_USER . DB_PASSWORD . DB_NAME );
	}

	public static function base64_encode( string $input ): string {
		// Required by the JWT base64url wire format; this is not code obfuscation.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return str_replace( '=', '', strtr( \base64_encode( $input ), '+/', '-_' ) );
	}

	public static function base64_decode( string $input ) {
		$remainder = strlen( $input ) % 4;

		if ( $remainder ) {
			$input .= str_repeat( '=', 4 - $remainder );
		}

		// Required by the JWT base64url wire format; this is not code obfuscation.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return base64_decode( strtr( $input, '-_', '+/' ) );
	}
}
