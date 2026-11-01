<?php

declare(strict_types=1);

namespace Pinova\Tests\Unit;

use Pinova\Services\OTPImmediateDelivery;
use Pinova\Services\OTPService;
use PHPUnit\Framework\TestCase;

final class OTPImmediateDeliveryTest extends TestCase {
	public function test_normal_helpers_are_inert_without_an_immediate_context(): void {
		self::assertFalse( OTPImmediateDelivery::is_active() );
		self::assertNull( OTPImmediateDelivery::admission( str_repeat( 'a', 32 ) ) );
		self::assertSame( 8, OTPImmediateDelivery::provider_timeout() );
		self::assertFalse( OTPImmediateDelivery::provider_was_attempted() );
		OTPImmediateDelivery::guard( str_repeat( 'a', 32 ), str_repeat( 'b', 16 ), [ 'email' ] );
	}

	/** @dataProvider headroom_cases */
	public function test_host_pressure_and_missing_observations_fail_closed( string|false $memory, array|false $load, bool $expected ): void {
		$method = new \ReflectionMethod( OTPImmediateDelivery::class, 'headroom_is_sufficient' );
		$method->setAccessible( true );
		self::assertSame( $expected, $method->invoke( null, $memory, $load ) );
	}

	public static function headroom_cases(): array {
		return [
			[ "MemAvailable: 1048576 kB\n", [ 2.99, 1, 1 ], true ],
			[ "MemAvailable: 1048575 kB\n", [ 1, 1, 1 ], false ],
			[ "MemAvailable: 2097152 kB\n", [ 3, 1, 1 ], false ],
			[ "MemAvailable: 2097152 kB\n", [ INF ], false ],
			[ "MemAvailable: 2097152 kB\n", [ -1 ], false ],
			[ "MemAvailable: 2097152 kB\n", [], false ],
			[ false, [ 0, 0, 0 ], false ],
			[ "MemFree: 2097152 kB\n", [ 0, 0, 0 ], false ],
			[ "MemAvailable: 2097152 kB\n", false, false ],
		];
	}

	public function test_diagnostic_clock_failure_and_discontinuity_remain_unknown(): void {
		$failed = static function (): never {
			throw new \RuntimeException( 'Synthetic clock failure.' );
		};
		self::assertNull( OTPService::diagnostic_clock( $failed ) );
		self::assertNull( OTPService::diagnostic_duration( 1000000, $failed ) );
		foreach ( [ null, false, '1000', INF, NAN, -1 ] as $invalid ) {
			self::assertNull( OTPService::diagnostic_clock( static fn() => $invalid ) );
			self::assertNull( OTPService::diagnostic_duration( 1000000, static fn() => $invalid ) );
		}
		self::assertNull( OTPService::diagnostic_duration( null, static fn(): int => 2000000 ) );
		self::assertNull( OTPService::diagnostic_duration( INF, static fn(): int => 2000000 ) );
		self::assertNull( OTPService::diagnostic_duration( 2000000, static fn(): int => 1000000 ) );
		self::assertNull( OTPService::diagnostic_duration( 0, static fn(): int => 600001000000 ) );
		self::assertSame( 1, OTPService::diagnostic_duration( 1000000, static fn(): int => 2000000 ) );
		self::assertSame( 0, OTPService::diagnostic_duration( 1000000, static fn(): int => 1000000 ) );
	}

}
