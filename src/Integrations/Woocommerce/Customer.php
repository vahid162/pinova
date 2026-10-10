<?php

namespace Pinova\Integrations\Woocommerce;

use Pinova\Objects\Mobile;
use Pinova\Services\UserService;
use WC_Data;

class Customer {

	public function __construct() {
		add_action( 'pinova/user_registered', [ $this, 'set_new_customer_billing_phone' ], 10, 1 );
		add_filter( 'woocommerce_customer_get_pinova_mobile', [ $this, 'get_pinova_mobile' ], 10, 2 );
		add_filter( 'woocommerce_data_store_wp_user_read_meta', [ $this, 'remove_virtual_mobile' ] );
	}

	public function set_new_customer_billing_phone( int $user_id ) {

		$mobile = new Mobile( UserService::get_mobile( $user_id ) ?? '' );
		if ( ! $mobile->is_valid() ) {
			return;
		}
		$mobile = str_replace( '+98', '0', $mobile->get_formatted() );

		update_user_meta( $user_id, 'billing_phone', $mobile );
	}

	/** Present the mobile without adding unsaved rows to WooCommerce's metadata store. */
	public function get_pinova_mobile( $value, WC_Data $object ) {
		return is_array( $value ) ? $value : ( UserService::get_mobile( $object->get_id() ) ?? $value );
	}

	/** Old WooCommerce caches may still contain the previous adapter's unsaved row. */
	public function remove_virtual_mobile( array $meta_data ): array {
		return array_values(
			array_filter(
				$meta_data,
				static fn( $meta ): bool => 'pinova_mobile' !== $meta->meta_key || ! empty( $meta->meta_id )
			)
		);
	}
}
