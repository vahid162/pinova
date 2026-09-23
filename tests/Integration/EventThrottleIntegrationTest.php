<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Install;
use Pinova\Logging\EventThrottle;
use Pinova\Logging\LogRepository;
use WP_UnitTestCase;

final class EventThrottleIntegrationTest extends WP_UnitTestCase {
	/** @var array<string, mixed> */
	private array $original_server;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->original_server = $_SERVER;
		$_SERVER['REMOTE_ADDR'] = '192.0.2.80';
		$wpdb->query( "DELETE FROM {$wpdb->prefix}pinova_rate_limits WHERE scope IN ('log_logout_rejected', 'log_reset_failed', 'log_reset_succeeded')" );
		LogRepository::delete_all();
		update_option( 'pinova_logging', [ 'minimum_level' => 'info', 'diagnostic_until' => 0 ] );
	}

	public function tear_down(): void {
		$_SERVER = $this->original_server;
		LogRepository::delete_all();
		delete_option( 'pinova_logging' );
		parent::tear_down();
	}

	public function test_duplicate_attempts_and_changed_reasons_share_one_source_budget(): void {
		for ( $i = 0; $i < 5; ++$i ) {
			EventThrottle::log( 'auth.password_reset_failed', [ 'reason' => 'failure_' . $i, 'password' => 'secret-password' ] );
		}
		self::assertSame( 1, LogRepository::paginate()['total'] );
		$context = LogRepository::paginate()['rows'][0]['context'];
		self::assertStringNotContainsString( 'secret-password', $context );
		self::assertStringNotContainsString( '192.0.2.80', $context );
	}

	public function test_source_and_site_windows_reopen_without_new_bucket_keys(): void {
		global $wpdb;
		EventThrottle::log( 'auth.logout_rejected' );
		$before = $wpdb->get_col( "SELECT bucket_key FROM {$wpdb->prefix}pinova_rate_limits WHERE scope = 'log_logout_rejected' ORDER BY bucket_key" );
		$wpdb->query( "UPDATE {$wpdb->prefix}pinova_rate_limits SET reset_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE scope = 'log_logout_rejected'" );
		EventThrottle::log( 'auth.logout_rejected' );
		$after = $wpdb->get_col( "SELECT bucket_key FROM {$wpdb->prefix}pinova_rate_limits WHERE scope = 'log_logout_rejected' ORDER BY bucket_key" );
		self::assertSame( $before, $after );
		self::assertCount( 2, $after );
		self::assertSame( 2, LogRepository::paginate()['total'] );
	}

	public function test_rotating_sources_cannot_exceed_site_budget_or_grow_the_counter(): void {
		global $wpdb;
		$slots = [];
		for ( $i = 1; count( $slots ) < 105; ++$i ) {
			$ip = '198.51.' . intdiv( $i, 256 ) . '.' . ( $i % 256 );
			$slot = hexdec( substr( hash_hmac( 'sha256', inet_pton( $ip ), wp_salt( 'auth' ) ), 0, 4 ) ) % 1024;
			if ( isset( $slots[ $slot ] ) ) {
				continue;
			}
			$slots[ $slot ] = true;
			$_SERVER['REMOTE_ADDR'] = $ip;
			EventThrottle::log( 'auth.password_reset_succeeded' );
		}
		self::assertSame( 100, LogRepository::paginate()['total'] );
		$site_key = hash( 'sha256', 'pinova:event-throttle:log_reset_succeeded:site' );
		self::assertSame( '100', $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM {$wpdb->prefix}pinova_rate_limits WHERE bucket_key = %s", $site_key ) ) );
	}

	public function test_fixed_source_slot_collisions_only_suppress_observation(): void {
		$slots = [];
		for ( $i = 1; $i <= 1025; ++$i ) {
			$ip = '198.51.' . intdiv( $i, 256 ) . '.' . ( $i % 256 );
			$slot = hexdec( substr( hash_hmac( 'sha256', inet_pton( $ip ), wp_salt( 'auth' ) ), 0, 4 ) ) % 1024;
			if ( isset( $slots[ $slot ] ) ) {
				foreach ( [ $slots[ $slot ], $ip ] as $source ) {
					$_SERVER['REMOTE_ADDR'] = $source;
					EventThrottle::log( 'auth.logout_rejected' );
				}
				self::assertSame( 1, LogRepository::paginate()['total'] );
				return;
			}
			$slots[ $slot ] = $ip;
		}
		self::fail( 'More sources than fixed slots must collide.' );
	}

	public function test_competing_source_consumption_allows_only_the_atomic_write_winner(): void {
		$interleave = null;
		$interleave = static function ( string $query ) use ( &$interleave ): string {
			if ( str_starts_with( $query, 'UPDATE ' ) && str_contains( $query, 'pinova_rate_limits' ) ) {
				remove_filter( 'query', $interleave );
				// Another request wins between this request's insert and conditional update.
				EventThrottle::log( 'auth.logout_rejected', [ 'reason' => 'winning_request' ] );
			}
			return $query;
		};
		add_filter( 'query', $interleave );
		try {
			EventThrottle::log( 'auth.logout_rejected', [ 'reason' => 'losing_request' ] );
		} finally {
			remove_filter( 'query', $interleave );
		}
		self::assertSame( 1, LogRepository::paginate()['total'] );
		self::assertStringContainsString( 'winning_request', LogRepository::paginate()['rows'][0]['context'] );
	}

	public function test_competing_site_consumption_cannot_overdraw_the_last_available_slot(): void {
		global $wpdb;
		$site_key = hash( 'sha256', 'pinova:event-throttle:log_logout_rejected:site' );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}pinova_rate_limits (bucket_key, scope, hits, reset_at, updated_at) VALUES (%s, 'log_logout_rejected', 99, UTC_TIMESTAMP() + INTERVAL 15 MINUTE, UTC_TIMESTAMP())", $site_key ) );
		$interleave = null;
		$interleave = static function ( string $query ) use ( &$interleave, $site_key ): string {
			if ( str_starts_with( $query, 'UPDATE ' ) && str_contains( $query, $site_key ) ) {
				remove_filter( 'query', $interleave );
				global $wpdb;
				// Another source wins this same atomic site-budget update first.
				self::assertSame( 1, $wpdb->query( $query ) );
			}
			return $query;
		};
		add_filter( 'query', $interleave );
		try {
			EventThrottle::log( 'auth.logout_rejected' );
		} finally {
			remove_filter( 'query', $interleave );
		}
		self::assertSame( 0, LogRepository::paginate()['total'] );
		self::assertSame( '100', $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM {$wpdb->prefix}pinova_rate_limits WHERE bucket_key = %s", $site_key ) ) );
	}

	/** @dataProvider unavailable_backend_modes */
	public function test_database_failure_denies_emission_and_restores_error_mode( bool $throw ): void {
		global $wpdb;
		$before = $wpdb->suppress_errors;
		$fail = static function ( string $query ) use ( $throw ): string {
			if ( str_contains( $query, 'INSERT IGNORE INTO' ) && str_contains( $query, 'pinova_rate_limits' ) ) {
				if ( $throw ) {
					throw new \RuntimeException( 'private-database-failure' );
				}
				return 'INSERT INTO pinova_test_missing_throttle_table (invalid_column) VALUES (1)';
			}
			return $query;
		};
		add_filter( 'query', $fail );
		try {
			EventThrottle::log( 'auth.logout_rejected' );
		} finally {
			remove_filter( 'query', $fail );
		}
		self::assertSame( $before, $wpdb->suppress_errors );
		self::assertSame( 0, LogRepository::paginate()['total'] );
	}

	/** @return array<string, array{bool}> */
	public function unavailable_backend_modes(): array {
		return [ 'false query result' => [ false ], 'throwable query' => [ true ] ];
	}

	public function test_unknown_events_are_not_recorded_and_normal_threshold_is_respected(): void {
		global $wpdb;
		EventThrottle::log( 'arbitrary.attacker.event' );
		self::assertSame( '0', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pinova_rate_limits WHERE scope IN ('log_logout_rejected', 'log_reset_failed', 'log_reset_succeeded')" ) );
		update_option( 'pinova_logging', [ 'minimum_level' => 'error', 'diagnostic_until' => 0 ] );
		EventThrottle::log( 'auth.logout_rejected' );
		self::assertSame( 0, LogRepository::paginate()['total'] );
		self::assertSame( '0', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pinova_rate_limits WHERE scope IN ('log_logout_rejected', 'log_reset_failed', 'log_reset_succeeded')" ) );
	}
}
