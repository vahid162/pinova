<?php

declare(strict_types=1);

namespace Pinova\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pinova\Logging\DiagnosticContext;
use Pinova\Logging\HandlerInterface;
use Pinova\Logging\Logger;
use Pinova\Logging\SafeContext;

final class DiagnosticContextTest extends TestCase {

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
}

final class DiagnosticMemoryHandler implements HandlerInterface {
	/** @var array<int, array<string, mixed>> */
	public array $records = [];

	public function write( array $record ): void {
		$this->records[] = $record;
	}
}
