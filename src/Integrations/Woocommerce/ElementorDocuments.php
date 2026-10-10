<?php

namespace Pinova\Integrations\Woocommerce;

/** Compatibility for postless WooCommerce document prototypes in Elementor Pro. */
final class ElementorDocuments {

	public static function guard_enqueue_scripts(): void {
		global $wp_filter;

		if ( ! defined( 'ELEMENTOR_VERSION' ) || '4.3.3' !== ELEMENTOR_VERSION
			|| ! defined( 'ELEMENTOR_PRO_VERSION' ) || '4.3.0' !== ELEMENTOR_PRO_VERSION ) {
			return;
		}

		$hook = $wp_filter['wp_enqueue_scripts'] ?? null;
		if ( ! $hook instanceof \WP_Hook ) {
			return;
		}

		foreach ( $hook->callbacks[11] ?? [] as $entry ) {
			$callback = $entry['function'];
			if ( ! is_array( $callback ) || ! isset( $callback[0], $callback[1] )
				|| ! is_object( $callback[0] ) || 'enqueue_scripts' !== $callback[1]
				|| ! in_array( get_class( $callback[0] ), [
					'ElementorPro\Modules\Woocommerce\Documents\Product',
					'ElementorPro\Modules\Woocommerce\Documents\Product_Archive',
				], true ) || ! is_callable( [ $callback[0], 'get_post' ] ) ) {
				continue;
			}

			// Type prototypes have no post. Keep real documents and their preview assets intact.
			if ( null === $callback[0]->get_post() ) {
				remove_action( 'wp_enqueue_scripts', $callback, 11 );
			}
		}
	}
}
