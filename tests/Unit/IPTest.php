<?php

declare(strict_types=1);

namespace Pinova\Tests\Unit;

use Pinova\Helpers\IP;
use PHPUnit\Framework\TestCase;

final class IPTest extends TestCase {
	public function test_ipv4_and_ipv6_cidr_matching(): void {
		self::assertTrue( IP::in_cidr( '192.0.2.10', '192.0.2.0/24' ) );
		self::assertFalse( IP::in_cidr( '198.51.100.10', '192.0.2.0/24' ) );
		self::assertTrue( IP::in_cidr( '2001:db8::10', '2001:db8::/32' ) );
		self::assertFalse( IP::in_cidr( '2001:db9::10', '2001:db8::/32' ) );
	}

	public function test_untrusted_forwarded_header_is_ignored(): void {
		self::assertSame(
			'198.51.100.20',
			IP::get(
				[
					'REMOTE_ADDR'          => '198.51.100.20',
					'HTTP_X_FORWARDED_FOR' => '203.0.113.99',
				]
			)
		);
	}

	public function test_invalid_remote_address_does_not_become_loopback(): void {
		self::assertSame( '', IP::get( [ 'REMOTE_ADDR' => 'not-an-ip' ] ) );
	}

	public function test_trusted_chain_is_evaluated_from_right_to_left(): void {
		self::assertSame(
			'203.0.113.10',
			IP::get(
				[
					'REMOTE_ADDR'          => '10.0.0.5',
					'HTTP_X_FORWARDED_FOR' => '203.0.113.10, 10.0.0.4',
				],
				[ '10.0.0.0/8' ],
				'HTTP_X_FORWARDED_FOR'
			)
		);
	}

	public function test_invalid_forwarded_chain_fails_closed(): void {
		self::assertSame(
			'10.0.0.5',
			IP::get(
				[
					'REMOTE_ADDR'          => '10.0.0.5',
					'HTTP_X_FORWARDED_FOR' => '203.0.113.10, forged',
				],
				[ '10.0.0.0/8' ],
				'HTTP_X_FORWARDED_FOR'
			)
		);
	}
}
