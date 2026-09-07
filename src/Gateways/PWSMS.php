<?php

namespace Pinova\Gateways;

use Exception;

class PWSMS extends BaseGateway {

	protected string $name = 'پیامک ووکامرس';

	protected string $url = 'persian woocommerce sms';

	public string $video_url = 'https://www.aparat.com/v/azj395y';

	public function send( string $mobile, string $message ): bool {

		$message = str_replace( 'pattern:', 'patterncode:', $message );

		$data = [
			'mobile'  => $mobile,
			'message' => $message,
		];

		$response = PWSMS()->send_sms( $data );

		if ( $response === true ) {
			return true;
		}

		throw new Exception( $response );
	}

	public function is_enable(): bool {
		return function_exists( 'PWSMS' );
	}

	public function options(): array {

		if ( function_exists( 'PWSMS' ) ) {
			$description = sprintf( 'تنظیمات سامانه پیامک از منو <a href="%s" target="_blank">ووکامرس فارسی » پیامک ووکامرس » وب سرویس</a> اعمال می‌شود.', admin_url( 'admin.php?page=persian-woocommerce-sms-pro&tab=main' ) );
		} else {
			$description = sprintf( 'برای نصب و فعالسازی افزونه پیامک ووکامرس از مخزن وردپرس، <a href="%s" target="_blank">اینجا</a> کلیک کنید.', admin_url( 'plugin-install.php?tab=plugin-information&plugin=persian-woocommerce-sms' ) );
		}

		return [
			[
				'label'       => 'راهنما',
				'key'         => 'pwsms_help',
				'type'        => 'html',
				'description' => $description,
			],
		];
	}
}