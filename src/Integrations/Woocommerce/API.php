<?php


namespace Pinova\Integrations\Woocommerce;

use Exception;
use Pinova\API\RestAPI;
use Pinova\Channels\SMS;
use Pinova\Identity\IdentityConflictException;
use Pinova\Objects\Identifier;
use Pinova\Services\OTPService;
use Pinova\Services\UserService;
use Pinova\Services\ValidationService;
use WC_Customer;
use WP_REST_Request;
use WP_REST_Response;

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
	 * @return WP_REST_Response
	 */
	public function create_customer( WP_REST_Request $request ): WP_REST_Response {
		/** @var Identifier $mobile */
		$mobile = $request->get_param( 'mobile' );

		/** @var Identifier $email */
		$email      = $request->get_param( 'email' );
		$first_name = $request->get_param( 'first_name' );
		$last_name  = $request->get_param( 'last_name' );

		$order_id = $request->get_param( 'order_id' );

		/** @var array $order_data */
		$order_data = $request->get_param( 'order_data' );
		$order      = null;

		if ( $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order || ! current_user_can( 'edit_shop_order', $order_id ) ) {
				return self::response( false, __( 'سفارش انتخاب‌شده معتبر نیست یا اجازهٔ ویرایش آن را ندارید.', 'pinova' ), [], 403 );
			}
		}

		try {
			$user_id = UserService::match( $mobile, true, true );
		} catch ( IdentityConflictException $conflict ) {
			return self::response( false, __( 'این شناسه بین چند حساب تعارض دارد و باید توسط مدیر بررسی شود.', 'pinova' ), [], 409 );
		}

		if ( $user_id ) {
			return self::response( false, sprintf( __( 'کاربر با تلفن همراه %s وجود دارد.', 'pinova' ), $mobile->get_value() ), [], 409 );
		}

		if ( ! empty( $email ) ) {

			try {
				$user_id = UserService::match( $email, true, true );
			} catch ( IdentityConflictException $conflict ) {
				return self::response( false, __( 'این شناسه بین چند حساب تعارض دارد و باید توسط مدیر بررسی شود.', 'pinova' ), [], 409 );
			}

			$email = $email->get_value();

			if ( $user_id ) {
				return self::response( false, sprintf( __( 'کاربر با ایمیل %s وجود دارد.', 'pinova' ), $email ), [], 409 );
			}

		}

		$invalid_fields = $this->invalid_order_fields( $order_data );

		if ( $invalid_fields ) {
			return self::response(
				false,
				__( 'دادهٔ سفارش شامل فیلدهای پشتیبانی‌نشده است.', 'pinova' ),
				[ 'invalid_fields' => $invalid_fields ],
				400
			);
		}

		try {

			$user_id = UserService::create( $mobile->get_value(), $email, [
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'role'       => 'customer',
			] );

			$customer = new WC_Customer( $user_id );
			$customer->set_role( 'customer' );
			$customer->set_billing_first_name( $first_name );
			$customer->set_billing_last_name( $last_name );
			$customer->set_billing_email( $email );

			$this->apply_allowed_order_data( $customer, $order_data );

			$customer->save();

			if ( $order ) {
				$order->set_customer_id( $customer->get_id() );
				$order->save();

			}

		} catch ( Exception $e ) {
			return self::response( false, $e->getMessage(), [], 500 );
		}

		$message = sprintf(
			__( 'حساب کاربری «%s %s» با تلفن همراه %s با موفقیت ایجاد شد.', 'pinova' ),
			$first_name,
			$last_name,
			$mobile->get_value()
		);

		return self::response( true, $message, [
			'user_id' => $user_id,
		] );
	}

	public function permission_callback( WP_REST_Request $request ): bool {
		return current_user_can( self::ROUTE_PERMISSION[ $request->get_route() ] ?? 'manage_woocommerce' );
	}

	private function apply_allowed_order_data( WC_Customer $customer, array $order_data ): void {
		foreach ( $order_data as $raw_key => $raw_value ) {
			$key    = ltrim( sanitize_key( (string) $raw_key ), '_' );
			$value  = 'billing_email' === $key ? sanitize_email( (string) $raw_value ) : sanitize_text_field( (string) $raw_value );
			$setter = 'set_' . $key;

			if ( '' !== $value && is_callable( [ $customer, $setter ] ) ) {
				$customer->{$setter}( $value );
			}
		}
	}

	private function invalid_order_fields( array $order_data ): array {
		$allowed_fields = [
			'billing_company',
			'billing_country',
			'billing_address_1',
			'billing_address_2',
			'billing_city',
			'billing_state',
			'billing_postcode',
			'billing_phone',
			'billing_email',
			'shipping_first_name',
			'shipping_last_name',
			'shipping_company',
			'shipping_country',
			'shipping_address_1',
			'shipping_address_2',
			'shipping_city',
			'shipping_state',
			'shipping_postcode',
			'shipping_phone',
		];

		$invalid_fields = [];

		foreach ( $order_data as $raw_key => $raw_value ) {
			$key = ltrim( sanitize_key( (string) $raw_key ), '_' );

			if ( ! in_array( $key, $allowed_fields, true ) || ! is_scalar( $raw_value ) ) {
				$invalid_fields[] = sanitize_key( (string) $raw_key );
				continue;
			}

		}

		return array_values( array_unique( array_filter( $invalid_fields ) ) );
	}

}
