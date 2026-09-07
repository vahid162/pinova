<?php

namespace Pinova\Channels;

use Exception;
use Pinova\Pinova;
use Pinova\Services\SMSService;

class SMS implements ChannelInterface {

	public static function send_code( string $identifier, int $code ): bool {

		$message = Pinova::get_option( 'sms.message_code' );

		if ( empty( $message ) ) {
			// Use for default patterns
			$message = $code;
		}

		$message = SMSService::replace_code( $message, $code );

		$gateway = SMSService::get_gateway_instance();

		if ( ! $gateway->is_enable() ) {
			throw new Exception( sprintf( 'درگاه پیامکی «%s» پیکربندی نشده است.', $gateway->get_name() ) );
		}

		return $gateway->send( $identifier, $message );
	}

	public static function send_message( string $identifier, string $message ): bool {

		$gateway = SMSService::get_gateway_instance();

		if ( ! $gateway->is_enable() ) {
			throw new Exception( sprintf( 'درگاه پیامکی «%s» پیکربندی نشده است.', $gateway->get_name() ) );
		}

		return $gateway->send( $identifier, $message );
	}
}

