<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use WP_UnitTestCase;

final class PluginLoadTest extends WP_UnitTestCase {
	public function test_plugin_version_is_defined(): void {
		self::assertTrue( defined( 'PINOVA_VERSION' ) );
	}
}
