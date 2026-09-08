<?php

declare(strict_types=1);

$config_file = getenv( 'WP_TESTS_CONFIG_FILE_PATH' );

if ( $config_file && ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', $config_file );
}

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

if ( getenv( 'PINOVA_TEST_HPOS' ) ) {
	tests_add_filter(
		'option_woocommerce_custom_orders_table_enabled',
		static fn(): string => 'yes' === getenv( 'PINOVA_TEST_HPOS' ) ? 'yes' : 'no'
	);
	tests_add_filter(
		'option_woocommerce_custom_orders_table_data_sync_enabled',
		static fn(): string => 'yes' === getenv( 'PINOVA_TEST_HPOS' ) ? 'yes' : 'no'
	);
}

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/pinova.php';

		$woocommerce_file = getenv( 'WC_INTEGRATION_PLUGIN_FILE' );

		if ( $woocommerce_file && file_exists( $woocommerce_file ) ) {
			require $woocommerce_file;

			if ( class_exists( '\\WC_Install' ) ) {
				\WC_Install::create_tables();
			}
		}
	}
);

require $_tests_dir . '/includes/bootstrap.php';
