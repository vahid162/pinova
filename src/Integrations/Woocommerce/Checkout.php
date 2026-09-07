<?php

namespace Pinova\Integrations\Woocommerce;

use Pinova\Objects\Mobile;
use WC_Customer;

class Checkout {

	public function __construct() {
		add_filter( 'woocommerce_checkout_registration_enabled', '__return_true' );
		add_filter( 'pre_option_woocommerce_enable_myaccount_registration', [ $this, 'return_yes' ] );
		add_filter( 'pre_option_woocommerce_registration_generate_password', [ $this, 'return_yes' ] );
		add_filter( 'pre_option_woocommerce_registration_generate_username', [ $this, 'return_yes' ] );

		add_action( 'woocommerce_checkout_process', [ $this, 'validate_checkout_phone' ] );
		add_action( 'woocommerce_checkout_update_customer', [ $this, 'update_customer' ], 10, 2 );
	}

	public static function return_yes(): string {
		return 'yes';
	}

	public function validate_checkout_phone() {

		$posted_data = WC()->checkout()->get_posted_data();

		$phone = $posted_data['billing_phone'] ?? '';

		if ( empty( $phone ) ) {
			wc_add_notice( 'لطفا شماره موبایل را وارد کنید.', 'error' );

			return;
		}

		$mobile = new Mobile( $phone );

		if ( ! $mobile->is_valid() ) {
			wc_add_notice( 'شماره موبایل وارد شده معتبر نمی‌باشد.', 'error' );
		}

	}

	public function update_customer( WC_Customer $customer, array $posted_data ): void {

		$first_name = $posted_data['billing_first_name'] ?? null;

		if ( empty( $first_name ) ) {
			return;
		}

		if ( empty( $customer->get_first_name() ) || $customer->get_first_name() == 'کاربر' ) {
			$customer->set_first_name( $first_name );
			$customer->set_display_name( $customer->get_first_name() . ' ' . $customer->get_last_name() );
		}
	}
}