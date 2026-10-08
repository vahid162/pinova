<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Carbon\Carbon;
use Pinova\Exceptions\SendOTPException;
use Pinova\Gateways\BaseGateway;
use Pinova\Install;
use Pinova\Logging\EventThrottle;
use Pinova\Logging\LogRepository;
use Pinova\Models\OTP;
use Pinova\Objects\Identifier;
use Pinova\Services\ChannelService;
use Pinova\Services\OTPService;
use Pinova\Services\RateLimitService;
use WP_UnitTestCase;

final class ObservationGateway extends BaseGateway {

	protected string $name = 'Observation test gateway';
	protected string $url = 'example.test';
	public static bool $throws = false;
	public static int $calls = 0;

	public function send( string $mobile, string $message ): bool {
		++self::$calls;
		if ( self::$throws ) {
			throw new \RuntimeException( 'private-provider-message-' . $mobile . '-' . $message );
		}
		return false;
	}

	public function is_enable(): bool {
		return true;
	}

	public function options(): array {
		return [];
	}
}

final class DeliveryObservationIntegrationTest extends WP_UnitTestCase {

	private array $flows = [];
	private int $mail_calls = 0;
	private int $http_calls = 0;

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
		$this->flows = [];
		$this->mail_calls = 0;
		$this->http_calls = 0;
		ObservationGateway::$calls = 0;
		ObservationGateway::$throws = false;
		update_option( 'pinova_logging', [ 'minimum_level' => 'info', 'retention_days' => 14 ] );
		update_option( 'pinova_sms', [ 'gateway' => ObservationGateway::class ] );
		add_filter( 'pre_http_request', [ $this, 'intercept_http' ] );
		add_filter( 'pre_wp_mail', [ $this, 'intercept_mail' ] );
	}

	public function tear_down(): void {
		Carbon::setTestNow();
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ] );
		remove_filter( 'pre_wp_mail', [ $this, 'intercept_mail' ] );
		foreach ( $this->flows as $flow ) {
			RateLimitService::retire_queued_otp( $flow );
		}
		OTP::query()->delete();
		LogRepository::delete_all();
		delete_option( 'pinova_logging' );
		delete_option( 'pinova_sms' );
		parent::tear_down();
	}

	/** All HTTP and mail are intercepted; these fixtures never contact a recipient. */
	public function intercept_http(): array {
		++$this->http_calls;
		return [
			'headers' => [],
			'body' => '{"message_id":"synthetic-acceptance"}',
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies' => [],
		];
	}

	public function intercept_mail(): bool {
		++$this->mail_calls;
		return true;
	}

	/** @dataProvider channel_failures */
	public function test_each_channel_failure_is_observed_without_losing_another_acceptance( bool $throws, string $reason ): void {
		ObservationGateway::$throws = $throws;
		$otp = $this->otp( bin2hex( random_bytes( 16 ) ), '+989121234567', [ 'bale' => false, 'sms' => false ] );

		self::assertSame( [ 'bale' ], ChannelService::send( $otp, 4827 ) );
		self::assertSame( [ 'bale' => true, 'sms' => false ], $otp->fresh()->channels );
		self::assertSame( 1, ObservationGateway::$calls );
		self::assertSame( 1, $this->http_calls );
		$record = $this->record( 'otp.channel_send_failed', $reason );
		self::assertSame( $otp->flow_id, $record['flow_id'] );
		$context = json_decode( $record['context'], true );
		self::assertSame( 'sms', $context['channel'] );
		self::assertIsInt( $context['duration_ms'] );
		self::assertGreaterThanOrEqual( 0, $context['duration_ms'] );
		self::assertLessThanOrEqual( 600000, $context['duration_ms'] );
		self::assertStringNotContainsString( '+989121234567', $record['context'] );
		self::assertArrayNotHasKey( 'code', $context );
		self::assertStringNotContainsString( 'private-provider-message', $record['context'] );
	}

	public function channel_failures(): array {
		return [ [ false, 'provider_returned_false' ], [ true, 'provider_exception' ] ];
	}

	public function test_logging_failure_does_not_change_channel_acceptance(): void {
		$otp = $this->otp( bin2hex( random_bytes( 16 ) ), '+989121234567', [ 'bale' => false, 'sms' => false ] );
		$fail = static function (): void {
			throw new \RuntimeException( 'private-log-settings-failure' );
		};
		add_filter( 'pre_option_pinova_logging', $fail );
		try {
			self::assertSame( [ 'bale' ], ChannelService::send( $otp, 4827 ) );
		} finally {
			remove_filter( 'pre_option_pinova_logging', $fail );
		}
		self::assertSame( [ 'bale' => true, 'sms' => false ], $otp->fresh()->channels );
		self::assertSame( 0, LogRepository::paginate( 1, 10, '', [ 'event' => 'otp.channel_send_failed' ] )['total'] );
	}

	public function test_all_false_channels_still_fail_delivery_and_record_the_failure(): void {
		$otp = $this->otp( bin2hex( random_bytes( 16 ) ), '+989121234568', [ 'sms' => false ] );
		try {
			ChannelService::send( $otp, 4827 );
			self::fail( 'A false provider result must not be accepted.' );
		} catch ( SendOTPException $exception ) {
			$this->record( 'otp.channel_send_failed', 'provider_returned_false' );
			self::assertSame( [ 'sms' => false ], $otp->fresh()->channels );
		}
	}

	/** @dataProvider worker_stops */
	public function test_known_worker_stops_are_safe_terminal_observations( string $scenario, string $reason ): void {
		$identifier = 'invalid_payload' === $scenario ? 'not a valid identifier' : 'observation@example.test';
		if ( 'native_policy' === $scenario ) {
			self::factory()->user->create( [ 'user_email' => $identifier, 'role' => 'administrator' ] );
		}
		[ $flow ] = RateLimitService::decoy_flow( $identifier, 'authenticate', '192.0.2.19' );
		$this->flows[] = $flow;
		if ( in_array( $scenario, [ 'already_completed', 'provider_outcome_unknown', 'duplicate_records' ], true ) ) {
			$this->otp( $flow, $identifier, [ 'email' => 'already_completed' === $scenario ] );
			if ( 'duplicate_records' === $scenario ) {
				$this->otp( $flow, $identifier, [ 'email' => false ] );
			}
		}
		$count = OTP::query()->count();
		OTPService::deliver_queued( $flow );

		self::assertSame( $count, OTP::query()->count() );
		self::assertSame( 0, $this->mail_calls );
		self::assertSame( 0, $this->http_calls );
		self::assertSame( 0, ObservationGateway::$calls );
		$record = $this->record( 'otp.delivery_skipped', $reason );
		self::assertSame( $flow, $record['flow_id'] );
		self::assertNull( $record['user_id'] );
		$context = json_decode( $record['context'], true );
		self::assertSame( 'queued_otp', $context['operation'] );
		foreach ( [ 'identifier_type', 'identifier_fingerprint', 'ip_fingerprint', 'user_id', 'otp_type' ] as $key ) {
			self::assertArrayNotHasKey( $key, $context );
		}
		self::assertStringNotContainsString( $identifier, $record['context'] );
		self::assertStringNotContainsString( '192.0.2.19', $record['context'] );
		OTPService::deliver_queued( $flow );
		self::assertSame( 1, LogRepository::paginate( 1, 10, '', [ 'event' => 'otp.delivery_skipped' ] )['total'] );
	}

	public function worker_stops(): array {
		return [
			[ 'unmatched_email', 'policy_rejected' ],
			[ 'native_policy', 'policy_rejected' ],
			[ 'invalid_payload', 'invalid_payload' ],
			[ 'already_completed', 'already_completed' ],
			[ 'provider_outcome_unknown', 'provider_outcome_unknown' ],
			[ 'duplicate_records', 'duplicate_records' ],
		];
	}

	public function test_worker_exception_keeps_flow_without_exception_details(): void {
		[ $flow ] = RateLimitService::decoy_flow( 'worker-error@example.test', 'authenticate', '192.0.2.20' );
		$this->flows[] = $flow;
		$fail = static function ( string $query ): string {
			if ( str_contains( $query, 'SELECT `bucket_key`, `payload`, `reset_at`, `updated_at`' ) ) {
				throw new \RuntimeException( 'private-worker-diagnostic' );
			}
			return $query;
		};
		add_filter( 'query', $fail );
		try {
			OTPService::deliver_queued( $flow );
		} finally {
			remove_filter( 'query', $fail );
		}
		$record = $this->record( 'auth.request_failed', 'delivery_worker_error' );
		self::assertSame( $flow, $record['flow_id'] );
		self::assertStringNotContainsString( 'private-worker-diagnostic', $record['context'] );
		self::assertSame( 0, $this->mail_calls );
	}

	public function test_cancelled_claim_still_blocks_creation_without_claiming_its_cause(): void {
		$email = 'cancelled-observation@example.test';
		[ $flow ] = RateLimitService::decoy_flow( $email, 'authenticate', '192.0.2.21' );
		$this->flows[] = $flow;
		$claim = RateLimitService::claim_queued_otp( $flow );
		self::assertNotNull( $claim );
		self::assertTrue( RateLimitService::retire_queued_otp( $flow ) );
		try {
			OTPService::create( new Identifier( $email ), [ 'email' ], OTP::TYPE_LOGIN, null, true, $claim['deadline'], $flow, '192.0.2.21', $claim['claim_token'] );
			self::fail( 'A cancelled claim must not deliver a code.' );
		} catch ( SendOTPException $exception ) {
			$record = $this->record( 'otp.delivery_skipped', 'claim_unavailable' );
			self::assertSame( $flow, $record['flow_id'] );
			self::assertSame( 0, OTP::query()->count() );
			self::assertSame( 0, $this->mail_calls );
		} finally {
			RateLimitService::finish_queued_otp( $flow, $claim['claim_token'] );
		}
	}

	public function test_expired_and_missing_claims_never_send_or_invent_skipped_outcomes(): void {
		global $wpdb;
		[ $flow ] = RateLimitService::decoy_flow( 'expired-observation@example.test', 'authenticate', '192.0.2.22' );
		$this->flows[] = $flow;
		$wpdb->update( $wpdb->prefix . 'pinova_rate_limits', [ 'reset_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ], [ 'scope' => $flow ] );
		OTPService::deliver_queued( $flow );
		OTPService::deliver_queued( bin2hex( random_bytes( 16 ) ) );
		$this->record( 'auth.request_failed', 'queue_expired' );
		self::assertSame( 0, LogRepository::paginate( 1, 10, '', [ 'event' => 'otp.delivery_skipped' ] )['total'] );
		self::assertSame( 0, OTP::query()->count() );
		self::assertSame( 0, $this->mail_calls );
		self::assertSame( 0, $this->http_calls );
	}

	public function test_skipped_observations_are_sampled_and_follow_the_logging_threshold(): void {
		$context = [ 'flow_id' => bin2hex( random_bytes( 16 ) ), 'reason' => 'policy_rejected', 'operation' => 'queued_otp' ];
		for ( $index = 0; $index < 10; ++$index ) {
			EventThrottle::log( 'otp.delivery_skipped', $context );
		}
		self::assertSame( 1, LogRepository::paginate( 1, 10, '', [ 'event' => 'otp.delivery_skipped' ] )['total'] );
		update_option( 'pinova_logging', [ 'minimum_level' => 'error' ] );
		$context['flow_id'] = bin2hex( random_bytes( 16 ) );
		EventThrottle::log( 'otp.delivery_skipped', $context );
		self::assertSame( 1, LogRepository::paginate( 1, 10, '', [ 'event' => 'otp.delivery_skipped' ] )['total'] );
	}

	public function test_a_claim_returning_after_its_deadline_records_expiry_without_delivery(): void {
		[ $flow ] = RateLimitService::decoy_flow( 'claim-expiry@example.test', 'authenticate', '192.0.2.23' );
		$this->flows[] = $flow;
		// Project an elapsed deadline while preserving the real eligible row and
		// atomic claim. This models time passing before the claim returns.
		$elapsed = static function ( string $query ): string {
			return str_replace(
				'SELECT `bucket_key`, `payload`, `reset_at`, `updated_at`',
				'SELECT `bucket_key`, `payload`, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) AS `reset_at`, `updated_at`',
				$query
			);
		};
		add_filter( 'query', $elapsed );
		try {
			OTPService::deliver_queued( $flow );
		} finally {
			remove_filter( 'query', $elapsed );
		}
		self::assertSame( $flow, $this->record( 'otp.delivery_skipped', 'expired' )['flow_id'] );
		self::assertSame( 0, OTP::query()->count() );
		self::assertSame( 0, $this->mail_calls );
		self::assertSame( 0, $this->http_calls );
		self::assertSame( 0, ObservationGateway::$calls );
	}

	private function otp( string $flow, string $identifier, array $channels ): OTP {
		return OTP::query()->create(
			[
				'flow_id' => $flow,
				'identifier' => $identifier,
				'code' => '4827',
				'type' => OTP::TYPE_LOGIN,
				'channels' => $channels,
				'expires_at' => Carbon::now()->addMinutes( 3 ),
			]
		);
	}

	private function record( string $event, string $reason ): array {
		$rows = LogRepository::paginate( 1, 10, '', [ 'event' => $event ] )['rows'];
		self::assertCount( 1, $rows );
		self::assertSame( $reason, json_decode( $rows[0]['context'], true )['reason'] );
		return $rows[0];
	}
}
