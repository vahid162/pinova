<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Illuminate\Contracts\Events\Dispatcher;
use Pinova\Install;
use Pinova\Models\OTP;
use Pinova\Services\OTPImmediateDelivery;
use Pinova\Services\OTPQueueStore;
use Pinova\Services\OTPService;
use Pinova\Services\RateLimitService;
use WP_UnitTestCase;

/** Synthetic HTTP providers only; CLI dispatch is not evidence of FPM response ordering. */
final class OTPImmediateDeliveryIntegrationTest extends WP_UnitTestCase {
	private array $flows = [];
	private int $calls = 0;
	private array $timeouts = [];
	private bool $throws = false;
	private float $delay = 0;
	private bool $expire_after_provider = false;
	private bool $timeout = false;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();
		$this->flows = [];
		$this->calls = 0;
		$this->timeouts = [];
		$this->throws = false;
		$this->delay = 0;
		$this->expire_after_provider = false;
		$this->timeout = false;
		UnboundedImmediateGateway::$constructions = 0;
		OTP::query()->delete();
		update_option( 'pinova_sms', [ 'gateway' => 'MeliPayamak' ] );
		update_option( 'pinova_gateway_melipayamak', [ 'username' => 'synthetic', 'password' => 'synthetic' ] );
		update_option( 'pinova_messengers', [] );
		update_option( 'pinova_zohal', [] );
		add_filter( 'pre_http_request', [ $this, 'provider' ], 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', [ $this, 'provider' ], 10 );
		foreach ( $this->flows as $flow ) {
			RateLimitService::retire_queued_otp( $flow );
		}
		wp_unschedule_hook( 'pinova_otp_delivery' );
		OTP::query()->delete();
		parent::tear_down();
	}

	public function provider( $preempt, array $arguments, string $url ) {
		++$this->calls;
		$this->timeouts[] = $arguments['timeout'];
		if ( $this->delay > 0 ) {
			usleep( (int) ( $this->delay * 1000000 ) );
		}
		if ( $this->expire_after_provider ) {
			$this->expire_budget();
		}
		if ( $this->throws ) {
			throw new \RuntimeException( 'synthetic-unknown-acceptance' );
		}
		if ( $this->timeout ) {
			return new \WP_Error( 'http_request_failed', 'synthetic timeout' );
		}
		return [ 'headers' => [], 'body' => str_contains( $url, 'bale.ai' ) ? '{"message_id":1}' : '{"Value":"1234567890123456"}', 'response' => [ 'code' => 200, 'message' => 'OK' ] ];
	}

	public function test_private_dispatch_delivers_only_its_acknowledged_flow_and_cron_does_not_resend(): void {
		$flow = $this->reserve( '+989120000111' );
		$other = $this->reserve( '+989120000112' );
		$this->dispatch( $flow );
		self::assertSame( 1, $this->calls );
		self::assertSame( 1, OTP::query()->where( 'flow_id', $flow )->count() );
		self::assertSame( 'delivered', $this->row( $flow )['payload'] );
		self::assertStringStartsWith( 'ready:', $this->row( $other )['payload'] );
		OTPService::deliver_queued( $flow );
		self::assertSame( 1, $this->calls );
	}

	public function test_unsupported_email_and_arbitrary_sms_subclass_preserve_exact_ready_rows(): void {
		$email = $this->reserve( 'deferred@example.test' );
		$before = $this->row( $email );
		$this->dispatch( $email );
		self::assertSame( $before, $this->row( $email ) );
		$flow = $this->reserve( '+989120000113' );
		$before = $this->row( $flow );
		$this->with_gateway( UnboundedImmediateGateway::class, fn() => $this->dispatch( $flow ) );
		self::assertSame( $before, $this->row( $flow ) );
		self::assertSame( 0, UnboundedImmediateGateway::$constructions, 'Unsupported constructors must not execute during fast admission.' );
		self::assertSame( 0, $this->calls );
	}

	public function test_only_exact_native_gateway_names_admit_http_transports(): void {
		foreach ( [ 'MeliPayamak', '\\Pinova\\Gateways\\MaxSMS', 'Pinova\\Gateways\\PanelChi' ] as $name ) {
			$this->with_gateway( $name, static fn() => self::assertInstanceOf( \Pinova\Gateways\BaseGateway::class, OTPImmediateDelivery::bounded_gateway() ) );
		}
		foreach ( [ 'PWSMS', UnboundedImmediateGateway::class, 'missing', null, [] ] as $name ) {
			$this->with_gateway( $name, static fn() => self::assertNull( OTPImmediateDelivery::bounded_gateway() ) );
		}
		self::assertSame( 0, UnboundedImmediateGateway::$constructions );
	}

