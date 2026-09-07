<?php

namespace Pinova\Integrations\Flatsome;

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

		if ( ! class_exists( 'Flatsome' ) ) {
			return;
		}

		remove_action( 'wp_footer', 'flatsome_account_login_lightbox' );
		add_action( 'wp_footer', [ $this, 'login_modal' ] );

	}


	public static function get_template() {

		$custom_styles =
			'<style>
                #login-form-popup {padding: 10px;}
				.pinova-container .text-primary-500 { color: var(--fs-color-primary) !important;} 
				.pinova-container .loader { border-color: var(--fs-color-primary) !important;}
				.pinova-container .button { background-color: var(--fs-color-primary) !important; color: #fff !important;}
			</style>';

		$data = [
			'form_wrapper_classes'  => 'woocommerce-form-login',
			'submit_button_classes' => 'woocommerce-button button woocommerce-form-login__submit',
			'custom_styles'         => $custom_styles
		];

		ob_start();
		extract( $data );
		include PINOVA_DIR . '/templates/login-partial.php';

		return ob_get_clean();

	}

	public function login_modal() {


		if ( is_user_logged_in() ) {
			return;
		}

		echo '<div  id="login-form-popup" class="lightbox-content mfp-hide">
                   <div class="account-login-inner">
                   <h2 class="uppercase h3">ورود / عضویت</h2>
                    ' . self::get_template() . '
                   </div>            
        </div>
    ';

	}

}