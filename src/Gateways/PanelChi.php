<?php

namespace Pinova\Gateways;

use Exception;
use Pinova\Helpers\Curl;


class PanelChi extends BaseGateway {

	protected string $name = 'پنلچی';

	protected string $url = 'panelchi.com';

	public function send( string $mobile, string $message ): bool {

		if ( $this->is_pattern( $message ) ) {

			$pattern = $this->parse_pattern( $message );

			$url = 'https://api.panelchi.com/sms/pattern';

			$payload = [
				'sourceNumber' => $this->get_option( 'source_number' ),
				'recipient'    => $mobile,
				'pattern'      => $pattern['code'],
				'variables'    => $pattern['vars'],
			];

		} else {

			$url = 'https://api.panelchi.com/sms/send';

			$payload = [
				'sourceNumber' => $this->get_option( 'source_number' ),
				'recipients'   => [ $mobile ],
				'message'      => $message,
			];

		}

		$response = Curl::post( $url, json_encode( $payload ), [
			'Content-Type: application/json',
			'Authorization: Bearer ' . $this->get_option( 'api_key' ),
		] );

		if ( isset( $response['data']['uid'] ) ) {
			return true;
		}

		if ( isset( $response['detail'] ) ) {
			throw new Exception( $response['detail'], $response['code'] ?? 0 );
		}

		if ( isset( $response['title'] ) ) {
			throw new Exception( $response['title'], $response['code'] ?? 0 );
		}

		throw new Exception( 'خطای ناشناخته در ارسال به پنل‌چی رخ داده است.' );
	}

	public function is_enable(): bool {
		return ! empty( $this->get_option( 'api_key' ) );
	}

	public function options(): array {
		return [
			[
				'label'       => 'کلید api',
				'key'         => 'api_key',
				'description' => 'کلید api را از منو <a href="https://next.panelchi.com/api-keys" target="_blank">وبسرویس » کلید‌های API</a> دریافت کنید.',
				'field_class' => 'ltr',
			],
			[
				'label'       => 'شماره مبدا',
				'key'         => 'source_number',
				'description' => 'شماره مبدا/فرستنده را از پنل پیامک دریافت کنید.',
				'default'     => '10001',
				'field_class' => 'ltr',
			],
		];
	}
}