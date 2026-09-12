<?php

declare(strict_types=1);

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		$woocommerce = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		if ( ! is_readable( $woocommerce ) ) {
			throw new RuntimeException( 'WooCommerce is required by the configured Pinova integration matrix.' );
		}

		require_once $woocommerce;

		$hpos = getenv( 'PINOVA_TEST_HPOS' );
		if ( ! in_array( $hpos, [ 'yes', 'no' ], true ) ) {
			throw new RuntimeException( 'PINOVA_TEST_HPOS must be forwarded as yes or no.' );
		}

		update_option( 'woocommerce_custom_orders_table_enabled', $hpos );

		require dirname( __DIR__ ) . '/pinova.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';
