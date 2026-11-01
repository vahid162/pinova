<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Admin\Settings;
use Pinova\Install;
use Pinova\Logging\EventThrottle;

final class DiagnosticSettingsIntegrationTest extends \WP_UnitTestCase {

	private Settings $settings;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();
		$this->settings = Settings::instance();
		$this->reset_normalization();
		delete_option( 'pinova_logging' );
		$this->clear_budget();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down(): void {
		delete_option( 'pinova_logging' );
		$this->clear_budget();
		$this->reset_normalization();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_first_add_survives_wordpress_double_sanitization_and_initializes_once(): void {
		$writes = [];
		$passes = 0;
		$count = static function ( mixed $value ) use ( &$passes ): mixed {
			$passes = min( 3, $passes + 1 );
			return $value;
		};
		$watch = static function ( string $query ) use ( &$writes ): string {
			if ( str_contains( $query, EventThrottle::diagnostic_bucket_key() ) && str_starts_with( $query, 'INSERT' ) ) {
				$writes[] = $query;
			}
			return $query;
		};
		$before = time();
		add_filter( 'query', $watch );
		add_filter( 'sanitize_option_pinova_logging', $count, PHP_INT_MAX );
		try {
			self::assertTrue( update_option( 'pinova_logging', $this->activation() ) );
		} finally {
			remove_filter( 'query', $watch );
			remove_filter( 'sanitize_option_pinova_logging', $count, PHP_INT_MAX );
		}
		$value = get_option( 'pinova_logging' );
		self::assertGreaterThanOrEqual( $before + 900, $value['diagnostic_until'] );
		self::assertLessThanOrEqual( time() + 900, $value['diagnostic_until'] );
		self::assertSame( [ 'type' => 'operation', 'value' => 'queued_otp' ], $value['diagnostic_scope'] );
		self::assertTrue( $value['diagnostic_budget_initialized'] );
		self::assertSame( 2, $passes, 'An absent update must exercise both update_option and add_option sanitization.' );
		self::assertCount( 1, $writes );
		self::assertTrue( EventThrottle::diagnostics_snapshot()['read_success'] );
		self::assertFalse( update_option( 'pinova_logging', $value ), 'Saving normalized settings must be a real WordPress no-op.' );
	}

	public function test_keep_noop_off_and_reenable_preserve_the_original_budget(): void {
		update_option( 'pinova_logging', $this->activation() );
		$first = get_option( 'pinova_logging' );
		self::assertTrue( EventThrottle::admit_diagnostic( str_repeat( 'a', 32 ), time() + 60 ) );
		$budget = $this->budget();
		update_option( 'pinova_logging', [ 'minimum_level' => 'error', 'diagnostic_until' => 'keep' ] );
		$kept = get_option( 'pinova_logging' );
		self::assertSame( $first['diagnostic_until'], $kept['diagnostic_until'] );
		self::assertSame( 'error', $kept['minimum_level'] );
		self::assertFalse( update_option( 'pinova_logging', $kept ) );
		self::assertSame( $kept, get_option( 'pinova_logging' ) );
		update_option( 'pinova_logging', [ 'diagnostic_until' => 0 ] );
		self::assertSame( 0, get_option( 'pinova_logging' )['diagnostic_until'] );
		self::assertTrue( get_option( 'pinova_logging' )['diagnostic_budget_initialized'] );
		update_option( 'pinova_logging', $this->activation( 3600 ) );
		self::assertSame( $budget, $this->budget() );
	}

	public function test_previously_initialized_missing_or_corrupt_budget_is_never_recreated(): void {
		global $wpdb;
		update_option( 'pinova_logging', $this->activation() );
		$this->clear_budget();
		update_option( 'pinova_logging', $this->activation( 3600 ) );
		self::assertNull( $this->budget() );
		self::assertSame( 'unknown', EventThrottle::diagnostics_snapshot()['coverage'] );
		$wpdb->insert(
			$wpdb->prefix . 'pinova_rate_limits',
			[
				'bucket_key' => EventThrottle::diagnostic_bucket_key(),
				'scope' => 'log_diagnostics',
				'hits' => 7,
				'reset_at' => gmdate( 'Y-m-d H:i:s', time() + 900 ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
				'payload' => 'PRIVATE_CORRUPT_TEST_STATE',
			]
		);
		$corrupt = $this->budget();
		update_option( 'pinova_logging', $this->activation( 86400 ) );
		self::assertSame( $corrupt, $this->budget() );
		self::assertSame( 'unknown', EventThrottle::diagnostics_snapshot()['coverage'] );
	}

	public function test_unauthorized_saves_preserve_diagnostics_and_cannot_forge_initialization(): void {
		update_option( 'pinova_logging', $this->activation() );
		$previous = get_option( 'pinova_logging' );
		$budget = $this->budget();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		update_option( 'pinova_logging', [
			'minimum_level' => 'info',
			'diagnostic_until' => 86400,
			'diagnostic_scope' => [ 'type' => 'flow', 'value' => str_repeat( 'b', 32 ) ],
			'diagnostic_budget_initialized' => false,
		] );
		$current = get_option( 'pinova_logging' );
		self::assertSame( 'info', $current['minimum_level'], 'Existing programmatic ordinary-level sanitization is retained.' );
		foreach ( [ 'diagnostic_until', 'diagnostic_scope', 'diagnostic_budget_initialized' ] as $key ) {
			self::assertSame( $previous[ $key ], $current[ $key ] );
		}
		self::assertSame( $budget, $this->budget() );
		delete_option( 'pinova_logging' );
		$this->clear_budget();
		update_option( 'pinova_logging', $this->activation() + [ 'diagnostic_budget_initialized' => true ] );
		self::assertArrayNotHasKey( 'diagnostic_budget_initialized', get_option( 'pinova_logging' ) );
		self::assertNull( $this->budget() );
	}

	/** @dataProvider invalid_activations */
	public function test_invalid_scope_or_arbitrary_epoch_does_not_activate( mixed $until, mixed $scope ): void {
		update_option( 'pinova_logging', [ 'diagnostic_until' => $until, 'diagnostic_scope' => $scope ] );
		$value = get_option( 'pinova_logging' );
		self::assertSame( 0, $value['diagnostic_until'] );
		self::assertArrayNotHasKey( 'diagnostic_budget_initialized', $value );
		self::assertNull( $this->budget() );
	}

	public static function invalid_activations(): array {
		return [
			'future epoch' => [ 2000000000, [ 'type' => 'operation', 'value' => 'queued_otp' ] ],
			'negative duration' => [ -900, [ 'type' => 'operation', 'value' => 'queued_otp' ] ],
			'array duration' => [ [ 900 ], [ 'type' => 'operation', 'value' => 'queued_otp' ] ],
			'unscoped' => [ 900, [] ],
			'unknown operation' => [ 900, [ 'type' => 'operation', 'value' => 'PRIVATE_OPERATION' ] ],
			'uppercase flow' => [ 900, [ 'type' => 'flow', 'value' => str_repeat( 'A', 32 ) ] ],
			'adjacent scope secret' => [ 900, [ 'type' => 'operation', 'value' => 'queued_otp', 'secret' => 'PRIVATE_VALUE' ] ],
		];
	}

	public function test_legacy_window_is_not_repaired_by_reads_or_extended_by_keep(): void {
		global $wpdb;
		$until = time() + 500;
		$wpdb->insert( $wpdb->options, [ 'option_name' => 'pinova_logging', 'option_value' => maybe_serialize( [ 'minimum_level' => 'warning', 'diagnostic_until' => $until ] ), 'autoload' => 'no' ] );
		$this->clear_option_caches();
		self::assertSame( $until, get_option( 'pinova_logging' )['diagnostic_until'] );
		self::assertNull( $this->budget() );
		update_option( 'pinova_logging', [ 'diagnostic_until' => 'keep' ] );
		$value = get_option( 'pinova_logging' );
		self::assertSame( $until, $value['diagnostic_until'] );
		self::assertSame( [], $value['diagnostic_scope'] );
		self::assertArrayNotHasKey( 'diagnostic_budget_initialized', $value );
		self::assertNull( $this->budget() );
		update_option( 'pinova_logging', [ 'diagnostic_until' => 'keep', 'diagnostic_scope_type' => '', 'diagnostic_operation' => 'queued_otp', 'diagnostic_flow_id' => '' ] );
		self::assertSame( $until, get_option( 'pinova_logging' )['diagnostic_until'] );
		self::assertNull( $this->budget() );
	}

	public function test_failed_previous_option_read_is_not_permission_to_initialize(): void {
		global $wpdb;
		$this->clear_option_caches();
		$attempts = 0;
		$fail = static function ( string $query ) use ( &$attempts ): string {
			if ( str_starts_with( $query, 'SELECT option_value FROM ' ) && str_contains( $query, "'pinova_logging'" ) ) {
				return 'SELECT option_value FROM pinova_test_missing_diagnostic_option';
			}
			if ( str_starts_with( $query, 'INSERT' ) && str_contains( $query, EventThrottle::diagnostic_bucket_key() ) ) {
				++$attempts;
			}
			return $query;
		};
		$old_policy = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			$value = apply_filters( 'sanitize_option_pinova_logging', $this->activation(), 'pinova_logging', [] );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $old_policy );
		}
		self::assertSame( 0, $attempts );
		self::assertSame( 0, $value['diagnostic_until'] );
		self::assertSame( [], $value['diagnostic_scope'] );
		self::assertTrue( $value['diagnostic_budget_initialized'], 'An uncertain prior marker must not be erased and later treated as absent.' );
		self::assertNull( $this->budget() );
	}

