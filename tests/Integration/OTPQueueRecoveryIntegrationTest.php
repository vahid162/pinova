<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Illuminate\Contracts\Events\Dispatcher;
use Pinova\API\MobileVerificationAPI;
use Pinova\API\UserAPI;
use Pinova\Helpers\JWT;
use Pinova\Install;
use Pinova\Logging\LogRepository;
use Pinova\Models\OTP;
use Pinova\Objects\Identifier;
use Pinova\Pinova;
use Pinova\Services\OTPService;
use Pinova\Services\RateLimitService;
use WP_REST_Request;
use WP_UnitTestCase;

/** Synthetic providers only: no test contacts a recipient. */
final class OTPQueueRecoveryIntegrationTest extends WP_UnitTestCase {
	private int $mail_calls = 0;
	private bool $mail_throws = false;
	private array $flows = [];

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'pinova_rate_limits' ) );
		OTP::query()->delete();
		LogRepository::delete_all();
		$this->mail_calls = 0;
		$this->mail_throws = false;
		$this->flows = [];
		wp_set_current_user( 0 );
		update_option( 'pinova_logging', [ 'minimum_level' => 'info', 'retention_days' => 14 ] );
		add_action( 'pinova_otp_delivery', [ OTPService::class, 'deliver_queued' ] );
		add_filter( 'pre_wp_mail', [ $this, 'intercept_mail' ] );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', [ $this, 'intercept_mail' ] );
		remove_action( 'shutdown', [ OTPService::class, 'spawn_queued_delivery' ], 100 );
		wp_unschedule_hook( 'pinova_otp_delivery' );
		wp_unschedule_hook( 'pinova_otp_reconcile' );
		foreach ( $this->flows as $flow ) {
			RateLimitService::retire_queued_otp( $flow );
		}
		OTP::query()->delete();
		LogRepository::delete_all();
		delete_option( 'pinova_logging' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function intercept_mail(): bool {
		++$this->mail_calls;
		if ( $this->mail_throws ) {
			throw new \RuntimeException( 'private-provider-outcome' );
		}
		return true;
	}

	public function test_public_response_is_accepted_without_a_provider_then_lost_cron_recovers_once(): void {
		$email = 'public-recovery@example.test';
		self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );
		$request = new WP_REST_Request( 'POST', '/pinova/user/authenticate' );
		$request->set_param( 'identifier', new Identifier( $email ) );
		$request->set_param( 'force_otp', true );
		$response = ( new UserAPI() )->authenticate( $request );
		self::assertSame( 200, $response->get_status() );
		$flow = JWT::decode( $response->get_data()['data']['jwt'] )['flow_id'];
		$this->flows[] = $flow;
		self::assertSame( 0, $this->mail_calls );
		self::assertStringStartsWith( 'ready:', $this->payload( $flow ) );
		// Losing the serialized cron option must not lose durable acceptance.
		wp_unschedule_hook( 'pinova_otp_delivery' );
		self::assertSame( 1, OTPService::run_due_delivery() );
		self::assertSame( 1, $this->mail_calls );
		self::assertSame( 1, OTP::query()->where( 'flow_id', $flow )->count() );
		self::assertSame( 0, OTPService::run_due_delivery() );
		self::assertSame( 1, $this->mail_calls );
		$rows = LogRepository::paginate( 1, 10, '', [ 'event' => 'otp.queue_recovered' ] )['rows'];
		self::assertCount( 1, $rows );
		self::assertSame( $flow, $rows[0]['flow_id'] );
		self::assertSame( 'missing_schedule', json_decode( $rows[0]['context'], true )['reason'] );
		self::assertStringNotContainsString( $email, $rows[0]['context'] );
	}

	public function test_schedule_failure_returns_uniform_unavailable_and_never_recovers_its_reservation(): void {
		$email = 'rejected-recovery@example.test';
		self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );
		$request = new WP_REST_Request( 'POST', '/pinova/user/authenticate' );
		$request->set_param( 'identifier', new Identifier( $email ) );
		$request->set_param( 'force_otp', true );
		$deny = static fn() => false;
		add_filter( 'pre_schedule_event', $deny );
		try {
			$response = ( new UserAPI() )->authenticate( $request );
		} finally {
			remove_filter( 'pre_schedule_event', $deny );
		}
		self::assertSame( 503, $response->get_status() );
		self::assertFalse( $response->get_data()['success'] );
		self::assertSame( 1, RateLimitService::queue_health()['reserved'] );
		self::assertSame( 0, RateLimitService::queue_health()['pending'] );
		self::assertSame( 0, OTPService::run_due_delivery() );
		self::assertSame( 0, $this->mail_calls );
		self::assertSame( 0, OTP::query()->count() );
		$rows = LogRepository::paginate( 1, 10, '', [ 'event' => 'auth.request_failed' ] )['rows'];
		self::assertCount( 1, $rows );
		self::assertSame( 'queue_schedule_failed', json_decode( $rows[0]['context'], true )['reason'] );
		self::assertStringNotContainsString( $email, $rows[0]['context'] );
	}

	public function test_acknowledgement_write_failure_leaves_scheduled_reservation_non_sendable(): void {
		global $wpdb;
		$flow = $this->reserve( 'ack-failure@example.test' );
		$table = $wpdb->prefix . 'pinova_rate_limits';
		$deny = static function ( string $query ) use ( $table ): string {
			return str_contains( $query, 'UPDATE' ) && str_contains( $query, 'SET `payload`' ) && str_contains( $query, "'ready:")
				? "UPDATE `{$table}` SET `payload` = `payload` WHERE 1 = 0" : $query;
		};
		add_filter( 'query', $deny );
		try {
			OTPService::schedule_queued_delivery( $flow );
			self::fail( 'A failed acknowledgement must reject initiation.' );
		} catch ( \Pinova\Exceptions\RateLimitUnavailableException $exception ) {
			self::assertStringStartsWith( 'reserved:', $this->payload( $flow ) );
		} finally {
			remove_filter( 'query', $deny );
		}
		self::assertSame( 1, OTPService::run_due_delivery() );
		self::assertSame( 0, $this->mail_calls );
		self::assertSame( 0, OTP::query()->count() );
		self::assertSame( 0, OTPService::run_due_delivery() );
	}

	/** @dataProvider acknowledgement_race_states */
	public function test_acknowledgement_cas_loser_rechecks_accepted_state( string $state, bool $accepted ): void {
		global $wpdb;
		$flow = $this->reserve( 'ack-race@example.test' );
		$cipher = substr( $this->payload( $flow ), 9 );
		$table = $wpdb->prefix . 'pinova_rate_limits';
		$raced = false;
		$race = static function ( string $query ) use ( $wpdb, $table, $flow, $cipher, $state, &$raced ): string {
			if ( $raced || ! str_contains( $query, 'UPDATE' ) || ! str_contains( $query, "'ready:" ) ) {
				return $query;
			}
			$raced = true;
			$payloads = [
				'ready' => 'ready:' . $cipher,
				'processing' => 'processing:' . str_repeat( 'a', 16 ) . ':ready:' . $cipher,
				'delivered' => 'delivered',
				'cancelled' => 'cancelled:' . str_repeat( 'a', 16 ),
				'reserved' => 'reserved:' . $cipher,
				'expired' => 'ready:' . $cipher,
			];
			$values = [ 'payload' => $payloads[ $state ] ];
			if ( 'expired' === $state ) {
				$values['reset_at'] = gmdate( 'Y-m-d H:i:s', time() - 1 );
			}
			$wpdb->update( $table, $values, [ 'scope' => $flow ] );
			// The competitor has finished before our guarded write/readback.
			return $wpdb->prepare( 'UPDATE %i SET `payload` = `payload` WHERE 1 = 0', $table );
		};
		add_filter( 'query', $race );
		try {
			self::assertSame( $accepted, RateLimitService::acknowledge_queued_otp( $flow ) );
			self::assertTrue( $raced );
		} finally {
			remove_filter( 'query', $race );
		}
		self::assertSame( 0, $this->mail_calls );
	}

	public static function acknowledgement_race_states(): array {
		return [
			'ready' => [ 'ready', true ],
			'processing' => [ 'processing', true ],
			'delivered' => [ 'delivered', true ],
			'cancelled' => [ 'cancelled', false ],
			'reserved' => [ 'reserved', false ],
			'expired' => [ 'expired', false ],
		];
	}

	public function test_acknowledgement_database_error_is_not_hidden_by_accepted_readback(): void {
		global $wpdb;
		$flow = $this->reserve( 'ack-error@example.test' );
		$cipher = substr( $this->payload( $flow ), 9 );
		$table = $wpdb->prefix . 'pinova_rate_limits';
		$raced = false;
		$fail = static function ( string $query ) use ( $wpdb, $table, $flow, $cipher, &$raced ): string {
			if ( $raced || ! str_contains( $query, 'UPDATE' ) || ! str_contains( $query, "'ready:" ) ) {
				return $query;
			}
			$raced = true;
			$wpdb->update( $table, [ 'payload' => 'ready:' . $cipher ], [ 'scope' => $flow ] );
			return 'UPDATE pinova_missing_ack_table SET payload = NULL';
		};
		$suppressed = $wpdb->suppress_errors();
		add_filter( 'query', $fail );
		try {
			self::assertFalse( RateLimitService::acknowledge_queued_otp( $flow ) );
			self::assertTrue( $raced );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $suppressed );
		}
		self::assertStringStartsWith( 'ready:', $this->payload( $flow ) );
		self::assertSame( 0, $this->mail_calls );
	}

	public function test_claim_contention_remains_recoverable_after_its_one_shot_is_consumed(): void {
		global $wpdb;
		$flow = $this->reserve( 'contention@example.test' );
		OTPService::schedule_queued_delivery( $flow );
		$name = 'pinova_otp_' . $flow;
		self::assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) );
		try {
			self::assertSame( 1, OTPService::run_due_delivery() );
			self::assertSame( 0, $this->mail_calls );
			self::assertFalse( wp_next_scheduled( 'pinova_otp_delivery', [ $flow ] ) );
			self::assertStringStartsWith( 'ready:', $this->payload( $flow ) );
			self::assertSame( 1, LogRepository::paginate( 1, 10, '', [ 'event' => 'otp.queue_deferred' ] )['total'] );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
		self::assertSame( 1, OTPService::run_due_delivery() );
		self::assertSame( 1, $this->mail_calls );
		self::assertSame( 0, OTPService::run_due_delivery() );
	}

	public function test_three_earlier_lock_held_orphans_do_not_starve_an_unlocked_flow(): void {
		global $wpdb;
		$locked = [];
		try {
			foreach ( range( 1, 3 ) as $index ) {
				$flow = $this->reserve( 'locked-' . $index . '@example.test' );
				self::assertTrue( RateLimitService::acknowledge_queued_otp( $flow ) );
				$wpdb->update( $wpdb->prefix . 'pinova_rate_limits', [ 'reset_at' => gmdate( 'Y-m-d H:i:s', time() + 90 ) ], [ 'scope' => $flow ] );
				self::assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', 'pinova_otp_' . $flow ) ) );
				$locked[] = $flow;
			}
			$orphan = $this->reserve( 'unlocked-orphan@example.test' );
			self::assertTrue( RateLimitService::acknowledge_queued_otp( $orphan ) );
			self::assertSame( 1, OTPService::run_due_delivery() );
			self::assertSame( 1, $this->mail_calls );
			self::assertSame( 1, OTP::query()->where( 'flow_id', $orphan )->count() );
			self::assertSame( 0, OTPService::run_due_delivery() );
			foreach ( $locked as $flow ) {
				self::assertStringStartsWith( 'ready:', $this->payload( $flow ) );
			}
		} finally {
			foreach ( $locked as $flow ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'pinova_otp_' . $flow ) );
			}
		}
	}

	public function test_legacy_or_reserved_or_expired_or_null_rows_are_never_recovered(): void {
		global $wpdb;
		$email = 'legacy-recovery@example.test';
		self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );
		[ $legacy ] = RateLimitService::decoy_flow( $email, 'authenticate', '192.0.2.201' );
		$this->flows[] = $legacy;
		$reserved = $this->reserve( 'unaccepted@example.test' );
		$expired = $this->reserve( 'expired-recovery@example.test' );
		$empty = $this->reserve( 'empty-recovery@example.test' );
		self::assertTrue( RateLimitService::acknowledge_queued_otp( $expired ) );
		$wpdb->update( $wpdb->prefix . 'pinova_rate_limits', [ 'reset_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ], [ 'scope' => $expired ] );
		$wpdb->update( $wpdb->prefix . 'pinova_rate_limits', [ 'payload' => null ], [ 'scope' => $empty ] );
		self::assertSame( 0, OTPService::run_due_delivery() );
		self::assertSame( 0, $this->mail_calls );
		self::assertNull( RateLimitService::claim_queued_otp( $reserved ) );
		self::assertSame( 1, RateLimitService::queue_health()['expired'] );
		self::assertSame( 1, RateLimitService::queue_health()['legacy'] );
		$rows = LogRepository::paginate( 1, 10, '', [ 'event' => 'auth.request_failed' ] )['rows'];
		self::assertCount( 1, $rows );
		self::assertSame( $expired, $rows[0]['flow_id'] );
		self::assertSame( 'queue_expired', json_decode( $rows[0]['context'], true )['reason'] );
		self::assertSame( 0, OTP::query()->count() );
		// A valid historical job may still deliver its legacy ciphertext once.
		self::assertTrue( wp_schedule_single_event( time() - 1, 'pinova_otp_delivery', [ $legacy ] ) );
		self::assertSame( 1, OTPService::run_due_delivery() );
		self::assertSame( 1, $this->mail_calls );
	}

	public function test_durable_recovery_obeys_three_job_bound_and_future_or_recurring_jobs(): void {
		foreach ( range( 1, 4 ) as $index ) {
			$flow = $this->reserve( 'bounded-' . $index . '@example.test' );
			self::assertTrue( RateLimitService::acknowledge_queued_otp( $flow ) );
		}
		$future = $this->reserve( 'future-ready@example.test' );
		self::assertTrue( RateLimitService::acknowledge_queued_otp( $future ) );
		self::assertTrue( wp_schedule_single_event( time() + 60, 'pinova_otp_delivery', [ $future ] ) );
		$recurring = $this->reserve( 'recurring-ready@example.test' );
		self::assertTrue( RateLimitService::acknowledge_queued_otp( $recurring ) );
		self::assertTrue( wp_schedule_event( time() - 1, 'hourly', 'pinova_otp_delivery', [ $recurring ] ) );
		self::assertSame( 3, OTPService::run_due_delivery() );
		self::assertSame( 3, $this->mail_calls );
		self::assertSame( 1, OTPService::run_due_delivery() );
		self::assertSame( 4, $this->mail_calls );
		self::assertNotFalse( wp_next_scheduled( 'pinova_otp_delivery', [ $future ] ) );
		self::assertSame( 'hourly', wp_get_scheduled_event( 'pinova_otp_delivery', [ $recurring ] )->schedule );
	}

	public function test_native_schedules_cannot_hide_an_orphan_beyond_the_candidate_window(): void {
		global $wpdb;
		$scheduled = [];
		foreach ( range( 1, 32 ) as $index ) {
			$flow = $this->reserve( 'window-' . $index . '@example.test' );
			self::assertTrue( RateLimitService::acknowledge_queued_otp( $flow ) );
			$wpdb->update( $wpdb->prefix . 'pinova_rate_limits', [ 'reset_at' => gmdate( 'Y-m-d H:i:s', time() + 90 ) ], [ 'scope' => $flow ] );
			if ( 0 === $index % 2 ) {
				self::assertTrue( wp_schedule_single_event( time() + 60, 'pinova_otp_delivery', [ $flow ] ) );
			} else {
				self::assertTrue( wp_schedule_event( time() - 1, 'hourly', 'pinova_otp_delivery', [ $flow ] ) );
			}
			$scheduled[ $flow ] = wp_get_scheduled_event( 'pinova_otp_delivery', [ $flow ] );
		}
		$orphan = $this->reserve( 'window-orphan@example.test' );
		self::assertTrue( RateLimitService::acknowledge_queued_otp( $orphan ) );
		self::assertSame( 1, OTPService::run_due_delivery() );
		self::assertSame( 1, $this->mail_calls );
		self::assertSame( 1, OTP::query()->where( 'flow_id', $orphan )->count() );
		self::assertSame( 0, OTPService::run_due_delivery() );
		foreach ( $scheduled as $flow => $event ) {
			self::assertEquals( $event, wp_get_scheduled_event( 'pinova_otp_delivery', [ $flow ] ) );
			self::assertStringStartsWith( 'ready:', $this->payload( $flow ) );
		}
	}

	public function test_provider_exception_keeps_original_code_and_never_resends_its_flow(): void {
		$flow = $this->reserve( 'unknown-acceptance@example.test' );
		OTPService::schedule_queued_delivery( $flow );
		$this->mail_throws = true;
		self::assertSame( 1, OTPService::run_due_delivery() );
		$otp = OTP::query()->where( 'flow_id', $flow )->first();
		self::assertNotNull( $otp );
		self::assertFalse( $otp->isExpired() );
		$hash = $otp->code;
		$this->mail_throws = false;
		self::assertTrue( wp_schedule_single_event( time() - 1, 'pinova_otp_delivery', [ $flow ] ) );
		self::assertSame( 1, OTPService::run_due_delivery() );
		self::assertSame( 1, $this->mail_calls );
		self::assertSame( $hash, $otp->fresh()->code );
		self::assertSame( 0, LogRepository::paginate( 1, 10, '', [ 'event' => 'otp.delivery_failed' ] )['total'] );
		$rows = LogRepository::paginate( 1, 10, '', [ 'event' => 'otp.delivery_skipped' ] )['rows'];
		self::assertSame( 'provider_outcome_unknown', json_decode( $rows[0]['context'], true )['reason'] );
		self::assertStringNotContainsString( 'private-provider-outcome', $rows[0]['context'] );
	}

	public function test_lock_free_stale_acknowledged_claim_recovers_without_replacing_ciphertext(): void {
		global $wpdb;
		$flow = $this->reserve( 'stale-ready@example.test' );
		self::assertTrue( RateLimitService::acknowledge_queued_otp( $flow ) );
		$claim = RateLimitService::claim_queued_otp( $flow );
		self::assertNotNull( $claim );
		self::assertStringStartsWith( 'processing:' . $claim['claim_token'] . ':ready:', $this->payload( $flow ) );
		// A closed worker connection frees its lock while retaining the lease.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'pinova_otp_' . $flow ) );
		$wpdb->update( $wpdb->prefix . 'pinova_rate_limits', [ 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 61 ) ], [ 'scope' => $flow ] );
		self::assertSame( 1, OTPService::run_due_delivery() );
		self::assertSame( 1, $this->mail_calls );
		self::assertSame( 0, OTPService::run_due_delivery() );
		self::assertSame( 1, $this->mail_calls );
	}

	public function test_post_acceptance_save_failure_preserves_code_and_blocks_explicit_retry_resend(): void {
		$email = 'accepted-save-failure@example.test';
		$flow = $this->reserve( $email );
		OTPService::schedule_queued_delivery( $flow );
		$original = OTP::getEventDispatcher();
		$dispatcher = new class( $original ) implements Dispatcher {
			public bool $failed = false;
			private ?Dispatcher $original;
			public function __construct( ?Dispatcher $original ) { $this->original = $original; }
			public function listen( $events, $listener = null ) { $this->original?->listen( $events, $listener ); }
			public function hasListeners( $eventName ) { return $this->original?->hasListeners( $eventName ) ?? false; }
			public function subscribe( $subscriber ) { $this->original?->subscribe( $subscriber ); }
			public function until( $event, $payload = [] ) { return $this->dispatch( $event, $payload, true ); }
			public function dispatch( $event, $payload = [], $halt = false ) {
				if ( 'eloquent.saving: ' . OTP::class === $event && $payload instanceof OTP && $payload->exists ) {
					$this->failed = true;
					throw new \RuntimeException( 'private-channel-save-failure' );
				}
				return $this->original ? $this->original->dispatch( $event, $payload, $halt ) : ( $halt ? null : [] );
			}
			public function push( $event, $payload = [] ) { $this->original?->push( $event, $payload ); }
			public function flush( $event ) { $this->original?->flush( $event ); }
			public function forget( $event ) { $this->original?->forget( $event ); }
			public function forgetPushed() { $this->original?->forgetPushed(); }
		};
		OTP::setEventDispatcher( $dispatcher );
		try {
			self::assertSame( 1, OTPService::run_due_delivery() );
		} finally {
			if ( null === $original ) {
				OTP::unsetEventDispatcher();
			} else {
				OTP::setEventDispatcher( $original );
			}
		}
		self::assertTrue( $dispatcher->failed );
		self::assertSame( $original, OTP::getEventDispatcher() );
		$otp = OTP::query()->where( 'flow_id', $flow )->first();
		self::assertNotNull( $otp );
		$hash = $otp->code;
		self::assertSame( 1, $this->mail_calls );
		[ $retry_flow ] = RateLimitService::decoy_flow( $email, 'authenticate', '192.0.2.201', null, true );
		self::assertSame( $flow, $retry_flow );
		OTPService::schedule_queued_delivery( $retry_flow );
		self::assertSame( 1, OTPService::run_due_delivery() );
		self::assertSame( 1, $this->mail_calls );
		self::assertSame( $hash, $otp->fresh()->code );
	}

	public function test_authenticated_mobile_initiation_also_requires_durable_acceptance(): void {
		$id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $id );
		$request = new WP_REST_Request( 'POST', '/pinova/mobile/request' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'mobile', '09120000193' );
		$response = ( new MobileVerificationAPI() )->initiate( $request );
		self::assertSame( 200, $response->get_status() );
		$flow = JWT::decode( $response->get_data()['data']['jwt'] )['flow_id'];
		$this->flows[] = $flow;
		self::assertStringStartsWith( 'ready:', $this->payload( $flow ) );
		self::assertSame( 0, $this->mail_calls );
		self::assertTrue( RateLimitService::delete_queued_for_user( $id, $flow ) );
		self::assertSame( 0, RateLimitService::queue_health()['pending'] );
		self::assertNull( RateLimitService::claim_queued_otp( $flow ) );
	}

	public function test_native_reconciliation_schedule_is_idempotent_and_removed_on_deactivation(): void {
		$pinova = Pinova::instance();
		self::assertNotNull( $pinova );
		$pinova->schedule_cleanup();
		$first = wp_get_scheduled_event( 'pinova_otp_reconcile' );
		self::assertNotFalse( $first );
		self::assertSame( 'pinova_minute', $first->schedule );
		$pinova->schedule_cleanup();
		self::assertEquals( $first, wp_get_scheduled_event( 'pinova_otp_reconcile' ) );
		Install::deactivate();
		self::assertFalse( wp_next_scheduled( 'pinova_otp_reconcile' ) );
	}

	private function reserve( string $email ): string {
		self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );
		[ $flow ] = RateLimitService::decoy_flow( $email, 'authenticate', '192.0.2.201', null, true );
		$this->flows[] = $flow;
		return $flow;
	}

	private function payload( string $flow ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT `payload` FROM %i WHERE `scope` = %s', $wpdb->prefix . 'pinova_rate_limits', $flow ) );
	}
}
