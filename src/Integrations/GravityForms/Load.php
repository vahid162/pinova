<?php

namespace Pinova\Integrations\GravityForms;

use GFAPI;
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
		add_filter( 'gform_form_args', [ $this, 'required_login_redirect' ] );
	}

	public function required_login_redirect( $args ) {
		global $wp;

		if ( is_user_logged_in() ) {
			return $args;
		}

		$form = GFAPI::get_form( $args['form_id'] );

		if ( ! $form || ! rgar( $form, 'requireLogin' ) ) {
			return $args;
		}

		$current_url  = home_url( add_query_arg( [], $wp->request ) );
		$redirect_url = Pinova::get_login_url( $current_url );

		wp_safe_redirect( $redirect_url );
		exit;
	}
}