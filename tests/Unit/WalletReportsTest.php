<?php

declare(strict_types=1);

namespace Pinova\Integrations\Woocommerce {

	function has_filter( $hook, $callback ) {
		return $GLOBALS['pinova_wallet_test_priority'] ?? false;
	}
}

namespace Pinova\Tests\Unit {

	use PHPUnit\Framework\TestCase;
	use Pinova\Integrations\Woocommerce\WalletReports;

	class WalletReportFixture {}

	/**
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class WalletReportsTest extends TestCase {

		private function prepare( string $version ): void {
			define( 'WOO_WALLET_PLUGIN_VERSION', $version );
			class_alias( WalletReportFixture::class, 'Woo_Wallet' );
			$GLOBALS['woo_wallet'] = new WalletReportFixture();
			$GLOBALS['wpdb'] = (object) [ 'prefix' => 'test_' ];
			$GLOBALS['pinova_wallet_test_priority'] = 10;
		}

		public function test_only_the_native_hpos_numeric_exclusion_changes(): void {
			$this->prepare( '1.7.1' );
			$where = <<<'SQL'
WHERE orders.status = 'wc-completed' AND posts.ID NOT IN (101, 202) AND 'posts.ID NOT IN (303)' = "posts.ID NOT IN (404)" /* posts.ID NOT IN (505) */ AND orders.parent_order_id = 0
SQL;
			$query = [ 'from' => 'FROM test_wc_orders AS orders', 'where' => $where, 'select' => 'SELECT COUNT(orders.id)', 'join' => '' ];
			$expected = $query;
			$expected['where'] = str_replace( 'AND posts.ID NOT IN (101, 202)', 'AND orders.id NOT IN (101, 202)', $where );
			self::assertSame( $expected, WalletReports::report_query( $query ) );
			self::assertSame( $expected, WalletReports::report_query( $expected ) );
		}

		public function test_unrecognized_query_shapes_and_literals_remain_exact(): void {
			$this->prepare( '1.7.1' );
			$clauses = [
				'WHERE posts.ID NOT IN (SELECT id FROM other_orders)',
				"WHERE posts.ID NOT IN ('101')",
				'WHERE posts.ID NOT IN ()',
				'WHERE posts.ID NOT IN (101, expression())',
				'WHERE otherposts.ID NOT IN (101)',
				"WHERE 'escaped\\'posts.ID NOT IN (101)' = 'doubled''posts.ID NOT IN (202)'",
				"WHERE 1 = 1 -- posts.ID NOT IN (101)\n AND orders.id > 0",
				"WHERE 1 = 1 # posts.ID NOT IN (101)\n AND orders.id > 0",
			];
			foreach ( $clauses as $where ) {
				$query = [ 'from' => 'FROM test_wc_orders AS orders', 'where' => $where ];
				self::assertSame( $query, WalletReports::report_query( $query ) );
			}
			foreach ( [ 'FROM test_posts AS posts', 'FROM other_wc_orders AS orders', 'FROM test_wc_orders AS custom_orders' ] as $from ) {
				$query = [ 'from' => $from, 'where' => 'WHERE posts.ID NOT IN (101)' ];
				self::assertSame( $query, WalletReports::report_query( $query ) );
			}
			self::assertSame( [ 'where' => [] ], WalletReports::report_query( [ 'where' => [] ] ) );
		}

		public function test_missing_or_reordered_native_callback_is_untouched(): void {
			$this->prepare( '1.7.1' );
			$query = [ 'from' => 'FROM test_wc_orders AS orders', 'where' => 'WHERE posts.ID NOT IN (101)' ];
			foreach ( [ false, 9, 12 ] as $priority ) {
				$GLOBALS['pinova_wallet_test_priority'] = $priority;
				self::assertSame( $query, WalletReports::report_query( $query ) );
			}
			$GLOBALS['pinova_wallet_test_priority'] = 10;
			$GLOBALS['woo_wallet'] = new \stdClass();
			self::assertSame( $query, WalletReports::report_query( $query ) );
			unset( $GLOBALS['woo_wallet'] );
			self::assertSame( $query, WalletReports::report_query( $query ) );
		}

		public function test_unsupported_wallet_version_is_untouched(): void {
			$this->prepare( '1.7.2' );
			$query = [ 'from' => 'FROM test_wc_orders AS orders', 'where' => 'WHERE posts.ID NOT IN (101)' ];
			self::assertSame( $query, WalletReports::report_query( $query ) );
		}

		public function test_absent_wallet_is_untouched(): void {
			$query = [ 'from' => 'FROM test_wc_orders AS orders', 'where' => 'WHERE posts.ID NOT IN (101)' ];
			self::assertSame( $query, WalletReports::report_query( $query ) );
		}
	}
}
