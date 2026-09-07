<?php

namespace Pinova\Integrations\Perfmatters;

class Load {

	protected static ?Load $_instance = null;

	public static function instance(): ?Load {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	public function __construct() {
		add_filter( 'perfmatters_defer_js_exclusions', [ $this, 'exclude_scripts' ] );
		add_filter( 'perfmatters_delay_js_exclusions', [ $this, 'exclude_scripts' ] );
		add_filter( 'perfmatters_minify_js_exclusions', [ $this, 'exclude_scripts' ] );
	}

	public function exclude_scripts( $scripts ) {

		if ( ! is_array( $scripts ) ) {
			return $scripts;
		}
		
		$scripts[] = 'pinova/assets/js/global.js';
		$scripts[] = 'pinova/assets/js/pages/login-form.js';
		$scripts[] = 'login-form-js-extra';
		$scripts[] = 'pinova/assets/js/notyf.min.js';

		return $scripts;
	}
}