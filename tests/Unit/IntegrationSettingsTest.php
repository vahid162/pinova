<?php

declare(strict_types=1);

namespace Pinova\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pinova\Integrations\IntegrationSettings;
use Pinova\Logging\SafeContext;
use Pinova\Logging\SettingsAudit;

final class IntegrationSettingsTest extends TestCase {

	public function test_only_exact_checkbox_values_enable_a_switch(): void {
		foreach ( [ null, [], new \stdClass(), 'yes', 'true', 2, 1.0, '01', ' 1', 'on' ] as $value ) {
			self::assertSame( [ 'wpforo_enabled' => '0', 'dokan_enabled' => '0' ], IntegrationSettings::normalize( [ 'wpforo_enabled' => $value, 'dokan_enabled' => $value ] ) );
		}
		foreach ( [ true, 1, '1' ] as $value ) {
			self::assertSame( [ 'wpforo_enabled' => '1', 'dokan_enabled' => '1' ], IntegrationSettings::normalize( [ 'wpforo_enabled' => $value, 'dokan_enabled' => $value, 'secret' => 'ignored' ] ) );
		}
		self::assertSame( [ 'wpforo_enabled' => '0', 'dokan_enabled' => '0' ], IntegrationSettings::normalize( 'malformed' ) );
	}

	public function test_callable_contract_rejects_inaccessible_or_changed_required_arguments(): void {
		$method = new \ReflectionMethod( IntegrationSettings::class, 'accepts' );
		$method->setAccessible( true );
		$api = new class() {
			public function current( $value, $optional = null ): void {}
			public function changed( $first, $second ): void {}
			private function inaccessible(): void {}
		};
		self::assertTrue( $method->invoke( null, [ $api, 'current' ], 1 ) );
		self::assertFalse( $method->invoke( null, [ $api, 'changed' ], 1 ) );
		self::assertFalse( $method->invoke( null, [ $api, 'inaccessible' ], 0 ) );
		self::assertFalse( $method->invoke( null, [ $api, 'missing' ], 0 ) );
	}

	public function test_diagnostic_versions_reject_arbitrary_payloads(): void {
		$method = new \ReflectionMethod( IntegrationSettings::class, 'version' );
		$method->setAccessible( true );
		define( 'PINOVA_CAPABILITY_GOOD_VERSION', '999.2.3-beta.4' );
		define( 'PINOVA_CAPABILITY_BAD_VERSION', 'private-token' );
		self::assertSame( '999.2.3-beta.4', $method->invoke( null, 'PINOVA_CAPABILITY_GOOD_VERSION' ) );
		self::assertSame( '', $method->invoke( null, 'PINOVA_CAPABILITY_BAD_VERSION' ) );
		self::assertSame( '', $method->invoke( null, 'PINOVA_CAPABILITY_MISSING_VERSION' ) );
	}

	public function test_audit_retains_only_integration_key_names(): void {
		$keys = SettingsAudit::changed_keys( IntegrationSettings::OPTION, [], [ 'wpforo_enabled' => 'PRIVATE', 'dokan_enabled' => 'PRIVATE', 'secret' => 'PRIVATE' ] );
		self::assertSame( [ 'wpforo_enabled', 'dokan_enabled' ], $keys );
		self::assertSame( [ 'changed_keys' => $keys ], ( new SafeContext() )->sanitize( [ 'changed_keys' => $keys, 'old_value' => 'PRIVATE', 'new_value' => 'PRIVATE' ] ) );
	}
}
