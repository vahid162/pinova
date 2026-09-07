<?php

namespace Pinova\Channels;

use Exception;
use Pinova\Helpers\Curl;
use Pinova\Pinova;

class Bale implements ChannelInterface {

	public static function send_code( string $identifier, int $code ): bool {

		if ( function_exists( 'pinova_bale_send_v2' ) ) {
			return pinova_bale_send_v2( $identifier, $code );
		}

		return self::send_v3( $identifier, $code );
	}

	public static function send_message( string $identifier, string $message ): bool {

		$data = [
			'bot_id'       => intval( self::get_bot_id() ),
			'phone_number' => $identifier,
			'message_data' => [
				'message' => [
					'text' => $message,
				],
			],
		];

		$response = Curl::post( 'https://safir.bale.ai/api/v3/send_message', wp_json_encode( $data ), [
			'api-access-key: ' . self::get_api_token(),
			'Content-Type: application/json',
		] );

		if ( isset( $response['message_id'] ) ) {
			return true;
		}

		if ( is_string( $response ) ) {
			throw new Exception( $response );
		}

		if ( isset( $response['error_data'] [0]['description'] ) ) {
			throw new Exception( $response['error_data'] [0]['description'] );
		}

		throw new Exception( 'خطای ناشناخته در ارسال پیام به بله رخ داده است.' );
	}

	/**
	 * @throws Exception
	 */
	public static function send_v3( string $identifier, int $code ): bool {

		$data = [
			'bot_id'       => intval( self::get_bot_id() ),
			'phone_number' => $identifier,
			'message_data' => [
				'otp_message' => [
					'otp' => strval( $code ),
				],
			],
		];

		$response = Curl::post( 'https://safir.bale.ai/api/v3/send_message', wp_json_encode( $data ), [
			'api-access-key: ' . self::get_api_token(),
			'Content-Type: application/json',
		] );

		if ( isset( $response['message_id'] ) ) {
			return true;
		}

		if ( is_string( $response ) ) {
			throw new Exception( $response );
		}

		if ( isset( $response['error_data'] [0]['description'] ) ) {
			throw new Exception( $response['error_data'] [0]['description'] );
		}

		throw new Exception( 'خطای ناشناخته در ارسال کد تایید به بله ۳ رخ داده است.' );
	}

	public static function is_enable(): bool {
		return ( self::get_api_token() && self::get_bot_id() ) || function_exists( 'pinova_bale_send_v2' );
	}

	public static function get_api_token() {
		return Pinova::get_option( 'messengers.bale_api_token' );
	}

	public static function get_bot_id() {
		return Pinova::get_option( 'messengers.bale_bot_id' );
	}

}
