<?php

declare(strict_types=1);

namespace Pinova\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pinova\Logging\BuildMetadata;
use Pinova\Logging\EventEvidence;
use Pinova\Logging\HandlerInterface;
use Pinova\Logging\InvalidLogLevelException;
use Pinova\Logging\Logger;
use Pinova\Logging\SafeContext;

final class LoggingTest extends TestCase {

	public function test_diagnostic_catalogue_preserves_finite_facts_in_input_and_historical_output(): void {
		$observed = time();
		$facts    = [
			'component'                 => 'otp_delivery',
			'stage'                     => 'worker_started',
			'source_ref'                => 'otp_service.deliver_queued',
			'operation'                 => 'queued_otp',
			'execution_mode'            => 'immediate',
			'observed_at'               => $observed,
			'request_to_worker_seconds' => 31,
		];
		$input    = $facts + [
			'identifier'        => 'private@example.test',
			'otp'               => 'private-otp',
			'provider_response' => 'private-provider-response',
			'exception'         => new \RuntimeException( 'private-exception-message' ),
		];
		$safe     = ( new SafeContext() )->sanitize( $input, 'otp.diagnostic' );

		self::assertEquals( $facts, $safe );
		$historical = EventEvidence::redact(
			[
				'created_at'     => gmdate( 'Y-m-d H:i:s', $observed + 2 ),
				'event'          => 'otp.diagnostic',
				'level'          => 'debug',
				'correlation_id' => '12345678-1234-4234-8234-123456789abc',
				'flow_id'        => str_repeat( 'a', 32 ),
				'context'        => json_encode( $input ),
			]
		);

		self::assertSame( 'otp.diagnostic', $historical['event'] );
		self::assertEquals( $facts, $historical['context'] );
		self::assertSame( $observed, $historical['context']['observed_at'] );
		self::assertStringNotContainsString( 'private', (string) json_encode( $historical ) );
	}

	public function test_diagnostic_codes_are_not_accepted_merely_because_they_look_like_codes(): void {
		$context = ( new SafeContext() )->sanitize(
			[
				'component'      => 'private_component',
				'stage'          => 'private_stage',
				'source_ref'     => 'private.source_ref',
				'error_code'     => 'private_error_code',
				'execution_mode' => 'private_execution_mode',
				'operation'      => 'private_operation',
				'result'         => 'private_result',
				'observed_at'    => 'private_timestamp',
			],
			'otp.diagnostic'
		);

		self::assertSame( [], $context );
		self::assertSame( [], ( new SafeContext() )->sanitize( [ 'stage' => 'worker_started', 'source_ref' => 'otp_service.deliver_queued' ] ) );
	}

	public function test_queue_timing_fields_are_numeric_and_secrets_remain_denied(): void {
		$context = new SafeContext();
		self::assertSame(
			[ 'queue_delay_seconds' => 120, 'remaining_seconds' => 60 ],
			$context->sanitize( [ 'queue_delay_seconds' => 120, 'remaining_seconds' => 60, 'jwt' => 'private-token', 'identifier' => 'private@example.test' ] )
		);
		self::assertSame( [], $context->sanitize( [ 'queue_delay_seconds' => -1, 'remaining_seconds' => 'private-token' ] ) );
	}

	public function test_source_checkout_does_not_fabricate_build_metadata_or_accept_caller_spoofing(): void {
		self::assertSame( [ 'package_identity' => 'source' ], BuildMetadata::info() );

		$handler = new class() implements HandlerInterface {
			/** @var array<string, mixed> */
			public array $record = [];

			public function write( array $record ): void {
				$this->record = $record;
			}
		};
		$logger  = new Logger(
			$handler,
			new SafeContext( static fn(): string => 'salt' ),
			static fn(): array => [ 'minimum_level' => 'warning' ],
			static fn(): string => 'build-test'
		);

		$logger->warning(
			'logging.build_metadata_test',
			[
				'build_commit'     => str_repeat( 'f', 40 ),
				'package_identity' => 'forged-package',
				'release_tag'      => 'v9.9.9-rc1',
				'password'         => 'do-not-log',
			]
		);

		self::assertSame( [ 'package_identity' => 'source' ], $handler->record['context'] );
	}

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

