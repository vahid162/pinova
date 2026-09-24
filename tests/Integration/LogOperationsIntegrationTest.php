<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Admin\Logs;
use Pinova\Install;
use Pinova\Logging\LogRepository;
use WP_UnitTestCase;

final class LogOperationsIntegrationTest extends WP_UnitTestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();
		LogRepository::delete_all();
	}

	public function tear_down(): void {
		LogRepository::delete_all();
		parent::tear_down();
	}

	public function test_filters_are_exact_and_incident_batches_are_bounded(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'pinova_logs';
		$base  = [
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
			'level'      => 'warning',
			'user_id'    => 17,
			'context'    => '{"reason":"invalid_nonce"}',
		];
		$wpdb->insert(
			$table,
			$base + [
				'event'          => 'auth.one',
				'correlation_id' => 'abc-1',
				'flow_id'        => str_repeat( 'a', 32 ),
			]
		);
		$wpdb->insert(
			$table,
			$base + [
				'event'          => 'auth.two',
				'correlation_id' => 'abc-2',
				'flow_id'        => str_repeat( 'b', 32 ),
			]
		);

		$filtered = LogRepository::paginate(
			1,
			50,
			'',
			[
				'event'   => 'auth.one',
				'user_id' => 17,
			]
		);
		self::assertSame( 1, $filtered['total'] );
		self::assertSame( 'abc-1', $filtered['rows'][0]['correlation_id'] );
		self::assertSame( 1, LogRepository::paginate( 1, 50, '', [ 'flow_id' => str_repeat( 'b', 32 ) ] )['total'] );
		self::assertFalse( LogRepository::valid_filters( [ 'flow_id' => 'not-a-flow' ] ) );
		self::assertSame( 0, LogRepository::paginate( 1, 50, '', [ 'event' => 'auth.%' ] )['total'] );
		self::assertFalse( LogRepository::valid_filters( [ 'created_from' => '2026-01-01' ] ) );
		self::assertFalse(
			LogRepository::valid_filters(
				[
					'created_from' => '2026-01-01',
					'created_to'   => '2026-05-01',
				]
			)
		);

		$batch = LogRepository::incident_batch( [ 'user_id' => 17 ], 0, 1 );
		self::assertTrue( $batch['success'] );
		self::assertCount( 1, $batch['rows'] );
		self::assertSame( 'auth.two', $batch['rows'][0]['event'] );
		$next = LogRepository::incident_batch( [ 'user_id' => 17 ], (int) $batch['rows'][0]['id'], 1 );
		self::assertSame( 'auth.one', $next['rows'][0]['event'] );
	}

	public function test_incident_redaction_drops_legacy_secrets_and_fingerprints(): void {
		$row      = [
			'id'             => 8,
			'created_at'     => '2026-01-01 00:00:00',
			'level'          => 'warning',
			'event'          => 'auth.request_failed',
			'correlation_id' => 'abc-1',
			'flow_id'        => 'secret-legacy-value',
			'user_id'        => 17,
			'context'        => '{"http_status":403,"attempts":"123","count":"09120000000","duration_ms":9123456789,"identifier_type":"email","identifier_fingerprint":"deadbeef","password":"secret-password","reason":"secret-token"}',
		];
		$exported = Logs::redact_incident_row( $row );
		$json     = wp_json_encode( $exported );

		self::assertSame( 403, $exported['context']['http_status'] );
		self::assertSame( 'email', $exported['context']['identifier_type'] );
		self::assertSame( '', $exported['flow_id'] );
		self::assertArrayNotHasKey( 'attempts', $exported['context'] );
		self::assertArrayNotHasKey( 'count', $exported['context'] );
		self::assertArrayNotHasKey( 'duration_ms', $exported['context'] );
		self::assertStringNotContainsString( '09120000000', $json );
		self::assertStringNotContainsString( 'deadbeef', $json );
		self::assertStringNotContainsString( 'secret-password', $json );
		self::assertStringNotContainsString( 'secret-token', $json );
	}

	public function test_incident_redaction_accepts_only_known_events_and_generated_correlation_ids(): void {
		$row = [
			'created_at'     => '2026-01-01 00:00:00',
			'level'          => 'warning',
			'event'          => '09123456789',
			'correlation_id' => 'token-like-legacy-secret',
			'flow_id'        => 'not-a-flow-id',
			'context'        => '{}',
		];
		$redacted = Logs::redact_incident_row( $row );

		self::assertSame( 'logging.unknown_event', $redacted['event'] );
		self::assertSame( '', $redacted['correlation_id'] );
		self::assertSame( '', $redacted['flow_id'] );
		self::assertStringNotContainsString( '09123456789', (string) wp_json_encode( $redacted ) );
		self::assertStringNotContainsString( 'token-like-legacy-secret', (string) wp_json_encode( $redacted ) );
		foreach ( [ str_repeat( 'a', 32 ), str_repeat( 'b', 64 ), '123e4567-e89b-12d3-a456-426614174000' ] as $legacy_id ) {
			$row['correlation_id'] = $legacy_id;
			self::assertSame( '', Logs::redact_incident_row( $row )['correlation_id'] );
		}

		$row['event']          = 'auth.request_failed';
		$row['correlation_id'] = '123e4567-e89b-42d3-a456-426614174000';
		$row['flow_id']        = str_repeat( 'a', 32 );
		$redacted             = Logs::redact_incident_row( $row );

		self::assertSame( 'auth.request_failed', $redacted['event'] );
		self::assertSame( $row['correlation_id'], $redacted['correlation_id'] );
		self::assertSame( $row['flow_id'], $redacted['flow_id'] );
	}

	public function test_authorized_incident_export_is_exact_redacted_and_audited(): void {
		global $wpdb;

		$administrator = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $administrator );
		update_option(
			'pinova_logging',
			[
				'minimum_level'    => 'error',
				'retention_days'   => 14,
				'diagnostic_until' => 0,
			]
		);

		$table = $wpdb->prefix . 'pinova_logs';
		$today = gmdate( 'Y-m-d' );
		$base  = [
			'created_at'     => $today . ' 12:00:00',
			'level'          => 'warning',
			'correlation_id' => '123e4567-e89b-42d3-a456-426614174000',
			'flow_id'        => str_repeat( 'c', 32 ),
			'user_id'        => $administrator,
			'context'        => '{"http_status":403,"attempts":"123","count":"09120000000","duration_ms":9123456789,"identifier_fingerprint":"secret-fingerprint","password":"secret-password"}',
		];
		$wpdb->insert( $table, $base + [ 'event' => 'auth.request_failed' ] );
		$wpdb->insert( $table, $base + [ 'event' => 'auth.password_failed' ] );

		$json = $this->capture_export(
			[
				'event'        => 'auth.request_failed',
				'level'        => 'warning',
				'created_from' => $today,
				'created_to'   => $today,
			]
		);
		$data = json_decode( $json, true );

		self::assertIsArray( $data );
		self::assertSame( PINOVA_VERSION, $data['plugin_version'] );
		self::assertSame( 'source', $data['build']['package_identity'] );
		self::assertSame( [ $today, $today ], $data['selected_range'] );
		self::assertSame( 'warning', $data['level_filter'] );
		self::assertCount( 1, $data['events'] );
		self::assertSame( 'auth.request_failed', $data['events'][0]['event'] );
		self::assertSame( $base['correlation_id'], $data['events'][0]['correlation_id'] );
		self::assertSame( str_repeat( 'c', 32 ), $data['events'][0]['flow_id'] );
		self::assertSame( 403, $data['events'][0]['context']['http_status'] );
		self::assertArrayNotHasKey( 'attempts', $data['events'][0]['context'] );
		self::assertArrayNotHasKey( 'count', $data['events'][0]['context'] );
		self::assertArrayNotHasKey( 'duration_ms', $data['events'][0]['context'] );
		self::assertStringNotContainsString( '09120000000', $json );
		self::assertStringNotContainsString( 'secret-', $json );
		self::assertFalse( $data['truncated'] );
		self::assertTrue( $data['complete'] );
		self::assertLessThanOrEqual( 2097152, strlen( $json ) );

		$audit = $wpdb->get_row( $wpdb->prepare( 'SELECT `user_id`, `context` FROM %i WHERE `event` = %s ORDER BY `id` DESC LIMIT 1', $table, 'logging.incident_exported' ), ARRAY_A );
		self::assertIsArray( $audit );
		self::assertSame( (string) $administrator, $audit['user_id'] );
		self::assertSame( 1, json_decode( $audit['context'], true )['count'] );
	}

	public function test_incident_export_and_viewer_hide_legacy_secrets_in_record_fields(): void {
		global $wpdb;

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$wpdb->insert(
			$wpdb->prefix . 'pinova_logs',
			[
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
				'level'          => 'warning',
				'event'          => 'otp.09123456789',
				'correlation_id' => 'token-like-legacy-secret',
				'flow_id'        => 'not-a-flow-id',
				'context'        => '{}',
			]
		);

		$json = $this->capture_export( [] );
		$data = json_decode( $json, true );
		self::assertIsArray( $data );
		self::assertSame( 'logging.unknown_event', $data['events'][0]['event'] );
		self::assertSame( '', $data['events'][0]['correlation_id'] );
		self::assertSame( '', $data['events'][0]['flow_id'] );
		self::assertStringNotContainsString( '09123456789', $json );
		self::assertStringNotContainsString( 'token-like-legacy-secret', $json );

		$original_get  = $_GET;
		$_GET         = [];
		$buffer_level = ob_get_level();
		ob_start();
		try {
			Logs::render();
			$html = (string) ob_get_clean();
		} finally {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			$_GET = $original_get;
		}
		self::assertStringContainsString( 'logging.unknown_event', $html );
		self::assertStringNotContainsString( '09123456789', $html );
		self::assertStringNotContainsString( 'token-like-legacy-secret', $html );
		$wpdb->insert(
			$wpdb->prefix . 'pinova_logs',
			[
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'level'      => 'warning',
				'event'      => 'Auth.Request_Failed',
				'context'    => '{}',
			]
		);

		$filtered = LogRepository::paginate( 1, 50, '', [ 'event' => 'logging.unknown_event' ] );
		self::assertSame( 2, $filtered['total'] );
		self::assertSame( 0, LogRepository::paginate( 1, 50, '', [ 'event' => 'auth.request_failed' ] )['total'] );
		self::assertSame( 'logging.unknown_event', Logs::redact_incident_row( $filtered['rows'][0] )['event'] );
		$batch = LogRepository::incident_batch( [ 'event' => 'logging.unknown_event' ] );
		self::assertTrue( $batch['success'] );
		self::assertCount( 2, $batch['rows'] );
		self::assertSame( 'logging.unknown_event', Logs::redact_incident_row( $batch['rows'][0] )['event'] );
		$filtered_json = $this->capture_export( [ 'event' => 'logging.unknown_event' ] );
		$filtered_data = json_decode( $filtered_json, true );
		self::assertCount( 2, $filtered_data['events'] );
		self::assertSame( 'logging.unknown_event', $filtered_data['events'][0]['event'] );
		self::assertStringNotContainsString( '09123456789', $filtered_json );
	}

	public function test_failed_manual_clear_does_not_claim_or_audit_success(): void {
		global $wpdb;

		self::assertSame( 0, LogRepository::delete_all(), 'An empty table is a successful deletion.' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$table = $wpdb->prefix . 'pinova_logs';
		$wpdb->insert(
			$table,
			[
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
				'level'          => 'warning',
				'event'          => 'auth.request_failed',
				'correlation_id' => '123e4567-e89b-42d3-a456-426614174000',
				'context'        => '{}',
			]
		);

		$fail_delete = static function ( string $query ) use ( $table ): string {
			return str_starts_with( $query, 'DELETE FROM ' ) && str_contains( $query, $table )
				? 'SELECT `pinova_missing_column` FROM ' . $table . ' LIMIT 1'
				: $query;
		};
		$die_handler = static function (): callable {
			return static function ( $message, $title, $args ): void {
				throw new \RuntimeException( (string) $args['response'] );
			};
		};
		$original_request = $_REQUEST;
		$previous_errors  = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail_delete );
		add_filter( 'wp_die_handler', $die_handler );
		try {
			self::assertFalse( LogRepository::delete_all() );
			$_REQUEST = [ '_wpnonce' => wp_create_nonce( 'pinova_clear_logs' ) ];
			try {
				( new Logs() )->clear();
				self::fail( 'Expected database failure to prevent clear success.' );
			} catch ( \RuntimeException $exception ) {
				self::assertSame( '503', $exception->getMessage() );
			}
		} finally {
			$_REQUEST = $original_request;
			remove_filter( 'query', $fail_delete );
			remove_filter( 'wp_die_handler', $die_handler );
			$wpdb->suppress_errors( $previous_errors );
		}

		self::assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) );
		self::assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE `event` = %s', $table, 'logging.cleared' ) ) );
	}

	public function test_incident_export_marks_the_row_limit(): void {
		global $wpdb;

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$table = $wpdb->prefix . 'pinova_logs';
		$today = gmdate( 'Y-m-d' );
		for ( $index = 0; $index < 1001; ++$index ) {
			$wpdb->insert(
				$table,
				[
					'created_at'     => $today . ' 12:00:00',
					'level'          => 'warning',
					'event'          => 'auth.cap_test',
					'correlation_id' => 'cap-test',
					'context'        => '{}',
				]
			);
		}

		$json = $this->capture_export( [ 'event' => 'auth.cap_test' ] );
		$data = json_decode( $json, true );
		self::assertIsArray( $data );
		self::assertCount( 1000, $data['events'] );
		self::assertTrue( $data['truncated'] );
		self::assertTrue( $data['complete'] );
		self::assertLessThanOrEqual( 2097152, strlen( $json ) );
	}

	public function test_incident_export_rejects_partial_and_array_filters(): void {
		global $wpdb;

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$today = gmdate( 'Y-m-d' );
		$wpdb->insert(
			$wpdb->prefix . 'pinova_logs',
			[
				'created_at'     => $today . ' 12:00:00',
				'level'          => 'warning',
				'event'          => 'auth.should_not_export',
				'correlation_id' => 'partial-range',
				'context'        => '{}',
			]
		);
		$handler = static function (): callable {
			return static function ( $message, $title, $args ): void {
				throw new \RuntimeException( (string) $args['response'] );
			};
		};
		add_filter( 'wp_die_handler', $handler );
		$original_post    = $_POST;
		$original_request = $_REQUEST;
		try {
			foreach ( [ [ 'created_from' => $today ], [ 'event' => [ 'auth.should_not_export' ] ], [ 'level' => [ 'warning' ] ] ] as $filters ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Supplying a valid nonce to test malformed filters.
				$_POST = $filters + [ '_wpnonce' => wp_create_nonce( 'pinova_export_incident' ) ];
				$_REQUEST = $_POST;
				try {
					( new \ReflectionMethod( Logs::class, 'export_incident_response' ) )->invoke( new Logs() );
					self::fail( 'Expected malformed export filter to be rejected.' );
				} catch ( \RuntimeException $exception ) {
					self::assertSame( '400', $exception->getMessage() );
				}
			}
		} finally {
			$_POST    = $original_post;
			$_REQUEST = $original_request;
			remove_filter( 'wp_die_handler', $handler );
		}
	}

	public function test_array_valued_viewer_filters_do_not_broaden_or_throw(): void {
		global $wpdb;

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$wpdb->insert(
			$wpdb->prefix . 'pinova_logs',
			[
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
				'level'          => 'warning',
				'event'          => 'auth.should_not_render',
				'correlation_id' => 'invalid-viewer',
				'context'        => '{}',
			]
		);
		$original_get = $_GET;
		try {
			foreach ( [ [ 'event' => [ 'auth.should_not_render' ] ], [ 'level' => [ 'warning' ] ], [ 'paged' => [ '1' ], 'user_id' => [ '2' ] ] ] as $filters ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Deliberately testing malformed read-only filters.
				$_GET = $filters;
				$buffer_level = ob_get_level();
				ob_start();
				try {
					Logs::render();
					$html = ob_get_clean();
				} finally {
					while ( ob_get_level() > $buffer_level ) {
						ob_end_clean();
					}
				}
				self::assertStringNotContainsString( '<code>auth.should_not_render</code>', $html );
			}
		} finally {
			$_GET = $original_get;
		}
	}

	/** @param array<string,mixed> $filters */
	private function capture_export( array $filters ): string {
		$original_post    = $_POST;
		$original_request = $_REQUEST;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Supplying a valid nonce to exercise the authorized response.
		$_POST = $filters + [ '_wpnonce' => wp_create_nonce( 'pinova_export_incident' ) ];
		$_REQUEST = $_POST;
		$buffer_level = ob_get_level();
		ob_start();
		try {
			( new \ReflectionMethod( Logs::class, 'export_incident_response' ) )->invoke( new Logs(), false );
			return (string) ob_get_clean();
		} finally {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			$_POST    = $original_post;
			$_REQUEST = $original_request;
		}
	}

	public function test_incident_export_rejects_missing_capability_and_nonce(): void {
		$handler = static function (): callable {
			return static function (): void {
				throw new \RuntimeException( 'denied' );
			};
		};
		add_filter( 'wp_die_handler', $handler );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Deliberately testing that an absent nonce is rejected.
		$original_post = $_POST;
		$_POST         = [];

		try {
			wp_set_current_user( 0 );
			try {
				( new Logs() )->export_incident();
				self::fail( 'Expected capability denial.' );
			} catch ( \RuntimeException $exception ) {
				self::assertSame( 'denied', $exception->getMessage() );
			}

			wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
			try {
				( new Logs() )->export_incident();
				self::fail( 'Expected nonce denial.' );
			} catch ( \RuntimeException $exception ) {
				self::assertSame( 'denied', $exception->getMessage() );
			}
		} finally {
			$_POST = $original_post;
			remove_filter( 'wp_die_handler', $handler );
		}
	}
}
