<?php

declare(strict_types=1);

namespace Pinova\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pinova\Logging\HandlerInterface;
use Pinova\Logging\InvalidLogLevelException;
use Pinova\Logging\Logger;
use Pinova\Logging\SafeContext;

final class LoggingTest extends TestCase {

	public function test_logger_keeps_only_safe_structured_context(): void {
		$handler     = new class() implements HandlerInterface {
			/** @var array<int, array<string, mixed>> */
			public array $records = [];

			public function write( array $record ): void {
				$this->records[] = $record;
			}
		};
		$context     = new SafeContext( static fn(): string => 'unit-test-salt' );
		$logger      = new Logger(
			$handler,
			$context,
			static fn(): array => [
				'minimum_level'    => 'warning',
				'diagnostic_until' => 0,
			],
			static fn(): string => 'correlation-test'
		);
		$email       = 'person@example.test';
		$fingerprint = $logger->fingerprint( $email, 'email' );

		$logger->warning(
			"Identity Conflict\r\nInjected",
			[
				'user_id'                => 42,
				'identifier_type'        => 'Email',
				'identifier_fingerprint' => $fingerprint,
				'candidate_count'        => 2,
				'channels'               => [ 'sms', "email\r\nforged" ],
				'exception'              => new \RuntimeException( 'secret person@example.test', 17 ),
				'email'                  => $email,
				'password'               => 'do-not-log',
				'otp'                    => '123456',
				'token'                  => 'secret-token',
			]
		);

		self::assertCount( 1, $handler->records );
		$record = $handler->records[0];
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure unit test runs without WordPress.
		$encoded = json_encode( $record );

		self::assertSame( 'pinova.event.v1', $record['schema'] );
		self::assertSame( 'identity_conflict_injected', $record['event'] );
		self::assertSame( 'correlation-test', $record['correlation_id'] );
		self::assertSame( 42, $record['context']['user_id'] );
		self::assertSame( $fingerprint, $record['context']['identifier_fingerprint'] );
		self::assertSame( 'RuntimeException', $record['context']['exception_class'] );
		self::assertSame( '17', $record['context']['exception_code'] );
		self::assertStringNotContainsString( $email, (string) $encoded );
		self::assertStringNotContainsString( 'do-not-log', (string) $encoded );
		self::assertStringNotContainsString( '123456', (string) $encoded );
		self::assertStringNotContainsString( 'secret-token', (string) $encoded );
	}

	public function test_threshold_and_diagnostic_expiry_are_enforced(): void {
		$handler  = new class() implements HandlerInterface {
			/** @var array<int, array<string, mixed>> */
			public array $records = [];

			public function write( array $record ): void {
				$this->records[] = $record;
			}
		};
		$standard = new Logger(
			$handler,
			new SafeContext( static fn(): string => 'salt' ),
			static fn(): array => [
				'minimum_level'    => 'warning',
				'diagnostic_until' => 0,
			],
			static fn(): string => 'standard'
		);
		$standard->info( 'auth.succeeded' );
		$standard->warning( 'auth.rate_limited' );

		self::assertCount( 1, $handler->records );
		self::assertSame( 'auth.rate_limited', $handler->records[0]['event'] );

		$diagnostic = new Logger(
			$handler,
			new SafeContext( static fn(): string => 'salt' ),
			static fn(): array => [
				'minimum_level'    => 'error',
				'diagnostic_until' => time() + 60,
			],
			static fn(): string => 'diagnostic'
		);
		$diagnostic->debug( 'auth.trace' );

		self::assertCount( 2, $handler->records );
		self::assertSame( 'auth.trace', $handler->records[1]['event'] );
	}

	public function test_fingerprint_is_keyed_stable_and_purpose_scoped(): void {
		$context = new SafeContext( static fn(): string => 'stable-secret' );

		self::assertSame( $context->fingerprint( 'value', 'email' ), $context->fingerprint( 'value', 'email' ) );
		self::assertNotSame( hash( 'sha256', 'value' ), $context->fingerprint( 'value', 'email' ) );
		self::assertNotSame( $context->fingerprint( 'value', 'email' ), $context->fingerprint( 'value', 'mobile' ) );
	}

	public function test_unknown_psr_level_is_rejected(): void {
		$handler = new class() implements HandlerInterface {
			public function write( array $record ): void {
				unset( $record );
			}
		};

		$this->expectException( InvalidLogLevelException::class );
		( new Logger( $handler ) )->log( 'trace', 'not-a-psr-level' );
	}

	public function test_handler_failure_does_not_interrupt_the_observed_operation(): void {
		$handler = new class() implements HandlerInterface {
			public function write( array $record ): void {
				unset( $record );
				throw new \RuntimeException( 'handler failed' );
			}
		};
		$logger  = new Logger(
			$handler,
			new SafeContext( static fn(): string => 'salt' ),
			static fn(): array => [
				'minimum_level'    => 'warning',
				'diagnostic_until' => 0,
			]
		);

		$logger->warning( 'logging.handler_failure_test' );

		self::assertTrue( true );
	}

	public function test_audit_event_bypasses_threshold_and_fingerprint_failure_is_safe(): void {
		$handler = new class() implements HandlerInterface {
			/** @var array<int, array<string, mixed>> */
			public array $records = [];

			public function write( array $record ): void {
				$this->records[] = $record;
			}
		};
		$context = new SafeContext(
			static function (): string {
				throw new \RuntimeException( 'salt provider failed' );
			}
		);
		$logger  = new Logger(
			$handler,
			$context,
			static fn(): array => [
				'minimum_level'    => 'error',
				'diagnostic_until' => 0,
			],
			static fn(): string => 'audit-correlation'
		);

		$logger->audit( 'warning', 'logging.cleared', [ 'operation' => 'manual_clear' ] );

		self::assertCount( 1, $handler->records );
		self::assertSame( 'logging.cleared', $handler->records[0]['event'] );
		self::assertSame( '', $logger->fingerprint( 'private-value', 'test' ) );
	}
}
