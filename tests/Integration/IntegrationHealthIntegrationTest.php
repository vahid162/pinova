<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Install;
use Pinova\Integrations\IntegrationHealth;
use Pinova\Integrations\IntegrationSettings;
use Pinova\Logging\EventEvidence;
use Pinova\Logging\IssueMonitor;
use Pinova\Logging\LogRepository;
use WP_UnitTestCase;

final class IntegrationHealthIntegrationTest extends WP_UnitTestCase {

	private array $saved_get;
	private array $saved_request;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();
		$this->saved_get     = $_GET;
		$this->saved_request = $_REQUEST;
		$_GET               = [];
		$_REQUEST           = [];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		delete_option( IntegrationSettings::OPTION );
		foreach ( [ 'wpforo', 'dokan' ] as $slug ) {
			delete_option( 'pinova_integration_health_' . $slug );
		}
		update_option( 'pinova_logging', [ 'minimum_level' => 'info', 'retention_days' => 14 ] );
		LogRepository::delete_all();
	}

	public function tear_down(): void {
		delete_option( IntegrationSettings::OPTION );
		foreach ( [ 'wpforo', 'dokan' ] as $slug ) {
			delete_option( 'pinova_integration_health_' . $slug );
		}
		delete_option( 'pinova_logging' );
		LogRepository::delete_all();
		$_GET     = $this->saved_get;
		$_REQUEST = $this->saved_request;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_unchanged_observations_write_nothing_and_disabled_defaults_are_quiet(): void {
		IntegrationHealth::observe();
		self::assertSame( 0, LogRepository::paginate()['total'] );
		$this->enable();
		IntegrationHealth::observe();
		self::assertSame( 2, LogRepository::paginate()['total'] );
		$writes = $this->writes( static fn() => IntegrationHealth::observe() );
		self::assertSame( [], $writes );
		self::assertSame( 2, LogRepository::paginate()['total'] );
		global $wpdb;
		foreach ( [ 'wpforo', 'dokan' ] as $slug ) {
			$autoload = $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, 'pinova_integration_health_' . $slug ) );
			self::assertNotContains( $autoload, [ 'yes', 'on', 'auto-on', 'auto' ] );
		}
	}

	public function test_log_views_exports_and_unauthorized_requests_do_not_observe(): void {
		$this->enable();
		$_GET['page'] = 'pinova-logs';
		self::assertSame( [], $this->writes( static fn() => IntegrationHealth::observe_admin() ) );
		$_GET = [];
		foreach ( [ 'pinova_export_issues', 'pinova_export_incident', 'pinova_review_issue', 'pinova_clear_logs' ] as $action ) {
			$_REQUEST['action'] = $action;
			self::assertSame( [], $this->writes( static fn() => IntegrationHealth::observe_admin() ) );
		}
		$_REQUEST = [];
		wp_set_current_user( 0 );
		self::assertSame( [], $this->writes( static fn() => IntegrationHealth::observe_admin() ) );
		self::assertSame( 0, LogRepository::paginate()['total'] );
	}

	public function test_current_health_remains_visible_when_transition_levels_are_disabled(): void {
		$this->enable();
		update_option( 'pinova_logging', [ 'minimum_level' => 'error', 'retention_days' => 14 ] );
		LogRepository::delete_all();
		IntegrationHealth::observe();
		self::assertSame( 0, LogRepository::paginate()['total'] );
		$report = IssueMonitor::report();
		self::assertTrue( $report['integrations']['wpforo']['requested'] );
		self::assertTrue( $report['integrations']['dokan']['requested'] );
		self::assertContains( $report['integrations']['wpforo']['state'], IntegrationSettings::STATES );
		self::assertContains( $report['integrations']['dokan']['state'], IntegrationSettings::STATES );
		self::assertSame( [], $report['issues'] );
	}

	public function test_competing_claims_admit_only_the_successful_atomic_update(): void {
		global $wpdb;
		$name = 'pinova_integration_health_wpforo';
		$next = [ 'state' => 'unavailable', 'reason' => 'missing_plugin' ];
		update_option( $name, [ 'state' => 'active', 'reason' => 'ready' ], false );
		$interleave = null;
		$interleave = static function ( string $query ) use ( &$interleave, $name ): string {
			if ( str_starts_with( $query, 'UPDATE ' ) && str_contains( $query, $name ) ) {
				remove_filter( 'query', $interleave );
				global $wpdb;
				// A concurrent observer atomically wins before this observer writes.
				self::assertSame( 1, $wpdb->query( $query ) );
				wp_cache_delete( $name, 'options' );
			}
			return $query;
		};
		add_filter( 'query', $interleave );
		try {
			self::assertFalse( ( new \ReflectionMethod( IntegrationHealth::class, 'claim' ) )->invoke( null, 'wpforo', $next ) );
		} finally {
			remove_filter( 'query', $interleave );
		}
		self::assertSame( $next, get_option( $name ) );
		self::assertSame( 0, LogRepository::paginate()['total'] );
	}

	public function test_failed_and_recursive_observation_writes_are_nonfatal(): void {
		$this->enable();
		$throw = static function (): void {
			throw new \RuntimeException( 'private-health-payload' );
		};
		add_filter( 'pre_add_option_pinova_integration_health_wpforo', $throw );
		try {
			IntegrationHealth::observe();
		} finally {
			remove_filter( 'pre_add_option_pinova_integration_health_wpforo', $throw );
		}
		self::assertSame( 1, LogRepository::paginate()['total'] );
		$calls = 0;
		$reenter = static function ( $value ) use ( &$calls ) {
			++$calls;
			IntegrationHealth::observe();
			return $value;
		};
		add_filter( 'pre_add_option_pinova_integration_health_wpforo', $reenter );
		try {
			IntegrationHealth::observe();
		} finally {
			remove_filter( 'pre_add_option_pinova_integration_health_wpforo', $reenter );
		}
		self::assertSame( 1, $calls );
		self::assertSame( 2, LogRepository::paginate()['total'] );
	}

	public function test_failed_atomic_update_preserves_observation_and_database_error_policy(): void {
		global $wpdb;
		$name = 'pinova_integration_health_wpforo';
		$previous = [ 'state' => 'active', 'reason' => 'ready' ];
		update_option( $name, $previous, false );
		$suppressed = $wpdb->suppress_errors;
		$fail = static function ( string $query ) use ( $name ): string {
			return str_starts_with( $query, 'UPDATE ' ) && str_contains( $query, $name )
				? 'SELECT * FROM pinova_missing_health_fixture_table' : $query;
		};
		add_filter( 'query', $fail );
		try {
			self::assertFalse( ( new \ReflectionMethod( IntegrationHealth::class, 'claim' ) )->invoke( null, 'wpforo', [ 'state' => 'unavailable', 'reason' => 'missing_plugin' ] ) );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->last_error = '';
		}
		self::assertSame( $suppressed, $wpdb->suppress_errors );
		self::assertSame( $previous, get_option( $name ) );
		self::assertSame( 0, LogRepository::paginate()['total'] );
	}

	public function test_transition_evidence_is_finite_and_does_not_claim_recovery(): void {
		$rows = [];
		foreach ( [ 'wpforo', 'dokan' ] as $slug ) {
			foreach ( [ 'unavailable' => 'missing_plugin', 'active' => 'ready' ] as $status => $reason ) {
				$rows[] = [
					'id' => count( $rows ) + 1,
					'created_at' => gmdate( 'Y-m-d H:i:s' ),
					'event' => 'integration.' . $slug . '_status_changed',
					'level' => 'warning',
					'context' => wp_json_encode( [ 'status' => $status, 'reason' => $reason, 'password' => 'private-password', 'version' => 'private-version' ] ),
				];
			}
		}
		$issues = IssueMonitor::summarize( $rows );
		self::assertCount( 2, $issues );
		foreach ( $issues as $issue ) {
			self::assertSame( 'compatibility_observation', $issue['category'] );
			self::assertSame( 'root_cause_unconfirmed', $issue['diagnosis'] );
			self::assertSame( 1, $issue['observed_count'] );
		}
		$bad = $rows[0];
		$bad['context'] = wp_json_encode( [ 'reason' => 'private_reason', 'status' => 'private_status' ] );
		self::assertSame( [], EventEvidence::redact( $bad )['context'] );
		$json = wp_json_encode( $issues );
		self::assertStringNotContainsString( 'private-password', $json );
		self::assertStringNotContainsString( 'private-version', $json );
	}

	private function enable(): void {
		update_option( IntegrationSettings::OPTION, [ 'wpforo_enabled' => '1', 'dokan_enabled' => '1' ] );
		LogRepository::delete_all();
	}

	/** @return string[] */
	private function writes( callable $operation ): array {
		$writes = [];
		$watch = static function ( string $query ) use ( &$writes ): string {
			if ( preg_match( '/\A\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER|TRUNCATE)\b/i', $query ) ) {
				$writes[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $watch );
		try {
			$operation();
		} finally {
			remove_filter( 'query', $watch );
		}
		return $writes;
	}
}
