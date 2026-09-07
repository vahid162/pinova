<?php

namespace Pinova\Integrations\WPRocket;

class Load {

	protected static ?Load $_instance = null;

	public static function instance(): ?Load {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	public function __construct() {
		add_filter( 'rocket_exclude_defer_js', [ $this, 'exclude_scripts' ] );
		add_filter( 'rocket_delay_js_exclusions', [ $this, 'exclude_scripts' ] );
	}

	public function exclude_scripts( $scripts ) {

		if ( ! is_array( $scripts ) ) {
			return $scripts;
		}

		$scripts[] = '/pinova/assets/js/(.*).js';


		return $scripts;
	}
}