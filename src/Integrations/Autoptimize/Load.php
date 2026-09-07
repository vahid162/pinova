<?php

namespace Pinova\Integrations\Autoptimize;

class Load {

	protected static ?Load $_instance = null;

	public static function instance(): ?Load {

		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	public function __construct() {
		add_filter( 'autoptimize_filter_js_exclude', [ $this, 'exclude_scripts' ] );
	}

	/**
	 * @param string $exclusions
	 *
	 * @return string
	 */
	public function exclude_scripts( $exclusions ): string {

		if ( ! is_string( $exclusions ) ) {
			return $exclusions;
		}

		$pinova_exclusions = implode( ',', [
			'pinova/assets',
			'var pinova =',
		] );

		if ( empty( trim( $exclusions ) ) ) {
			return $pinova_exclusions;
		}

		return $exclusions . ',' . $pinova_exclusions;
	}
}