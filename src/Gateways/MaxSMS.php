<?php

namespace Pinova\Gateways;

use Exception;
use Pinova\Helpers\Curl;

class MaxSMS extends BaseGateway {

	protected string $name = 'مکس اس ام اس';

	protected string $url = 'maxsms.co';

	public string $video_url = 'https://www.aparat.com/v/juf50s7';

	public function send( string $mobile, string $message ): bool {

		if ( $this->is_pattern( $message ) ) {

			$pattern = $this->parse_pattern( $message );

			$payload = [
				'sending_type' => 'pattern',
				'from_number'  => $this->get_option( 'sender' ),
				'recipients'   => [ $mobile ],
				'code'         => $pattern['code'],
				'params'       => $pattern['vars'],
			];

		} else {

			$payload = [
				'sending_type' => 'webservice',
				'from_number'  => $this->get_option( 'sender' ),
				'message'      => $message,
				'params'       => [
					'recipients' => [ $mobile ],
				],
			];

		}

		$response = Curl::post( 'https://edge.ippanel.com/v1/api/send', json_encode( $payload ), [
			'Content-Type: application/json',
			'Authorization: ' . $this->get_option( 'api_key' ),
		] );

		if ( isset( $response['meta']['status'] ) && $response['meta']['status'] ) {
			return true;
		}

		if ( isset( $response['meta']['message'] ) ) {
			throw new Exception( $response['meta']['message'] );
		}

		throw new Exception( 'خطای ناشناخته در ارسال به مکس اس ام اس رخ داده است.' );
	}

	public function is_enable(): bool {
		return ! empty( $this->get_option( 'api_key' ) );
	}

	public function options(): array {
		return [
			[
				'label'       => 'کلید دسترسی',
				'key'         => 'api_key',
				'description' => 'کلید دسترسی را از منو <a href="https://ippanel.com/developers/api-keys" target="_blank">برنامه نویسان » کلیدهای دسترسی</a> دریافت کنید.',
				'field_class' => 'ltr',
			],
			[
				'label'       => 'شماره فرستنده',
				'key'         => 'sender',
				'description' => 'شماره مبدا/فرستنده را از منو <a href="https://ippanel.com/line-management" target="_blank">مدیریت خطوط</a> دریافت کنید.',
				'field_class' => 'ltr',
			],
		];
	}
}
