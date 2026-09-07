<?php

namespace Pinova\Channels;

use Exception;
use Pinova\Helpers\Curl;
use Pinova\Pinova;

class Call implements ChannelInterface {

	public static function send_code( string $identifier, int $code ): bool {

		$data = [
			'mobile' => str_replace( '+98', '0', $identifier ),
			'code'   => $code,
		];

		$response = Curl::post( 'https://service.zohal.io/api/v0/services/inquiry/voice_otp', json_encode( $data ), [
			'Content-Type: application/json',
			'Authorization: Bearer ' . Pinova::get_option( 'zohal.api_key' ),
			'zohal-reseller-id: pinova',
		] );

		$result = $response['result'] ?? 0;

		if ( $result == 1 ) {
			return true;
		}

		throw new Exception( $response['response_body']['message'], $result );
	}

	public static function send_message( string $identifier, string $message ): bool {
		throw new Exception( 'تماس صوتی قابلیت ارسال پیام ندارد.' );
	}

	public static function is_enable(): bool {
		$api_key = Pinova::get_option( 'zohal.api_key' );

		return ! empty( $api_key );
	}

}