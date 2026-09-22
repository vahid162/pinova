<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Install;
use Pinova\Integrations\Wordpress\Privacy;
use Pinova\Logging\Logger;
use Pinova\Logging\LogRepository;
use Pinova\Services\UserService;
use WP_UnitTestCase;

final class PrivacyIntegrationTest extends WP_UnitTestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();
		LogRepository::delete_all();
	}

	public function tear_down(): void {
		LogRepository::delete_all();
		parent::tear_down();
	}

	public function test_export_includes_owned_profile_and_safe_audit_facts_only(): void {
		global $wpdb;

		$user_id = self::factory()->user->create( [ 'user_email' => 'privacy@example.test' ] );
		update_user_meta( $user_id, 'pinova_mobile', '09120000000' );
		Logger::instance()->audit(
			'warning',
			'privacy.export_test',
			[
				'user_id'                => $user_id,
				'identifier_fingerprint' => Logger::instance()->fingerprint( 'privacy@example.test', 'email' ),
				'password'               => 'never-export-password',
			]
		);
		$wpdb->insert(
			$wpdb->prefix . 'pinova_otp',
			[
				'user_id'     => $user_id,
				'identifier'  => 'privacy@example.test',
				'code'        => '789456',
				'ip_address'  => '127.0.0.1',
				'attempts'    => 0,
				'type'        => 'login',
				'channels'    => '{}',
				'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
				'verified_at' => null,
			]
		);

		$export = Privacy::export_personal_data( 'privacy@example.test', 1 );
		$json   = (string) wp_json_encode( $export );

		self::assertTrue( $export['done'] );
		self::assertStringContainsString( '+989120000000', $json );
		self::assertStringContainsString( 'privacy.export_test', $json );
		self::assertStringContainsString( Logger::instance()->correlation_id(), $json );
		self::assertStringNotContainsString( '789456', $json );
		self::assertStringNotContainsString( 'never-export-password', $json );
		self::assertStringNotContainsString( 'identifier_fingerprint', $json );
	}

	public function test_export_does_not_claim_a_mobile_shaped_login_without_owned_meta(): void {
		global $wpdb;

		$user_id = self::factory()->user->create(
			[
				'user_email' => 'login-mobile@example.test',
				'user_login' => '09123334444',
			]
		);
		$wpdb->delete(
			$wpdb->usermeta,
			[
				'user_id'  => $user_id,
				'meta_key' => 'pinova_mobile',
			],
			[ '%d', '%s' ]
		);
		clean_user_cache( $user_id );

		$export        = Privacy::export_personal_data( 'login-mobile@example.test', 1 );
		$json          = (string) wp_json_encode( $export );
		$profile_items = array_values(
			array_filter(
				$export['data'],
				static fn( array $item ): bool => 'pinova-profile' === ( $item['group_id'] ?? '' )
			)
		);

		self::assertGreaterThan( 0, $user_id );
		self::assertSame( '+989123334444', UserService::get_mobile( $user_id ) );
		self::assertNull( UserService::get_persisted_mobile( $user_id ) );
		self::assertSame( [], $profile_items );
		self::assertStringNotContainsString( '09123334444', $json );
		self::assertStringNotContainsString( '+989123334444', $json );
	}

	public function test_eraser_removes_mobile_and_otp_then_anonymizes_audit_row(): void {
		global $wpdb;

		$user_id       = self::factory()->user->create( [ 'user_email' => 'erase@example.test' ] );
		$other_user_id = self::factory()->user->create( [ 'user_email' => 'other@example.test' ] );
		update_user_meta( $user_id, 'pinova_mobile', '09121111111' );
		Logger::instance()->audit(
			'warning',
			'privacy.erase_test',
			[
				'user_id'                => $user_id,
				'identifier_fingerprint' => Logger::instance()->fingerprint( 'erase@example.test', 'email' ),
				'operation'              => 'privacy_erasure',
			]
		);
		$wpdb->insert(
			$wpdb->prefix . 'pinova_otp',
			[
				'user_id'     => $user_id,
				'identifier'  => 'erase@example.test',
				'code'        => '456123',
				'ip_address'  => '127.0.0.1',
				'attempts'    => 0,
				'type'        => 'forget',
				'channels'    => '{}',
				'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
				'verified_at' => null,
			]
		);
		$wpdb->insert(
			$wpdb->prefix . 'pinova_otp',
			[
				'user_id'     => null,
				'identifier'  => '+989121111111',
				'code'        => '111222',
				'ip_address'  => '127.0.0.1',
				'attempts'    => 0,
				'type'        => 'login',
				'channels'    => '{}',
				'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
				'verified_at' => null,
			]
		);
		$wpdb->insert(
			$wpdb->prefix . 'pinova_otp',
			[
				'user_id'     => $other_user_id,
				'identifier'  => '+989121111111',
				'code'        => '333444',
				'ip_address'  => '127.0.0.1',
				'attempts'    => 0,
				'type'        => 'login',
				'channels'    => '{}',
				'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
				'verified_at' => null,
			]
		);

		$result = Privacy::erase_personal_data( 'erase@example.test', 1 );
		$row    = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT `event`, `correlation_id`, `user_id`, `context` FROM %i WHERE `event` = %s',
				$wpdb->prefix . 'pinova_logs',
				'privacy.erase_test'
			),
			ARRAY_A
		);

		self::assertTrue( $result['items_removed'] );
		self::assertTrue( $result['done'] );
		self::assertSame(
			'0',
			(string) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE `user_id` = %d OR `identifier` = %s',
					$wpdb->prefix . 'pinova_otp',
					$user_id,
					'erase@example.test'
				)
			)
		);
		self::assertSame(
			'1',
			(string) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE `user_id` = %d AND `identifier` = %s',
					$wpdb->prefix . 'pinova_otp',
					$other_user_id,
					'+989121111111'
				)
			)
		);
		self::assertSame(
			'1',
			(string) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE `identifier` = %s',
					$wpdb->prefix . 'pinova_otp',
					'+989121111111'
				)
			)
		);
		self::assertSame(
			'0',
			(string) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE `user_id` = %d AND `meta_key` = %s',
					$wpdb->usermeta,
					$user_id,
					'pinova_mobile'
				)
			)
		);
		self::assertIsArray( $row );
		self::assertSame( 'privacy.erase_test', $row['event'] );
		self::assertNull( $row['user_id'] );
		self::assertStringContainsString( 'privacy_erasure', $row['context'] );
		self::assertStringNotContainsString( 'fingerprint', $row['context'] );
	}
}
