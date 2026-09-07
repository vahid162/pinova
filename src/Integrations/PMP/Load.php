<?php

namespace Pinova\Integrations\PMP;

use Pinova\Helper;
use Pinova\Pinova;

class Load {

	protected static ?Load $_instance = null;

	public static function instance(): ?Load {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	public function __construct() {
		// Todo add login page
		add_action( 'pmpro_checkout_after_pricing_fields', [ $this, 'force_login' ] );
	}

	public function force_login() {

		if ( get_current_user_id() ) {
			return;
		}

		Helper::js_redirect_to( Pinova::get_login_url( home_url( add_query_arg( null, null ) ) ) );
	}
}