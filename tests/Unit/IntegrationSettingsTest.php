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

	public function test_only_profile_dependencies_can_be_supported(): void {
		$method = new \ReflectionMethod( IntegrationSettings::class, 'dependency_status' );
		$method->setAccessible( true );
		$versions = [ 'woocommerce' => '11.1.2', 'wpforo' => '3.2.1', 'dokan' => '5.1.3', 'dokan_pro' => false ];
		foreach ( [ 'wpforo', 'dokan' ] as $slug ) {
			self::assertSame( 'supported', $method->invoke( null, $slug, $versions ) );
			self::assertSame( 'missing', $method->invoke( null, $slug, array_replace( $versions, [ $slug => '' ] ) ) );
			self::assertSame( 'unsupported', $method->invoke( null, $slug, array_replace( $versions, [ $slug => '999.0' ] ) ) );
			self::assertSame( 'missing', $method->invoke( null, $slug, array_replace( $versions, [ 'woocommerce' => '' ] ) ) );
			self::assertSame( 'unsupported', $method->invoke( null, $slug, array_replace( $versions, [ 'woocommerce' => '11.1.0' ] ) ) );
		}
		self::assertSame( 'unsupported', $method->invoke( null, 'dokan', array_replace( $versions, [ 'dokan_pro' => true ] ) ) );
		self::assertSame( 'supported', $method->invoke( null, 'wpforo', array_replace( $versions, [ 'dokan_pro' => true ] ) ) );
		self::assertSame( 'unsupported', $method->invoke( null, 'unrecognized', $versions ) );
	}

	public function test_audit_retains_only_integration_key_names(): void {
		$keys = SettingsAudit::changed_keys( IntegrationSettings::OPTION, [], [ 'wpforo_enabled' => 'PRIVATE', 'dokan_enabled' => 'PRIVATE', 'secret' => 'PRIVATE' ] );
		self::assertSame( [ 'wpforo_enabled', 'dokan_enabled' ], $keys );
		self::assertSame( [ 'changed_keys' => $keys ], ( new SafeContext() )->sanitize( [ 'changed_keys' => $keys, 'old_value' => 'PRIVATE', 'new_value' => 'PRIVATE' ] ) );
	}
}
