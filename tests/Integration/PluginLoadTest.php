<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Illuminate\Database\Eloquent\Model;
use Pinova\Services\FirewallService;
use WP_UnitTestCase;

final class PluginLoadTest extends WP_UnitTestCase {
	public function test_plugin_version_is_defined(): void {
		self::assertTrue( defined( 'PINOVA_VERSION' ) );
	}

	public function test_database_connection_is_ready_without_install_bootstrap(): void {
		self::assertNotNull( Model::getConnectionResolver() );
		self::assertNull( FirewallService::is_blocked( 'pinova-integration-probe.invalid' ) );
	}

	public function test_plugin_load_does_not_trigger_woocommerce_translation_too_early(): void {
		$functions = $GLOBALS['pinova_doing_it_wrong_functions'] ?? [];

		self::assertNotContains( '_load_textdomain_just_in_time', $functions );
	}
}
