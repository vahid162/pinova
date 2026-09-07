<?php

namespace Pinova\Integrations\LiteSpeedCache;

class Load {

	protected static ?Load $_instance = null;

	public static function instance(): ?Load {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	public function __construct() {
		add_filter( 'litespeed_optimize_js_excludes', [ $this, 'exclude_scripts' ] );
		add_filter( 'litespeed_optm_js_defer_exc', [ $this, 'exclude_scripts' ] );
	}

	public function exclude_scripts( $scripts ) {

		if ( ! is_array( $scripts ) ) {
			$scripts = ! empty( $scripts ) ? [ $scripts ] : [];
		}

		$scripts[] = 'pinova/assets/js/global.js';
		$scripts[] = 'pinova/assets/js/pages/login-form.js';
		$scripts[] = 'pinova/assets/js/notyf.min.js';
		$scripts[] = 'login-form-js-extra';
		$scripts[] = 'var pinova =';

		return array_unique( $scripts );
	}
}