	public function test_anonymous_exception_names_cannot_persist_a_null_byte_or_source_path(): void {
		$exception = new class( 'private-exception-message', 23 ) extends \RuntimeException {};
		$class     = get_class( $exception );
		self::assertStringContainsString( '@anonymous', $class );
		self::assertStringContainsString( __FILE__, $class );

		foreach ( [ [ 'exception' => $exception ], [ 'exception_class' => $class, 'exception_code' => 23 ] ] as $input ) {
			$safe = ( new SafeContext() )->sanitize( $input );
			self::assertArrayNotHasKey( 'exception_class', $safe );
			self::assertSame( '23', $safe['exception_code'] );
			$encoded = (string) json_encode( $safe );
			foreach ( [ '@anonymous', __FILE__, 'private-exception-message', '\u0000' ] as $forbidden ) {
				self::assertStringNotContainsString( $forbidden, $encoded );
			}
		}
	}

	/** @dataProvider exception_class_names */
	public function test_exception_class_names_are_validated_without_stripping_or_truncating( string $class, bool $valid ): void {
		$safe = ( new SafeContext() )->sanitize( [ 'exception_class' => $class, 'exception_code' => 'E-17' ] );
		self::assertSame( 'E-17', $safe['exception_code'] );
		if ( $valid ) {
			self::assertSame( $class, $safe['exception_class'] );
		} else {
			self::assertArrayNotHasKey( 'exception_class', $safe );
		}
	}

	public static function exception_class_names(): array {
		return [
			'ordinary class' => [ 'RuntimeException', true ],
			'namespaced class' => [ 'Vendor\\Domain\\NamedError', true ],
			'fully qualified class' => [ '\\Vendor\\Domain\\NamedError', true ],
			'case and underscore' => [ 'Vendor_2\\_NamedError42', true ],
			'exact byte limit' => [ str_repeat( 'A', 160 ), true ],
			'over byte limit' => [ str_repeat( 'A', 161 ), false ],
			'empty' => [ '', false ],
			'leading digit' => [ '9RuntimeException', false ],
			'empty namespace segment' => [ 'Vendor\\\\NamedError', false ],
			'trailing separator' => [ 'Vendor\\', false ],
			'newline' => [ "RuntimeException\n", false ],
			'null byte' => [ "RuntimeException\0private-source", false ],
			'leading whitespace' => [ ' RuntimeException', false ],
			'path suffix' => [ 'RuntimeException/private/source.php:42', false ],
			'absolute path' => [ '/private/source/RuntimeException', false ],
			'Windows path' => [ 'C:\\private\\source.php', false ],
			'non-ASCII symbol' => [ 'Vendor\\NämédError', false ],
			'hyphen' => [ 'Vendor\\Named-Error', false ],
		];
	}

	public function test_named_throwables_remain_exact_and_explicit_names_do_not_trigger_autoloading(): void {
		$sanitizer = new SafeContext();
		foreach ( [ new \RuntimeException( 'private-runtime-message', 17 ), new LoggingNamedException( 'private-namespaced-message', 29 ) ] as $exception ) {
			$safe = $sanitizer->sanitize( [ 'exception' => $exception ] );
			self::assertSame( get_class( $exception ), $safe['exception_class'] );
			self::assertSame( (string) $exception->getCode(), $safe['exception_code'] );
		}
		// Preload the ordinary sanitizer's existing catalogue dependency first.
		$sanitizer->sanitize( [] );
		$autoloads = 0;
		$loader = static function ( string $class ) use ( &$autoloads ): void { ++$autoloads; };
		spl_autoload_register( $loader, true, true );
		try {
			$safe = $sanitizer->sanitize( [ 'exception_class' => 'NotLoaded\\ExactCaseError' ] );
		} finally {
			spl_autoload_unregister( $loader );
		}
		self::assertSame( 'NotLoaded\\ExactCaseError', $safe['exception_class'] );
		self::assertSame( 0, $autoloads );
	}

