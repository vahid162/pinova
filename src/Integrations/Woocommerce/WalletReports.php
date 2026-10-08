<?php

namespace Pinova\Integrations\Woocommerce;

/** Preserve TeraWallet's recharge-order exclusions in native HPOS reports. */
final class WalletReports {

	public static function report_query( array $query ): array {
		global $wpdb;
		$wallet = $GLOBALS['woo_wallet'] ?? null;
		if ( ! defined( 'WOO_WALLET_PLUGIN_VERSION' ) || '1.7.1' !== WOO_WALLET_PLUGIN_VERSION
			|| ! is_object( $wallet ) || ! is_a( $wallet, 'Woo_Wallet' )
			|| 10 !== has_filter( 'woocommerce_reports_get_order_report_query', [ $wallet, 'woocommerce_reports_get_order_report_query' ] )
			|| ! isset( $query['from'], $query['where'] ) || ! is_string( $query['where'] )
			|| 'FROM ' . $wpdb->prefix . 'wc_orders AS orders' !== $query['from'] ) {
			return $query;
		}
		// Only the characterized numeric exclusion changes. Preserve literals and comments.
		$pattern        = <<<'SQL'
~'(?:[^'\\]|\\.|'')*'(*SKIP)(*F)|"(?:[^"\\]|\\.|"")*"(*SKIP)(*F)|/\*.*?\*/(*SKIP)(*F)|(?:\#|--\s)[^\r\n]*(*SKIP)(*F)|\bposts\.ID\b(?=\s+NOT IN\s*\(\s*[0-9]+(?:\s*,\s*[0-9]+)*\s*\))~s
SQL;
		$query['where'] = preg_replace( $pattern, 'orders.id', $query['where'] ) ?? $query['where'];
		return $query;
	}
}
