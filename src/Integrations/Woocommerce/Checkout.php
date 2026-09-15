<?php

namespace Pinova\Integrations\Woocommerce;

use Pinova\Objects\Mobile;
use WC_Customer;
use WP_Error;

class Checkout {

	public function __construct() {
		add_filter( 'woocommerce_checkout_registration_enabled', '__return_true' );
		add_filter( 'pre_option_woocommerce_enable_myaccount_registration', [ $this, 'return_yes' ] );
		add_filter( 'pre_option_woocommerce_registration_generate_password', [ $this, 'return_yes' ] );
		add_filter( 'pre_option_woocommerce_registration_generate_username', [ $this, 'return_yes' ] );

		add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate_checkout_phone' ], 10, 2 );
		add_action( 'woocommerce_checkout_update_customer', [ $this, 'update_customer' ], 10, 2 );
	}

	public static function return_yes(): string {
		return 'yes';
	}

	public function validate_checkout_phone( array $posted_data, WP_Error $errors ): void {

		$phone = $posted_data['billing_phone'] ?? '';

		if ( ! is_string( $phone ) || empty( $phone ) ) {
			$errors->add( 'pinova_billing_phone_required', 'لطفا شماره موبایل را وارد کنید.' );

			return;
		}

		$mobile = new Mobile( $phone );

		if ( ! $mobile->is_valid() ) {
			$errors->add( 'pinova_billing_phone_invalid', 'شماره موبایل وارد شده معتبر نمی‌باشد.' );
		}
	}

	public function update_customer( WC_Customer $customer, array $posted_data ): void {

		$first_name = $posted_data['billing_first_name'] ?? null;

		if ( empty( $first_name ) ) {
			return;
		}

		if ( empty( $customer->get_first_name() ) || $customer->get_first_name() === 'کاربر' ) {
			$customer->set_first_name( $first_name );
			$customer->set_display_name( $customer->get_first_name() . ' ' . $customer->get_last_name() );
		}
	}
}
