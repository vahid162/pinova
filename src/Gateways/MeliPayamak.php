<?php

namespace Pinova\Gateways;

use Exception;
use Pinova\Helpers\Curl;

class MeliPayamak extends BaseGateway {

	protected string $name = 'ملی پیامک';

	protected string $url = 'melipayamak.com';

	public string $video_url = 'https://www.aparat.com/v/vhnno20';

	public function send( string $mobile, string $message ): bool {

		if ( is_numeric( $message ) ) {

			$url = 'https://rest.payamak-panel.com/api/SendSMS/SendOtp';

			$payload = [
				'username' => $this->get_option( 'username' ),
				'password' => $this->get_option( 'password' ),
				'from'     => $this->get_option( 'sender' ),
				'to'       => $mobile,
				'code'     => $message,
			];

		} elseif ( $this->is_pattern( $message ) ) {

			$url = 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber';

			$pattern = $this->parse_pattern( $message );

			$payload = [
				'username' => $this->get_option( 'username' ),
				'password' => $this->get_option( 'password' ),
				'to'       => $mobile,
				'text'     => implode( ';', $pattern['vars'] ),
				'bodyId'   => intval( $pattern['code'] ),
			];

		} else {

			$url = 'https://rest.payamak-panel.com/api/SendSMS/SendSMS';

			$payload = [
				'username' => $this->get_option( 'username' ),
				'password' => $this->get_option( 'password' ),
				'from'     => $this->get_option( 'sender' ),
				'to'       => $mobile,
				'text'     => $message,
				'isflash'  => false,
			];
		}

		$response = Curl::post( $url, wp_json_encode( $payload ), [
			'Content-Type: application/json; charset=utf-8',
		] );

		$value = $response['Value'] ?? '';

		if ( strlen( $value ) > 15 ) {
			return true;
		}

		if ( $value ) {
			throw new Exception( sprintf( 'خطای %s در ارسال پیامک رخ داده است.', $value ) );
		}

		if ( isset( $response['StrRetStatus'] ) ) {
			throw new Exception( sprintf( 'خطای %s در ارسال پیامک رخ داده است.', $response['StrRetStatus'] ) );
		}

		throw new Exception( 'خطای ناشناخته در ارسال به ملی پیامک رخ داده است.' );
	}

	public function is_enable(): bool {
		return ! empty( $this->get_option( 'username' ) ) && ! empty( $this->get_option( 'password' ) );
	}

	public function options(): array {
		return [
			[
				'label'       => 'نام کاربری',
				'key'         => 'username',
				'field_class' => 'ltr',
			],
			[
				'label'       => 'API Key',
				'key'         => 'password',
				'description' => 'APIKey را از منو <a href="https://login.melipayamak.com/?module=ApiSetting" target="_blank">توسعه دهندگان » تنظیمات وب سرویس</a> دریافت کنید.',
				'field_class' => 'ltr',
			],
			[
				'label'       => 'شماره فرستنده',
				'key'         => 'sender',
				'description' => 'شماره مبدا/فرستنده را از منو <a href="https://login.melipayamak.com/?module=NumberList" target="_blank">تنظیمات » شماره‌ها</a> دریافت کنید.',
				'field_class' => 'ltr',
			],
		];
	}
}
