<?php
/** Native wallet recharge exclusions, without activating wallet payment hooks. */

declare(strict_types=1);

use Automattic\WooCommerce\Utilities\OrderUtil;
use Pinova\Integrations\Woocommerce\WalletReports;

$staging_url = getenv( 'PINOVA_STAGING_BASELINE_URL' );
$local_target = 'local' === wp_get_environment_type() && in_array( wp_parse_url( home_url(), PHP_URL_HOST ), [ 'localhost', '127.0.0.1' ], true );
$staging_target = 'staging' === wp_get_environment_type() && is_string( $staging_url ) && 'https' === wp_parse_url( $staging_url, PHP_URL_SCHEME )
	&& hash_equals( untrailingslashit( $staging_url ), untrailingslashit( home_url() ) );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'PINOVA_THIRD_PARTY_BASELINE' ) || true !== PINOVA_THIRD_PARTY_BASELINE
	|| ( ! $local_target && ! $staging_target ) ) {
	throw new RuntimeException( 'Wallet reports require the disposable local site or explicitly selected staging URL.' );
}
$check = static function ( bool $condition, string $label ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'Wallet report regression failed: ' . $label );
	}
};

add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'pre_http_request', static fn() => new WP_Error( 'pinova_fixture_no_network' ) );
$root = dirname( __DIR__, 2 );
$manifest = json_decode( file_get_contents( $root . '/.build/third-party/verified.json' ), true, 512, JSON_THROW_ON_ERROR );
$fixtures = array_column( $manifest['callback_fixtures'], null, 'slug' );
$fixture = $fixtures['woo-wallet'];
$path = $root . '/.build/third-party/woo-wallet';
$check( '1.7.1' === $fixture['version'], 'pinned supported wallet version' );
$check( ! class_exists( 'Woo_Wallet', false ) && ! defined( 'WOO_WALLET_PLUGIN_VERSION' ), 'wallet payment plugin is not active in this fixture' );
$check( 11 === has_filter( 'woocommerce_reports_get_order_report_query', [ WalletReports::class, 'report_query' ] ), 'Pinova registered wallet compatibility independently' );

// The archive is verified before extraction. Load only the real report class and
// helper definitions; constructor bypass avoids wallet installation/payment hooks.
require_once $path . '/includes/class-woo-wallet.php';
require_once $path . '/includes/helper/woo-wallet-util.php';
define( 'WOO_WALLET_PLUGIN_VERSION', $fixture['version'] );
$GLOBALS['woo_wallet'] = ( new ReflectionClass( Woo_Wallet::class ) )->newInstanceWithoutConstructor();
$wallet = [ $GLOBALS['woo_wallet'], 'woocommerce_reports_get_order_report_query' ];
$wallet_cpt = [ $GLOBALS['woo_wallet'], 'filter_wallet_topup_orders' ];
$dokan = [ ( new ReflectionClass( \WeDevs\Dokan\Order\Admin\Hooks::class ) )->newInstanceWithoutConstructor(), 'admin_order_reports_remove_parents' ];
add_filter( 'woocommerce_reports_get_order_report_query', $wallet, 10 );
add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $wallet_cpt, 10, 2 );
require_once WC_ABSPATH . 'includes/admin/reports/class-wc-admin-report.php';

global $wpdb;
$orders = [];
$previous_errors = $wpdb->suppress_errors();
try {
	foreach ( [ 0, 0, null ] as $index => $parent ) {
		$order = wc_create_order( [ 'status' => 'completed', 'created_via' => 'pinova-wallet-report-fixture', 'parent' => $parent ?? $orders[0]->get_id() ] );
		$check( $order instanceof WC_Order, 'owned synthetic order creation' );
		$orders[] = $order;
		if ( 1 === $index ) {
			$order->update_meta_data( '_wc_wallet_purchase_credited', true );
			$order->save();
		}
	}
	$ids = array_map( static fn( WC_Order $order ): int => $order->get_id(), $orders );
	$recharge_ids = array_map( 'intval', get_wallet_rechargeable_orders() );
	$check( in_array( $ids[1], $recharge_ids, true ) && ! in_array( $ids[0], $recharge_ids, true ), 'native wallet selects recharge orders only' );
	$args = [
		'data' => [ 'ID' => [ 'type' => 'post_data', 'function' => 'COUNT', 'name' => 'total' ] ],
		'where' => [ [ 'key' => 'ID', 'value' => $ids, 'operator' => 'IN' ] ],
		'order_status' => [ 'completed' ],
		'query_type' => 'get_var',
		'nocache' => true,
	];
	$report = new WC_Admin_Report();
	if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
		remove_filter( 'woocommerce_reports_get_order_report_query', [ WalletReports::class, 'report_query' ], 11 );
		$report->get_order_report_data( $args );
		$check( false !== strpos( $wpdb->last_error, 'posts.ID' ), 'unadapted native wallet reproduces the observed HPOS alias error' );
		add_filter( 'woocommerce_reports_get_order_report_query', [ WalletReports::class, 'report_query' ], 11 );
	}
	$result = $report->get_order_report_data( $args );
	$check( '' === $wpdb->last_error && 2 === (int) $result, 'wallet excludes recharge order and preserves ordinary parent/child orders' );

	add_filter( 'woo_wallet_exclude_wallet_rechargeable_orders_from_report', '__return_false' );
	$result = $report->get_order_report_data( $args );
	$check( '' === $wpdb->last_error && 3 === (int) $result, 'wallet exclusion opt-out is preserved' );
	remove_filter( 'woo_wallet_exclude_wallet_rechargeable_orders_from_report', '__return_false' );

	foreach ( [ 'wallet-first', 'dokan-first' ] as $sequence ) {
		remove_filter( 'woocommerce_reports_get_order_report_query', $wallet, 10 );
		remove_filter( 'woocommerce_reports_get_order_report_query', $dokan, 10 );
		$callbacks = 'wallet-first' === $sequence ? [ $wallet, $dokan ] : [ $dokan, $wallet ];
		foreach ( $callbacks as $callback ) {
			add_filter( 'woocommerce_reports_get_order_report_query', $callback, 10 );
		}
		$result = $report->get_order_report_data( $args );
		$check( '' === $wpdb->last_error && 1 === (int) $result, 'both native restrictions survive callback order ' . $sequence );
	}
} finally {
	add_filter( 'woocommerce_reports_get_order_report_query', [ WalletReports::class, 'report_query' ], 11 );
	remove_filter( 'woocommerce_reports_get_order_report_query', $wallet, 10 );
	remove_filter( 'woocommerce_reports_get_order_report_query', $dokan, 10 );
	remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $wallet_cpt, 10 );
	remove_filter( 'woo_wallet_exclude_wallet_rechargeable_orders_from_report', '__return_false' );
	$cleanup_complete = true;
	foreach ( array_reverse( $orders ) as $order ) {
		$order_id = $order->get_id();
		$order->delete( true );
		$cleanup_complete = false === wc_get_order( $order_id ) && $cleanup_complete;
	}
	unset( $GLOBALS['woo_wallet'] );
	$wpdb->suppress_errors( $previous_errors );
	$check( $cleanup_complete, 'owned orders removed' );
}
WP_CLI::line( 'PINOVA_WALLET_REPORTS_COMPLETE' );
