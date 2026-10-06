<?php
/** Native Dokan report constraints against both WooCommerce order stores. */

declare(strict_types=1);

use Automattic\WooCommerce\Utilities\OrderUtil;
use Pinova\Integrations\Dokan\Load;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'PINOVA_THIRD_PARTY_BASELINE' ) || true !== PINOVA_THIRD_PARTY_BASELINE
	|| 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), [ 'localhost', '127.0.0.1' ], true ) ) {
	throw new RuntimeException( 'Dokan reports require the marked disposable loopback site.' );
}
$check = static function ( bool $condition, string $label ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'Dokan report regression failed: ' . $label );
	}
};
add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'pre_http_request', static fn() => new WP_Error( 'pinova_fixture_no_network' ) );
global $wpdb;
$from = 'FROM ' . $wpdb->prefix . 'wc_orders AS orders';
$where = "WHERE orders.status = 'wc-pending' AND posts.post_parent = 0 AND 'posts.post_parent' = 'posts.post_parent'";
$query = [ 'from' => $from, 'where' => $where, 'select' => 'SELECT COUNT(orders.id)' ];
$expected = $query;
$expected['where'] = str_replace( ' AND posts.post_parent = 0', ' AND orders.parent_order_id = 0', $where );
$check( Load::report_query( $query ) === $expected, 'HPOS token changes while SQL literals and other clauses remain exact' );
$quoted = [ 'from' => $from, 'where' => <<<'SQL'
WHERE orders.status = 'wc-pending' AND posts.post_parent = 0 AND 'escaped\'posts.post_parent' = "posts.post_parent" AND 'doubled''posts.post_parent' <> ''
SQL
];
$expected_quoted = $quoted;
$expected_quoted['where'] = str_replace( ' AND posts.post_parent = 0', ' AND orders.parent_order_id = 0', $quoted['where'] );
$check( Load::report_query( $quoted ) === $expected_quoted, 'escaped and doubled SQL literals remain unchanged' );
$legacy = [ 'from' => 'FROM ' . $wpdb->posts . ' AS posts', 'where' => 'WHERE posts.post_parent = 0' ];
$check( Load::report_query( $legacy ) === $legacy, 'legacy order store remains unchanged' );
$malformed = [ 'from' => $from, 'where' => [] ];
$check( Load::report_query( $malformed ) === $malformed, 'upstream malformed clauses remain unchanged' );
require_once WC_ABSPATH . 'includes/admin/reports/class-wc-admin-report.php';
$native = [ ( new ReflectionClass( \WeDevs\Dokan\Admin\Hooks::class ) )->newInstanceWithoutConstructor(), 'admin_order_reports_remove_parents' ];
add_filter( 'woocommerce_reports_get_order_report_query', $native );
$orders = [];
$previous = $wpdb->suppress_errors();
try {
	foreach ( [ 0, 0, null ] as $parent ) {
		$order = wc_create_order( [ 'status' => 'pending', 'parent' => $parent ?? $orders[0]->get_id() ] );
		$check( $order instanceof WC_Order, 'native synthetic order creation' );
		$orders[] = $order;
	}
	$ids = array_map( static fn( WC_Order $order ): int => $order->get_id(), $orders );
	$args = [
		'data' => [ 'ID' => [ 'type' => 'post_data', 'function' => 'COUNT', 'name' => 'total' ] ],
		'where' => [ [ 'key' => 'ID', 'value' => $ids, 'operator' => 'IN' ] ],
		'order_status' => [ 'pending' ],
		'query_type' => 'get_var',
		'nocache' => true,
	];
	$report = new WC_Admin_Report();
	if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
		remove_filter( 'woocommerce_reports_get_order_report_query', [ Load::class, 'report_query' ], 11 );
		$report->get_order_report_data( $args );
		$check( false !== strpos( $wpdb->last_error, 'post_parent' ), 'unadapted native Dokan query reproduces the observed HPOS defect' );
		add_filter( 'woocommerce_reports_get_order_report_query', [ Load::class, 'report_query' ], 11 );
	}
	$result = $report->get_order_report_data( $args );
	$check( '' === $wpdb->last_error && 2 === (int) $result, 'native report excludes the child order without a schema error' );
} finally {
	add_filter( 'woocommerce_reports_get_order_report_query', [ Load::class, 'report_query' ], 11 );
	remove_filter( 'woocommerce_reports_get_order_report_query', $native );
	foreach ( array_reverse( $orders ) as $order ) {
		$order->delete( true );
	}
	$wpdb->suppress_errors( $previous );
}
WP_CLI::line( 'PINOVA_DOKAN_REPORTS_COMPLETE' );
