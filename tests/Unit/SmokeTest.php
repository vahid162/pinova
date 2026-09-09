<?php

declare(strict_types=1);

namespace Pinova\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase {
	public function test_autoloader_can_load_pinova_classes(): void {
		self::assertTrue( class_exists( \Pinova\Objects\Mobile::class ) );
	}
}
