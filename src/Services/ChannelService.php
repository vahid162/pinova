<?php

namespace Pinova\Services;

use Carbon\Carbon;
use Exception;
use Pinova\Channels\Bale;
use Pinova\Channels\Call;
use Pinova\Channels\Email;
use Pinova\Channels\SMS;
use Pinova\Exceptions\SendOTPException;
use Pinova\Models\OTP;
use Pinova\Logging\Logger;
use Pinova\Objects\Identifier;

class ChannelService {

	/**
	 * @throws Exception
	 */
	public static function send( OTP $otp, int $code ): array {

		$identifier = new Identifier( $otp->identifier );
		$channels   = $otp->channels;

		foreach ( $channels as $channel => &$success ) {

			if ( in_array( $channel, [ 'sms', 'bale', 'call', 'email' ], true ) ) {

				try {
					$success = self::send_channel( $channel, $identifier, $code, $otp->type );
				} catch ( \Throwable $e ) {
					Logger::instance()->warning(
						'otp.channel_send_failed',
						[
							'user_id'                => $otp->user_id,
							'otp_type'               => $otp->type,
							'channel'                => $channel,
							'identifier_type'        => $identifier->get_type(),
							'identifier_fingerprint' => Logger::instance()->fingerprint( $identifier->get_value(), $identifier->get_type() ),
							'exception'              => $e,
						]
					);
					do_action( 'pinova/channel_send_failed', sanitize_key( (string) $channel ) );
				}

			}

		}

		$successful_channels = array_keys( $channels, true );

		if ( empty( $successful_channels ) ) {
			throw new SendOTPException( 'خطایی در زمان ارسال کد تایید رخ داده است.' );
		}

		$otp->channels = $channels;
		$otp->save();

		return $successful_channels;
	}

	/** @throws \Throwable */
	private static function send_channel( string $channel, Identifier $identifier, int $code, string $otp_type ): bool {
		switch ( $channel ) {
			case 'sms':
				return self::send_sms( $identifier, $code );
			case 'bale':
				return self::send_bale( $identifier, $code );
			case 'call':
				return self::send_call( $identifier, $code );
			case 'email':
				return self::send_email( $identifier, $code, $otp_type );
			default:
				return false;
		}
	}

	/**
	 * @throws Exception
	 */
	public static function send_sms( Identifier $identifier, int $code ): bool {

		if ( ! $identifier->is_mobile() ) {
			throw new Exception( 'پیامک صرفا به تلفن همراه ارسال می‌شود.' );
		}

		return SMS::send_code( $identifier->get_value(), $code );
	}

	/**
	 * @throws Exception
	 */
	public static function send_bale( Identifier $identifier, int $code ): bool {

		if ( ! $identifier->is_mobile() ) {
			throw new Exception( 'پیام بله صرفا به تلفن همراه ارسال می‌شود.' );
		}

		return Bale::send_code( $identifier->get_value(), $code );
	}

	/**
	 * @throws Exception
	 */
	public static function send_call( Identifier $identifier, int $code ): bool {

		if ( ! $identifier->is_mobile() ) {
			throw new Exception( 'پیام صوتی صرفا به تلفن همراه ارسال می‌شود.' );
		}

		return Call::send_code( $identifier->get_value(), $code );
	}

	/**
	 * @throws Exception
	 */
	public static function send_email( Identifier $identifier, int $code, string $otp_type = OTP::TYPE_LOGIN ): bool {

		if ( ! $identifier->is_email() ) {
			throw new Exception( 'ایمیل صرفا به آدرس ایمیل ارسال می‌شود.' );
		}

		return Email::send_code_for_purpose( $identifier->get_value(), $code, $otp_type );
	}

	public static function get_channels( Identifier $identifier ): array {

		if ( $identifier->is_mobile() ) {

			if ( Bale::is_enable() ) {
				$channels[] = 'bale';
			}

			/** @var OTP $last_otp */
			$last_otp = OTP::query()
			               ->where( 'identifier', $identifier->get_value() )
			               ->where( 'expires_at', '>=', Carbon::now()->subMinutes( 14 ) )
			               ->latest( 'id' )
			               ->first();

			if ( isset( $last_otp->channels['sms'] ) && Call::is_enable() ) {
				$channels[] = 'call';
			} else {
				$channels[] = 'sms';
			}

		} else { // $identifier->is_email()
			$channels = [
				'email',
			];
		}

		return $channels;
	}

	public static function get_message( array $successful_channels, Identifier $identifier, ?int $user_id = null ): string {

		$channel_labels = [
			'bale'  => 'پیام‌رسان بله',
			'call'  => 'تماس',
			'sms'   => 'پیامک',
			'email' => 'ایمیل',
		];

		$successful_channels = array_map( function ( $key, $value ) {
			return is_string( $value ) ? $value : $key;
		}, array_keys( $successful_channels ), $successful_channels );

		$successful_channels = array_intersect_key( $channel_labels, array_flip( $successful_channels ) );

		$destination = strip_tags( (string) $identifier->get_value() );

		return sprintf(
			__( 'کد تأیید از طریق %1$s به %2$s ارسال شد.', 'pinova' ),
			implode( ' و ', array_values( $successful_channels ) ),
			"\u{2066}" . $destination . "\u{2069}"
		);
	}

}
