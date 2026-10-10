<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\API\UserAPI;
use Pinova\Install;
use Pinova\Logging\EventEvidence;
use Pinova\Logging\IssueMonitor;
use Pinova\Logging\LogRepository;
use Pinova\Models\OTP;
use Pinova\Services\MobileVerificationService as Proof;
use Pinova\Services\OTPService;
use WP_REST_Request;
use WP_UnitTestCase;

/** Real WooCommerce saves followed by purpose-bound authentication, without provider calls. */
final class MobileMetadataRegressionTest extends WP_UnitTestCase {
	private array $server;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->server = $_SERVER;
		$_SERVER['REMOTE_ADDR'] = '192.0.2.147';
		wp_set_current_user( 0 );
		OTP::query()->delete();
		LogRepository::delete_all();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'pinova_rate_limits' ) );
		update_option( 'pinova_logging', [ 'minimum_level' => 'info', 'retention_days' => 14 ] );
	}

	public function tear_down(): void {
		$_SERVER = $this->server;
		wp_set_current_user( 0 );
		OTP::query()->delete();
		LogRepository::delete_all();
		delete_option( 'pinova_logging' );
		parent::tear_down();
	}

	private function account(): int {
		return self::factory()->user->create( [ 'user_login' => '989121234765', 'user_email' => '', 'role' => 'subscriber' ] );
	}

	private function rows( int $id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT umeta_id, meta_value FROM %i WHERE user_id = %d AND meta_key = 'pinova_mobile' ORDER BY umeta_id", $wpdb->usermeta, $id ), ARRAY_A );
	}

	private function duplicates( int $id ): void {
		add_user_meta( $id, 'pinova_mobile', '+989121234765' );
		add_user_meta( $id, 'pinova_mobile', '+989121234765' );
		self::assertCount( 2, $this->rows( $id ) );
	}

	private function otp( int $id, string $purpose = OTP::TYPE_LOGIN ): OTP {
		return OTP::query()->create( [ 'user_id' => $id, 'identifier' => '+989121234765', 'type' => $purpose, 'flow_id' => bin2hex( random_bytes( 16 ) ), 'code' => '5832', 'channels' => [ 'sms' => true ] ] );
	}

	private function request( OTP $otp ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/pinova/user/login/otp' );
		$request->set_param( 'jwt', OTPService::signed_state( $otp ) );
		$request->set_param( 'code', '5832' );
		return $request;
	}

	public function test_customer_read_save_cycles_never_persist_virtual_mobile_rows(): void {
		$id = $this->account();
		for ( $cycle = 0; $cycle < 3; ++$cycle ) {
			$customer = new \WC_Customer( $id );
			self::assertSame( '+989121234765', $customer->get_meta( 'pinova_mobile' ) );
			self::assertSame( '', $customer->get_meta( 'pinova_mobile', true, 'edit' ) );
			self::assertSame( [], $customer->get_meta( 'pinova_mobile', false ) );
			$customer->set_billing_city( 'Fixture city ' . $cycle );
			$customer->save();
			self::assertSame( [], $this->rows( $id ) );
		}
		self::assertSame( '989121234765', get_userdata( $id )->user_login );
	}

	public function test_identical_legacy_rows_survive_saves_and_allow_login_and_fresh_proof(): void {
		$id = $this->account();
		$this->duplicates( $id );
		$before = $this->rows( $id );
		self::assertFalse( Proof::is_verified( $id ) );
		for ( $cycle = 0; $cycle < 3; ++$cycle ) {
			$customer = new \WC_Customer( $id );
			self::assertSame( '+989121234765', $customer->get_meta( 'pinova_mobile' ) );
			self::assertCount( 2, $customer->get_meta( 'pinova_mobile', false ) );
			$customer->set_billing_city( 'Fixture city ' . $cycle );
			$customer->save();
			self::assertSame( $before, $this->rows( $id ) );
		}
		$otp = $this->otp( $id );
		$response = ( new UserAPI() )->login_otp( $this->request( $otp ) );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( $id, get_current_user_id() );
		self::assertTrue( Proof::is_verified( $id ) );
		self::assertSame( $before, $this->rows( $id ) );
		$customer = new \WC_Customer( $id );
		$customer->save();
		self::assertTrue( Proof::is_verified( $id ), 'An unrelated WooCommerce save must not revoke proof.' );
		wp_set_current_user( 0 );
		self::assertSame( 401, ( new UserAPI() )->login_otp( $this->request( $otp ) )->get_status(), 'The consumed OTP remains single-use.' );
	}

	public function test_upgrade_discards_only_unsaved_mobile_rows_from_old_woocommerce_cache(): void {
		$id = $this->account();
		$customer = new class( $id ) extends \WC_Customer {
			// Native customers leave this disabled; extensions can enable WC_Data caching.
			protected $cache_group = 'pinova_mobile_cache_fixture';
		};
		$customer->get_meta_data();
		$key = $customer->get_meta_cache_key();
		$cached = wp_cache_get( $key, 'pinova_mobile_cache_fixture' );
		self::assertIsArray( $cached );
		$cached[] = (object) [ 'meta_id' => 0, 'meta_key' => 'pinova_mobile', 'meta_value' => '+989121234765' ];
		$cached[] = (object) [ 'meta_key' => 'pinova_mobile', 'meta_value' => '+989121234765' ];
		wp_cache_set( $key, $cached, 'pinova_mobile_cache_fixture' );
		$customer_class = get_class( $customer );
		$customer = new $customer_class( $id );
		self::assertSame( '+989121234765', $customer->get_meta( 'pinova_mobile' ) );
		self::assertSame( [], $customer->get_meta( 'pinova_mobile', false ) );
		$customer->save();
		self::assertSame( [], $this->rows( $id ) );
	}

	public function test_identical_legacy_rows_allow_recovery_without_minting_mobile_proof(): void {
		$id = $this->account();
		$this->duplicates( $id );
		$before = $this->rows( $id );
		$api = new UserAPI();
		$response = $api->forgot_verify( $this->request( $this->otp( $id, OTP::TYPE_FORGET ) ) );
		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data()['data'];
		$request = new WP_REST_Request( 'POST', '/pinova/auth/forgot/change' );
		foreach ( $data + [ 'password_1' => 'Fixture-password-874!', 'password_2' => 'Fixture-password-874!' ] as $key => $value ) {
			$request->set_param( $key, $value );
		}
		self::assertSame( 200, $api->forgot_change( $request )->get_status() );
		self::assertTrue( wp_check_password( 'Fixture-password-874!', get_userdata( $id )->user_pass, $id ) );
		self::assertFalse( Proof::is_verified( $id ) );
		self::assertSame( $before, $this->rows( $id ) );
		self::assertSame( '989121234765', get_userdata( $id )->user_login );
	}

	/** @dataProvider ambiguous_mobile_values */
	public function test_distinct_mobile_rows_still_fail_closed( string $other ): void {
		$id = $this->account();
		$this->duplicates( $id );
		add_user_meta( $id, 'pinova_mobile', $other );
		self::assertFalse( Proof::is_verified( $id ) );
		$this->expectException( \Exception::class );
		Proof::current_mobile( $id );
	}

	public function ambiguous_mobile_values(): array {
		return [ [ '+989121234766' ], [ '+989121234765 ' ], [ '09121234765' ] ];
	}

	public function test_duplicate_security_generations_are_not_collapsed(): void {
		$id = $this->account();
		$this->duplicates( $id );
		$epoch = Proof::epoch( $id );
		add_user_meta( $id, Proof::EPOCH_META, $epoch );
		$otp = $this->otp( $id );
		self::assertSame( 503, ( new UserAPI() )->login_otp( $this->request( $otp ) )->get_status() );
		self::assertNull( $otp->fresh()->verified_at );
		self::assertSame( 0, get_current_user_id() );
		self::assertSame( 'auth.request_failed:mobile_evidence_unavailable', IssueMonitor::report()['issues'][0]['code'] );
	}

	public function test_proof_write_failure_is_reported_as_completion_failure_not_invalid_code(): void {
		$id = $this->account();
		$otp = $this->otp( $id );
		$deny = static fn( $check, $user_id, $key ) => $id === (int) $user_id && Proof::PROOF_META === $key ? false : $check;
		add_filter( 'update_user_metadata', $deny, 100, 3 );
		try {
			$response = ( new UserAPI() )->login_otp( $this->request( $otp ) );
		} finally {
			remove_filter( 'update_user_metadata', $deny, 100 );
		}
		self::assertSame( 503, $response->get_status() );
		self::assertStringContainsString( 'تکمیل درخواست', $response->get_data()['message'] );
		self::assertNotNull( $otp->fresh()->verified_at );
		self::assertSame( 0, get_current_user_id() );
		$rows = LogRepository::paginate( 1, 10, '', [ 'event' => 'auth.request_failed' ] )['rows'];
		self::assertCount( 1, $rows );
		self::assertSame( $otp->flow_id, $rows[0]['flow_id'] );
		$event = EventEvidence::redact( $rows[0] );
		self::assertSame( 'otp_completion_failed', $event['context']['reason'] );
		self::assertSame( 'verify_otp', $event['context']['operation'] );
		self::assertStringNotContainsString( '+989121234765', $rows[0]['context'] );
		self::assertArrayNotHasKey( 'code', json_decode( $rows[0]['context'], true ) );
		self::assertSame( 'confirmed_failure', IssueMonitor::report()['issues'][0]['category'] );
	}

	/** @dataProvider late_policy_modes */
	public function test_post_consumption_policy_denial_remains_an_expected_rejection( bool $method_specific ): void {
		$id = $this->account();
		$otp = $this->otp( $id );
		$calls = 0;
		$deny = static function ( $allowed, $user, $method ) use ( $method_specific, &$calls ) {
			++$calls;
			return $method_specific ? 'mobile_verification' !== $method : 1 === $calls;
		};
		add_filter( 'pinova/authentication_policy', $deny, 100, 3 );
		try {
			$response = ( new UserAPI() )->login_otp( $this->request( $otp ) );
		} finally {
			remove_filter( 'pinova/authentication_policy', $deny, 100 );
		}
		self::assertSame( 401, $response->get_status() );
		self::assertNotNull( $otp->fresh()->verified_at );
		self::assertSame( 0, get_current_user_id() );
		self::assertFalse( Proof::is_verified( $id ) );
		$issues = IssueMonitor::report()['issues'];
		self::assertCount( 1, $issues );
		self::assertSame( 'otp.verify_failed:policy_rejected', $issues[0]['code'] );
		self::assertSame( 'expected_rejection', $issues[0]['category'] );
	}

	public function late_policy_modes(): array {
		return [ [ true ], [ false ] ];
	}

	/** @dataProvider session_failure_modes */
	public function test_failure_at_the_session_boundary_is_visible( bool $throw ): void {
		$id = $this->account();
		$otp = $this->otp( $id );
		$start = static function () use ( $throw ): void {
			if ( $throw ) {
				throw new \RuntimeException( 'private-session-detail' );
			}
			add_filter( 'pinova/authentication_policy', '__return_false' );
		};
		add_action( 'pinova/authentication_start', $start );
		try {
			$response = ( new UserAPI() )->login_otp( $this->request( $otp ) );
		} finally {
			remove_action( 'pinova/authentication_start', $start );
			remove_filter( 'pinova/authentication_policy', '__return_false' );
		}
		self::assertSame( $throw ? 503 : 401, $response->get_status() );
		self::assertSame( 0, get_current_user_id() );
		$issue = IssueMonitor::report()['issues'][0];
		self::assertSame( $throw ? 'confirmed_failure' : 'expected_rejection', $issue['category'] );
		self::assertSame( 'auth.session_failed:' . ( $throw ? 'session_exception' : 'policy_rejected' ), $issue['code'] );
		self::assertSame( $otp->flow_id, $issue['evidence'][0]['flow_id'] );
		self::assertStringNotContainsString( 'private-session-detail', wp_json_encode( $issue ) );
	}

	public function session_failure_modes(): array {
		return [ [ false ], [ true ] ];
	}
}
