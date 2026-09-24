<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Install;
use Pinova\Integrations\Wordpress\Privacy;
use Pinova\Logging\Logger;
use Pinova\Logging\LogRepository;
use Pinova\Models\OTP;
use Pinova\Services\UserService;
use Pinova\Services\RateLimitService;
use Pinova\Services\OTPService;
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
			'auth.request_failed',
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
		self::assertStringContainsString( 'auth.request_failed', $json );
		self::assertStringContainsString( Logger::instance()->correlation_id(), $json );
		self::assertStringNotContainsString( '789456', $json );
		self::assertStringNotContainsString( 'never-export-password', $json );
		self::assertStringNotContainsString( 'identifier_fingerprint', $json );
	}

	public function test_approved_erasure_removes_a_pending_encrypted_otp_delivery(): void {
		global $wpdb;

		$email = 'queued-erasure@example.test';
		self::factory()->user->create( [ 'user_email' => $email ] );
		[ $flow_id ] = RateLimitService::decoy_flow( $email, 'authenticate', '192.0.2.44' );
		self::assertNotNull( $wpdb->get_var( $wpdb->prepare( 'SELECT `payload` FROM %i WHERE `scope` = %s', $wpdb->prefix . 'pinova_rate_limits', $flow_id ) ) );

		$result = Privacy::erase_personal_data( $email );
		self::assertTrue( $result['done'] );
		self::assertNull( RateLimitService::claim_queued_otp( $flow_id ) );
		self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT `payload` FROM %i WHERE `scope` = %s', $wpdb->prefix . 'pinova_rate_limits', $flow_id ) ) );
	}

	public function test_erasure_remains_incomplete_until_a_claimed_worker_has_exited(): void {
		$email = 'claimed-erasure@example.test';
		self::factory()->user->create( [ 'user_email' => $email ] );
		[ $flow_id ] = RateLimitService::decoy_flow( $email, 'authenticate', '192.0.2.44' );
		$claim = RateLimitService::claim_queued_otp( $flow_id );
		self::assertNotNull( $claim );

		$first = Privacy::erase_personal_data( $email );
		self::assertFalse( $first['done'] );
		self::assertTrue( $first['items_retained'] );
		self::assertFalse( RateLimitService::queued_otp_is_active( $flow_id, $claim['claim_token'] ) );
		RateLimitService::finish_queued_otp( $flow_id, $claim['claim_token'] );

		$second = Privacy::erase_personal_data( $email );
		self::assertTrue( $second['done'] );
		self::assertFalse( $second['items_retained'] );
		self::assertNull( RateLimitService::claim_queued_otp( $flow_id ) );
	}

	public function test_erasure_retires_a_cancelled_claim_after_worker_crash(): void {
		global $wpdb;

		$email = 'crashed-claimed-erasure@example.test';
		self::factory()->user->create( [ 'user_email' => $email ] );
		[ $flow_id ] = RateLimitService::decoy_flow( $email, 'authenticate', '192.0.2.44' );
		self::assertNotNull( RateLimitService::claim_queued_otp( $flow_id ) );
		self::assertFalse( Privacy::erase_personal_data( $email )['done'] );
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'pinova_otp_' . $flow_id ) );

		self::assertTrue( Privacy::erase_personal_data( $email )['done'] );
		self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT `payload` FROM %i WHERE `scope` = %s', $wpdb->prefix . 'pinova_rate_limits', $flow_id ) ) );
	}

	public function test_erasure_retires_a_legacy_processing_marker_after_grace(): void {
		global $wpdb;

		$email = 'legacy-claim-erasure@example.test';
		self::factory()->user->create( [ 'user_email' => $email ] );
		[ $flow_id ] = RateLimitService::decoy_flow( $email, 'authenticate', '192.0.2.44' );
		$table = $wpdb->prefix . 'pinova_rate_limits';
		$wpdb->update( $table, [ 'payload' => 'processing', 'reset_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ], [ 'scope' => $flow_id ] );
		self::assertFalse( Privacy::erase_personal_data( $email )['done'] );
		$wpdb->update( $table, [ 'reset_at' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS - 2 ) ], [ 'scope' => $flow_id ] );
		self::assertTrue( Privacy::erase_personal_data( $email )['done'] );
		self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT `payload` FROM %i WHERE `scope` = %s', $table, $flow_id ) ) );
	}

	public function test_verified_flow_keeps_an_active_delivery_barrier_until_worker_exit(): void {
		$email   = 'verified-while-sending@example.test';
		$user_id = self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );
		[ $flow_id ] = RateLimitService::decoy_flow( $email, 'authenticate', '192.0.2.44' );
		$claim = RateLimitService::claim_queued_otp( $flow_id );
		self::assertNotNull( $claim );
		$otp = OTP::query()->create(
			[
				'user_id'    => $user_id,
				'flow_id'    => $flow_id,
				'identifier' => $email,
				'code'       => '1234',
				'type'       => OTP::TYPE_LOGIN,
				'channels'   => [ 'email' => false ],
			]
		);
		OTPService::verify_with_flow( OTPService::signed_state( $otp ), '1234', [ OTP::TYPE_LOGIN ] );

		$first = Privacy::erase_personal_data( $email );
		self::assertFalse( $first['done'] );
		self::assertTrue( $first['items_retained'] );
		RateLimitService::finish_queued_otp( $flow_id, $claim['claim_token'] );
		self::assertTrue( Privacy::erase_personal_data( $email )['done'] );
	}

	public function test_expired_flow_does_not_hide_an_in_flight_delivery_from_erasure(): void {
		global $wpdb;

		$email = 'expired-while-sending@example.test';
		self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );
		[ $flow_id ] = RateLimitService::decoy_flow( $email, 'authenticate', '192.0.2.44' );
		$claim = RateLimitService::claim_queued_otp( $flow_id );
		self::assertNotNull( $claim );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET `reset_at` = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE `scope` = %s', $wpdb->prefix . 'pinova_rate_limits', $flow_id ) );

		$first = Privacy::erase_personal_data( $email );
		self::assertFalse( $first['done'] );
		self::assertNotNull( $wpdb->get_var( $wpdb->prepare( 'SELECT `payload` FROM %i WHERE `scope` = %s', $wpdb->prefix . 'pinova_rate_limits', $flow_id ) ) );
		RateLimitService::finish_queued_otp( $flow_id, $claim['claim_token'] );
		self::assertTrue( Privacy::erase_personal_data( $email )['done'] );
	}

	public function test_erasure_during_provider_send_reports_incomplete(): void {
		global $wpdb;

		$email = 'sending-erasure@example.test';
		self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );
		[ $flow_id ] = RateLimitService::decoy_flow( $email, 'authenticate', '192.0.2.44' );
		$first = null;
		$mail = static function () use ( $email, &$first ): bool {
			$first = Privacy::erase_personal_data( $email );
			return true;
		};
		add_filter( 'pre_wp_mail', $mail );
		try {
			OTPService::deliver_queued( $flow_id );
		} finally {
			remove_filter( 'pre_wp_mail', $mail );
		}

		self::assertIsArray( $first );
		self::assertFalse( $first['done'] );
		self::assertTrue( $first['items_retained'] );
		// PHPUnit wraps wpdb in a transaction while Eloquent uses a separate
		// connection. A same-request delete can hit MySQL's stale-read error;
		// the next WordPress privacy request retries outside this transaction.
		self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT `payload` FROM %i WHERE `scope` = %s', $wpdb->prefix . 'pinova_rate_limits', $flow_id ) ) );
	}

	public function test_erasure_covers_a_uniquely_owned_digits_only_mobile_queue(): void {
		global $wpdb;

		$email   = 'digits-only-erasure@example.test';
		$user_id = self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );
		update_user_meta( $user_id, 'digits_phone', '09123450123' );
		$mobile = '+989123450123';
		[ $flow_id ] = RateLimitService::decoy_flow( $mobile, 'authenticate', '192.0.2.44' );
		self::assertSame( $mobile, UserService::get_mobile( $user_id ) );

		$result = Privacy::erase_personal_data( $email );
		self::assertTrue( $result['done'] );
		self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT `payload` FROM %i WHERE `scope` = %s', $wpdb->prefix . 'pinova_rate_limits', $flow_id ) ) );
	}

	public function test_erasure_covers_a_configured_legacy_mobile_alias_queue(): void {
		global $wpdb;

		$email   = 'configured-alias-erasure@example.test';
		$user_id = self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );
		$previous_advanced = get_option( 'pinova_advanced', false );
		update_option( 'pinova_advanced', [ 'mobile_possible_meta_keys' => 'legacy_mobile_for_test' ] );
		try {
			update_user_meta( $user_id, 'legacy_mobile_for_test', '09123450678' );
			$mobile = '+989123450678';
			[ $flow_id ] = RateLimitService::decoy_flow( $mobile, 'authenticate', '192.0.2.44' );
			self::assertSame( $mobile, UserService::get_mobile( $user_id ) );

			$result = Privacy::erase_personal_data( $email );
			self::assertTrue( $result['done'] );
			self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT `payload` FROM %i WHERE `scope` = %s', $wpdb->prefix . 'pinova_rate_limits', $flow_id ) ) );
		} finally {
			if ( false === $previous_advanced ) {
				delete_option( 'pinova_advanced' );
			} else {
				update_option( 'pinova_advanced', $previous_advanced );
			}
		}
	}

	public function test_erasure_clears_a_superseded_alias_before_removing_the_mobile_override(): void {
		global $wpdb;

		$email   = 'superseded-alias-erasure@example.test';
		$user_id = self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );
		update_user_meta( $user_id, 'digits_phone', '09123450888' );
		update_user_meta( $user_id, 'pinova_mobile', '+989123450999' );
		[ $flow_id ] = RateLimitService::decoy_flow( '+989123450888', 'authenticate', '192.0.2.44' );
		self::assertSame( [], UserService::mobile_candidate_ids_result( '+989123450888' )['ids'] );

		$result = Privacy::erase_personal_data( $email );
		self::assertTrue( $result['done'] );
		self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT `payload` FROM %i WHERE `scope` = %s', $wpdb->prefix . 'pinova_rate_limits', $flow_id ) ) );
		// The compatibility filter may expose the retained Digits alias after the
		// physical override is deleted; inspect the physical row directly.
		self::assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE `user_id` = %d AND `meta_key` = %s', $wpdb->usermeta, $user_id, 'pinova_mobile' ) ) );
	}

	public function test_erasure_does_not_delete_an_ambiguous_mobile_alias_queue(): void {
		global $wpdb;

		$email = 'ambiguous-erasure@example.test';
		$first_id = self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );
		$other_id = self::factory()->user->create( [ 'user_email' => 'other-ambiguous@example.test', 'role' => 'subscriber' ] );
		update_user_meta( $first_id, 'digits_phone', '09123450456' );
		update_user_meta( $other_id, 'digits_phone_no', '09123450456' );
		[ $flow_id ] = RateLimitService::decoy_flow( '+989123450456', 'authenticate', '192.0.2.44' );

		$result = Privacy::erase_personal_data( $email );
		self::assertFalse( $result['done'] );
		self::assertTrue( $result['items_retained'] );
		self::assertNotNull( $wpdb->get_var( $wpdb->prepare( 'SELECT `payload` FROM %i WHERE `scope` = %s', $wpdb->prefix . 'pinova_rate_limits', $flow_id ) ) );
	}

	public function test_erasure_retries_when_alias_ownership_read_fails(): void {
		global $wpdb;

		$email = 'alias-read-failure@example.test';
		$user_id = self::factory()->user->create( [ 'user_email' => $email ] );
		update_user_meta( $user_id, 'digits_phone', '09123450789' );
		[ $flow_id ] = RateLimitService::decoy_flow( '+989123450789', 'authenticate', '192.0.2.44' );
		$fail_alias_read = static function ( string $query ) use ( $wpdb ): string {
			if ( str_contains( $query, '`meta_key` IN' ) && str_contains( $query, $wpdb->usermeta ) ) {
				return "SELECT `pinova_missing_column` FROM {$wpdb->usermeta} LIMIT 1";
			}
			return $query;
		};
		add_filter( 'query', $fail_alias_read );
		try {
			$result = Privacy::erase_personal_data( $email );
		} finally {
			remove_filter( 'query', $fail_alias_read );
		}

		self::assertFalse( $result['done'] );
		self::assertTrue( $result['items_retained'] );
		self::assertNotNull( $wpdb->get_var( $wpdb->prepare( 'SELECT `payload` FROM %i WHERE `scope` = %s', $wpdb->prefix . 'pinova_rate_limits', $flow_id ) ) );
	}

	public function test_export_redacts_legacy_secrets_in_event_and_correlation_fields(): void {
		global $wpdb;

		$email   = 'historical-log@example.test';
		$user_id = self::factory()->user->create( [ 'user_email' => $email ] );
		$wpdb->insert(
			$wpdb->prefix . 'pinova_logs',
			[
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
				'level'          => 'warning',
				'event'          => 'otp.09123456789',
				'correlation_id' => 'legacy-token-secret',
				'user_id'        => $user_id,
				'context'        => '{}',
			]
		);

		$export = Privacy::export_personal_data( $email, 1 );
		$json   = (string) wp_json_encode( $export );

		self::assertTrue( $export['done'] );
		self::assertStringContainsString( 'logging.unknown_event', $json );
		self::assertStringNotContainsString( '09123456789', $json );
		self::assertStringNotContainsString( 'legacy-token-secret', $json );
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

	public function test_eraser_anonymizes_an_unowned_mobile_fingerprint_after_key_rotation(): void {
		global $wpdb;

		$user_id = self::factory()->user->create(
			[
				'user_email' => 'rotated-mobile-key@example.test',
				'user_login' => '09125554444',
				'meta_input' => [ 'created_by' => 'pinova' ],
			]
		);
		$other_user_id = self::factory()->user->create( [ 'user_email' => 'rotated-mobile-key-other@example.test' ] );
		$old_fingerprint = substr( hash_hmac( 'sha256', 'mobile:+989125554444', 'retired-auth-salt' ), 0, 32 );

		Logger::instance()->audit(
			'info',
			'privacy.rotated_mobile_key',
			[
				'identifier_type'        => 'mobile',
				'identifier_fingerprint' => $old_fingerprint,
			]
		);
		Logger::instance()->audit(
			'info',
			'privacy.rotated_mobile_key_other_owner',
			[
				'user_id'                => $other_user_id,
				'identifier_type'        => 'mobile',
				'identifier_fingerprint' => $old_fingerprint,
			]
		);

		$result          = Privacy::erase_personal_data( 'rotated-mobile-key@example.test', 1 );
		$unowned_context = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT `context` FROM %i WHERE `event` = %s',
				$wpdb->prefix . 'pinova_logs',
				'privacy.rotated_mobile_key'
			)
		);
		$owned_row       = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT `user_id`, `context` FROM %i WHERE `event` = %s',
				$wpdb->prefix . 'pinova_logs',
				'privacy.rotated_mobile_key_other_owner'
			),
			ARRAY_A
		);

		self::assertGreaterThan( 0, $user_id );
		self::assertTrue( $result['items_removed'] );
		self::assertFalse( $result['items_retained'] );
		self::assertTrue( $result['done'] );
		self::assertStringNotContainsString( $old_fingerprint, $unowned_context );
		self::assertStringNotContainsString( 'identifier_fingerprint', $unowned_context );
		self::assertIsArray( $owned_row );
		self::assertSame( (string) $other_user_id, (string) $owned_row['user_id'] );
		self::assertStringContainsString( $old_fingerprint, (string) $owned_row['context'] );
	}

	public function test_eraser_uses_the_matched_accounts_stored_email_for_fingerprints(): void {
		global $wpdb;

		$stored_email = 'StoredCase@example.test';
		$user_id      = self::factory()->user->create( [ 'user_email' => $stored_email ] );
		$fingerprint  = Logger::instance()->legacy_fingerprint( 'STOREDcase@example.test', 'email' );
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

	public function test_export_aborts_when_an_owned_read_fails(): void {
		global $wpdb;

		$email   = 'export-read-failure@example.test';
		$user_id = self::factory()->user->create( [ 'user_email' => $email ] );
		update_user_meta( $user_id, 'pinova_mobile', '09124445555' );
		Logger::instance()->audit( 'warning', 'privacy.export_read_failure', [ 'user_id' => $user_id ] );

		$fail_mobile_read = static function ( string $query ) use ( $wpdb ): string {
			if ( str_contains( $query, 'SELECT `meta_value`' ) && str_contains( $query, 'pinova_mobile' ) ) {
				return "SELECT `pinova_missing_column` FROM {$wpdb->usermeta} LIMIT 1";
			}

			return $query;
		};
		add_filter( 'query', $fail_mobile_read );
		try {
			$mobile_failure = Privacy::export_personal_data( $email, 1 );
		} finally {
			remove_filter( 'query', $fail_mobile_read );
		}

		$fail_log_read = static function ( string $query ) use ( $wpdb ): string {
			if ( str_contains( $query, 'SELECT `id`, `created_at`, `event`, `correlation_id`' ) ) {
				return "SELECT `pinova_missing_column` FROM {$wpdb->prefix}pinova_logs LIMIT 1";
			}

			return $query;
		};
		add_filter( 'query', $fail_log_read );
		try {
			$log_failure = Privacy::export_personal_data( $email, 1 );
			$later_page_failure = Privacy::export_personal_data( $email, 2 );
		} finally {
			remove_filter( 'query', $fail_log_read );
		}

		self::assertWPError( $mobile_failure );
		self::assertSame( 'pinova_privacy_export_failed', $mobile_failure->get_error_code() );
		self::assertWPError( $log_failure );
		self::assertSame( 'pinova_privacy_export_failed', $log_failure->get_error_code() );
		self::assertWPError( $later_page_failure );
		self::assertSame( 'pinova_privacy_export_failed', $later_page_failure->get_error_code() );
	}

	public function test_eraser_retries_when_the_account_lookup_fails(): void {
		global $wpdb;

		$email   = 'account-read-failure@example.test';
		$user_id = self::factory()->user->create( [ 'user_email' => $email ] );
		update_user_meta( $user_id, 'pinova_mobile', '09123336666' );
		$wpdb->insert(
			$wpdb->prefix . 'pinova_otp',
			[
				'user_id'     => $user_id,
				'identifier'  => $email,
				'code'        => '112233',
				'ip_address'  => '127.0.0.1',
				'attempts'    => 0,
				'type'        => 'login',
				'channels'    => '{}',
				'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
				'verified_at' => null,
			]
		);

		$fail_user_read = static function ( string $query ) use ( $wpdb ): string {
			if ( str_contains( $query, 'SELECT `ID`' ) && str_contains( $query, '`user_email`' ) ) {
				return "SELECT `pinova_missing_column` FROM {$wpdb->users} LIMIT 1";
			}

			return $query;
		};
		add_filter( 'query', $fail_user_read );
		try {
			$export = Privacy::export_personal_data( $email, 1 );
			$result = Privacy::erase_personal_data( $email, 1 );
		} finally {
			remove_filter( 'query', $fail_user_read );
		}

		self::assertWPError( $export );
		self::assertSame( 'pinova_privacy_export_failed', $export->get_error_code() );
		self::assertFalse( $result['items_removed'] );
		self::assertTrue( $result['items_retained'] );
		self::assertFalse( $result['done'] );
		self::assertSame( '+989123336666', UserService::get_persisted_mobile( $user_id ) );
		self::assertSame(
			'1',
			(string) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE `user_id` = %d',
					$wpdb->prefix . 'pinova_otp',
					$user_id
				)
			)
		);
	}

	public function test_eraser_retries_when_the_physical_mobile_lookup_fails(): void {
		global $wpdb;

		$email   = 'mobile-read-failure@example.test';
		$user_id = self::factory()->user->create( [ 'user_email' => $email ] );
		update_user_meta( $user_id, 'pinova_mobile', '09128889999' );
		$wpdb->insert(
			$wpdb->prefix . 'pinova_otp',
			[
				'user_id'     => $user_id,
				'identifier'  => '+989128889999',
				'code'        => '135790',
				'ip_address'  => '127.0.0.1',
				'attempts'    => 0,
				'type'        => 'login',
				'channels'    => '{}',
				'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
				'verified_at' => null,
			]
		);

		$fail_mobile_read = static function ( string $query ) use ( $wpdb ): string {
			if ( str_contains( $query, 'SELECT `meta_value`' ) && str_contains( $query, 'pinova_mobile' ) ) {
				return "SELECT `pinova_missing_column` FROM {$wpdb->usermeta} LIMIT 1";
			}

			return $query;
		};
		add_filter( 'query', $fail_mobile_read );
		try {
			$result = Privacy::erase_personal_data( $email, 1 );
		} finally {
			remove_filter( 'query', $fail_mobile_read );
		}

		self::assertFalse( $result['items_removed'] );
		self::assertTrue( $result['items_retained'] );
		self::assertFalse( $result['done'] );
		self::assertSame( '+989128889999', UserService::get_persisted_mobile( $user_id ) );
		self::assertSame(
			'1',
			(string) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE `user_id` = %d',
					$wpdb->prefix . 'pinova_otp',
					$user_id
				)
			)
		);
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
				'flow_id'                => str_repeat( 'a', 32 ),
				'identifier_fingerprint' => $fingerprint,
				'operation'              => 'privacy_erasure',
			]
		);
		Logger::instance()->audit(
			'warning',
			'privacy.other_owner',
			[
				'user_id'                => $other_user_id,
				'flow_id'                => str_repeat( 'b', 32 ),
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
				'SELECT `event`, `correlation_id`, `flow_id`, `user_id`, `context` FROM %i WHERE `event` = %s',
				$wpdb->prefix . 'pinova_logs',
				'privacy.erase_test'
			),
			ARRAY_A
		);
		$other_log = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT `flow_id`, `user_id`, `context` FROM %i WHERE `event` = %s',
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
		self::assertNull( $row['flow_id'] );
		self::assertStringContainsString( 'privacy_erasure', $row['context'] );
		self::assertStringNotContainsString( 'fingerprint', $row['context'] );
		self::assertIsArray( $other_log );
		self::assertSame( (string) $other_user_id, (string) $other_log['user_id'] );
		self::assertSame( str_repeat( 'b', 32 ), $other_log['flow_id'] );
		self::assertStringContainsString( $fingerprint, $other_log['context'] );
	}
}
