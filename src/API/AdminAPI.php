<?php


namespace Pinova\API;

use Exception;
use Pinova\Channels\SMS;
use Pinova\Logging\Logger;
use Pinova\Objects\Identifier;
use Pinova\Services\OTPService;
use Pinova\Services\UserService;
use Pinova\Services\ValidationService;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

class AdminAPI extends RestAPI {

	public const ROUTE_PERMISSION = [
		'/pinova/admin/test/sms'     => 'manage_options',
	];

	public function register_routes() {

		register_rest_route( 'pinova/admin/test', '/sms', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'test_sms' ],
			'permission_callback' => [ $this, 'permission_callback' ],
			'args'                => [
				'identifier' => [
					'required'          => true,
					'validate_callback' => [ ValidationService::class, 'identifier' ],
				],
			],
		] );

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

			SMS::send_code( $identifier->get_value(), $code );

			$message = sprintf( "کد تایید «%d» با موفقیت پیامک شد.", $code );
			$success = true;
			Logger::instance()->notice(
				'admin.sms_test_succeeded',
				[
					'user_id'                => get_current_user_id(),
					'identifier_type'        => $identifier->get_type(),
					'identifier_fingerprint' => Logger::instance()->fingerprint( $identifier->get_value(), $identifier->get_type() ),
				]
			);

		} catch ( Exception $e ) {
			$message = $e->getMessage();
			$success = false;
			Logger::instance()->error(
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
