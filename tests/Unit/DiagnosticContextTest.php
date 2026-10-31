<?php

declare(strict_types=1);

namespace Pinova\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pinova\Logging\DiagnosticContext;
use Pinova\Logging\HandlerInterface;
use Pinova\Logging\Logger;
use Pinova\Logging\SafeContext;

final class DiagnosticContextTest extends TestCase {

	public function test_stage_catalogue_contains_only_possible_finite_observations(): void {
		self::assertSame( [ 'queue_accepted', 'immediate_admission', 'worker_started', 'provider_started', 'provider_completed', 'channel_completed', 'delivery_completed', 'verification_completed' ], DiagnosticContext::stage_names() );
	}

	public function test_observe_is_memory_only_and_flush_preserves_the_actual_occurrence_time(): void {
		$now          = time();
		$observed     = $now;
		$deadline     = $now + 60;
		$config_reads = 0;
		$admissions   = 0;
		$handler      = new DiagnosticMemoryHandler();
		$logger       = new Logger(
			$handler,
			new SafeContext(),
			static function () use ( &$config_reads, $deadline ): array {
				++$config_reads;
				return [
					'minimum_level'    => 'error',
					'diagnostic_until' => $deadline,
					'diagnostic_scope' => [ 'type' => 'operation', 'value' => 'queued_otp' ],
				];
			},
			static fn(): string => '12345678-1234-4234-8234-123456789abc',
			static function ( string $flow, int $expiry ) use ( &$admissions, $deadline ): bool {
				++$admissions;
				self::assertSame( str_repeat( 'a', 32 ), $flow );
				self::assertSame( $deadline, $expiry );
				return true;
			}
		);
		$context      = new DiagnosticContext( str_repeat( 'a', 32 ), $deadline, 'queued_otp', static function () use ( &$now ): int { return $now; } );

		$context->observe( 'worker_started', [ 'execution_mode' => 'cron', 'request_to_worker_seconds' => 31, 'observed_at' => 1, 'password' => 'private-password' ] );
		$context->observe( 'provider_completed', [ 'execution_mode' => 'cron', 'result' => 'accepted', 'provider_duration_ms' => 250 ] );
		self::assertSame( 0, $config_reads );
		self::assertSame( 0, $admissions );
		self::assertSame( [], $handler->records );

		$now += 2;
		$context->flush( $logger );
		self::assertCount( 2, $handler->records );
		self::assertSame( 2, $admissions );
		foreach ( $handler->records as $record ) {
			self::assertSame( 'otp.diagnostic', $record['event'] );
			self::assertSame( 'debug', $record['level'] );
			self::assertSame( str_repeat( 'a', 32 ), $record['flow_id'] );
			self::assertSame( $observed, $record['context']['observed_at'] );
			self::assertStringNotContainsString( 'private', (string) json_encode( $record ) );
		}
		$context->flush( $logger );
		self::assertCount( 2, $handler->records, 'A second flush must never replay buffered observations.' );
		self::assertSame( 2, $admissions );
	}

	public function test_one_context_cannot_buffer_more_than_twelve_observations_or_reopen_after_flush(): void {
		$handler = new DiagnosticMemoryHandler();
		$logger  = new Logger(
			$handler,
			new SafeContext(),
			static fn(): array => [
				'minimum_level'    => 'error',
				'diagnostic_until' => time() + 60,
				'diagnostic_scope' => [ 'type' => 'operation', 'value' => 'queued_otp' ],
			],
			null,
			static fn(): bool => true
		);
		$context = new DiagnosticContext( str_repeat( 'a', 32 ), time() + 60, 'queued_otp' );
		for ( $index = 0; $index < 13; ++$index ) {
			$context->observe( 'worker_started', [ 'execution_mode' => 'cron' ] );
		}
		$context->flush( $logger );
		self::assertCount( 12, $handler->records );
		self::assertSame( 'incomplete', $context->coverage() );
		$context->observe( 'worker_started', [ 'execution_mode' => 'cron' ] );
		$context->flush( $logger );
		self::assertCount( 12, $handler->records );
	}

