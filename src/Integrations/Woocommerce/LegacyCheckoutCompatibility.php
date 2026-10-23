<?php

namespace Pinova\Integrations\Woocommerce;

use Exception;
use Pinova\Logging\EventThrottle;
use WC_Order;

/** Contact data cannot establish ownership of an existing customer account. */
final class LegacyCheckoutCompatibility {
	public function __construct() {
		add_filter( 'woocommerce_billing_fields', [ $this, 'optional_email' ] );
		add_action( 'after_setup_theme', [ $this, 'remove_legacy_callbacks' ], PHP_INT_MAX );
		add_action( 'woocommerce_checkout_create_order', [ $this, 'check_owner' ], PHP_INT_MAX );
	}

	public function optional_email( array $fields ): array {
		if ( isset( $fields['billing_email'] ) && is_array( $fields['billing_email'] ) ) {
			$fields['billing_email']['required'] = false;
		}
		return $fields;
	}

	public function remove_legacy_callbacks(): void {
		remove_action( 'woocommerce_checkout_create_order', 'mihanwp_set_customer_email', 10 );
		remove_filter( 'woocommerce_checkout_posted_data', 'mihanwp_remove_register_alert', 10 );
	}

	public function check_owner( WC_Order $order ): void {
		if ( (int) $order->get_customer_id() === get_current_user_id() && ( ! get_current_user_id() || false === \Pinova\Services\AccountLinkService::retired_state( get_current_user_id() ) ) ) {
			return;
		}
		EventThrottle::log(
			'integration.operation_failed',
			[
				'operation' => 'woocommerce',
				'reason'    => 'order_owner_mismatch',
			]
		);
		throw new Exception( __( 'حساب سفارش با حساب واردشده مطابقت ندارد. صفحه را تازه کنید و دوباره تلاش کنید؛ اگر مشکل ادامه داشت با پشتیبانی تماس بگیرید.', 'pinova' ) );
	}
}
