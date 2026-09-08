<?php

namespace Pinova\Integrations\Woocommerce;

use Pinova\Services\UserService;
use WC_Data;

class Customer {

	public function __construct() {
		add_filter( 'woocommerce_data_store_wp_user_read_meta', [ $this, 'get_pinova_mobile' ], 10, 2 );
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