	public function test_expired_buffer_is_suppressed_before_configuration_or_budget_work(): void {
		$now          = time();
		$config_reads = 0;
		$admissions   = 0;
		$handler      = new DiagnosticMemoryHandler();
		$logger       = new Logger(
			$handler,
			new SafeContext(),
			static function () use ( &$config_reads ): array { ++$config_reads; return []; },
			null,
			static function () use ( &$admissions ): bool { ++$admissions; return true; }
		);
		$context      = new DiagnosticContext( str_repeat( 'a', 32 ), $now + 1, 'queued_otp', static function () use ( &$now ): int { return $now; } );
		$context->observe( 'worker_started', [ 'execution_mode' => 'cron' ] );
		$now += 2;
		$context->flush( $logger );

		self::assertSame( [], $handler->records );
		self::assertSame( 0, $config_reads );
		self::assertSame( 0, $admissions );
		self::assertSame( 'expired', $context->coverage() );
	}

	/** @dataProvider diagnostic_scopes */
	public function test_only_the_selected_active_scope_consumes_the_shared_budget( array $scope, int $remaining, bool $expected ): void {
		$handler    = new DiagnosticMemoryHandler();
		$admissions = 0;
		$logger     = new Logger(
			$handler,
			new SafeContext(),
			static fn(): array => [ 'minimum_level' => 'error', 'diagnostic_until' => time() + $remaining, 'diagnostic_scope' => $scope ],
			null,
			static function () use ( &$admissions ): bool { ++$admissions; return true; }
		);
		$context    = new DiagnosticContext( str_repeat( 'a', 32 ), time() + 60 );
		$context->observe( 'worker_started', [ 'execution_mode' => 'cron' ] );
		$context->flush( $logger );

		self::assertSame( $expected ? 1 : 0, $admissions );
		self::assertCount( $expected ? 1 : 0, $handler->records );
	}

	public static function diagnostic_scopes(): array {
		return [
			'operation matches' => [ [ 'type' => 'operation', 'value' => 'queued_otp' ], 60, true ],
			'flow matches' => [ [ 'type' => 'flow', 'value' => str_repeat( 'a', 32 ) ], 60, true ],
			'other operation' => [ [ 'type' => 'operation', 'value' => 'verify_otp' ], 60, false ],
			'other flow' => [ [ 'type' => 'flow', 'value' => str_repeat( 'b', 32 ) ], 60, false ],
			'unknown scope' => [ [ 'type' => 'all', 'value' => 'private_scope' ], 60, false ],
			'legacy unscoped window' => [ [], 60, false ],
			'expired scope' => [ [ 'type' => 'operation', 'value' => 'queued_otp' ], -1, false ],
			'unbounded scope deadline' => [ [ 'type' => 'operation', 'value' => 'queued_otp' ], 86500, false ],
		];
	}

	/** @dataProvider observation_failures */
	public function test_observation_failure_cannot_escape_or_replay_the_buffer( string $failure ): void {
		$handler       = new DiagnosticMemoryHandler();
		$handler->fail = 'handler' === $failure;
		$admissions    = 0;
		$logger        = new Logger(
			$handler,
			new SafeContext(),
			static function () use ( $failure ): array {
				if ( 'configuration' === $failure ) {
					throw new \RuntimeException( 'private-configuration-error' );
				}
				return [ 'minimum_level' => 'error', 'diagnostic_until' => time() + 60, 'diagnostic_scope' => [ 'type' => 'operation', 'value' => 'queued_otp' ] ];
			},
			static function () use ( $failure ): string {
				if ( 'correlation' === $failure ) {
					throw new \RuntimeException( 'private-correlation-error' );
				}
				return '12345678-1234-4234-8234-123456789abc';
			},
			static function () use ( &$admissions, $failure ): bool {
				++$admissions;
				if ( 'admission' === $failure ) {
					throw new \RuntimeException( 'private-backend-error' );
				}
				return 'budget_exhausted' !== $failure;
			},
			static function () use ( $failure ): array {
				if ( 'metadata' === $failure ) {
					throw new \RuntimeException( 'private-metadata-error' );
				}
				return [ 'package_identity' => 'source' ];
			}
		);
		$context       = new DiagnosticContext( str_repeat( 'a', 32 ), time() + 60 );
		$context->observe( 'worker_started', [ 'execution_mode' => 'cron' ] );
		$context->flush( $logger );
		$context->flush( $logger );

		self::assertSame( [], $handler->records );
		self::assertSame( 'incomplete', $context->coverage() );
		self::assertSame( in_array( $failure, [ 'configuration', 'metadata' ], true ) ? 0 : 1, $admissions );
	}

	public static function observation_failures(): array {
		return array_map( static fn( string $failure ): array => [ $failure ], [ 'configuration', 'metadata', 'admission', 'budget_exhausted', 'handler', 'correlation' ] );
	}

