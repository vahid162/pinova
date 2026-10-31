<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Install;
use Pinova\Models\OTP;
use Pinova\Services\OTPService;
use Pinova\Services\RateLimitService;
use WP_UnitTestCase;

/** Real queue/adapter/logger boundaries with nonbillable, intercepted providers. */
final class OTPDiagnosticsIntegrationTest extends WP_UnitTestCase {

	/** @var string[] */
	private array $http_calls = [];

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();
		$this->http_calls = [];
		update_option( 'pinova_logging', [ 'minimum_level' => 'warning', 'retention_days' => 14 ] );
		update_option( 'pinova_sms', [ 'gateway' => 'MeliPayamak' ] );
		update_option( 'pinova_gateway_melipayamak', [ 'username' => 'synthetic', 'password' => 'synthetic' ] );
		update_option( 'pinova_messengers', [ 'bale_api_token' => 'synthetic', 'bale_bot_id' => '1' ] );
		update_option( 'pinova_zohal', [] );
		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		parent::tear_down();
	}

	/** Intercept every URL, including a regressed second handoff. No recipient is contacted. */
	public function intercept_http( $preempt, array $arguments, string $url ) {
		$this->http_calls[] = $url;
		if ( 'https://safir.bale.ai/api/v3/send_message' === $url ) {
			return new \WP_Error( 'http_request_failed', 'Synthetic unknown provider outcome.' );
		}
		return [
			'headers'  => [],
			'body'     => '{"Value":"1234567890123456"}',
			'response' => [ 'code' => 200, 'message' => 'OK' ],
		];
	}

	public function test_cron_legacy_logging_reconnect_does_not_allow_a_second_provider_handoff(): void {
		global $wpdb;

		$original    = $wpdb;
		$database    = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$flow        = null;
		$user_id     = null;
		$reconnected = false;
		$before_id   = '';
		$after_id    = '';
		$database->set_prefix( $original->prefix );
		$reconnect = static function ( string $query ) use ( $database, &$reconnected, &$before_id, &$after_id ): string {
			if ( ! $reconnected && str_starts_with( $query, 'INSERT INTO ' )
				&& str_contains( $query, $database->prefix . 'pinova_logs' )
				&& str_contains( $query, 'otp.channel_send_failed' ) ) {
				$reconnected = true;
				$before_id   = (string) $database->get_var( 'SELECT CONNECTION_ID()' );
				self::assertTrue( $database->close() );
				self::assertTrue( $database->db_connect( false ) );
				$after_id = (string) $database->get_var( 'SELECT CONNECTION_ID()' );
			}
			return $query;
		};

		try {
			// Only this autocommit connection owns the queue and its flow lock.
			// Reconnecting it must not commit PHPUnit's primary transaction.
			$wpdb    = $database;
			$mobile  = '+989120000131';
			$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
			update_user_meta( $user_id, 'pinova_mobile', $mobile );
			[ $flow, $deadline ] = RateLimitService::decoy_flow( $mobile, 'authenticate', '192.0.2.231', null, true );
			self::assertTrue( RateLimitService::acknowledge_queued_otp( $flow ) );

			add_filter( 'query', $reconnect );
			OTPService::deliver_queued( $flow );
			remove_filter( 'query', $reconnect );

			self::assertTrue( $reconnected, 'The ordinary channel-failure logger must actually reconnect the claim connection.' );
			self::assertNotSame( '', $before_id );
			self::assertNotSame( '', $after_id );
			self::assertNotSame( $before_id, $after_id, 'The original connection lease must have been lost.' );
			self::assertCount( 1, $this->http_calls, 'A persisted token must not authorize another provider after the connection-owned flow lock was lost.' );
			self::assertSame( 'https://safir.bale.ai/api/v3/send_message', $this->http_calls[0] );
			$otp = OTP::query()->where( 'flow_id', $flow )->first();
			self::assertInstanceOf( OTP::class, $otp, 'Keep the OTP whose first provider outcome is unknown.' );
			self::assertSame( [ 'bale' => false, 'sms' => false ], $otp->channels );
			self::assertSame( $deadline, $otp->expires_at->getTimestamp() );
			$payload = $database->get_var( $database->prepare( 'SELECT `payload` FROM %i WHERE `scope` = %s', $database->prefix . 'pinova_rate_limits', $flow ) );
			self::assertFalse( is_string( $payload ) && str_starts_with( $payload, 'ready:' ), 'An attempted unknown outcome may never become READY again.' );

			OTPService::deliver_queued( $flow );
			self::assertCount( 1, $this->http_calls, 'A second worker pass must not automatically resend the uncertain OTP.' );
			self::assertSame( 1, OTP::query()->where( 'flow_id', $flow )->count() );
		} finally {
			remove_filter( 'query', $reconnect );
			if ( null !== $flow ) {
				RateLimitService::retire_queued_otp( $flow );
				RateLimitService::delete_queued_for_identifiers( [ '+989120000131' ] );
				OTP::query()->where( 'flow_id', $flow )->delete();
				$database->delete( $database->prefix . 'pinova_logs', [ 'flow_id' => $flow ] );
			}
			if ( null !== $user_id ) {
				wp_delete_user( $user_id );
			}
			$wpdb = $original;
			$database->close();
		}
	}
}
