<?php

namespace Pinova\API;

use Pinova\Logging\Logger;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

abstract class RestAPI {

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	abstract public function register_routes();

	/** Attach the request reference even when WordPress rejects before our callback. */
	public static function correlation_header( \WP_HTTP_Response $response, \WP_REST_Server $server, WP_REST_Request $request ): \WP_HTTP_Response {
		// WordPress matches REST routes case-insensitively.
		$route = strtolower( $request->get_route() );
		if ( '/pinova' !== $route && 0 !== strpos( $route, '/pinova/' ) ) {
			return $response;
		}

		$headers = array_change_key_case( $response->get_headers(), CASE_LOWER );
		if ( ! array_key_exists( 'x-pinova-correlation-id', $headers ) ) {
			$response->header( 'X-Pinova-Correlation-ID', Logger::instance()->correlation_id() );
		}

		return $response;
	}

	/** @return bool|WP_Error */
	public function permission_callback( WP_REST_Request $request ) {
		return true;
	}

	/**
	 * @param bool        $success
	 * @param string|null $message
	 * @param array       $data
	 *
	 * @param int         $status
	 * @param array       $headers
	 *
	 * @return WP_REST_Response
	 */
	public static function response(
		bool $success,
		?string $message = null,
		array $data = [],
		int $status = 200,
		array $headers = []
	): WP_REST_Response {
		$response = new WP_REST_Response(
			[
				'success' => $success,
				'message' => $message,
				'data'    => $data,
			],
			$status
		);

		foreach ( $headers as $name => $value ) {
			$response->header( sanitize_key( (string) $name ), (string) $value );
		}

		$response->header( 'X-Pinova-Correlation-ID', Logger::instance()->correlation_id() );

		return $response;
	}
}
