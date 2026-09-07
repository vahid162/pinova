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

		$headerEncoded  = self::base64_encode( json_encode( $header ) );
		$payloadEncoded = self::base64_encode( json_encode( $payload ) );

		$signature = hash_hmac(
			self::ALGORITHM,
			$headerEncoded . '.' . $payloadEncoded,
			self::get_secret(),
			true
		);

		$signatureEncoded = self::base64_encode( $signature );

		return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
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

		[ $headerEncoded, $payloadEncoded, $signatureEncoded ] = $parts;

		$header  = json_decode( self::base64_decode( $headerEncoded ), true );
		$payload = json_decode( self::base64_decode( $payloadEncoded ), true );

		if ( ! is_array( $header ) || ! is_array( $payload ) ) {
			throw new Exception( 'Invalid JWT encoding' );
		}

		$expectedSignature = hash_hmac(
			self::ALGORITHM,
			$headerEncoded . '.' . $payloadEncoded,
			self::get_secret(),
			true
		);

		$expectedSignatureEncoded = self::base64_encode( $expectedSignature );

		if ( ! hash_equals( $expectedSignatureEncoded, $signatureEncoded ) ) {
			throw new Exception( 'Invalid signature' );
		}

		if ( isset( $payload['exp'] ) && time() >= $payload['exp'] ) {
			throw new Exception( 'Token expired' );
		}

		return $payload;
	}

	private static function get_secret(): string {

		if ( defined( 'AUTH_KEY' ) && AUTH_KEY != 'put your unique phrase here' ) {
			return AUTH_KEY;
		}

		return sha1( DB_HOST . DB_USER . DB_PASSWORD . DB_NAME );
	}

	public static function base64_encode( string $input ): string {
		return str_replace( '=', '', strtr( \base64_encode( $input ), '+/', '-_' ) );
	}

	public static function base64_decode( string $input ) {
		$remainder = strlen( $input ) % 4;

		if ( $remainder ) {
			$input .= str_repeat( '=', 4 - $remainder );
		}

		return base64_decode( strtr( $input, '-_', '+/' ) );
	}

}