	public function test_arming_is_default_off_and_unsupported_cli_preserves_buffers_and_hooks(): void {
		$before = ob_get_level();
		$response = new \WP_REST_Response( [ 'success' => true, 'data' => [] ], 200 );
		$request = new \WP_REST_Request( 'POST', '/pinova/user/authenticate' );
		OTPImmediateDelivery::arm( str_repeat( 'a', 32 ), $response, $request );
		self::assertSame( $before, ob_get_level() );
		self::assertFalse( OTPImmediateDelivery::is_active() );
	}

	public function test_full_slots_leave_the_next_flow_unchanged(): void {
		global $wpdb;
		$first = OTPQueueStore::acquire_immediate_slot();
		self::assertIsArray( $first );
		// Same-connection recursive GET_LOCK must not create another admission.
		self::assertNull( OTPQueueStore::acquire_immediate_slot() );
		$original = $wpdb;
		$independent = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$independent->set_prefix( $original->prefix );
		$third = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$third->set_prefix( $original->prefix );
		try {
			$wpdb = $independent;
			$second = OTPQueueStore::acquire_immediate_slot();
			self::assertIsArray( $second );
			$wpdb = $third;
			self::assertNull( OTPQueueStore::acquire_immediate_slot(), 'A third independent connection must not exceed the two occupied site slots.' );
			$wpdb = $original;
			$flow = $this->reserve( '+989120000114' );
			$before = $this->row( $flow );
			$this->dispatch( $flow );
			self::assertSame( $before, $this->row( $flow ) );
			self::assertSame( 0, $this->calls );
		} finally {
			$wpdb = $independent;
			if ( isset( $second ) ) {
				OTPQueueStore::release_immediate_slot( $second );
			}
			$wpdb = $original;
			OTPQueueStore::release_immediate_slot( $first );
			$independent->close();
			$third->close();
		}
	}

	public function test_admission_decline_preserves_ciphertext_deadline_and_timestamps(): void {
		$flow = $this->reserve( '+989120000115' );
		$before = $this->row( $flow );
		self::assertNull( OTPQueueStore::claim_queued_otp( $flow, static fn( array $queued ): bool => false ) );
		self::assertSame( $before, $this->row( $flow ) );
		$claim = OTPQueueStore::claim_queued_otp( $flow );
		self::assertIsArray( $claim );
		self::assertTrue( OTPQueueStore::defer_queued_otp( $flow, $claim['claim_token'] ) );
		self::assertSame( $before, $this->row( $flow ) );
	}

