<?php

namespace Pinova\Integrations\Woocommerce;

use Pinova\Objects\Mobile;
use Pinova\Services\UserService;
use WC_Data;

class Customer {

	public function __construct() {
		add_action( 'pinova/user_registered', [ $this, 'set_new_customer_billing_phone' ], 10, 1 );
		add_filter( 'woocommerce_data_store_wp_user_read_meta', [ $this, 'get_pinova_mobile' ], 10, 2 );
	}

	public function set_new_customer_billing_phone( int $user_id ) {

		$user   = get_user( $user_id );
		$mobile = new Mobile( $user->user_login );
		$mobile = str_replace( '+98', '0', $mobile->get_formatted() );

		update_user_meta( $user_id, 'billing_phone', $mobile );
	}

	public function get_pinova_mobile( array $meta_data, WC_Data $object ): array {

		$meta_data[] = (object) [
			'meta_id'    => 0,
			'meta_key'   => 'pinova_mobile',
			'meta_value' => UserService::get_mobile( $object->get_id() ),
		];

		return $meta_data;
	}
}