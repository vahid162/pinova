<?php

namespace Pinova\API;

use Pinova\Admin\Settings;
use Pinova\Services\SMSService;
use WP_REST_Request;
use WP_REST_Response;

class GatewayAPI extends RestAPI {

	public function register_routes() {

		register_rest_route( 'pinova/admin', '/gateway/get-options', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'get_options' ],
			'permission_callback' => [ $this, 'permission_callback' ],
		] );

	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function get_options( WP_REST_Request $request ): WP_REST_Response {

		$gateway = $request->get_param( 'gateway' );
		$section = 'pinova_sms';

		try {
			$gateway = SMSService::get_gateway_instance( $gateway );
		} catch ( \Exception $e ) {
			return self::response( false, $e->getMessage(), [], 400 );
		}

		$options = $gateway->options();

		if ( ! empty( $gateway->video_url ) ) {

			$options[] = [
				'label'       => 'آموزش',
				'key'         => 'pinova_video',
				'type'        => 'html',
				'description' => sprintf( 'برای مشاهده آموزش ویدیویی اتصال پینوا به %s، <a href="%s" target="_blank">اینجا</a> کنید.', $gateway->get_name(), $gateway->video_url ),
			];

		}

		$rows = [];

		foreach ( $options as $field ) {

			$field_id       = $field['id'] ?? $field['key'] ?? '';
			$field['value'] = $gateway->get_option( $field_id, $field['default'] ?? null );
			$input_html     = Settings::render_field( $field, $section );

			$rows[] = sprintf(
				'<tr class="pinova-gateway-dynamic-row"><th scope="row"><label for="pinova_sms[%1$s]">%2$s</label></th><td>%3$s</td></tr>',
				esc_attr( $field_id ),
				esc_html( $field['label'] ),
				$input_html
			);
		}

		return self::response( true, null, $rows );
	}

	/**
	 * The middleware to check if user can access the routes and set initial important values in 'request' param
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return bool
	 */
	public function permission_callback( WP_REST_Request $request ): bool {
		return current_user_can( 'manage_options' );
	}
}