	public function test_flow_id_is_a_strict_top_level_field_not_arbitrary_context(): void {
		$handler = new class() implements HandlerInterface {
			public array $records = [];

			public function write( array $record ): void {
				$this->records[] = $record;
			}
		};
		$logger = new Logger( $handler, new SafeContext(), static fn(): array => [ 'minimum_level' => 'info' ] );
		$logger->info( 'otp.verified', [ 'flow_id' => str_repeat( 'a', 32 ), 'jwt' => 'secret-token' ] );
		$logger->info( 'otp.verify_failed', [ 'flow_id' => 'unsafe-flow-id' ] );

		self::assertSame( str_repeat( 'a', 32 ), $handler->records[0]['flow_id'] );
		self::assertArrayNotHasKey( 'flow_id', $handler->records[0]['context'] );
		self::assertArrayNotHasKey( 'jwt', $handler->records[0]['context'] );
		self::assertNull( $handler->records[1]['flow_id'] );
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
		self::assertFalse( $standard->is_enabled( 'notice' ) );
		self::assertTrue( $standard->is_enabled( 'WARNING' ) );
		self::assertFalse( $standard->is_enabled( 'unknown' ) );

		self::assertCount( 1, $handler->records );
		self::assertSame( 'auth.rate_limited', $handler->records[0]['event'] );

		$now     = time();
		$windows = [
			'legacy active'      => [ 'diagnostic_until' => $now + 60 ],
			'operation active'   => [ 'diagnostic_until' => $now + 60, 'diagnostic_scope' => [ 'type' => 'operation', 'value' => 'queued_otp' ] ],
			'flow active'        => [ 'diagnostic_until' => $now + 60, 'diagnostic_scope' => [ 'type' => 'flow', 'value' => str_repeat( 'a', 32 ) ] ],
			'operation expired'  => [ 'diagnostic_until' => $now - 1, 'diagnostic_scope' => [ 'type' => 'operation', 'value' => 'queued_otp' ] ],
			'operation disabled' => [ 'diagnostic_until' => 0, 'diagnostic_scope' => [ 'type' => 'operation', 'value' => 'queued_otp' ] ],
		];
		foreach ( $windows as $label => $window ) {
			$config     = [ 'minimum_level' => 'error' ] + $window;
			$diagnostic = new Logger(
				$handler,
				new SafeContext( static fn(): string => 'salt' ),
				static fn(): array => $config,
				static fn(): string => 'diagnostic'
			);
			foreach ( [ 'debug', 'info', 'notice', 'warning' ] as $level ) {
				self::assertFalse( $diagnostic->is_enabled( $level ), $label . ' must preserve the ordinary minimum level.' );
				$diagnostic->log( $level, 'auth.trace' );
			}
			self::assertTrue( $diagnostic->is_enabled( 'ERROR' ) );
			self::assertFalse( $diagnostic->is_enabled( 'unknown' ) );
			self::assertCount( 1, $handler->records );
			$diagnostic->error( 'auth.request_failed' );
			self::assertCount( 2, $handler->records );
			self::assertSame( 'auth.request_failed', $handler->records[1]['event'] );
			array_pop( $handler->records );
		}
	}

	public function test_enabled_check_fails_closed_when_configuration_throws(): void {
		$logger = new Logger(
			null,
			null,
			static function (): array {
				throw new \RuntimeException( 'configuration failed' );
			}
		);

		self::assertFalse( $logger->is_enabled( 'warning' ) );
	}

	public function test_fingerprint_is_keyed_stable_and_purpose_scoped(): void {
		$context = new SafeContext( static fn(): string => 'stable-secret' );

		self::assertSame( $context->fingerprint( 'value', 'email' ), $context->fingerprint( 'value', 'email' ) );
		self::assertSame( $context->fingerprint( 'Case@Example.Test', 'email' ), $context->fingerprint( 'case@example.test', 'EMAIL' ) );
		self::assertNotSame( $context->fingerprint( 'Case@Example.Test', 'email' ), $context->legacy_fingerprint( 'Case@Example.Test', 'email' ) );
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

final class LoggingNamedException extends \RuntimeException {}
