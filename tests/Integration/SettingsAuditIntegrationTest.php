<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Admin\Logs;
use Pinova\Install;
use Pinova\Logging\LogRepository;
use Pinova\Logging\SettingsAudit;
use WP_UnitTestCase;

final class SettingsAuditIntegrationTest extends WP_UnitTestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();
		$this->reset_request_audit_count();
		wp_set_current_user( 0 );
		update_option( 'pinova_sms', [ 'gateway' => 'before' ] );
		LogRepository::delete_all();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		LogRepository::delete_all();
		delete_option( 'pinova_sms' );
		delete_option( 'pinova_logging' );
		delete_option( 'pinova_gateway_melipayamak' );
		$this->reset_request_audit_count();
		parent::tear_down();
	}

	public function test_authorized_update_logs_only_changed_field_names(): void {
		global $wpdb;

		update_option(
			'pinova_logging',
			[
				'minimum_level'    => 'error',
				'diagnostic_until' => 0,
			]
		);
		LogRepository::delete_all();
		$actor = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $actor );
		update_option(
			'pinova_sms',
			[
				'gateway'        => 'after',
				'message_code'   => 'PRIVATE_MESSAGE_VALUE',
				'unknown_secret' => 'PRIVATE_UNKNOWN_VALUE',
			]
		);

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT `event`, `level`, `user_id`, `context` FROM %i ORDER BY `id` DESC LIMIT 1', $wpdb->prefix . 'pinova_logs' ),
			ARRAY_A
		);
		self::assertIsArray( $row );
		self::assertSame( 'settings.updated', $row['event'] );
		self::assertSame( 'notice', $row['level'] );
		self::assertSame( (string) $actor, $row['user_id'] );
		$context = json_decode( $row['context'], true );
		self::assertIsArray( $context );
		self::assertSame( 'pinova_sms', $context['operation'] );
		self::assertSame( 'success', $context['result'] );
		self::assertSame( [ 'gateway', 'message_code' ], $context['changed_keys'] );
		self::assertStringNotContainsString( 'PRIVATE_', $row['context'] );
	}

	public function test_unauthorized_and_no_op_updates_do_not_emit_audit_events(): void {
		global $wpdb;

		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );
		update_option( 'pinova_sms', [ 'gateway' => 'subscriber-change' ] );
		$actor = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $actor );
		update_option( 'pinova_sms', [ 'gateway' => 'subscriber-change' ] );

		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE `event` = %s', $wpdb->prefix . 'pinova_logs', 'settings.updated' ) );
		self::assertSame( 0, (int) $count );
	}

	public function test_authorized_provider_credential_update_records_names_not_values(): void {
		global $wpdb;

		update_option( 'pinova_gateway_melipayamak', [ 'username' => 'old-username' ] );
		LogRepository::delete_all();
		$actor = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $actor );
		update_option(
			'pinova_gateway_melipayamak',
			[
				'username'       => 'PRIVATE_USERNAME_VALUE',
				'password'       => 'PRIVATE_PASSWORD_VALUE',
				'sender'         => 'PRIVATE_SENDER_VALUE',
				'unknown_secret' => 'PRIVATE_UNKNOWN_VALUE',
			]
		);

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT `event`, `user_id`, `context` FROM %i ORDER BY `id` DESC LIMIT 1', $wpdb->prefix . 'pinova_logs' ),
			ARRAY_A
		);
		self::assertIsArray( $row );
		self::assertSame( 'settings.updated', $row['event'] );
		self::assertSame( (string) $actor, $row['user_id'] );
		self::assertStringNotContainsString( 'PRIVATE_', $row['context'] );
		$context = json_decode( $row['context'], true );
		self::assertIsArray( $context );
		self::assertSame( 'pinova_gateway_melipayamak', $context['operation'] );
		self::assertSame( 'success', $context['result'] );
		self::assertSame( [ 'username', 'password', 'sender' ], $context['changed_keys'] );

		$exported = Logs::redact_incident_row(
			$row + [
				'created_at'     => '2026-01-01 00:00:00',
				'level'          => 'notice',
				'correlation_id' => 'provider-audit',
			]
		);
		self::assertSame( 'pinova_gateway_melipayamak', $exported['context']['operation'] );
		self::assertSame( [ 'username', 'password', 'sender' ], $exported['context']['changed_keys'] );
		self::assertStringNotContainsString( 'PRIVATE_', wp_json_encode( $exported ) );
	}

	public function test_audit_events_are_capped_per_request(): void {
		global $wpdb;

		$actor = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $actor );

		for ( $index = 0; $index < 25; ++$index ) {
			update_option( 'pinova_sms', [ 'gateway' => 'provider_' . $index ] );
		}

		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE `event` = %s', $wpdb->prefix . 'pinova_logs', 'settings.updated' ) );
		self::assertSame( 20, (int) $count );
	}

	/** PHPUnit runs many simulated requests in one PHP process. */
	private function reset_request_audit_count(): void {
		$property = new \ReflectionProperty( SettingsAudit::class, 'emitted' );
		$property->setValue( null, 0 );
	}
}
