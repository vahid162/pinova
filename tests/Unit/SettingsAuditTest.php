<?php

declare(strict_types=1);

namespace Pinova\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pinova\Logging\SafeContext;
use Pinova\Logging\SettingsAudit;

final class SettingsAuditTest extends TestCase {

	public function test_only_persisted_first_party_keys_are_reported(): void {
		$old = [
			'gateway'      => 'old-provider',
			'message_code' => 'old-secret',
		];
		$new = [
			'gateway'                  => 'new-provider',
			'message_code'             => 'new-secret',
			'private_value_in_key_123' => 'secret',
		];

		self::assertSame(
			[ 'gateway', 'message_code' ],
			SettingsAudit::changed_keys( 'pinova_sms', $old, $new )
		);
		self::assertSame( [], SettingsAudit::changed_keys( 'pinova_sms', $old, $old ) );
		self::assertSame( [], SettingsAudit::changed_keys( 'unrelated_option', $old, $new ) );
	}

	public function test_addition_removal_and_explicit_purge_option_are_named_without_values(): void {
		self::assertSame(
			[ 'minimum_level', 'retention_days' ],
			SettingsAudit::changed_keys(
				'pinova_logging',
				[ 'minimum_level' => 'warning' ],
				[
					'minimum_level'  => 'error',
					'retention_days' => 14,
				]
			)
		);
		self::assertSame( [ 'delete_data_on_uninstall' ], SettingsAudit::changed_keys( 'pinova_delete_data_on_uninstall', 0, 1 ) );
		self::assertSame( [], SettingsAudit::changed_keys( 'pinova_delete_data_on_uninstall', 1, 1 ) );
	}

	public function test_context_rejects_unknown_keys_and_adjacent_secret_values(): void {
		$context = ( new SafeContext() )->sanitize(
			[
				'changed_keys' => [ 'api_key', 'private_value_in_key_123', 'api_key', 'native_login_slug' ],
				'old_value'    => 'old-secret',
				'new_value'    => 'new-secret',
				'api_key'      => 'provider-secret',
			]
		);

		self::assertSame( [ 'api_key', 'native_login_slug' ], $context['changed_keys'] );
		self::assertSame( [ 'changed_keys' ], array_keys( $context ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure unit test runs without WordPress.
		self::assertStringNotContainsString( 'secret', (string) json_encode( $context ) );
	}
}
