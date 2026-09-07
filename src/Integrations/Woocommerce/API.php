<?php


namespace Pinova\Integrations\Woocommerce;

use Exception;
use Pinova\API\RestAPI;
use Pinova\Channels\SMS;
use Pinova\Objects\Identifier;
use Pinova\Services\OTPService;
use Pinova\Services\UserService;
use Pinova\Services\ValidationService;
use WC_Customer;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

class API extends RestAPI {

	public const ROUTE_PERMISSION = [
		'/pinova/woocommerce/customer/create' => 'edit_shop_orders',
	];

	public function register_routes() {

		register_rest_route( 'pinova/woocommerce/customer', '/create', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'create_customer' ],
			'permission_callback' => [ $this, 'permission_callback' ],
			'args'                => [
				'first_name' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => [ ValidationService::class, 'not_empty' ],
				],
				'last_name'  => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => [ ValidationService::class, 'not_empty' ],
				],
				'mobile'     => [
					'required'          => true,
					'validate_callback' => [ ValidationService::class, 'mobile' ],
				],
				'email'      => [
					'required'          => false,
					'validate_callback' => [ ValidationService::class, 'email' ],
					'default'           => ''
				],
				'order_id'   => [
					'required'          => false,
					'default'           => null,
					'type'              => [ 'integer', 'null' ],
					'sanitize_callback' => 'absint',
					'validate_callback' => 'rest_validate_request_arg',
				],
				'order_data' => [
					'required'          => false,
					'default'           => [],
					'validate_callback' => function( $value ) {
						return is_array( $value );
					},
				],
			],
		] );
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return void
	 */
	public function create_customer( WP_REST_Request $request ): void {
		/** @var Identifier $mobile */
		$mobile = $request->get_param( 'mobile' );

		/** @var Identifier $email */
		$email      = $request->get_param( 'email' );
		$first_name = $request->get_param( 'first_name' );
		$last_name  = $request->get_param( 'last_name' );

		$order_id = $request->get_param( 'order_id' );

		/** @var array $order_data */
		$order_data = $request->get_param( 'order_data' );

		$user_id = UserService::match( $mobile );

		if ( $user_id ) {
			self::response( false, sprintf( __( 'کاربر با تلفن همراه %s وجود دارد.', 'pinova' ), $mobile->get_value() ) );
		}

		if ( ! empty( $email ) ) {

			$user_id = UserService::match( $email );

			$email = $email->get_value();

			if ( $user_id ) {
				self::response( false, sprintf( __( 'کاربر با ایمیل %s وجود دارد.', 'pinova' ), $email ) );
			}

		}

		try {

			$user_id = UserService::create( $mobile->get_value(), $email, [
				'first_name' => $first_name,
				'last_name'  => $last_name,
			] );

			$customer = new WC_Customer( $user_id );
			$customer->set_billing_first_name( $first_name );
			$customer->set_billing_last_name( $last_name );
			$customer->set_billing_email( $email );

			foreach ( $order_data as $meta => $value ) {

				if ( empty( $value ) ) {
					continue;
				}

				$meta = ltrim( $meta, '_' );

				if ( method_exists( $customer, "set_{$meta}" ) ) {
					$customer->{"set_{$meta}"}( $value );
				} else {
					$customer->update_meta_data( $meta, $value );
				}

			}

			$customer->save();

			if ( $order_id ) {

				$order = wc_get_order( $order_id );
				$order->set_customer_id( $customer->get_id() );
				$order->save();

			}

		} catch ( Exception $e ) {
			self::response( false, $e->getMessage() );
		}

		$message = sprintf(
			__( 'حساب کاربری «%s %s» با تلفن همراه %s با موفقیت ایجاد شد.', 'pinova' ),
			$first_name,
			$last_name,
			$mobile->get_value()
		);

		self::response( true, $message, [
			'user_id' => $user_id,
		] );
	}

	public function permission_callback( WP_REST_Request $request ): bool {
		return current_user_can( self::ROUTE_PERMISSION[ $request->get_route() ] ?? 'manage_woocommerce' );
	}

}