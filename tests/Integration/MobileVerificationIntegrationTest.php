<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\API\MobileVerificationAPI;
use Pinova\Helpers\JWT;
use Pinova\Install;
use Pinova\Integrations\Wordpress\Privacy;
use Pinova\Integrations\Wordpress\UserProfile;
use Pinova\Integrations\Woocommerce\Account;
use Pinova\Models\OTP;
use Pinova\Objects\Identifier;
use Pinova\Services\MobileVerificationService as Proof;
use Pinova\Services\OTPService;
use Pinova\Services\RateLimitService;
use Pinova\Services\UserService;
use WP_REST_Request;
use WP_UnitTestCase;

final class MobileVerificationIntegrationTest extends WP_UnitTestCase {
	private array $server;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->server = $_SERVER;
		$_SERVER['REMOTE_ADDR'] = '192.0.2.139';
		OTP::query()->delete();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'pinova_rate_limits' ) );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$_SERVER = $this->server;
		wp_set_current_user( 0 );
		wp_unschedule_hook( 'pinova_otp_delivery' );
		remove_action( 'shutdown', [ OTPService::class, 'spawn_queued_delivery' ], 100 );
		OTP::query()->delete();
		parent::tear_down();
	}

	private function account( string $mobile = '09121234567' ): int {
		$id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $id, 'pinova_mobile', $mobile );
		return $id;
	}

	private function otp( int $id, string $mobile = '09121234567', string $purpose = OTP::TYPE_LOGIN ): OTP {
		return OTP::query()->create( [
			'user_id' => $id, 'identifier' => $mobile, 'type' => $purpose,
			'code' => '4821', 'flow_id' => bin2hex( random_bytes( 16 ) ), 'channels' => [ 'sms' => true ],
		] );
	}

	private function verify( OTP $otp ): array {
		return OTPService::verify_with_flow( OTPService::signed_state( $otp ), '4821', [ $otp->type ] );
	}

	private function request( string $route, array $params ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/pinova/mobile/' . $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	public function test_legacy_numbers_and_forged_flags_are_not_proof(): void {
		$id = $this->account();
		update_user_meta( $id, 'created_by', 'pinova' );
		update_user_meta( $id, 'login_method', 'otp' );
		update_user_meta( $id, Proof::PROOF_META, '{"version":1,"at":1,"mac":"forged"}' );
		self::assertFalse( Proof::is_verified( $id ) );
	}

	public function test_successful_mobile_login_otp_mints_bound_proof_without_setting_session(): void {
		$id = $this->account();
		$this->verify( $this->otp( $id ) );
		self::assertTrue( Proof::is_verified( $id ) );
		self::assertSame( 0, get_current_user_id() );
		$raw = get_user_meta( $id, Proof::PROOF_META, true );
		self::assertStringNotContainsString( '09121234567', $raw );
		self::assertSame( [ 'version', 'at', 'mac' ], array_keys( json_decode( $raw, true ) ) );
	}

	public function test_mobile_recovery_and_email_login_never_mint_mobile_proof(): void {
		$id = $this->account();
		$this->verify( $this->otp( $id, '09121234567', OTP::TYPE_FORGET ) );
		self::assertFalse( Proof::is_verified( $id ) );
		$this->verify( $this->otp( $id, get_userdata( $id )->user_email, OTP::TYPE_LOGIN ) );
		self::assertFalse( Proof::is_verified( $id ) );
	}

	public function test_change_delete_and_readding_old_number_cannot_revive_proof(): void {
		$id = $this->account();
		$this->verify( $this->otp( $id ) );
		$old_proof = get_user_meta( $id, Proof::PROOF_META, true );
		update_user_meta( $id, 'pinova_mobile', '09129876543' );
		self::assertFalse( Proof::is_verified( $id ) );
		delete_user_meta( $id, 'pinova_mobile' );
		add_user_meta( $id, 'pinova_mobile', '09121234567' );
		update_user_meta( $id, Proof::PROOF_META, $old_proof );
		self::assertFalse( Proof::is_verified( $id ) );
	}

	public function test_noop_profile_sync_preserves_proof_but_legacy_alias_edits_revoke(): void {
		$id = $this->account();
		$this->verify( $this->otp( $id ) );
		update_user_meta( $id, 'pinova_mobile', '09121234567' );
		self::assertTrue( Proof::is_verified( $id ) );
		update_user_meta( $id, 'digits_phone', '09129876543' );
		self::assertFalse( Proof::is_verified( $id ) );
	}

	public function test_unsupported_bulk_meta_deletion_is_rejected_instead_of_losing_revocations(): void {
		$id = $this->account();
		$this->verify( $this->otp( $id ) );
		self::assertFalse( delete_metadata( 'user', 0, 'pinova_mobile', '', true ) );
		self::assertSame( '09121234567', UserService::get_persisted_mobile( $id ) );
		self::assertTrue( Proof::is_verified( $id ) );
	}

	public function test_conflict_and_duplicate_protected_rows_fail_closed(): void {
		$id = $this->account();
		$this->verify( $this->otp( $id ) );
		$other = $this->account();
		self::assertFalse( Proof::is_verified( $id ) );
		delete_user_meta( $other, 'pinova_mobile' );
		self::assertTrue( Proof::is_verified( $id ) );
		add_user_meta( $id, Proof::EPOCH_META, get_user_meta( $id, Proof::EPOCH_META, true ) );
		self::assertFalse( Proof::is_verified( $id ) );
	}

	public function test_rotated_signing_key_invalidates_old_evidence(): void {
		$id = $this->account();
		$this->verify( $this->otp( $id ) );
		$salt = static fn( $value, $scheme ) => 'auth' === $scheme ? 'rotated-test-key' : $value;
		add_filter( 'salt', $salt, 10, 2 );
		try {
			self::assertFalse( Proof::is_verified( $id ) );
		} finally {
			remove_filter( 'salt', $salt, 10 );
		}
	}

	public function test_revocation_between_consumption_and_record_cannot_mint_evidence(): void {
		$id = $this->account();
		$epoch = Proof::epoch( $id );
		Proof::revoke( $id );
		$this->expectException( \Exception::class );
		Proof::record( $id, new Identifier( '09121234567' ), $epoch );
	}

	public function test_proof_only_new_number_preserves_account_username_session_and_reset_authority(): void {
		global $wpdb;
		$id = $this->account();
		wp_set_current_user( $id );
		$before = get_userdata( $id );
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
		$cookies = 0;
		$cookie = static function () use ( &$cookies ): void { ++$cookies; };
		add_action( 'set_auth_cookie', $cookie );
		try {
			$otp = $this->otp( $id, '09129876543', OTP::TYPE_VERIFY_MOBILE );
			[ $user ] = $this->verify( $otp );
		} finally {
			remove_action( 'set_auth_cookie', $cookie );
		}
		clean_user_cache( $id );
		self::assertSame( $id, $user->ID );
		self::assertSame( $id, get_current_user_id() );
		self::assertSame( $before->user_login, get_userdata( $id )->user_login );
		self::assertSame( $before->user_activation_key, get_userdata( $id )->user_activation_key );
		self::assertSame( $count, $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ) );
		self::assertSame( 0, $cookies );
		self::assertSame( '09129876543', UserService::get_persisted_mobile( $id ) );
		self::assertTrue( Proof::is_verified( $id ) );
	}

	public function test_wrong_user_and_login_purpose_do_not_consume_proof_only_otp(): void {
		$id = $this->account();
		$other = $this->account( '09129876543' );
		$otp = $this->otp( $id, '09121111111', OTP::TYPE_VERIFY_MOBILE );
		wp_set_current_user( $other );
		try {
			$this->verify( $otp );
			self::fail( 'Wrong user must be rejected.' );
		} catch ( \Exception $exception ) {
			self::assertFalse( $otp->fresh()->isVerified() );
			self::assertSame( 0, (int) $otp->fresh()->attempts );
		}
		wp_set_current_user( $id );
		try {
			OTPService::verify_with_flow( OTPService::signed_state( $otp ), '4821', [ OTP::TYPE_LOGIN, OTP::TYPE_REGISTER ] );
			self::fail( 'Wrong purpose must be rejected.' );
		} catch ( \Exception $exception ) {
			self::assertFalse( $otp->fresh()->isVerified() );
		}
	}

	public function test_consumed_proof_cannot_be_replayed_after_revocation(): void {
		$id = $this->account();
		wp_set_current_user( $id );
		$otp = $this->otp( $id, '09121234567', OTP::TYPE_VERIFY_MOBILE );
		$this->verify( $otp );
		Proof::revoke( $id );
		try {
			$this->verify( $otp );
			self::fail( 'Consumed proof must not replay.' );
		} catch ( \Exception $exception ) {
			self::assertFalse( Proof::is_verified( $id ) );
		}
	}

	public function test_mobile_rest_requires_current_session_and_its_nonce(): void {
		$id = $this->account();
		$api = new MobileVerificationAPI();
		$request = $this->request( 'request', [ 'mobile' => '09121234567' ] );
		self::assertSame( 403, $api->initiate( $request )->get_status() );
		wp_set_current_user( $id );
		self::assertSame( 403, $api->initiate( $request )->get_status() );
		$request = $this->request( 'request', [ 'mobile' => '09121234567' ] );
		$response = $api->initiate( $request );
		self::assertSame( 200, $response->get_status() );
		$payload = JWT::decode( $response->get_data()['data']['jwt'] );
		self::assertSame( $id, $payload['user_id'] );
		self::assertSame( OTP::TYPE_VERIFY_MOBILE, $payload['purpose'] );
		self::assertArrayNotHasKey( 'identifier', $payload );
		self::assertSame( 0, OTP::query()->count() );
	}

	public function test_one_user_proof_queue_is_bound_to_the_requested_number_and_other_users_are_isolated(): void {
		$id = $this->account();
		$other = $this->account( '09129876543' );
		$flow = RateLimitService::decoy_flow( '09121111111', OTP::TYPE_VERIFY_MOBILE, '192.0.2.139', $id );
		self::assertSame( $flow, RateLimitService::decoy_flow( '09121111111', OTP::TYPE_VERIFY_MOBILE, '192.0.2.139', $id ) );
		$other_flow = RateLimitService::decoy_flow( '09121111111', OTP::TYPE_VERIFY_MOBILE, '192.0.2.139', $other );
		self::assertNotSame( $flow[0], $other_flow[0] );
		try {
			RateLimitService::decoy_flow( '09122222222', OTP::TYPE_VERIFY_MOBILE, '192.0.2.139', $id );
			self::fail( 'A different target must not reuse an earlier code.' );
		} catch ( \Exception $exception ) {
			$claim = RateLimitService::claim_queued_otp( $flow[0] );
			self::assertSame( $id, $claim['user_id'] );
			self::assertSame( '09121111111', $claim['identifier'] );
			RateLimitService::finish_queued_otp( $flow[0], $claim['claim_token'] );
		}
	}

	public function test_privacy_erasure_cancels_new_number_queue_and_removes_evidence(): void {
		$id = $this->account();
		$this->verify( $this->otp( $id ) );
		$flow = RateLimitService::decoy_flow( '09121111111', OTP::TYPE_VERIFY_MOBILE, '192.0.2.139', $id );
		$result = Privacy::erase_personal_data( get_userdata( $id )->user_email );
		self::assertTrue( $result['done'] );
		self::assertFalse( $result['items_retained'] );
		self::assertNull( RateLimitService::claim_queued_otp( $flow[0] ) );
		self::assertFalse( Proof::is_verified( $id ) );
		self::assertSame( '', get_user_meta( $id, Proof::PROOF_META, true ) );
		self::assertSame( '', get_user_meta( $id, Proof::PENDING_META, true ) );
	}

	public function test_privacy_waits_for_active_new_number_delivery_and_prevents_further_send(): void {
		$id = $this->account();
		$flow = RateLimitService::decoy_flow( '09121111111', OTP::TYPE_VERIFY_MOBILE, '192.0.2.139', $id );
		$claim = RateLimitService::claim_queued_otp( $flow[0] );
		try {
			$result = Privacy::erase_personal_data( get_userdata( $id )->user_email );
			self::assertFalse( $result['done'] );
			self::assertTrue( $result['items_retained'] );
			self::assertFalse( RateLimitService::queued_otp_is_active( $flow[0], $claim['claim_token'] ) );
		} finally {
			RateLimitService::finish_queued_otp( $flow[0], $claim['claim_token'] );
		}
		self::assertTrue( Privacy::erase_personal_data( get_userdata( $id )->user_email )['done'] );
	}

	public function test_self_profile_changes_require_proof_while_administrator_assignment_revokes_it(): void {
		$id = $this->account();
		$this->verify( $this->otp( $id ) );
		global $wpdb;
		$wpdb->replace( $wpdb->options, [ 'option_name' => 'pinova_integrations', 'option_value' => maybe_serialize( [ 'wpforo_enabled' => '1' ] ), 'autoload' => 'no' ] );
		wp_cache_delete( 'pinova_integrations', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_set_current_user( $id );
		$errors = ( new UserProfile() )->validate_mobile( '09129876543', $id, new \WP_Error() );
		self::assertContains( 'mobile_proof_required', $errors->get_error_codes() );
		$_POST['pinova_mobile'] = '09129876543';
		try {
			$errors = new \WP_Error();
			( new Account() )->validate_mobile_field( $errors, (object) [ 'ID' => $id ] );
			self::assertContains( 'mobile_proof_required', $errors->get_error_codes() );
			( new Account() )->save_mobile( $id );
			self::assertSame( '09121234567', UserService::get_persisted_mobile( $id ) );
		} finally {
			unset( $_POST['pinova_mobile'] );
		}
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		self::assertFalse( Proof::requires_proof_for_change( $id, '09129876543' ) );
		update_user_meta( $id, 'pinova_mobile', '09129876543' );
		self::assertFalse( Proof::is_verified( $id ) );
	}

	public function test_recovery_state_binds_origin_and_current_identity_without_exposing_number(): void {
		$id = $this->account();
		$jwt = UserService::generate_jwt( $id, null, new Identifier( '09121234567' ) );
		$payload = JWT::decode( $jwt );
		self::assertSame( 'mobile', $payload['origin'] );
		self::assertStringNotContainsString( '09121234567', wp_json_encode( $payload ) );
		self::assertSame( [ $id, null, 'mobile' ], UserService::parse_jwt_with_flow( $jwt ) );
		self::assertSame( [ $id, null, 'unknown' ], UserService::parse_jwt_with_flow( JWT::encode( [ 'user_id' => $id ] ) ) );
		update_user_meta( $id, 'pinova_mobile', '09129876543' );
		$this->expectException( \Exception::class );
		UserService::parse_jwt_with_flow( $jwt );
	}

	public function test_proof_state_is_not_password_reset_authority(): void {
		$id = $this->account();
		$otp = $this->otp( $id, '09121234567', OTP::TYPE_VERIFY_MOBILE );
		$this->expectException( \Exception::class );
		UserService::parse_jwt_with_flow( OTPService::signed_state( $otp ) );
	}
	public function test_successful_registration_proves_the_created_account_only(): void {
		update_option( 'pinova_general', [ 'wordpress_users_can_register' => '1' ] );
		$otp = OTP::query()->create( [
			'identifier' => '09125556666', 'type' => OTP::TYPE_REGISTER,
			'code' => '4821', 'flow_id' => bin2hex( random_bytes( 16 ) ), 'channels' => [ 'sms' => true ],
		] );
		[ $user ] = $this->verify( $otp );
		self::assertTrue( Proof::is_verified( $user->ID ) );
		self::assertSame( $user->ID, UserService::get_by_mobile( '09125556666' ) );
		self::assertSame( 0, get_current_user_id() );
		self::assertNotContains( 'seller', $user->roles );
	}

	public function test_database_read_failure_is_not_verified(): void {
		global $wpdb;
		$id = $this->account();
		$this->verify( $this->otp( $id ) );
		$fail = static function ( string $sql ): string {
			return false !== strpos( $sql, "meta_key = '_pinova_mobile_epoch'" ) ? 'SELECT missing_pinova_column' : $sql;
		};
		$previous = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			self::assertFalse( Proof::is_verified( $id ) );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous );
		}
	}

	public function test_protected_alias_misconfiguration_cannot_recurse_or_mint_evidence(): void {
		$id = $this->account();
		$aliases = static fn( array $keys ): array => array_merge( $keys, [ Proof::PROOF_META, Proof::EPOCH_META, Proof::PENDING_META ] );
		add_filter( 'pinova/mobile_possible_meta_keys', $aliases );
		try {
			self::assertTrue( Proof::revoke( $id ) );
			self::assertFalse( Proof::is_verified( $id ) );
		} finally {
			remove_filter( 'pinova/mobile_possible_meta_keys', $aliases );
		}
	}

	public function test_key_rotation_does_not_hide_pending_new_number_from_erasure(): void {
		$id = $this->account();
		$flow = RateLimitService::decoy_flow( '09121111111', OTP::TYPE_VERIFY_MOBILE, '192.0.2.139', $id );
		$salt = static fn( $value, $scheme ) => 'auth' === $scheme ? 'rotated-queue-key' : $value;
		add_filter( 'salt', $salt, 10, 2 );
		try {
			self::assertTrue( Proof::erase_pending( $id ) );
		} finally {
			remove_filter( 'salt', $salt, 10 );
		}
		self::assertNull( RateLimitService::claim_queued_otp( $flow[0] ) );
	}

	public function test_privacy_reports_removal_when_only_private_proof_was_present(): void {
		$id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $id, Proof::PROOF_META, '{}' );
		$result = Privacy::erase_personal_data( get_userdata( $id )->user_email );
		self::assertTrue( $result['done'] );
		self::assertTrue( $result['items_removed'] );
		self::assertSame( '', get_user_meta( $id, Proof::PROOF_META, true ) );
	}

}
