<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Admin\Issues;
use Pinova\Admin\Logs;
use Pinova\Install;
use Pinova\Logging\IssueMonitor;
use Pinova\Logging\LogRepository;
use WP_UnitTestCase;

final class IssueMonitorIntegrationTest extends WP_UnitTestCase {

	private int $administrator;
	private array $saved_get;
	private array $saved_post;
	private array $saved_request;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();
		$this->saved_get = $_GET;
		$this->saved_post = $_POST;
		$this->saved_request = $_REQUEST;
		$_GET = [];
		$_POST = [];
		$_REQUEST = [];
		$this->administrator = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->administrator );
		update_option( 'pinova_logging', [ 'minimum_level' => 'info', 'retention_days' => 14 ] );
		LogRepository::delete_all();
		$this->clear_reviews();
		add_filter( 'wp_die_handler', [ $this, 'die_handler' ] );
	}

	public function tear_down(): void {
		remove_filter( 'wp_die_handler', [ $this, 'die_handler' ] );
		$this->clear_reviews();
		LogRepository::delete_all();
		delete_option( 'pinova_logging' );
		$_GET = $this->saved_get;
		$_POST = $this->saved_post;
		$_REQUEST = $this->saved_request;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function die_handler(): callable {
		return static function ( $message, $title = '', $args = [] ): void {
			$status = is_array( $args ) ? ( $args['response'] ?? 500 ) : $args;
			throw new \RuntimeException( (string) $status );
		};
	}

	public function test_report_reads_do_not_write_and_describe_the_evidence_limits(): void {
		$id = $this->insert_event();
		$writes = [];
		$watch = static function ( string $query ) use ( &$writes ): string {
			if ( preg_match( '/\A\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER|TRUNCATE)\b/i', $query ) ) {
				$writes[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $watch );
		try {
			$report = IssueMonitor::report();
		} finally {
			remove_filter( 'query', $watch );
		}
		self::assertSame( [], $writes );
		self::assertSame( 'pinova.issues.v1', $report['schema'] );
		self::assertSame( [ gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) ], $report['selected_range'] );
		self::assertTrue( $report['coverage']['complete'] );
		self::assertSame( 1, $report['coverage']['rows_examined'] );
		self::assertSame( $id, $report['coverage']['snapshot_event_id'] );
		self::assertTrue( $report['coverage']['observed_counts_only'] );
		self::assertSame( 'not_inspected', $report['coverage']['external_logs'] );
		self::assertSame( 'not_verified', $report['coverage']['physical_delivery'] );
		self::assertSame( 'info', $report['coverage']['minimum_level'] );
		// Settings accepts the selected duration and stores its absolute deadline.
		update_option( 'pinova_logging', [ 'minimum_level' => 'error', 'diagnostic_until' => 900 ] );
		self::assertGreaterThan( time(), get_option( 'pinova_logging' )['diagnostic_until'] );
		self::assertSame( 'debug', IssueMonitor::report()['coverage']['minimum_level'] );
	}

	public function test_review_preserves_evidence_and_recurrence_reopens_it(): void {
		global $wpdb;
		$id = $this->insert_event();
		$report = IssueMonitor::report();
		$issue = $report['issues'][0];
		self::assertTrue( $this->review( $report, 'acknowledged' ) );
		self::assertSame( 'acknowledged', IssueMonitor::report()['issues'][0]['state'] );
		self::assertFalse( $this->review( $report, 'resolved_unverified' ), 'A stale review token must not overwrite a concurrent review.' );
		self::assertTrue( $this->review( IssueMonitor::report(), 'resolved_unverified' ) );
		self::assertSame( 'resolved_unverified', IssueMonitor::report()['issues'][0]['state'] );
		self::assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id = %d', $wpdb->prefix . 'pinova_logs', $id ) ) );
		$name = 'pinova_issue_review_' . hash( 'sha256', $issue['code'] );
		$autoload = $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
		self::assertContains( $autoload, [ 'no', 'off', 'auto-off' ] );
		$this->insert_event();
		$reopened = IssueMonitor::report()['issues'][0];
		self::assertSame( 'open', $reopened['state'] );
		self::assertSame( 2, $reopened['observed_count'] );
		self::assertSame( 'resolved_unverified', $reopened['review']['state'] );
	}

	public function test_stale_observation_unknown_issue_and_incomplete_report_cannot_be_reviewed(): void {
		$this->insert_event();
		$before = IssueMonitor::report();
		$this->insert_event();
		$fresh = IssueMonitor::report();
		$old = $before['issues'][0];
		self::assertFalse( IssueMonitor::review( $fresh, $old['code'], 'resolved_unverified', $old['last_event_id'], IssueMonitor::review_token( $old['review'] ) ) );
		self::assertFalse( IssueMonitor::review( $fresh, 'private-dynamic-issue', 'acknowledged', $old['last_event_id'], IssueMonitor::review_token( $old['review'] ) ) );
		self::assertFalse( $this->review( $fresh, 'repaired_automatically' ) );
		foreach ( [ 'complete', 'truncated' ] as $key ) {
			$incomplete = $fresh;
			$incomplete['coverage'][ $key ] = 'truncated' === $key;
			self::assertFalse( $this->review( $incomplete, 'resolved_unverified' ) );
		}
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertFalse( $this->review( $fresh, 'acknowledged' ) );
	}

	public function test_an_older_range_cannot_overwrite_a_newer_review(): void {
		global $wpdb;
		$old_id = $this->insert_event();
		$yesterday = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		$wpdb->update( $wpdb->prefix . 'pinova_logs', [ 'created_at' => $yesterday . ' 12:00:00' ], [ 'id' => $old_id ] );
		$this->insert_event();
		self::assertTrue( $this->review( IssueMonitor::report(), 'acknowledged' ) );
		$current = IssueMonitor::report()['issues'][0];
		$older = IssueMonitor::report( [ 'created_from' => $yesterday, 'created_to' => $yesterday ] );
		self::assertSame( $old_id, $older['issues'][0]['last_event_id'] );
		self::assertFalse( $this->review( $older, 'resolved_unverified' ) );
		self::assertSame( $current['review'], IssueMonitor::report()['issues'][0]['review'] );
		self::assertSame( 'acknowledged', IssueMonitor::report()['issues'][0]['state'] );
		$_GET = [ 'created_from' => $yesterday, 'created_to' => $yesterday ];
		ob_start();
		Issues::render();
		$html = (string) ob_get_clean();
		self::assertStringNotContainsString( 'name="action" value="pinova_review_issue"', $html );
	}

	public function test_discarded_oversized_context_prevents_reviewing_the_report(): void {
		$this->insert_event( 'otp.delivery_failed', [ 'password' => str_repeat( 'private-secret', 1000 ) ] );
		$report = IssueMonitor::report();
		self::assertTrue( $report['coverage']['context_truncated'] );
		self::assertFalse( $report['coverage']['complete'] );
		self::assertSame( [], $report['issues'][0]['evidence'][0]['context'] );
		self::assertFalse( $this->review( $report, 'resolved_unverified' ) );
		self::assertSame( 'open', $report['issues'][0]['state'] );
		self::assertStringNotContainsString( 'private-secret', wp_json_encode( $report ) );
	}

	public function test_export_and_admin_projection_share_safe_evidence_and_classifications(): void {
		$this->insert_event( 'otp.channel_send_failed', [ 'reason' => 'provider_returned_false', 'channel' => 'sms', 'duration_ms' => 53, 'password' => 'private-password', 'identifier_fingerprint' => str_repeat( 'd', 32 ) ] );
		$this->insert_event( 'otp.verify_failed', [ 'reason' => 'token_expired', 'token' => 'private-token' ] );
		$expected = IssueMonitor::report();
		$this->post( 'pinova_export_issues' );
		$json = $this->invoke( 'export_response', false );
		$export = json_decode( $json, true );
		self::assertSame( $expected['issues'], $export['issues'] );
		self::assertSame( $expected['coverage'], $export['coverage'] );
		self::assertLessThanOrEqual( 2097152, strlen( $json ) );
		ob_start();
		try {
			Logs::render();
			$html = (string) ob_get_clean();
		} finally {
			if ( false === isset( $html ) ) {
				ob_end_clean();
			}
		}
		foreach ( $expected['issues'] as $issue ) {
			self::assertStringContainsString( $issue['code'], $html );
			self::assertNotEmpty( $issue['investigation_hint'] );
			self::assertStringContainsString( $issue['investigation_hint'], $html );
			self::assertStringContainsString( Issues::category_label( $issue['category'] ), $html );
			self::assertStringContainsString( '#' . $issue['evidence'][0]['id'], $html );
		}
		foreach ( [ $json, $html ] as $output ) {
			self::assertStringNotContainsString( 'private-password', $output );
			self::assertStringNotContainsString( 'private-token', $output );
			self::assertStringNotContainsString( str_repeat( 'd', 32 ), $output );
		}
		self::assertSame( 53, $export['issues'][1]['evidence'][0]['context']['duration_ms'] );
	}

	public function test_export_and_review_require_capability_and_nonce(): void {
		$this->insert_event();
		foreach ( [ 'export_response' => 'pinova_export_issues', 'review_response' => 'pinova_review_issue' ] as $method => $nonce ) {
			wp_set_current_user( 0 );
			$this->post( $nonce );
			$this->assert_response_status( $method, 403 );
			wp_set_current_user( $this->administrator );
			$_POST = [];
			$_REQUEST = [];
			$this->assert_response_status( $method, 403 );
		}
	}

	public function test_admin_review_rechecks_fresh_evidence_and_preserves_prior_rows(): void {
		$id = $this->insert_event();
		$issue = IssueMonitor::report()['issues'][0];
		$input = [
			'issue' => $issue['code'],
			'state' => 'acknowledged',
			'through_id' => (string) $issue['last_event_id'],
			'expected' => IssueMonitor::review_token( $issue['review'] ),
		];
		$this->insert_event();
		$this->post( 'pinova_review_issue', $input );
		$this->assert_response_status( 'review_response', 409 );
		$issue = IssueMonitor::report()['issues'][0];
		$input['through_id'] = (string) $issue['last_event_id'];
		$this->post( 'pinova_review_issue', $input );
		self::assertSame( '', $this->invoke( 'review_response' ) );
		self::assertSame( 'acknowledged', IssueMonitor::report()['issues'][0]['state'] );
		self::assertContains( $id, array_column( IssueMonitor::report()['issues'][0]['evidence'], 'id' ) );
	}

	public function test_invalid_or_overlong_date_ranges_are_not_broadened(): void {
		$this->insert_event();
		$today = gmdate( 'Y-m-d' );
		foreach ( [
			[ 'created_from' => $today ],
			[ 'created_from' => [ $today ], 'created_to' => $today ],
			[ 'created_from' => '2026-02-30', 'created_to' => '2026-02-30' ],
			[ 'created_from' => gmdate( 'Y-m-d', time() - 7 * DAY_IN_SECONDS ), 'created_to' => $today ],
			[ 'created_from' => $today, 'created_to' => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) ],
		] as $input ) {
			$report = IssueMonitor::report( Issues::dates( $input ) );
			self::assertFalse( $report['coverage']['valid_range'] );
			self::assertFalse( $report['coverage']['complete'] );
			self::assertSame( [], $report['issues'] );
			$this->post( 'pinova_export_issues', $input );
			$this->assert_response_status( 'export_response', 400 );
		}
		self::assertTrue( IssueMonitor::report( [ 'created_from' => gmdate( 'Y-m-d', time() - 6 * DAY_IN_SECONDS ), 'created_to' => $today ] )['coverage']['valid_range'] );
	}

	public function test_failed_review_redirect_preserves_the_review_and_returns_a_safe_link(): void {
		$this->insert_event();
		$issue = IssueMonitor::report()['issues'][0];
		$dates = [ 'created_from' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), 'created_to' => gmdate( 'Y-m-d' ) ];
		$this->post( 'pinova_review_issue', $dates + [
			'issue' => $issue['code'], 'state' => 'acknowledged',
			'through_id' => (string) $issue['last_event_id'], 'expected' => IssueMonitor::review_token( $issue['review'] ),
		] );
		$response = [];
		$handler = static function () use ( &$response ): callable {
			return static function ( $message, $title, $args ) use ( &$response ): void {
				$response = [ 'message' => $message, 'args' => $args ];
				throw new \RuntimeException( 'review_redirect_fallback' );
			};
		};
		add_filter( 'wp_redirect', '__return_false', PHP_INT_MAX );
		add_filter( 'wp_die_handler', $handler, PHP_INT_MAX );
		try {
			$this->invoke( 'review' );
			self::fail( 'A rejected redirect must return a controlled response.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'review_redirect_fallback', $exception->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', '__return_false', PHP_INT_MAX );
			remove_filter( 'wp_die_handler', $handler, PHP_INT_MAX );
		}
		self::assertSame( 503, $response['args']['response'] );
		self::assertNotEmpty( $response['message'] );
		self::assertSame( add_query_arg( [ 'page' => 'pinova-logs' ] + $dates, admin_url( 'admin.php' ) ), $response['args']['link_url'] );
		$issues = array_column( IssueMonitor::report()['issues'], null, 'code' );
		self::assertSame( 'acknowledged', $issues[ $issue['code'] ]['state'] );
		$failure = $issues['auth.redirect_failed:headers_sent'] ?? $issues['auth.redirect_failed:safe_redirect_rejected'];
		self::assertSame( 'issue_review', $failure['evidence'][0]['context']['operation'] );
	}

	public function test_row_limit_is_explicit_and_blocks_resolution(): void {
		global $wpdb;
		$values = [];
		$arguments = [ $wpdb->prefix . 'pinova_logs' ];
		for ( $index = 0; $index < 1001; ++$index ) {
			$values[] = '(%s,%s,%s,%s,%s)';
			array_push( $arguments, gmdate( 'Y-m-d H:i:s' ), 'warning', 'otp.delivery_failed', '123e4567-e89b-42d3-a456-426614174000', '{}' );
		}
		self::assertSame( 1001, $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (created_at,level,event,correlation_id,context) VALUES ' . implode( ',', $values ), $arguments ) ) );
		$report = IssueMonitor::report();
		self::assertSame( 1000, $report['coverage']['rows_examined'] );
		self::assertTrue( $report['coverage']['truncated'] );
		self::assertFalse( $report['coverage']['complete'] );
		self::assertSame( 1000, $report['issues'][0]['observed_count'] );
		self::assertFalse( $this->review( $report, 'resolved_unverified' ) );
	}

	public function test_read_failure_is_incomplete_evidence_not_an_all_clear(): void {
		global $wpdb;
		$this->insert_event();
		$deny = static function ( string $query ): string {
			return str_contains( $query, '`id` <' ) && str_contains( $query, 'pinova_logs' ) ? 'SELECT * FROM pinova_missing_issue_fixture_table' : $query;
		};
		$previous = $wpdb->suppress_errors( true );
		add_filter( 'query', $deny );
		try {
			$report = IssueMonitor::report();
		} finally {
			remove_filter( 'query', $deny );
			$wpdb->suppress_errors( $previous );
			$wpdb->last_error = '';
		}
		self::assertFalse( $report['coverage']['complete'] );
		self::assertSame( [], $report['issues'] );
	}

	public function test_new_events_between_batches_wait_for_the_next_snapshot(): void {
		for ( $index = 0; $index < 101; ++$index ) {
			$last = $this->insert_event();
		}
		$reads = 0;
		$new_id = 0;
		$interleave = function ( string $query ) use ( &$reads, &$new_id ): string {
			if ( str_contains( $query, '`id` <' ) && str_contains( $query, 'pinova_logs' ) ) {
				++$reads;
				if ( 2 === $reads ) {
					$new_id = $this->insert_event();
				}
			}
			return $query;
		};
		add_filter( 'query', $interleave );
		try {
			$report = IssueMonitor::report();
		} finally {
			remove_filter( 'query', $interleave );
		}
		self::assertGreaterThan( $last, $new_id );
		self::assertSame( $last, $report['coverage']['snapshot_event_id'] );
		self::assertSame( 101, $report['coverage']['rows_examined'] );
		self::assertSame( 101, $report['issues'][0]['observed_count'] );
		self::assertSame( 102, IssueMonitor::report()['issues'][0]['observed_count'] );
	}

	private function review( array $report, string $state ): bool {
		$issue = $report['issues'][0];
		return IssueMonitor::review( $report, $issue['code'], $state, $issue['last_event_id'], IssueMonitor::review_token( $issue['review'] ) );
	}

	private function post( string $nonce, array $input = [] ): void {
		$_POST = $input + [ '_wpnonce' => wp_create_nonce( $nonce ) ];
		$_REQUEST = $_POST;
	}

	private function invoke( string $method, ...$args ): string {
		$level = ob_get_level();
		ob_start();
		try {
			( new \ReflectionMethod( Issues::class, $method ) )->invoke( new Issues(), ...$args );
			return (string) ob_get_clean();
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}
	}

	private function assert_response_status( string $method, int $status ): void {
		try {
			$this->invoke( $method, ...('export_response' === $method ? [ false ] : []) );
			self::fail( 'Expected the operation to be rejected.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( (string) $status, $exception->getMessage() );
		}
	}

	private function insert_event( string $event = 'otp.delivery_failed', array $context = [] ): int {
		global $wpdb;
		self::assertSame( 1, $wpdb->insert( $wpdb->prefix . 'pinova_logs', [
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
			'level' => 'warning',
			'event' => $event,
			'correlation_id' => '123e4567-e89b-42d3-a456-426614174000',
			'user_id' => $this->administrator,
			'context' => wp_json_encode( $context ),
		] ) );
		return (int) $wpdb->insert_id;
	}

	private function clear_reviews(): void {
		global $wpdb;
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'pinova_issue_review_' ) . '%' ) );
		foreach ( $names as $name ) {
			delete_option( $name );
		}
	}
}
