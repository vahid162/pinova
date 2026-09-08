<?php

declare(strict_types=1);

namespace Pinova\Tests\Unit;

use Pinova\Identity\ConflictRepository;
use Pinova\Identity\IdentityNormalizer;
use Pinova\Objects\Identifier;
use PHPUnit\Framework\TestCase;

final class IdentityNormalizerTest extends TestCase {
	public function test_normalizes_mobile_to_e164(): void {
		self::assertSame( '+989120000000', IdentityNormalizer::normalize( Identifier::TYPE_MOBILE, '09120000000' ) );
		self::assertSame( '+989120000000', IdentityNormalizer::normalize( Identifier::TYPE_MOBILE, '989120000000' ) );
	}

	public function test_canonicalizes_email_and_username(): void {
		self::assertSame( 'pinova.admin@example.com', IdentityNormalizer::normalize( Identifier::TYPE_EMAIL, ' PINOVA.ADMIN@EXAMPLE.COM ' ) );
		self::assertSame( 'pinova_test_admin', IdentityNormalizer::normalize( Identifier::TYPE_USERNAME, 'Pinova_Test_Admin' ) );
	}

	public function test_empty_and_invalid_values_are_not_identities(): void {
		self::assertNull( IdentityNormalizer::normalize( Identifier::TYPE_EMAIL, '' ) );
		self::assertNull( IdentityNormalizer::normalize( Identifier::TYPE_MOBILE, '1234' ) );
		self::assertNull( IdentityNormalizer::normalize( 'unknown', 'value' ) );
	}

	public function test_masks_personally_identifying_values(): void {
		self::assertSame( 'p***@example.com', ConflictRepository::mask( 'email', 'pinova.admin@example.com' ) );
		self::assertSame( '+98******0000', ConflictRepository::mask( 'mobile', '+989120000000' ) );
		self::assertSame( 'pi***in', ConflictRepository::mask( 'username', 'pinova_test_admin' ) );
	}
}
