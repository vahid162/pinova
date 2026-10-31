<?php

namespace Pinova\Services;

use Pinova\Channels\Bale;
use Pinova\Exceptions\OTPImmediateDeliveryDeferredException;
use Pinova\Gateways\MaxSMS;
use Pinova\Gateways\MeliPayamak;
use Pinova\Gateways\PanelChi;
use Pinova\Gateways\BaseGateway;
use Pinova\Objects\Identifier;
use Pinova\Pinova;
use Pinova\Logging\DiagnosticContext;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

/** Optional request-local delivery after verified, successful native FPM completion. */
final class OTPImmediateDelivery {
	private const PROVIDER_BUDGET_SECONDS = 8;
	private static bool $armed            = false;
	private static ?array $context        = null;
	private bool $valid                   = true;
	private bool $post_dispatch           = false;
	private bool $pre_serve               = false;
	private bool $pre_echo                = false;
	private bool $body_closed             = false;
	private bool $late_closed             = false;
	private string $body                  = '';
	private int $late_level               = 0;
	private array $expected;

	private function __construct( private string $flow_id, private WP_REST_Response $response, private WP_REST_Request $request, private ?DiagnosticContext $diagnostic = null ) {
		$this->expected = $response->get_data();
	}

	/** Called only by successful originating APIs after scheduling and acknowledgement. */
	public static function arm( string $flow_id, WP_REST_Response $response, WP_REST_Request $request, ?int $original_deadline = null ): void {
		$diagnostic = null === $original_deadline ? null : OTPService::diagnostic_context( $flow_id, $original_deadline );
		try {
			if ( ! defined( 'PINOVA_OTP_IMMEDIATE_DELIVERY' ) || true !== PINOVA_OTP_IMMEDIATE_DELIVERY ) {
				self::observe_decline( $diagnostic, 'disabled' );
				return;
			}
			if ( self::$armed || ! preg_match( '/\A[a-f0-9]{32}\z/', $flow_id ) ) {
				self::observe_decline( $diagnostic, 'claim_unavailable' );
				return;
			}
			if ( 'fpm-fcgi' !== PHP_SAPI ) {
				self::observe_decline( $diagnostic, 'unsupported_runtime' );
				return;
			}
			if ( ! function_exists( 'fastcgi_finish_request' ) ) {
				self::observe_decline( $diagnostic, 'completion_unavailable' );
				return;
			}
			if ( headers_sent() || 200 !== $response->get_status() || ! is_array( $response->get_data() ) || true !== ( $response->get_data()['success'] ?? null ) ) {
				self::observe_decline( $diagnostic, 'response_unhealthy' );
				return;
			}
			if ( 1 !== has_action( 'shutdown', 'wp_ob_end_flush_all' ) ) {
				self::observe_decline( $diagnostic, 'unsupported_buffer' );
				return;
			}
			foreach ( ob_get_status( true ) as $buffer ) {
				if ( 'default output handler' !== $buffer['name'] || 0 !== $buffer['buffer_used'] ) {
					self::observe_decline( $diagnostic, 'unsupported_buffer' );
					return;
				}
			}
			$boundary = new self( $flow_id, $response, $request, $diagnostic );
			if ( ! ob_start( [ $boundary, 'observe_body' ] ) ) {
				self::observe_decline( $diagnostic, 'completion_unavailable' );
				return;
			}
			self::$armed = true;
			add_filter( 'rest_post_dispatch', [ $boundary, 'post_dispatch' ], PHP_INT_MAX, 3 );
			add_filter( 'rest_pre_serve_request', [ $boundary, 'pre_serve' ], PHP_INT_MAX, 4 );
			add_filter( 'rest_pre_echo_response', [ $boundary, 'pre_echo' ], PHP_INT_MAX, 3 );
			remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );
			add_action( 'shutdown', [ $boundary, 'flush_core_buffers' ], 1 );
			register_shutdown_function( [ $boundary, 'finish' ] );
		} catch ( Throwable $throwable ) {
			// Optional acceleration must never affect the accepted public response.
			unset( $throwable );
			self::observe_decline( $diagnostic, 'operation_failed' );
		}
	}

	private static function observe_decline( ?DiagnosticContext $diagnostic, string $reason ): void {
		if ( null !== $diagnostic ) {
			$diagnostic->observe(
				'immediate_admission',
				[
					'execution_mode' => 'request',
					'result'         => 'deferred',
					'error_code'     => $reason,
				]
			);
			OTPService::retain_diagnostic( $diagnostic );
		}
	}

	public function post_dispatch( $response, $server, $request ) {
		unset( $server );
		$this->post_dispatch = $response === $this->response && $request === $this->request
			&& 200 === $response->get_status() && $this->expected === $response->get_data();
		return $response;
	}

	public function pre_serve( $served, $response, $request, $server ) {
		unset( $server );
		$this->pre_serve = false === $served && $response === $this->response && $request === $this->request;
		return $served;
	}

	public function pre_echo( $data, $server, $request ) {
		unset( $server );
		$this->pre_echo = $request === $this->request && $data === $this->expected;
		return $data;
	}

	public function observe_body( string $chunk, int $phase ): string {
		// Retain a small expected envelope only. Never log or persist signed state.
		if ( strlen( $this->body ) + strlen( $chunk ) <= 8192 ) {
			$this->body .= $chunk;
		} else {
			$this->valid = false;
		}
		$this->valid = $this->valid && 0 === ( $phase & PHP_OUTPUT_HANDLER_CLEAN );
		if ( 0 !== ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
			$this->body_closed = true;
		}
		return $chunk;
	}

	public function observe_late( string $chunk, int $phase ): string {
		$this->valid = $this->valid && '' === $chunk && 0 === ( $phase & PHP_OUTPUT_HANDLER_CLEAN );
		if ( 0 !== ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
			$this->late_closed = true;
		}
		return $chunk;
	}

	/** Preserve WordPress's priority-one flush, then observe all later shutdown output. */
	public function flush_core_buffers(): void {
		wp_ob_end_flush_all();
		ob_start( [ $this, 'observe_late' ] );
		$this->late_level = ob_get_level();
	}

	public function finish(): void {
		try {
			$error    = error_get_last();
			$fatal    = null !== $error && in_array( $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ], true );
			$status   = ob_get_status();
			$observer = self::class . '::observe_late' === ( $status['name'] ?? '' );
			if ( ! $this->valid || ! $this->post_dispatch || ! $this->pre_serve || ! $this->pre_echo || ! $this->body_closed
				|| $this->late_closed || ! $observer || ob_get_level() !== $this->late_level || '' !== ob_get_contents()
				|| $fatal || 200 !== http_response_code() || ! $this->headers_are_healthy() || json_decode( $this->body, true ) !== $this->expected ) {
				self::observe_decline( $this->diagnostic, 'response_unhealthy' );
				return;
			}
			ob_end_flush();
			if ( true === fastcgi_finish_request() ) {
				self::dispatch( $this->flow_id, $this->diagnostic );
			} else {
				self::observe_decline( $this->diagnostic, 'completion_failed' );
			}
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			self::observe_decline( $this->diagnostic, 'operation_failed' );
		}
	}

	private function headers_are_healthy(): bool {
		$json = false;
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'Content-Type:' ) ) {
				$json = 1 === preg_match( '/\AContent-Type:\s*application\/json(?:\s*;|\s*\z)/i', $header );
			} elseif ( 0 === stripos( $header, 'Content-Length:' ) ) {
				$value = trim( substr( $header, 15 ) );
				if ( ! ctype_digit( $value ) || strlen( $this->body ) !== (int) $value ) {
					return false;
				}
			} elseif ( 0 === stripos( $header, 'Content-Encoding:' ) && 'identity' !== strtolower( trim( substr( $header, 17 ) ) ) ) {
				return false;
			}
		}
		return $json;
	}

	/** Only finish() reaches this in production; no endpoint accepts a flow override. */
	private static function dispatch( string $flow_id, ?DiagnosticContext $diagnostic = null ): void {
		if ( null !== self::$context ) {
			self::observe_decline( $diagnostic, 'claim_unavailable' );
			return;
		}
		$headroom = self::headroom_decline_reason();
		if ( null !== $headroom ) {
			self::observe_decline( $diagnostic, $headroom );
			return;
		}
		$lease = OTPQueueStore::acquire_immediate_slot();
		if ( null === $lease ) {
			self::observe_decline( $diagnostic, 'slot_unavailable' );
			return;
		}
		self::$context = [
			'flow'               => $flow_id,
			'lease'              => $lease,
			'deadline'           => microtime( true ) + self::PROVIDER_BUDGET_SECONDS,
			'attempted'          => false,
			'diagnostic'         => $diagnostic,
			'admission_observed' => false,
		];
		try {
			OTPService::deliver_queued( $flow_id );
		} finally {
			if ( ! self::$context['admission_observed'] && null !== $diagnostic ) {
				$diagnostic->observe(
					'immediate_admission',
					[
						'execution_mode' => 'immediate',
						'result'         => 'deferred',
						'error_code'     => 'claim_unavailable',
					]
				);
			}
			$released = false;
			try {
				$released = OTPQueueStore::release_immediate_slot_observed( $lease );
			} finally {
				// Unknown release keeps this finite request-local context as a
				// suppression latch. Even finish()'s catch may not flush beneath it.
				if ( $released ) {
					self::$context = null;
				}
				if ( null !== $diagnostic ) {
					OTPService::retain_diagnostic( $diagnostic );
				}
				OTPService::flush_diagnostics();
			}
		}
	}

	/** Unknown host pressure retains cron; this does not tune the shared PHP pool. */
	private static function has_headroom(): bool {
		return null === self::headroom_decline_reason();
	}

	private static function headroom_decline_reason(): ?string {
		if ( 'Linux' !== PHP_OS_FAMILY || ! function_exists( 'sys_getloadavg' ) ) {
			return 'headroom_unavailable';
		}
		$load = sys_getloadavg();
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bounded local procfs observation; unavailable pressure data silently retains cron.
		$memory = @file_get_contents( '/proc/meminfo', false, null, 0, 8192 );
		if ( ! is_array( $load ) || ! isset( $load[0] ) || ! is_numeric( $load[0] ) || ! is_finite( (float) $load[0] ) || 0 > $load[0]
			|| ! is_string( $memory ) || 1 !== preg_match( '/^MemAvailable:\s+(\d+)\s+kB$/m', $memory ) ) {
			return 'headroom_unavailable';
		}
		return self::headroom_is_sufficient( $memory, $load ) ? null : 'headroom_insufficient';
	}

	private static function headroom_is_sufficient( string|false $memory, array|false $load ): bool {
		return is_array( $load ) && isset( $load[0] ) && is_numeric( $load[0] ) && is_finite( (float) $load[0] ) && 0 <= $load[0] && 3 > $load[0]
			&& is_string( $memory ) && 1 === preg_match( '/^MemAvailable:\s+(\d+)\s+kB$/m', $memory, $matches ) && 1048576 <= (int) $matches[1];
	}

	public static function is_active( ?string $flow_id = null ): bool {
		return null !== self::$context && ( null === $flow_id || self::$context['flow'] === $flow_id );
	}

	/** Validate supported selected channels before changing durable READY state. */
	public static function admission( string $flow_id ): ?\Closure {
		if ( ! self::is_active( $flow_id ) ) {
			return null;
		}
		return static function ( array $queued ): bool {
			$identifier = new Identifier( $queued['identifier'] );
			if ( ! $identifier->is_mobile() ) {
				self::observe_active_admission( 'channel_unsupported' );
				return false;
			}
			if ( $queued['deadline'] <= time() ) {
				self::observe_active_admission( 'deadline_expired' );
				return false;
			}
			if ( ! self::capacity_is_active() ) {
				self::observe_active_admission( microtime( true ) >= self::$context['deadline'] ? 'budget_expired' : 'claim_unavailable' );
				return false;
			}
			if ( ! self::channels_are_bounded( ChannelService::get_channels( $identifier ) ) ) {
				self::observe_active_admission( 'channel_unsupported' );
				return false;
			}
			self::observe_active_admission( 'none' );
			return true;
		};
	}

	/** Pure buffered fact; no claim is acquired or restored for diagnostics. */
	public static function observe_claim_decline( string $flow_id ): void {
		if ( self::is_active( $flow_id ) ) {
			self::observe_active_admission( 'claim_unavailable' );
		}
	}

	private static function observe_active_admission( string $reason ): void {
		if ( null !== self::$context ) {
			self::$context['admission_observed'] = true;
			$diagnostic = self::$context['diagnostic'] ?? null;
			if ( $diagnostic instanceof DiagnosticContext ) {
				$diagnostic->observe(
					'immediate_admission',
					[
						'execution_mode' => 'immediate',
						'result'         => 'none' === $reason ? 'admitted' : 'deferred',
						'error_code'     => $reason,
					]
				);
			}
		}
	}

	public static function channels_are_bounded( array $channels ): bool {
		if ( [] === $channels ) {
			return false;
		}
		foreach ( $channels as $channel ) {
			if ( 'sms' === $channel ) {
				$gateway = self::bounded_gateway();
				if ( null === $gateway || ! $gateway->is_enable() ) {
					return false;
				}
			} elseif ( 'bale' === $channel ) {
				if ( function_exists( 'pinova_bale_send_v2' ) || ! Bale::is_enable() ) {
					return false;
				}
			} elseif ( 'call' !== $channel ) {
				return false;
			}
		}
		return true;
	}

	/** Reject arbitrary classes before their constructors can perform any work. */
	public static function bounded_gateway(): ?BaseGateway {
		$configured = Pinova::get_option( 'sms.gateway' );
		if ( ! is_string( $configured ) ) {
			return null;
		}
		$configured = ltrim( $configured, '\\' );
		foreach ( [ MeliPayamak::class, MaxSMS::class, PanelChi::class ] as $class ) {
			if ( $configured === $class || substr( $class, strrpos( $class, '\\' ) + 1 ) === $configured ) {
				return new $class();
			}
		}
		return null;
	}

	/** Recheck connection-owned capacity and claim before every channel and HTTP send. */
	public static function guard( string $flow_id, string $claim_token, array $channels = [] ): void {
		if ( ! self::is_active( $flow_id ) ) {
			return;
		}
		// Provider selection can read options and reconnect wpdb. Make the single
		// ownership/token observation the final database read before admission.
		if ( [] !== $channels && ! self::channels_are_bounded( $channels ) ) {
			throw new OTPImmediateDeliveryDeferredException( 'Immediate provider is unsupported.', reason: 'channel_unsupported' );
		}
		if ( microtime( true ) >= self::$context['deadline'] ) {
			throw new OTPImmediateDeliveryDeferredException( 'Immediate provider budget expired.', reason: 'budget_expired' );
		}
		if ( ! OTPQueueStore::immediate_claim_is_active( $flow_id, $claim_token, self::$context['lease'] ) ) {
			throw new OTPImmediateDeliveryDeferredException( 'Immediate delivery permission ended.' );
		}
		self::$context['claim_token'] = $claim_token;
	}

	private static function capacity_is_active(): bool {
		return null !== self::$context && microtime( true ) < self::$context['deadline'] && OTPQueueStore::owns_immediate_slot( self::$context['lease'] );
	}

	/** Curl uses this immediately before handing work to WordPress HTTP transports. */
	public static function provider_timeout(): int|float {
		$scope = OTPQueueStore::provider_scope();
		if ( null === $scope ) {
			return 8;
		}
		if ( ! self::is_active( $scope['flow'] ) ) {
			OTPQueueStore::guard_provider();
			OTPQueueStore::mark_provider_attempted();
			return 8;
		}
		self::guard( $scope['flow'], $scope['token'] );
		$remaining = self::$context['deadline'] - microtime( true );
		if ( 0 >= $remaining ) {
			throw new OTPImmediateDeliveryDeferredException( 'Immediate provider budget expired.', reason: 'budget_expired' );
		}
		// Mark before HTTP hooks: a throw or timeout has an unknown external outcome.
		self::$context['attempted'] = true;
		OTPQueueStore::mark_provider_attempted();
		return min( 8.0, $remaining );
	}

	public static function provider_was_attempted(): bool {
		return null !== self::$context && self::$context['attempted'];
	}
}
