<?php


namespace Pinova\API;

use Exception;
use Pinova\Channels\SMS;
use Pinova\Objects\Identifier;
use Pinova\Services\OTPService;
use Pinova\Services\UserService;
use Pinova\Services\ValidationService;
use WP_REST_Request;

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
	 * @return void
	 */
	public function test_sms( WP_REST_Request $request ): void {

		/** @var Identifier $identifier */
		$identifier = $request->get_param( 'identifier' );

		$code = OTPService::generate_code();

		try {

			SMS::send_code( $identifier->get_value(), $code );

			$message = sprintf( "کد تایید «%d» با موفقیت پیامک شد.", $code );
			$success = true;

		} catch ( Exception $e ) {
			$message = $e->getMessage();
			$success = false;
		}

		self::response( $success, $message );
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
