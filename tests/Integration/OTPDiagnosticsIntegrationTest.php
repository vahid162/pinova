<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Install;
use Pinova\Helpers\JWT;
use Pinova\Logging\DiagnosticContext;
use Pinova\Logging\EventThrottle;
use Pinova\Models\OTP;
use Pinova\Services\OTPImmediateDelivery;
use Pinova\Services\OTPQueueStore;
use Pinova\Services\OTPService;
use Pinova\Services\RateLimitService;
use WP_UnitTestCase;

/** Real queue/adapter/logger boundaries with nonbillable, intercepted providers. */
final class OTPDiagnosticsIntegrationTest extends WP_UnitTestCase {

	/** @var string[] */
	private array $http_calls = [];
	private bool $bale_accepts = false;
	private int $mail_calls = 0;
	private bool $mail_throws = false;
	private ?\Closure $during_http = null;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();
		$this->http_calls = [];
		$this->bale_accepts = false;
		$this->mail_calls = 0;
		$this->mail_throws = false;
		$this->during_http = null;
		update_option( 'pinova_logging', [ 'minimum_level' => 'warning', 'retention_days' => 14 ] );
		update_option( 'pinova_sms', [ 'gateway' => 'MeliPayamak' ] );
		update_option( 'pinova_gateway_melipayamak', [ 'username' => 'synthetic', 'password' => 'synthetic' ] );
		update_option( 'pinova_messengers', [ 'bale_api_token' => 'synthetic', 'bale_bot_id' => '1' ] );
		update_option( 'pinova_zohal', [] );
		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );
		add_filter( 'pre_wp_mail', [ $this, 'intercept_mail' ] );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		remove_filter( 'pre_wp_mail', [ $this, 'intercept_mail' ] );
		OTPService::flush_diagnostics();
		parent::tear_down();
	}

	/** Intercept every URL, including a regressed second handoff. No recipient is contacted. */
	public function intercept_http( $preempt, array $arguments, string $url ) {
		$this->http_calls[] = $url;
		if ( null !== $this->during_http ) {
			( $this->during_http )( $url, $arguments );
		}
		if ( 'https://safir.bale.ai/api/v3/send_message' === $url ) {
			if ( $this->bale_accepts ) {
				return [ 'headers' => [], 'body' => '{"message_id":1}', 'response' => [ 'code' => 200, 'message' => 'OK' ] ];
			}
			return new \WP_Error( 'http_request_failed', 'Synthetic unknown provider outcome.' );
		}
		return [
			'headers'  => [],
			'body'     => '{"Value":"1234567890123456"}',
			'response' => [ 'code' => 200, 'message' => 'OK' ],
		];
	}

	public function intercept_mail(): bool {
		++$this->mail_calls;
		if ( $this->mail_throws ) {
			throw new \RuntimeException( 'Synthetic unknown mail outcome.' );
		}
		return true;
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

	public function test_gateway_option_reconnect_before_first_http_retains_only_the_unattempted_queue(): void {
		update_option( 'pinova_messengers', [] );
		$this->with_flow( '+989120000132', function ( string $flow, int $deadline, \wpdb $database ): void {
			$reconnected = false;
			$reconnect = static function () use ( $database, &$reconnected ) {
				if ( ! $reconnected ) {
					$reconnected = true;
					self::assertTrue( $database->close() );
					self::assertTrue( $database->db_connect( false ) );
				}
				return false;
			};
			add_filter( 'pre_option_pinova_gateway_melipayamak', $reconnect );
			try {
				OTPService::deliver_queued( $flow );
			} finally {
				remove_filter( 'pre_option_pinova_gateway_melipayamak', $reconnect );
			}
			self::assertTrue( $reconnected );
			self::assertCount( 0, $this->http_calls );
			self::assertSame( 0, OTP::query()->where( 'flow_id', $flow )->count() );
			$row = $this->queue_row( $database, $flow );
			self::assertSame( $deadline, (int) strtotime( $row['reset_at'] . ' UTC' ) );
			self::assertStringStartsWith( 'processing:', $row['payload'], 'A lost lease cannot restore READY or consume an unattempted claim.' );
		} );
	}

	public function test_email_template_option_reconnect_stops_before_wp_mail(): void {
		$this->with_flow( 'diagnostic-email@example.test', function ( string $flow, int $deadline, \wpdb $database ): void {
			$reconnected = false;
			$reconnect = static function () use ( $database, &$reconnected ) {
				if ( ! $reconnected ) {
					$reconnected = true;
					self::assertTrue( $database->close() );
					self::assertTrue( $database->db_connect( false ) );
				}
				return false;
			};
			add_filter( 'pre_option_pinova_design', $reconnect );
			try {
				OTPService::deliver_queued( $flow );
			} finally {
				remove_filter( 'pre_option_pinova_design', $reconnect );
			}
			self::assertTrue( $reconnected );
			self::assertSame( 0, $this->mail_calls );
			self::assertSame( 0, OTP::query()->where( 'flow_id', $flow )->count() );
			self::assertStringStartsWith( 'processing:', $this->queue_row( $database, $flow )['payload'] );
		} );
	}

	/** @dataProvider native_identifiers */
	public function test_reconnect_inside_the_final_compound_read_never_authorizes_a_transport( string $identifier ): void {
		update_option( 'pinova_messengers', [] );
		$this->with_flow( $identifier, function ( string $flow, int $deadline, \wpdb $database ): void {
			$reconnected = false;
			$reconnect = static function ( string $query ) use ( $database, &$reconnected ): string {
				if ( ! $reconnected && str_contains( $query, 'AS flow_holder' )
					&& in_array( 'provider_timeout', array_column( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 ), 'function' ), true ) ) {
					$reconnected = true;
					self::assertTrue( $database->close() );
					self::assertTrue( $database->db_connect( false ) );
				}
				return $query;
			};
			add_filter( 'query', $reconnect );
			try {
				OTPService::deliver_queued( $flow );
			} finally {
				remove_filter( 'query', $reconnect );
			}
			self::assertTrue( $reconnected );
			self::assertCount( 0, $this->http_calls );
			self::assertSame( 0, $this->mail_calls );
			self::assertSame( 0, OTP::query()->where( 'flow_id', $flow )->count() );
			self::assertStringStartsWith( 'processing:', $this->queue_row( $database, $flow )['payload'] );
		} );
	}

	public static function native_identifiers(): array {
		return [ [ '+989120000133' ], [ 'diagnostic-final-mail@example.test' ] ];
	}

	public function test_first_accepted_channel_survives_a_lost_lease_without_a_second_send(): void {
		$this->bale_accepts = true;
		$this->with_flow( '+989120000134', function ( string $flow, int $deadline, \wpdb $database ): void {
			$this->during_http = static function () use ( $database ): void {
				self::assertTrue( $database->close() );
				self::assertTrue( $database->db_connect( false ) );
			};
			OTPService::deliver_queued( $flow );
			$this->during_http = null;
			self::assertCount( 1, $this->http_calls );
			$otp = OTP::query()->where( 'flow_id', $flow )->first();
			self::assertInstanceOf( OTP::class, $otp );
			self::assertSame( [ 'bale' => true, 'sms' => false ], $otp->channels );
			self::assertSame( $deadline, $otp->expires_at->getTimestamp() );
			OTPService::deliver_queued( $flow );
			self::assertCount( 1, $this->http_calls );
		} );
	}

	public function test_cron_timing_is_buffered_until_claim_release_and_retains_the_original_origin(): void {
		$this->bale_accepts = true;
		$this->with_flow( '+989120000135', function ( string $flow, int $deadline, \wpdb $database ): void {
			$deadline -= 30;
			self::assertSame( 1, $database->update( $database->prefix . 'pinova_rate_limits', [ 'reset_at' => gmdate( 'Y-m-d H:i:s', $deadline ) ], [ 'scope' => $flow ] ) );
			$this->with_diagnostics( function () use ( $flow, $deadline, $database ): void {
				$unsafe_writes = [];
				$inspect = static function ( string $query ) use ( &$unsafe_writes ): string {
					if ( str_contains( $query, 'otp.diagnostic' ) || ( str_starts_with( $query, 'UPDATE ' ) && str_contains( $query, EventThrottle::diagnostic_bucket_key() ) ) ) {
						$unsafe_writes[] = OTPQueueStore::has_held_claims() || OTPImmediateDelivery::is_active();
					}
					return $query;
				};
				add_filter( 'query', $inspect );
				try {
					OTPService::deliver_queued( $flow );
				} finally {
					remove_filter( 'query', $inspect );
				}
				self::assertNotEmpty( $unsafe_writes, 'The selected diagnostic scope must actually emit observations.' );
				self::assertNotContains( true, $unsafe_writes );
				$rows = $this->diagnostic_rows( $database, $flow );
				$stages = array_column( array_column( $rows, 'context' ), 'stage' );
				self::assertSame( [ 'worker_started', 'provider_started', 'provider_completed', 'channel_completed', 'provider_started', 'provider_completed', 'channel_completed', 'delivery_completed' ], $stages );
				$worker = $rows[0]['context'];
				self::assertSame( $deadline, $worker['flow_deadline'] );
				self::assertSame( $worker['observed_at'] - ( $deadline - 180 ), $worker['request_to_worker_seconds'] );
				self::assertGreaterThanOrEqual( 30, $worker['request_to_worker_seconds'], 'The claim updated_at must not replace the original request origin.' );
				self::assertSame( 'cron', $worker['execution_mode'] );
				foreach ( $rows as $row ) {
					self::assertLessThanOrEqual( (int) strtotime( $row['created_at'] . ' UTC' ), $row['context']['observed_at'] );
					self::assertStringNotContainsString( '+989120000135', wp_json_encode( $row ) );
					self::assertArrayNotHasKey( 'exception', $row['context'] );
				}
				self::assertSame( 'completed', $rows[2]['context']['result'], 'An HTTP response is transport completion, not panel acceptance.' );
				self::assertSame( 'accepted', $rows[3]['context']['result'] );
				self::assertIsInt( $rows[2]['context']['provider_duration_ms'] );
				self::assertIsInt( $rows[3]['context']['channel_duration_ms'] );
			} );
		} );
	}

	public function test_acknowledgement_records_only_its_winning_transition_and_arm_uses_the_persisted_deadline(): void {
		$this->with_flow( '+989120000139', function ( string $existing, int $deadline, \wpdb $database ): void {
			$this->with_diagnostics( function () use ( $database ): void {
				[ $flow, $original_deadline ] = RateLimitService::decoy_flow( 'diagnostic-ack@example.test', 'authenticate', '192.0.2.239', null, true );
				try {
					self::assertTrue( RateLimitService::acknowledge_queued_otp( $flow ) );
					self::assertTrue( RateLimitService::acknowledge_queued_otp( $flow ) );
					$rows = $this->diagnostic_rows( $database, $flow );
					self::assertCount( 1, $rows );
					self::assertSame( 'queue_accepted', $rows[0]['context']['stage'] );
					self::assertSame( $original_deadline, $rows[0]['context']['flow_deadline'] );
					OTPImmediateDelivery::arm( $flow, new \WP_REST_Response( [ 'success' => true, 'data' => [] ], 200 ), new \WP_REST_Request( 'POST', '/pinova/user/authenticate' ), $original_deadline );
					$rows = $this->diagnostic_rows( $database, $flow );
					self::assertCount( 2, $rows );
					self::assertSame( 'immediate_admission', $rows[1]['context']['stage'] );
					self::assertSame( 'disabled', $rows[1]['context']['error_code'] );
					self::assertSame( $original_deadline, $rows[1]['context']['flow_deadline'] );
				} finally {
					RateLimitService::delete_queued_for_identifiers( [ 'diagnostic-ack@example.test' ] );
					$database->delete( $database->prefix . 'pinova_logs', [ 'flow_id' => $flow ] );
				}
			} );
		} );
	}

	public function test_verification_detail_requires_a_matching_signed_record_and_purpose(): void {
		update_option( 'pinova_messengers', [] );
		$this->with_flow( '+989120000140', function ( string $flow, int $deadline, \wpdb $database, int $user_id ): void {
			$this->with_diagnostics( function () use ( $flow, $deadline, $database, $user_id ): void {
				$code = '';
				$this->during_http = static function ( string $url, array $arguments ) use ( &$code ): void {
					$body = json_decode( $arguments['body'], true );
					$code = (string) ( $body['code'] ?? '' );
				};
				OTPService::deliver_queued( $flow );
				$this->during_http = null;
				self::assertNotSame( '', $code );
				$jwt = JWT::encode( [ 'flow_id' => $flow ], max( 1, $deadline - time() ) );
				$old_ip = $_SERVER['REMOTE_ADDR'] ?? null;
				$_SERVER['REMOTE_ADDR'] = '192.0.2.232';
				try {
					try {
						OTPService::verify( $jwt, str_repeat( '0', strlen( $code ) ), [ OTP::TYPE_LOGIN ] );
						self::fail( 'The synthetic wrong code must be rejected.' );
					} catch ( \Exception $exception ) {
						self::assertNotInstanceOf( \PHPUnit\Framework\AssertionFailedError::class, $exception );
						self::assertSame( 1, OTP::query()->where( 'flow_id', $flow )->first()->attempts );
					}
					$rows = $this->diagnostic_rows( $database, $flow );
					self::assertCount( 1, $rows );
					self::assertSame( 'verification_completed', $rows[0]['context']['stage'] );
					self::assertSame( 'invalid_code', $rows[0]['context']['error_code'] );
					foreach ( [ [ 'not-a-token', [ OTP::TYPE_LOGIN ] ], [ $jwt, [ OTP::TYPE_FORGET ] ] ] as [ $state, $purposes ] ) {
						try {
							OTPService::verify( $state, $code, $purposes );
							self::fail( 'Untrusted or purpose-mismatched state must be rejected.' );
						} catch ( \Exception $exception ) {
						self::assertNotInstanceOf( \PHPUnit\Framework\AssertionFailedError::class, $exception );
							self::assertCount( 1, $this->diagnostic_rows( $database, $flow ), 'No new trusted diagnostic context may come from these failures.' );
						}
					}
					self::assertSame( $user_id, OTPService::verify( $jwt, $code, [ OTP::TYPE_LOGIN ] )->ID );
					$rows = $this->diagnostic_rows( $database, $flow );
					self::assertCount( 2, $rows );
					self::assertSame( 'verified', $rows[1]['context']['result'] );
					self::assertSame( 'none', $rows[1]['context']['error_code'] );
					self::assertStringNotContainsString( $jwt, wp_json_encode( $rows ) );
					self::assertStringNotContainsString( '+989120000140', wp_json_encode( $rows ) );
				} finally {
					if ( null === $old_ip ) {
						unset( $_SERVER['REMOTE_ADDR'] );
					} else {
						$_SERVER['REMOTE_ADDR'] = $old_ip;
					}
				}
			}, 'verify_otp' );
		} );
	}

	/** @dataProvider nested_outcomes */
	public function test_nested_cron_mail_restores_outer_immediate_scope_and_defers_all_diagnostic_writes( string $outcome ): void {
		$this->bale_accepts = true;
		$this->with_flow( '+989120000136', function ( string $flow, int $deadline, \wpdb $database ) use ( $outcome ): void {
			$this->with_flow( 'diagnostic-nested@example.test', function ( string $inner, int $inner_deadline, \wpdb $nested_database ) use ( $flow, $deadline, $database, $outcome ): void {
				$this->with_diagnostics( function () use ( $flow, $deadline, $database, $nested_database, $inner, $outcome ): void {
					$unsafe_writes = [];
					$inspect = static function ( string $query ) use ( &$unsafe_writes ): string {
						if ( str_contains( $query, 'otp.diagnostic' ) || ( str_starts_with( $query, 'UPDATE ' ) && str_contains( $query, EventThrottle::diagnostic_bucket_key() ) ) ) {
							$unsafe_writes[] = OTPQueueStore::has_held_claims() || OTPImmediateDelivery::is_active();
						}
						return $query;
					};
					$reconnect = static function () use ( $nested_database ) {
						self::assertTrue( $nested_database->close() );
						self::assertTrue( $nested_database->db_connect( false ) );
						return false;
					};
					$this->during_http = function () use ( $inner, $flow, $outcome, $reconnect ): void {
						$this->during_http = null;
						$before = OTPQueueStore::provider_scope();
						self::assertSame( $flow, $before['flow'] );
						self::assertTrue( OTPQueueStore::provider_was_attempted( $flow, $before['token'] ) );
						$this->mail_throws = 'throw' === $outcome;
						if ( 'reconnect' === $outcome ) {
							add_filter( 'pre_option_pinova_design', $reconnect );
						}
						try {
							OTPService::deliver_queued( $inner );
						} finally {
							remove_filter( 'pre_option_pinova_design', $reconnect );
						}
						self::assertSame( $before, OTPQueueStore::provider_scope() );
						self::assertTrue( OTPQueueStore::provider_was_attempted( $flow, $before['token'] ) );
					};
					add_filter( 'query', $inspect );
					try {
						$this->dispatch( $flow, new DiagnosticContext( $flow, $deadline ) );
					} finally {
						remove_filter( 'query', $inspect );
					}
					self::assertNull( OTPQueueStore::provider_scope() );
					self::assertNotEmpty( $unsafe_writes );
					self::assertNotContains( true, $unsafe_writes, 'Inner cleanup may not flush underneath the outer queue or fast-slot lease.' );
					self::assertSame( 'reconnect' === $outcome ? 0 : 1, $this->mail_calls );
					self::assertCount( 'reconnect' === $outcome ? 1 : 2, $this->http_calls );
					$outer_otp = OTP::query()->where( 'flow_id', $flow )->first();
					self::assertTrue( $outer_otp->channels['bale'] );
					self::assertSame( 'reconnect' !== $outcome, $outer_otp->channels['sms'] );
					$inner_rows = $this->diagnostic_rows( $database, $inner );
					self::assertNotEmpty( $inner_rows );
					self::assertSame( 'cron', $inner_rows[0]['context']['execution_mode'] );
					OTPService::deliver_queued( $inner );
					self::assertSame( 'reconnect' === $outcome ? 0 : 1, $this->mail_calls );
				} );
			} );
		} );
	}

	public static function nested_outcomes(): array {
		return [ [ 'accepted' ], [ 'throw' ], [ 'reconnect' ] ];
	}

	/** @dataProvider release_faults */
	public function test_unknown_fast_slot_release_suppresses_diagnostics_until_actual_test_owned_release( string $fault ): void {
		$this->bale_accepts = true;
		$this->with_flow( '+989120000137', function ( string $flow, int $deadline, \wpdb $database ) use ( $fault ): void {
			$this->with_diagnostics( function () use ( $flow, $deadline, $database, $fault ): void {
				$failed = false;
				$fail = $this->release_fault( 'pinova_otp_fast_', $fault, $failed );
				$context = new \ReflectionProperty( OTPImmediateDelivery::class, 'context' );
				$context->setAccessible( true );
				$previous_errors = $database->suppress_errors( true );
				add_filter( 'query', $fail );
				try {
					try {
						$this->dispatch( $flow, new DiagnosticContext( $flow, $deadline ) );
					} catch ( \RuntimeException $exception ) {
						self::assertStringContainsString( 'throw', $fault );
					}
					self::assertTrue( $failed, 'The injected native release fault must actually run.' );
					self::assertTrue( OTPImmediateDelivery::is_active() );
					$value = $context->getValue();
					self::assertSame( $value['lease']['connection'], (string) $database->get_var( $database->prepare( 'SELECT IS_USED_LOCK(%s)', $value['lease']['name'] ) ) );
					self::assertCount( 0, $this->diagnostic_rows( $database, $flow ) );
					$late = new DiagnosticContext( $flow, $deadline );
					$late->observe( 'immediate_admission', [ 'result' => 'deferred', 'error_code' => 'operation_failed' ] );
					OTPService::retain_diagnostic( $late );
					self::assertCount( 0, $this->diagnostic_rows( $database, $flow ), 'Later catch/fallback observations must respect the unknown-release latch.' );
				} finally {
					remove_filter( 'query', $fail );
					$value = $context->getValue();
					if ( is_array( $value ) ) {
						self::assertTrue( OTPQueueStore::release_immediate_slot_observed( $value['lease'] ), 'Test cleanup must positively release its actual native slot before clearing the latch.' );
					}
					$context->setValue( null, null );
					OTPService::flush_diagnostics();
					$database->suppress_errors( $previous_errors );
				}
				self::assertNotEmpty( $this->diagnostic_rows( $database, $flow ) );
			} );
		} );
	}

	/** @dataProvider release_faults */
	public function test_unknown_queue_release_suppresses_diagnostics_until_actual_test_owned_release( string $fault ): void {
		$this->bale_accepts = true;
		$this->with_flow( '+989120000138', function ( string $flow, int $deadline, \wpdb $database ) use ( $fault ): void {
			$this->with_diagnostics( function () use ( $flow, $database, $fault ): void {
				$scope = null;
				$this->during_http = function () use ( &$scope ): void {
					$scope = OTPQueueStore::provider_scope();
					$this->during_http = null;
				};
				$failed = false;
				$fail = $this->release_fault( 'pinova_otp_' . $flow, $fault, $failed );
				$unsafe = new \ReflectionProperty( OTPQueueStore::class, 'diagnostic_release_unknown' );
				$unsafe->setAccessible( true );
				$before_unsafe = $unsafe->getValue();
				$previous_errors = $database->suppress_errors( true );
				add_filter( 'query', $fail );
				try {
					try {
						OTPService::deliver_queued( $flow );
					} catch ( \RuntimeException $exception ) {
						self::assertStringContainsString( 'throw', $fault );
					}
					self::assertTrue( $failed );
					self::assertIsArray( $scope );
					self::assertTrue( OTPQueueStore::has_held_claims() );
					self::assertSame( (string) $database->get_var( 'SELECT CONNECTION_ID()' ), (string) $database->get_var( $database->prepare( 'SELECT IS_USED_LOCK(%s)', 'pinova_otp_' . $flow ) ) );
					self::assertCount( 0, $this->diagnostic_rows( $database, $flow ) );
					OTPService::flush_diagnostics();
					self::assertCount( 0, $this->diagnostic_rows( $database, $flow ) );
				} finally {
					remove_filter( 'query', $fail );
					if ( is_array( $scope ) ) {
						self::assertTrue( RateLimitService::finish_queued_otp( $flow, $scope['token'] ) );
						self::assertNull( $database->get_var( $database->prepare( 'SELECT IS_USED_LOCK(%s)', 'pinova_otp_' . $flow ) ) );
					}
					$unsafe->setValue( null, $before_unsafe );
					OTPService::flush_diagnostics();
					$database->suppress_errors( $previous_errors );
				}
				self::assertNotEmpty( $this->diagnostic_rows( $database, $flow ) );
			} );
		} );
	}

	public static function release_faults(): array {
		return [ [ 'release_throw' ], [ 'read_throw' ], [ 'release_zero' ], [ 'release_null' ], [ 'read_error' ] ];
	}

	private function release_fault( string $name, string $fault, bool &$failed ): \Closure {
		return static function ( string $query ) use ( $name, $fault, &$failed ): string {
			$release = str_contains( $query, 'SELECT RELEASE_LOCK(' );
			$read = str_contains( $query, 'AS holder' );
			if ( ! str_contains( $query, $name ) || ! in_array( 'release_named_lock_observed', array_column( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 ), 'function' ), true )
				|| ( str_starts_with( $fault, 'read_' ) ? ! $read : ! $release ) ) {
				return $query;
			}
			$failed = true;
			if ( str_contains( $fault, 'throw' ) ) {
				throw new \RuntimeException( 'Synthetic native release fault.' );
			}
			return 'release_zero' === $fault ? 'SELECT 0' : ( 'release_null' === $fault ? 'SELECT NULL' : 'SELECT `pinova_missing_health_column`' );
		};
	}

	private function with_flow( string $identifier, callable $operation ): void {
		global $wpdb;
		$original = $wpdb;
		$database = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$database->set_prefix( $original->prefix );
		$flow = null;
		$user_id = null;
		try {
			$wpdb = $database;
			$user_id = self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => str_contains( $identifier, '@' ) ? $identifier : '' ] );
			if ( str_starts_with( $identifier, '+' ) ) {
				update_user_meta( $user_id, 'pinova_mobile', $identifier );
			}
			[ $flow, $deadline ] = RateLimitService::decoy_flow( $identifier, 'authenticate', '192.0.2.232', null, true );
			self::assertTrue( RateLimitService::acknowledge_queued_otp( $flow ) );
			$operation( $flow, $deadline, $database, $user_id );
		} finally {
			$this->during_http = null;
			if ( null !== $flow ) {
				RateLimitService::retire_queued_otp( $flow );
				RateLimitService::delete_queued_for_identifiers( [ $identifier ] );
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

	private function with_diagnostics( callable $operation, string $operation_name = 'queued_otp' ): void {
		global $wpdb;
		$key = EventThrottle::diagnostic_bucket_key();
		$before = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE bucket_key = %s', $wpdb->prefix . 'pinova_rate_limits', $key ), ARRAY_A );
		self::assertTrue( EventThrottle::initialize_diagnostics() );
		$settings = static fn(): array => [ 'minimum_level' => 'warning', 'diagnostic_until' => time() + 300, 'diagnostic_scope' => [ 'type' => 'operation', 'value' => $operation_name ] ];
		add_filter( 'pre_option_pinova_logging', $settings );
		try {
			$operation();
		} finally {
			remove_filter( 'pre_option_pinova_logging', $settings );
			$wpdb->delete( $wpdb->prefix . 'pinova_rate_limits', [ 'bucket_key' => $key ] );
			if ( is_array( $before ) ) {
				$wpdb->insert( $wpdb->prefix . 'pinova_rate_limits', $before );
			}
		}
	}

	private function queue_row( \wpdb $database, string $flow ): array {
		return $database->get_row( $database->prepare( 'SELECT `payload`, `reset_at`, `updated_at` FROM %i WHERE scope = %s', $database->prefix . 'pinova_rate_limits', $flow ), ARRAY_A );
	}

	private function diagnostic_rows( \wpdb $database, string $flow ): array {
		$rows = $database->get_results( $database->prepare( 'SELECT `created_at`, `context` FROM %i WHERE event = %s AND flow_id = %s ORDER BY id', $database->prefix . 'pinova_logs', DiagnosticContext::EVENT, $flow ), ARRAY_A );
		foreach ( $rows as &$row ) {
			$row['context'] = json_decode( $row['context'], true );
		}
		return $rows;
	}

	private function dispatch( string $flow, DiagnosticContext $diagnostic ): void {
		$headroom = new \ReflectionMethod( OTPImmediateDelivery::class, 'has_headroom' );
		$headroom->setAccessible( true );
		$deadline = microtime( true ) + 15;
		do {
			$eligible = $headroom->invoke( null );
			if ( true === $eligible ) {
				break;
			}
			usleep( 250000 );
		} while ( microtime( true ) < $deadline );
		self::assertTrue( $eligible, 'Isolated immediate delivery tests require actual host headroom.' );
		$method = new \ReflectionMethod( OTPImmediateDelivery::class, 'dispatch' );
		$method->setAccessible( true );
		$method->invoke( null, $flow, $diagnostic );
	}
}
