<?php

namespace Pinova\Helpers;

use Pinova\Pinova;

class IP {

	private const ALLOWED_PROXY_HEADERS = [
		'HTTP_CF_CONNECTING_IP',
		'HTTP_X_FORWARDED_FOR',
		'HTTP_X_REAL_IP',
	];

	/**
	 * Resolve the client address. Forwarding headers are ignored unless the
	 * immediate peer is in the explicitly configured trusted proxy list.
	 */
	public static function get( ?array $server = null, ?array $trusted_proxies = null, ?string $trusted_header = null ): string {
		$server = $server ?? $_SERVER;
		$remote = trim( (string) ( $server['REMOTE_ADDR'] ?? '' ) );

		if ( ! self::is_valid( $remote ) ) {
			return '';
		}

		$trusted_proxies = $trusted_proxies ?? self::trusted_proxies();

		if ( empty( $trusted_proxies ) || ! self::is_trusted( $remote, $trusted_proxies ) ) {
			return $remote;
		}

		$header = null === $trusted_header ? self::proxy_header() : strtoupper( trim( $trusted_header ) );

		if ( ! in_array( $header, self::ALLOWED_PROXY_HEADERS, true ) ) {
			return $remote;
		}

		$forwarded = trim( (string) ( $server[ $header ] ?? '' ) );

		if ( '' === $forwarded ) {
			return $remote;
		}

		$chain   = array_map( 'trim', explode( ',', $forwarded ) );
		$chain[] = $remote;

		foreach ( $chain as $address ) {
			if ( ! self::is_valid( $address ) ) {
				return $remote;
			}
		}

		if ( count( $chain ) < 2 ) {
			return $remote;
		}

		$candidate = array_pop( $chain );

		while ( ! empty( $chain ) && self::is_trusted( $candidate, $trusted_proxies ) ) {
			$candidate = array_pop( $chain );
		}

		return self::is_valid( $candidate ) ? $candidate : $remote;
	}

	public static function is_valid( ?string $ip = '' ): bool {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP );
	}

	/** @param string[] $cidrs */
	public static function is_trusted( string $ip, array $cidrs ): bool {
		foreach ( $cidrs as $cidr ) {
			if ( self::in_cidr( $ip, trim( (string) $cidr ) ) ) {
				return true;
			}
		}

		return false;
	}

	public static function in_cidr( string $ip, string $cidr ): bool {
		if ( ! self::is_valid( $ip ) || '' === $cidr ) {
			return false;
		}

		[ $network, $prefix ] = array_pad( explode( '/', $cidr, 2 ), 2, null );

		if ( ! self::is_valid( $network ) ) {
			return false;
		}

		$ip_binary      = inet_pton( $ip );
		$network_binary = inet_pton( $network );

		if ( false === $ip_binary || false === $network_binary || strlen( $ip_binary ) !== strlen( $network_binary ) ) {
			return false;
		}

		$max_bits = 8 * strlen( $ip_binary );
		$prefix   = null === $prefix ? $max_bits : (int) $prefix;

		if ( $prefix < 0 || $prefix > $max_bits ) {
			return false;
		}

		$bytes = intdiv( $prefix, 8 );
		$bits  = $prefix % 8;

		if ( $bytes > 0 && substr( $ip_binary, 0, $bytes ) !== substr( $network_binary, 0, $bytes ) ) {
			return false;
		}

		if ( 0 === $bits ) {
			return true;
		}

		$mask = ( 0xff << ( 8 - $bits ) ) & 0xff;

		return ( ord( $ip_binary[ $bytes ] ) & $mask ) === ( ord( $network_binary[ $bytes ] ) & $mask );
	}

	/** @return string[] */
	private static function trusted_proxies(): array {
		$value = function_exists( 'get_option' ) && class_exists( Pinova::class )
			? (string) Pinova::get_option( 'advanced.trusted_proxy_cidrs', '' )
			: '';

		$cidrs = preg_split( '/[\r\n,]+/', $value ) ?: [];
		$cidrs = array_values( array_filter( array_map( 'trim', $cidrs ) ) );

		return function_exists( 'apply_filters' )
			? (array) apply_filters( 'pinova/trusted_proxy_cidrs', $cidrs )
			: $cidrs;
	}

	private static function proxy_header(): string {
		$header = function_exists( 'get_option' ) && class_exists( Pinova::class )
			? (string) Pinova::get_option( 'advanced.trusted_proxy_header', '' )
			: '';

		if ( function_exists( 'apply_filters' ) ) {
			$header = (string) apply_filters( 'pinova/trusted_proxy_header', $header );
		}

		return strtoupper( trim( $header ) );
	}
}