	/** @dataProvider final_context_sizes */
	public function test_final_context_including_metadata_is_bounded_before_admission( int $bytes, bool $expected ): void {
		$now      = time();
		$deadline = $now + 60;
		$facts    = DiagnosticContext::sanitize( [ 'stage' => 'worker_started', 'execution_mode' => 'cron', 'operation' => 'queued_otp', 'observed_at' => $now, 'flow_deadline' => $deadline ] );
		$metadata = [ 'package_identity' => 'pinova-release-zip', 'build_commit' => str_repeat( 'b', 40 ), 'release_tag' => 'v1.3.0-rc1' ];
		$metadata['release_tag'] .= str_repeat( '1', $bytes - strlen( (string) json_encode( array_merge( $facts, $metadata ), JSON_UNESCAPED_SLASHES ) ) );
		$handler    = new DiagnosticMemoryHandler();
		$admissions = 0;
		$logger     = new Logger(
			$handler,
			new SafeContext(),
			static fn(): array => [ 'minimum_level' => 'error', 'diagnostic_until' => $deadline, 'diagnostic_scope' => [ 'type' => 'operation', 'value' => 'queued_otp' ] ],
			null,
			static function () use ( &$admissions ): bool { ++$admissions; return true; },
			static fn(): array => $metadata
		);
		$context    = new DiagnosticContext( str_repeat( 'a', 32 ), $deadline, 'queued_otp', static fn(): int => $now );
		$context->observe( 'worker_started', [ 'execution_mode' => 'cron' ] );
		$context->flush( $logger );

		self::assertSame( $expected ? 1 : 0, $admissions );
		self::assertCount( $expected ? 1 : 0, $handler->records );
		if ( $expected ) {
			$encoded = json_encode( $handler->records[0]['context'], JSON_UNESCAPED_SLASHES );
			self::assertSame( $bytes, strlen( (string) $encoded ) );
			$historical = \Pinova\Logging\EventEvidence::redact( [ 'event' => 'otp.diagnostic', 'context' => $encoded ] );
			self::assertEquals( $handler->records[0]['context'], $historical['context'] );
		}
	}

	public static function final_context_sizes(): array {
		return [ [ 2048, true ], [ 2049, false ] ];
	}

	public function test_invalid_flow_deadline_and_clock_never_reach_configuration_or_budget(): void {
		$work = 0;
		$logger = new Logger(
			new DiagnosticMemoryHandler(),
			null,
			static function () use ( &$work ): array { ++$work; return []; },
			null,
			static function () use ( &$work ): bool { ++$work; return false; }
		);
		$now = time();
		foreach ( [
			[ 'private-flow', $now + 60, 'queued_otp', static fn(): int => $now ],
			[ str_repeat( 'A', 32 ), $now + 60, 'queued_otp', static fn(): int => $now ],
			[ str_repeat( 'a', 32 ), $now - 1, 'queued_otp', static fn(): int => $now ],
			[ str_repeat( 'a', 32 ), $now + 181, 'queued_otp', static fn(): int => $now ],
			[ str_repeat( 'a', 32 ), $now + 60, 'private_operation', static fn(): int => $now ],
			[ str_repeat( 'a', 32 ), $now + 60, 'queued_otp', static function (): int { throw new \RuntimeException( 'private-clock-error' ); } ],
		] as [ $flow, $deadline, $operation, $clock ] ) {
			$context = new DiagnosticContext( $flow, $deadline, $operation, $clock );
			$context->observe( 'worker_started' );
			$context->flush( $logger );
			self::assertSame( 'invalid', $context->coverage() );
		}
		self::assertSame( 0, $work );
	}

	public function test_clock_reversal_suppresses_previously_buffered_observations(): void {
		$now = time();
		$reads = 0;
		$context = new DiagnosticContext( str_repeat( 'a', 32 ), $now + 60, 'queued_otp', static function () use ( &$now ): int { return $now; } );
		$context->observe( 'worker_started' );
		--$now;
		$context->flush( new Logger( new DiagnosticMemoryHandler(), null, static function () use ( &$reads ): array { ++$reads; return []; } ) );
		self::assertSame( 'incomplete', $context->coverage() );
		self::assertSame( 0, $reads );
	}

