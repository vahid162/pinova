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

		$erasure = Privacy::erase_personal_data( 'login-mobile@example.test', 1 );

		self::assertFalse( $erasure['items_removed'] );
	}

	public function test_eraser_reports_retained_data_when_a_physical_delete_fails(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'retry-erasure@example.test' ] );
		update_user_meta( $user_id, 'pinova_mobile', '09125556666' );

		$block_delete = static function ( $check, int $object_id, string $meta_key ) use ( $user_id ) {
			if ( $user_id === $object_id && 'pinova_mobile' === $meta_key ) {
				return false;
			}

			return $check;
		};
		add_filter( 'delete_user_metadata', $block_delete, 10, 3 );

		try {
			$result = Privacy::erase_personal_data( 'retry-erasure@example.test', 1 );
		} finally {
			remove_filter( 'delete_user_metadata', $block_delete, 10 );
		}

		self::assertFalse( $result['items_removed'] );
		self::assertTrue( $result['items_retained'] );
		self::assertFalse( $result['done'] );
		self::assertNotSame( [], $result['messages'] );
		self::assertSame( '+989125556666', UserService::get_persisted_mobile( $user_id ) );
	}

	public function test_eraser_keeps_mobile_until_all_log_batches_finish(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'batched-erasure@example.test' ] );
		update_user_meta( $user_id, 'pinova_mobile', '09126667777' );

		for ( $index = 0; $index <= 100; ++$index ) {
			Logger::instance()->audit(
				'warning',
				'privacy.batched_erasure',
				[
					'user_id'   => $user_id,
					'operation' => 'privacy_erasure',
				]
			);
		}

		$first = Privacy::erase_personal_data( 'batched-erasure@example.test', 1 );

		self::assertFalse( $first['done'] );
		self::assertFalse( $first['items_retained'] );
		self::assertSame( '+989126667777', UserService::get_persisted_mobile( $user_id ) );

		$second = Privacy::erase_personal_data( 'batched-erasure@example.test', 2 );

		self::assertTrue( $second['done'] );
		self::assertFalse( $second['items_retained'] );
		self::assertNull( UserService::get_persisted_mobile( $user_id ) );
	}

	public function test_eraser_cleans_pre_account_records_for_a_pinova_created_mobile_login(): void {
		global $wpdb;

		$user_id = self::factory()->user->create(
			[
				'user_email' => 'registered-by-pinova@example.test',
				'user_login' => '09127778888',
				'meta_input' => [ 'created_by' => 'pinova' ],
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

		$fingerprint = Logger::instance()->fingerprint( '+989127778888', 'mobile' );
		Logger::instance()->audit(
			'info',
			'privacy.pre_account',
			[
				'identifier_type'        => 'mobile',
				'identifier_fingerprint' => $fingerprint,
			]
		);
		$wpdb->insert(
			$wpdb->prefix . 'pinova_otp',
			[
				'user_id'     => null,
				'identifier'  => '+989127778888',
				'code'        => '555666',
				'ip_address'  => '127.0.0.1',
				'attempts'    => 0,
				'type'        => 'register',
				'channels'    => '{}',
				'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
				'verified_at' => null,
			]
		);

		$result = Privacy::erase_personal_data( 'registered-by-pinova@example.test', 1 );
		$context = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT `context` FROM %i WHERE `event` = %s',
				$wpdb->prefix . 'pinova_logs',
				'privacy.pre_account'
			)
		);

		self::assertTrue( $result['items_removed'] );
		self::assertFalse( $result['items_retained'] );
		self::assertTrue( $result['done'] );
		self::assertSame(
			'0',
			(string) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE `identifier` = %s',
					$wpdb->prefix . 'pinova_otp',
					'+989127778888'
				)
			)
		);
		self::assertStringNotContainsString( $fingerprint, $context );
		self::assertStringNotContainsString( 'identifier_fingerprint', $context );
	}

	public function test_eraser_uses_the_matched_accounts_stored_email_for_fingerprints(): void {
		global $wpdb;

		$stored_email = 'StoredCase@example.test';
		$user_id      = self::factory()->user->create( [ 'user_email' => $stored_email ] );
		$fingerprint  = Logger::instance()->fingerprint( $stored_email, 'email' );
		Logger::instance()->audit(
			'info',
			'privacy.pre_account_email',
			[
				'identifier_type'        => 'email',
				'identifier_fingerprint' => $fingerprint,
			]
		);

		$result  = Privacy::erase_personal_data( 'storedcase@example.test', 1 );
		$context = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT `context` FROM %i WHERE `event` = %s',
				$wpdb->prefix . 'pinova_logs',
				'privacy.pre_account_email'
			)
		);

		self::assertGreaterThan( 0, $user_id );
		self::assertTrue( $result['done'] );
		self::assertFalse( $result['items_retained'] );
		self::assertStringNotContainsString( $fingerprint, $context );
		self::assertStringNotContainsString( 'identifier_fingerprint', $context );
	}

	public function test_eraser_removes_unowned_pre_account_email_records_without_a_user(): void {
		global $wpdb;

		$email       = 'orphaned-request@example.test';
		$fingerprint = Logger::instance()->fingerprint( $email, 'email' );
		Logger::instance()->audit(
			'info',
			'privacy.orphaned_pre_account',
			[
				'identifier_type'        => 'email',
				'identifier_fingerprint' => $fingerprint,
			]
		);
		$wpdb->insert(
			$wpdb->prefix . 'pinova_otp',
			[
				'user_id'     => null,
				'identifier'  => $email,
				'code'        => '246810',
				'ip_address'  => '127.0.0.1',
				'attempts'    => 0,
				'type'        => 'register',
				'channels'    => '{}',
				'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
				'verified_at' => null,
			]
		);

		$result  = Privacy::erase_personal_data( $email, 1 );
		$context = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT `context` FROM %i WHERE `event` = %s',
				$wpdb->prefix . 'pinova_logs',
				'privacy.orphaned_pre_account'
			)
		);

		self::assertFalse( get_user_by( 'email', $email ) );
		self::assertTrue( $result['items_removed'] );
		self::assertTrue( $result['done'] );
		self::assertFalse( $result['items_retained'] );
		self::assertSame(
			'0',
			(string) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE `identifier` = %s',
					$wpdb->prefix . 'pinova_otp',
					$email
				)
			)
		);
		self::assertStringNotContainsString( $fingerprint, $context );
		self::assertStringNotContainsString( 'identifier_fingerprint', $context );
	}

	public function test_eraser_removes_mobile_and_otp_then_anonymizes_audit_row(): void {
		global $wpdb;

		$user_id       = self::factory()->user->create( [ 'user_email' => 'erase@example.test' ] );
		$other_user_id = self::factory()->user->create( [ 'user_email' => 'other@example.test' ] );
		$fingerprint   = Logger::instance()->fingerprint( 'erase@example.test', 'email' );
		update_user_meta( $user_id, 'pinova_mobile', '09121111111' );
		Logger::instance()->audit(
			'warning',
			'privacy.erase_test',
			[
				'user_id'                => $user_id,
				'identifier_fingerprint' => $fingerprint,
				'operation'              => 'privacy_erasure',
			]
		);
		Logger::instance()->audit(
			'warning',
			'privacy.other_owner',
			[
				'user_id'                => $other_user_id,
				'identifier_fingerprint' => $fingerprint,
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
		$other_log = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT `user_id`, `context` FROM %i WHERE `event` = %s',
				$wpdb->prefix . 'pinova_logs',
				'privacy.other_owner'
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
		self::assertIsArray( $other_log );
		self::assertSame( (string) $other_user_id, (string) $other_log['user_id'] );
		self::assertStringContainsString( $fingerprint, $other_log['context'] );
	}
}
