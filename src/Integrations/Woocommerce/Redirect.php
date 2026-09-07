<?php

namespace Pinova\Integrations\Woocommerce;

use Pinova\Helper;
use Pinova\Pinova;

class Redirect {

	public function __construct() {
		add_action( 'template_redirect', [ $this, 'wc_checkout_to_pinova_login' ], 10 );
		add_filter( 'woocommerce_get_return_url', [ $this, 'wc_payment_return_url' ], 1000, 2 );
		add_filter( 'woocommerce_get_checkout_payment_url', [ $this, 'wc_payment_return_url' ], 1000, 2 );
	}

	public function wc_checkout_to_pinova_login(): void {

		if ( ! is_checkout() || is_user_logged_in() ) {
			return;
		}

		$checkout_url = wc_get_page_permalink( 'checkout' );

		if ( is_ssl() || 'yes' === get_option( 'woocommerce_force_ssl_checkout' ) ) {
			$checkout_url = str_replace( 'http:', 'https:', $checkout_url );
		}

		Helper::redirect_to( Pinova::get_login_url( $checkout_url ) );
	}

	public function wc_payment_return_url( string $return_url, $order ): string {

		if ( is_user_logged_in() ) {
			return $return_url;
		}

		return Pinova::get_login_url( $return_url );
	}
}