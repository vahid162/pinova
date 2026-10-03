<?php


namespace Pinova\API;

use Pinova\Channels\SMS;
use Pinova\Logging\Logger;
use Pinova\Objects\Identifier;
use Pinova\Services\OTPService;
use Pinova\Services\UserService;
use Pinova\Services\ValidationService;
use RuntimeException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

class AdminAPI extends RestAPI {

	public const ROUTE_PERMISSION = [
		'/pinova/admin/test/sms' => 'manage_options',
	];

	public function register_routes() {

		register_rest_route(
			'pinova/admin/test',
			'/sms',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'test_sms' ],
				'permission_callback' => [ $this, 'permission_callback' ],
				'args'                => [
					'identifier' => [
						'required'          => true,
						'validate_callback' => [ ValidationService::class, 'identifier' ],
					],
				],
			]
		);
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function test_sms( WP_REST_Request $request ): WP_REST_Response {

		/** @var Identifier $identifier */
		$identifier = $request->get_param( 'identifier' );

		$code = OTPService::generate_code();

		try {

			if ( ! SMS::send_code( $identifier->get_value(), $code ) ) {
				throw new RuntimeException( 'The SMS provider did not accept the test message.' );
			}

			$message = __( 'سرویس پیامک درخواست ارسال آزمایشی را پذیرفت. دریافت پیامک را روی گوشی مقصد بررسی کنید.', 'pinova' );
			$success = true;
			Logger::instance()->audit(
				'notice',
				'admin.sms_test_succeeded',
				[
					'user_id'                => get_current_user_id(),
					'identifier_type'        => $identifier->get_type(),
					'identifier_fingerprint' => Logger::instance()->fingerprint( $identifier->get_value(), $identifier->get_type() ),
				]
			);

		} catch ( Throwable $e ) {
			$message = __( 'نتیجهٔ ارسال پیامک آزمایشی تأیید نشد. تنظیمات درگاه و گزارش‌های پینوا را بررسی کنید.', 'pinova' );
			$success = false;
			Logger::instance()->audit(
				'error',
				'admin.sms_test_failed',
				[
					'user_id'                => get_current_user_id(),
					'identifier_type'        => $identifier->get_type(),
					'identifier_fingerprint' => Logger::instance()->fingerprint( $identifier->get_value(), $identifier->get_type() ),
					'exception'              => $e,
				]
			);
		}

		return self::response( $success, $message, [], $success ? 200 : 503 );
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return bool
	 */
	public function permission_callback( WP_REST_Request $request ): bool {
		return current_user_can( self::ROUTE_PERMISSION[ $request->get_route() ] ?? 'manage_options' );
	}
}