	public function test_a_corrupt_preexisting_marker_is_not_treated_as_first_activation(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->options, [ 'option_name' => 'pinova_logging', 'option_value' => maybe_serialize( [ 'diagnostic_budget_initialized' => false ] ), 'autoload' => 'no' ] );
		$this->clear_option_caches();
		update_option( 'pinova_logging', $this->activation() );
		self::assertFalse( get_option( 'pinova_logging' )['diagnostic_budget_initialized'] );
		self::assertNull( $this->budget(), 'Presence of a prior marker, even corrupt, cannot authorize repair.' );
	}

	public function test_failed_initialization_does_not_activate_or_claim_an_existing_budget(): void {
		global $wpdb;
		$fail = static function ( string $query ): string {
			return str_starts_with( $query, 'INSERT' ) && str_contains( $query, EventThrottle::diagnostic_bucket_key() )
				? 'INSERT INTO pinova_test_missing_diagnostic_budget (value) VALUES (1)' : $query;
		};
		$old_policy = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			update_option( 'pinova_logging', $this->activation() );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $old_policy );
		}
		$value = get_option( 'pinova_logging' );
		self::assertSame( 0, $value['diagnostic_until'] );
		self::assertArrayNotHasKey( 'diagnostic_budget_initialized', $value );
		self::assertNull( $this->budget() );
	}

	public function test_form_controls_select_explicit_scope_without_exporting_the_sticky_marker(): void {
		update_option( 'pinova_logging', $this->activation() );
		ob_start();
		$this->settings->callback_diagnostic_window( [ 'section' => 'pinova_logging', 'id' => 'diagnostic_until' ] );
		$this->settings->callback_diagnostic_scope( [ 'section' => 'pinova_logging', 'id' => 'diagnostic_scope' ] );
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'value="keep"', $html );
		self::assertStringContainsString( 'name="pinova_logging[diagnostic_scope_type]"', $html );
		self::assertStringContainsString( 'name="pinova_logging[diagnostic_operation]"', $html );
		self::assertStringContainsString( 'name="pinova_logging[diagnostic_flow_id]"', $html );
		self::assertStringNotContainsString( 'diagnostic_budget_initialized', $html );
		update_option( 'pinova_logging', [ 'diagnostic_until' => 'keep', 'diagnostic_scope_type' => 'flow', 'diagnostic_flow_id' => str_repeat( 'c', 32 ), 'diagnostic_operation' => 'verify_otp' ] );
		$value = get_option( 'pinova_logging' );
		self::assertSame( [ 'type' => 'flow', 'value' => str_repeat( 'c', 32 ) ], $value['diagnostic_scope'] );
		self::assertArrayNotHasKey( 'diagnostic_scope_type', $value );
		self::assertArrayNotHasKey( 'diagnostic_flow_id', $value );
	}

	/** @return array<string,mixed> */
	private function activation( int $seconds = 900 ): array {
		return [ 'minimum_level' => 'warning', 'retention_days' => 14, 'diagnostic_until' => $seconds, 'diagnostic_scope' => [ 'type' => 'operation', 'value' => 'queued_otp' ] ];
	}

	private function reset_normalization(): void {
		$property = new \ReflectionProperty( Settings::class, 'logging_normalization' );
		$property->setValue( null, null );
		$failed = new \ReflectionProperty( Settings::class, 'logging_read_failed' );
		$failed->setValue( null, false );
	}

	private function clear_option_caches(): void {
		wp_cache_delete( 'pinova_logging', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	private function clear_budget(): void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'pinova_rate_limits', [ 'bucket_key' => EventThrottle::diagnostic_bucket_key() ] );
	}

	private function budget(): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE bucket_key = %s', $wpdb->prefix . 'pinova_rate_limits', EventThrottle::diagnostic_bucket_key() ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}
}
