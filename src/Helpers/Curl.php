<?php

namespace Pinova\Helpers;

use Exception;

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
		$curl = curl_init( $url );

		if ( empty( $headers ) ) {
			$headers[] = 'Accept: application/json';
		}

		curl_setopt( $curl, CURLOPT_CUSTOMREQUEST, 'POST' );
		curl_setopt( $curl, CURLOPT_POSTFIELDS, $data );
		curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $curl, CURLOPT_TIMEOUT, 8 );
		curl_setopt( $curl, CURLOPT_HTTPHEADER, $headers );

		$response  = curl_exec( $curl );
		$error     = curl_error( $curl );
		$http_code = curl_getinfo( $curl, CURLINFO_HTTP_CODE );

		if ( $error ) {
			throw new Exception( $error );
		}

		if ( empty( $response ) ) {
			throw new Exception( sprintf( 'کد %s: پاسخی از %s دریافت نشد.', $http_code, $url ) );
		}

		try {
			return json_decode( $response, true, 512, JSON_THROW_ON_ERROR );
		} catch ( Exception $exception ) {
			throw new Exception( sprintf( 'کد %s: پاسخ دریافت شده معتبر نمی‌باشد.', $http_code ) );
		}
	}


}