	public function test_budget_expiring_before_first_http_call_restores_ready_and_removes_only_unattempted_code(): void {
		$flow = $this->reserve( '+989120000118' );
		$before = $this->row( $flow );
		$original = OTP::getEventDispatcher();
		$dispatcher = new class( $original, function (): void { $this->expire_budget(); } ) implements Dispatcher {
			public bool $expired = false;
			public function __construct( private ?Dispatcher $original, private \Closure $expire ) {}
			public function listen( $events, $listener = null ) { $this->original?->listen( $events, $listener ); }
			public function hasListeners( $eventName ) { return $this->original?->hasListeners( $eventName ) ?? false; }
			public function subscribe( $subscriber ) { $this->original?->subscribe( $subscriber ); }
			public function until( $event, $payload = [] ) { return $this->dispatch( $event, $payload, true ); }
			public function dispatch( $event, $payload = [], $halt = false ) {
				if ( 'eloquent.created: ' . OTP::class === $event ) {
					$this->expired = true;
					( $this->expire )();
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
			$this->dispatch( $flow );
		} finally {
			if ( null === $original ) {
				OTP::unsetEventDispatcher();
			} else {
				OTP::setEventDispatcher( $original );
			}
		}
		self::assertTrue( $dispatcher->expired, 'The test must expire the budget after creating its never-attempted OTP.' );
		self::assertSame( 0, $this->calls );
		self::assertSame( 0, OTP::query()->where( 'flow_id', $flow )->count() );
		self::assertSame( $before, $this->row( $flow ) );
		OTPService::deliver_queued( $flow );
		self::assertSame( 1, $this->calls, 'The original scheduled flow remains deliverable.' );
	}

	public function test_accepted_channel_survives_budget_exhaustion_and_no_second_channel_starts(): void {
		update_option( 'pinova_messengers', [ 'bale_api_token' => 'synthetic', 'bale_bot_id' => '1' ] );
		$flow = $this->reserve( '+989120000119' );
		$this->expire_after_provider = true;
		$this->dispatch( $flow );
		self::assertSame( 1, $this->calls );
		$otp = OTP::query()->where( 'flow_id', $flow )->first();
		self::assertSame( [ 'bale' => true, 'sms' => false ], $otp->channels );
		OTPService::deliver_queued( $flow );
		self::assertSame( 1, $this->calls );
	}

	public function test_connection_reconnect_invalidates_slot_and_claim_even_with_the_same_row_token(): void {
		global $wpdb;
		$original = $wpdb;
		$database = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$database->set_prefix( $original->prefix );
		$flow = null;
		try {
			$wpdb = $database;
			[ $flow ] = RateLimitService::decoy_flow( 'connection-' . wp_generate_uuid4() . '@example.test', 'authenticate', '192.0.2.247', null, true );
			self::assertTrue( RateLimitService::acknowledge_queued_otp( $flow ) );
			$lease = OTPQueueStore::acquire_immediate_slot();
			self::assertIsArray( $lease );
			$claim = OTPQueueStore::claim_queued_otp( $flow );
			self::assertIsArray( $claim );
			self::assertTrue( OTPQueueStore::owns_queued_claim( $flow, $claim['claim_token'] ) );
			self::assertTrue( OTPQueueStore::immediate_claim_is_active( $flow, $claim['claim_token'], $lease ) );
			self::assertFalse( OTPQueueStore::immediate_claim_is_active( $flow, $claim['claim_token'], [ 'connection' => $lease['connection'] ] ), 'A missing fast-slot name must never borrow the flow lock as its slot.' );
			self::assertFalse( OTPQueueStore::immediate_claim_is_active( $flow, $claim['claim_token'], [ 'name' => '', 'connection' => $lease['connection'] ] ) );
			self::assertNull( OTPQueueStore::provider_was_attempted( $flow, str_repeat( '0', 16 ) ), 'A different token has no attempt witness.' );
			self::assertFalse( OTPQueueStore::provider_was_attempted( $flow, $claim['claim_token'] ) );
			self::assertTrue( $database->close() );
			self::assertTrue( $database->db_connect( false ) );
			self::assertFalse( OTPQueueStore::owns_immediate_slot( $lease ) );
			self::assertFalse( OTPQueueStore::owns_queued_claim( $flow, $claim['claim_token'] ) );
			self::assertFalse( OTPQueueStore::defer_queued_otp( $flow, $claim['claim_token'] ), 'A reconnect may not rewrite a claim without its original lock.' );
			self::assertStringStartsWith( 'processing:' . $claim['claim_token'], $this->row( $flow )['payload'] );
			self::assertNull( OTPQueueStore::claim_queued_otp( $flow ), 'A fresh unowned processing lease remains subject to existing crash recovery.' );
			self::assertTrue( RateLimitService::finish_queued_otp( $flow, $claim['claim_token'] ) );
		} finally {
			if ( null !== $flow ) {
				RateLimitService::retire_queued_otp( $flow );
			}
			$wpdb = $original;
			$database->close();
		}
	}

	public function test_reconnect_during_final_provider_claim_read_cannot_replay_an_active_token_into_http(): void {
		global $wpdb;
		$original = $wpdb;
		$database = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$database->set_prefix( $original->prefix );
		$flow = null;
		$user_id = null;
		$reconnected = false;
		$reconnect = static function ( string $query ) use ( $database, &$reconnected ): string {
			// Exercise the last provider_timeout() observation, rather than an
			// earlier admission check. Include the previous token-only query so
			// reverting the combined ownership read makes this regression fail.
			$claim_read = str_contains( $query, 'AS immediate_slot_holder' ) || str_contains( $query, 'SELECT `payload` FROM' );
			if ( ! $reconnected && $claim_read && in_array( 'provider_timeout', array_column( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 16 ), 'function' ), true ) ) {
				$reconnected = true;
				self::assertTrue( $database->close() );
				self::assertTrue( $database->db_connect( false ) );
			}
			return $query;
		};
		try {
			// Autocommit on this independent connection retains the same payload
			// across reconnect without committing PHPUnit's primary transaction.
			$wpdb = $database;
			$mobile = '+989120000121';
			$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
			update_user_meta( $user_id, 'pinova_mobile', $mobile );
			[ $flow ] = RateLimitService::decoy_flow( $mobile, 'authenticate', '192.0.2.248', null, true );
			self::assertTrue( RateLimitService::acknowledge_queued_otp( $flow ) );
			$before = $this->row( $flow );
			add_filter( 'query', $reconnect );
			$this->dispatch( $flow );
			remove_filter( 'query', $reconnect );
			self::assertTrue( $reconnected, 'The test must reconnect inside the final provider claim observation.' );
			self::assertSame( 0, $this->calls );
			self::assertSame( 0, OTP::query()->where( 'flow_id', $flow )->count() );
			$after = $this->row( $flow );
			self::assertSame( $before['reset_at'], $after['reset_at'] );
			self::assertStringStartsWith( 'processing:', $after['payload'] );
			self::assertStringEndsWith( ':' . $before['payload'], $after['payload'] );
			$token = explode( ':', $after['payload'], 3 )[1];
			self::assertFalse( OTPQueueStore::owns_queued_claim( $flow, $token ) );
			self::assertTrue( RateLimitService::finish_queued_otp( $flow, $token ) );
		} finally {
			remove_filter( 'query', $reconnect );
			if ( null !== $flow ) {
				RateLimitService::delete_queued_for_identifiers( [ '+989120000121' ] );
			}
			if ( null !== $user_id ) {
				wp_delete_user( $user_id );
			}
			$wpdb = $original;
			$database->close();
		}
	}

	public function test_unknown_provider_acceptance_retains_code_and_cannot_resend(): void {
		$flow = $this->reserve( '+989120000116' );
		$this->throws = true;
		$this->dispatch( $flow );
		self::assertSame( 1, $this->calls );
		self::assertSame( 1, OTP::query()->where( 'flow_id', $flow )->count() );
		OTPService::deliver_queued( $flow );
		self::assertSame( 1, $this->calls );
	}

	public function test_http_timeout_retains_code_and_cannot_resend(): void {
		$flow = $this->reserve( '+989120000120' );
		$this->timeout = true;
		$this->dispatch( $flow );
		self::assertSame( 1, $this->calls );
		self::assertSame( 1, OTP::query()->where( 'flow_id', $flow )->count() );
		OTPService::deliver_queued( $flow );
		self::assertSame( 1, $this->calls );
	}

	public function test_sequential_channels_share_one_positive_http_timeout_budget(): void {
		update_option( 'pinova_messengers', [ 'bale_api_token' => 'synthetic', 'bale_bot_id' => '1' ] );
		$flow = $this->reserve( '+989120000117' );
		$this->delay = 0.1;
		$this->dispatch( $flow );
		self::assertSame( 2, $this->calls );
		self::assertGreaterThan( 0, $this->timeouts[1] );
		self::assertLessThan( $this->timeouts[0], $this->timeouts[1] );
		self::assertLessThanOrEqual( 8, $this->timeouts[0] );
	}

	/** Simulate option reads without invoking the unrelated settings-save hook. */
	private function with_gateway( $gateway, callable $operation ): mixed {
		$option = static fn(): array => [ 'gateway' => $gateway ];
		add_filter( 'pre_option_pinova_sms', $option );
		try {
			return $operation();
		} finally {
			remove_filter( 'pre_option_pinova_sms', $option );
		}
	}

	private function reserve( string $identifier ): string {
		if ( str_starts_with( $identifier, '+' ) ) {
			$user = self::factory()->user->create( [ 'role' => 'subscriber' ] );
			update_user_meta( $user, 'pinova_mobile', $identifier );
		}
		[ $flow ] = RateLimitService::decoy_flow( $identifier, 'authenticate', '192.0.2.246', null, true );
		$this->flows[] = $flow;
		OTPService::schedule_queued_delivery( $flow );
		return $flow;
	}

	private function row( string $flow ): array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT `payload`, `reset_at`, `updated_at` FROM %i WHERE `scope` = %s', $wpdb->prefix . 'pinova_rate_limits', $flow ), ARRAY_A );
	}

	private function dispatch( string $flow ): void {
		$headroom = new \ReflectionMethod( OTPImmediateDelivery::class, 'headroom_decline_reason' );
		$headroom->setAccessible( true );
		$deadline = microtime( true ) + 15;
		do {
			$decline_reason = $headroom->invoke( null );
			if ( null === $decline_reason ) {
				break;
			}
			usleep( 250000 );
		} while ( microtime( true ) < $deadline );
		self::assertNull( $decline_reason, 'Isolated immediate delivery tests require actual host headroom; do not bypass the pressure guard.' );
		$method = new \ReflectionMethod( OTPImmediateDelivery::class, 'dispatch' );
		$method->setAccessible( true );
		$method->invoke( null, $flow );
	}

	private function expire_budget(): void {
		$context = new \ReflectionProperty( OTPImmediateDelivery::class, 'context' );
		$context->setAccessible( true );
		$value = $context->getValue();
		self::assertIsArray( $value );
		$value['deadline'] = microtime( true ) - 1;
		$context->setValue( null, $value );
	}
}

class UnboundedImmediateGateway extends \Pinova\Gateways\MeliPayamak {
	public static int $constructions = 0;
	public function __construct() {
		++self::$constructions;
		parent::__construct();
	}
}
