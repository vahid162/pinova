<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Carbon\Carbon;
use Pinova\Exceptions\SendOTPException;
use Pinova\Gateways\BaseGateway;
use Pinova\Install;
use Pinova\Models\OTP;
use Pinova\Services\ChannelService;
use Pinova\Services\RateLimitService;
use WP_UnitTestCase;

final class DeliveryRunnerGateway extends BaseGateway {

	protected string $name = 'Delivery runner test gateway';
	protected string $url = 'example.test';
	public static int $calls = 0;

	public function send( string $mobile, string $message ): bool {
		++self::$calls;
		return true;
	}

	public function is_enable(): bool {
		return true;
	}

	public function options(): array {
		return [];
	}
}

final class DeliveryRunnerTest extends WP_UnitTestCase {

	/** @var array<string, string> */
	private array $claims = [];
	private int $http_calls = 0;
	private ?\Closure $after_bale = null;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();
		DeliveryRunnerGateway::$calls = 0;
		$this->http_calls = 0;
		$this->after_bale = null;
		update_option( 'pinova_sms', [ 'gateway' => DeliveryRunnerGateway::class ] );
		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );
	}

	public function tear_down(): void {
		Carbon::setTestNow();
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		foreach ( $this->claims as $flow_id => $token ) {
			RateLimitService::finish_queued_otp( $flow_id, $token );
			RateLimitService::retire_queued_otp( $flow_id );
		}
		$this->claims = [];
		$this->after_bale = null;
		parent::tear_down();
	}

	/** Intercept every transport so a failed regression can never send externally. */
	public function intercept_http( $pre, array $args, string $url ): array {
		++$this->http_calls;
		if ( 'https://safir.bale.ai/api/v3/send_message' === $url && null !== $this->after_bale ) {
			( $this->after_bale )();
		}
		return [
			'headers' => [],
			'body' => '{"message_id":"synthetic-delivery"}',
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies' => [],
		];
	}

	/** @dataProvider claim_modes */
	public function test_expiry_after_first_acceptance_stops_next_provider( bool $queued ): void {
		[ $otp, $token ] = $this->create_delivery( $queued );
		$this->after_bale = static function () use ( $otp ): void {
			Carbon::setTestNow( $otp->expires_at );
		};

		self::assertSame( [ 'bale' ], ChannelService::send( $otp, 1234, $token ) );
		self::assertSame( 1, $this->http_calls );
		self::assertSame( 0, DeliveryRunnerGateway::$calls );
		self::assertSame( [ 'bale' => true, 'sms' => false ], $otp->fresh()->channels );
	}

	public function test_cancelled_claim_stops_next_provider_and_preserves_acceptance(): void {
		[ $otp, $token ] = $this->create_delivery( true );
		$this->after_bale = static function () use ( $otp ): void {
			self::assertTrue( RateLimitService::retire_queued_otp( $otp->flow_id ) );
		};

		self::assertSame( [ 'bale' ], ChannelService::send( $otp, 1234, $token ) );
		self::assertSame( 1, $this->http_calls );
		self::assertSame( 0, DeliveryRunnerGateway::$calls );
		self::assertSame( [ 'bale' => true, 'sms' => false ], $otp->fresh()->channels );
	}

	/** @dataProvider claim_modes */
	public function test_expired_start_never_calls_a_provider( bool $queued ): void {
		[ $otp, $token ] = $this->create_delivery( $queued );
		Carbon::setTestNow( $otp->expires_at );

		try {
			ChannelService::send( $otp, 1234, $token );
			self::fail( 'An expired delivery was accepted.' );
		} catch ( SendOTPException $exception ) {
			self::assertSame( 0, $this->http_calls );
			self::assertSame( 0, DeliveryRunnerGateway::$calls );
			self::assertSame( [ 'bale' => false, 'sms' => false ], $otp->fresh()->channels );
		}
	}

	public function test_active_claim_can_deliver_both_channels(): void {
		[ $otp, $token ] = $this->create_delivery( true );

		self::assertSame( [ 'bale', 'sms' ], ChannelService::send( $otp, 1234, $token ) );
		self::assertSame( 1, $this->http_calls );
		self::assertSame( 1, DeliveryRunnerGateway::$calls );
		self::assertSame( [ 'bale' => true, 'sms' => true ], $otp->fresh()->channels );
	}

	/** @return array<string, array{bool}> */
	public function claim_modes(): array {
		return [ 'queued' => [ true ], 'without queue claim' => [ false ] ];
	}

	/** @return array{OTP, ?string} */
	private function create_delivery( bool $queued ): array {
		$mobile = '+989121234567';
		$flow_id = bin2hex( random_bytes( 16 ) );
		$token = null;
		if ( $queued ) {
			[ $flow_id ] = RateLimitService::decoy_flow( $mobile, 'authenticate', '192.0.2.73' );
			$claim = RateLimitService::claim_queued_otp( $flow_id );
			self::assertNotNull( $claim );
			$token = $claim['claim_token'];
			$this->claims[ $flow_id ] = $token;
		}
		$otp = OTP::query()->create(
			[
				'flow_id' => $flow_id,
				'identifier' => $mobile,
				'code' => '1234',
				'type' => OTP::TYPE_LOGIN,
				'channels' => [ 'bale' => false, 'sms' => false ],
				'expires_at' => Carbon::now()->addMinute(),
			]
		);
		return [ $otp, $token ];
	}
}