	public function test_unencodable_metadata_is_suppressed_before_admission(): void {
		$handler = new DiagnosticMemoryHandler();
		$admissions = 0;
		$logger = new Logger(
			$handler,
			null,
			static fn(): array => [ 'diagnostic_until' => time() + 60, 'diagnostic_scope' => [ 'type' => 'operation', 'value' => 'queued_otp' ] ],
			null,
			static function () use ( &$admissions ): bool { ++$admissions; return true; },
			static fn(): array => [ 'release_tag' => "\xFF" ]
		);
		$context = new DiagnosticContext( str_repeat( 'a', 32 ), time() + 60 );
		$context->observe( 'worker_started' );
		$context->flush( $logger );
		self::assertSame( 0, $admissions );
		self::assertSame( [], $handler->records );
		self::assertSame( 'incomplete', $context->coverage() );
	}

	/** @dataProvider admission_changes */
	public function test_expiry_or_scope_change_during_admission_cannot_authorize_a_late_write( string $change ): void {
		$now = time();
		$deadline = $now + 60;
		$config = [ 'minimum_level' => 'error', 'diagnostic_until' => $deadline, 'diagnostic_scope' => [ 'type' => 'operation', 'value' => 'queued_otp' ] ];
		$handler = new DiagnosticMemoryHandler();
		$admissions = 0;
		$logger = new Logger(
			$handler,
			null,
			static function () use ( &$config ): array { return $config; },
			null,
			static function () use ( &$admissions, &$now, &$config, $deadline, $change ): bool {
				++$admissions;
				if ( 'flow_expired' === $change ) {
					$now = $deadline;
				} elseif ( 'scope_expired' === $change ) {
					$config['diagnostic_until'] = time() - 1;
				} else {
					$config['diagnostic_scope'] = [ 'type' => 'flow', 'value' => str_repeat( 'b', 32 ) ];
				}
				return true;
			}
		);
		$context = new DiagnosticContext( str_repeat( 'a', 32 ), $deadline, 'queued_otp', static function () use ( &$now ): int { return $now; } );
		$context->observe( 'worker_started' );
		$context->flush( $logger );
		$context->flush( $logger );
		self::assertSame( [], $handler->records );
		self::assertSame( 1, $admissions, 'A consumed observation is never refunded or retried.' );
		self::assertSame( 'incomplete', $context->coverage() );
	}

	public static function admission_changes(): array {
		return [ [ 'flow_expired' ], [ 'scope_expired' ], [ 'scope_changed' ] ];
	}

	public function test_psr_and_administrative_audit_paths_cannot_bypass_diagnostic_admission(): void {
		$handler = new DiagnosticMemoryHandler();
		$logger = new Logger( $handler, null, static fn(): array => [ 'minimum_level' => 'info' ] );
		$logger->info( 'otp.diagnostic', [ 'stage' => 'worker_started' ] );
		$logger->audit( 'debug', 'otp.diagnostic', [ 'stage' => 'worker_started' ] );
		self::assertSame( [], $handler->records );
		$logger->audit( 'warning', 'logging.cleared', [ 'operation' => 'manual_clear' ] );
		self::assertCount( 1, $handler->records );
	}

	public function test_hostile_code_values_and_numeric_lookalikes_do_not_enter_diagnostic_context(): void {
		$object = new class() implements \Stringable {
			public function __toString(): string { throw new \RuntimeException( 'private-string-error' ); }
		};
		self::assertSame(
			[ 'component' => 'http_transport', 'stage' => 'provider_completed', 'source_ref' => 'curl.post' ],
			DiagnosticContext::sanitize(
				[
					'stage' => 'provider_completed', 'component' => $object, 'source_ref' => 'private/source.php',
					'error_code' => "provider_exception\nprivate-token", 'result' => 'private_result',
					'provider_duration_ms' => '250', 'http_status' => 999, 'observed_at' => 'private_timestamp',
					'exception' => new \RuntimeException( 'private-exception' ), 'password' => 'private-password',
				]
			)
		);
	}

	public function test_an_empty_flushed_context_is_incomplete_and_performs_no_observation_work(): void {
		$reads = 0;
		$logger = new Logger( new DiagnosticMemoryHandler(), null, static function () use ( &$reads ): array { ++$reads; return []; } );
		$context = new DiagnosticContext( str_repeat( 'a', 32 ), time() + 60 );
		$context->flush( $logger );
		self::assertSame( 'incomplete', $context->coverage() );
		self::assertSame( 0, $reads );
	}
}

final class DiagnosticMemoryHandler implements HandlerInterface {
	/** @var array<int, array<string, mixed>> */
	public array $records = [];
	public bool $fail = false;

	public function write( array $record ): void {
		if ( $this->fail ) {
			throw new \RuntimeException( 'private-handler-error' );
		}
		$this->records[] = $record;
	}
}
