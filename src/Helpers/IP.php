<?php

namespace Pinova\Helpers;

class IP {

	public static function get(): string {

		$proxy_headers = [
			'HTTP_CF_CONNECTING_IP',
			'CLIENT_IP',
			'FORWARDED',
			'FORWARDED_FOR',
			'FORWARDED_FOR_IP',
			'HTTP_CLIENT_IP',
			'HTTP_FORWARDED',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED_FOR_IP',
			'HTTP_PC_REMOTE_ADDR',
			'HTTP_PROXY_CONNECTION',
			'HTTP_VIA',
			'HTTP_X_FORWARDED',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_FORWARDED_FOR_IP',
			'HTTP_X_IMFORWARDS',
			'HTTP_XROXY_CONNECTION',
			'VIA',
			'X_FORWARDED',
			'X_FORWARDED_FOR',
			'REMOTE_ADDR',
		];

		$pattern = "/^([1-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(\.([0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3}$/";

		foreach ( $proxy_headers as $proxy_header ) {

			$ip = $_SERVER[ $proxy_header ] ?? null;

			if ( ! self::is_valid( $ip ) ) {
				continue;
			}

			if ( preg_match( $pattern, $ip ) ) {
				return $ip;
			}

			if ( stristr( ',', $ip ) !== false ) {

				$server            = explode( ',', $ip );
				$proxy_header_temp = trim( array_shift( $server ) );

				if ( ( $pos_temp = stripos( $proxy_header_temp, ':' ) ) !== false ) {
					$proxy_header_temp = substr( $proxy_header_temp, 0, $pos_temp );
				}

				if ( preg_match( $pattern, $proxy_header_temp ) ) {
					return $proxy_header_temp;
				}

			}

		}

		return '127.0.0.1';
	}

	public static function is_valid( ?string $ip = '' ): bool {
		return boolval( filter_var( $ip, FILTER_VALIDATE_IP ) );
	}
}
