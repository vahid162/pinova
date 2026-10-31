<?php

namespace Pinova\Logging;

use Pinova\Helpers\JWT;
use Throwable;

/** Bounded in-memory observations; flush only after releasing delivery claims. */
final class DiagnosticContext {

	public const EVENT             = 'otp.diagnostic';
	public const MAX_CONTEXT_BYTES = 2048;
	public const MAX_OBSERVATIONS  = 12;
	public const OPERATIONS        = [ 'queued_otp', 'verify_otp', 'verify_mobile' ];

	private const STAGES = [
		'queue_accepted'         => [ 'otp_queue', 'otp_queue.acknowledge_queued_otp' ],
		'immediate_admission'    => [ 'otp_immediate', 'otp_immediate.dispatch' ],
		'worker_started'         => [ 'otp_delivery', 'otp_service.deliver_queued' ],
		'provider_started'       => [ 'http_transport', 'curl.post' ],
		'provider_completed'     => [ 'http_transport', 'curl.post' ],
		'channel_completed'      => [ 'otp_delivery', 'channel_service.send' ],
		'delivery_completed'     => [ 'otp_delivery', 'otp_service.deliver_queued' ],
		'verification_completed' => [ 'otp_verification', 'otp_service.verify' ],
	];

	private const ERROR_CODES = [
		'none',
		'disabled',
		'unsupported_runtime',
		'unsupported_buffer',
		'response_unhealthy',
		'completion_unavailable',
		'completion_failed',
		'headroom_unavailable',
		'headroom_insufficient',
		'slot_unavailable',
		'channel_unsupported',
		'deadline_expired',
		'claim_unavailable',
		'budget_expired',
		'queue_schedule_failed',
		'queue_acknowledgement_failed',
		'invalid_payload',
		'duplicate_records',
		'policy_rejected',
		'already_completed',
		'provider_returned_false',
		'provider_exception',
		'provider_timeout',
		'provider_outcome_unknown',
		'invalid_token',
		'record_not_found',
		'flow_mismatch',
		'purpose_mismatch',
		'already_verified',
		'attempt_limit',
		'ip_mismatch',
		'invalid_code',
		'claim_rejected',
		'operation_failed',
	];

	private const CODE_VALUES = [
		'execution_mode' => [ 'immediate', 'cron', 'cli', 'request' ],
		'result'         => [ 'accepted', 'rejected', 'unknown', 'admitted', 'deferred', 'skipped', 'started', 'completed', 'failed', 'verified' ],
		'channel'        => [ 'sms', 'email', 'bale', 'call' ],
	];

	private const INTEGER_LIMITS = [
		'observed_at'               => [ 1577836800, 4102444799 ],
		'flow_deadline'             => [ 1577836800, 4102444799 ],
		'request_to_worker_seconds' => [ 0, 180 ],
		'provider_duration_ms'      => [ 0, 600000 ],
		'channel_duration_ms'       => [ 0, 600000 ],
		'remaining_seconds'         => [ 0, 180 ],
		'http_status'               => [ 100, 599 ],
	];

	private string $flow_id;
	private int $deadline;
	private string $operation;
	/** @var callable */
	private $clock;
	/** @var array<int,array<string,int|string>> */
	private array $observations = [];
	private int $observed_count = 0;
	private int $last_observed  = 0;
	private bool $valid         = false;
	private bool $flushed       = false;
	private string $coverage    = 'invalid';

	/** The caller must already have authenticated the flow and original deadline. */
	public function __construct( string $flow_id, int $deadline, string $operation = 'queued_otp', ?callable $clock = null ) {
		$this->flow_id   = $flow_id;
		$this->deadline  = $deadline;
		$this->operation = $operation;
		$this->clock     = $clock ?? 'time';
		$now             = $this->now();
		$this->valid     = null !== $now && 1 === preg_match( '/\A[a-f0-9]{32}\z/D', $flow_id )
			&& in_array( $operation, self::OPERATIONS, true ) && $deadline > $now && $deadline <= $now + JWT::DEFAULT_TTL;
		if ( $this->valid && null !== $now ) {
			$this->last_observed = $now;
			$this->coverage      = 'pending';
		}
	}

	/**
	 * No logger, configuration, database or WordPress call belongs in this method.
	 *
	 * @param array<string,mixed> $details
	 */
	public function observe( string $stage, array $details = [] ): void {
		if ( $this->flushed || ! $this->is_current() ) {
			return;
		}
		if ( self::MAX_OBSERVATIONS <= $this->observed_count ) {
			$this->coverage = 'incomplete';
			return;
		}
		$details['stage']         = $stage;
		$details['operation']     = $this->operation;
		$details['observed_at']   = $this->last_observed;
		$details['flow_deadline'] = $this->deadline;
		$safe                     = self::sanitize( $details );
		if ( [] === $safe ) {
			$this->coverage = 'incomplete';
			return;
		}
		$this->observations[] = $safe;
		++$this->observed_count;
	}

	/** Called by the owner only after every connection-owned delivery lease ends. */
	public function flush( ?Logger $logger = null ): void {
		if ( $this->flushed ) {
			return;
		}
		$this->flushed      = true;
		$observations       = $this->observations;
		$this->observations = [];
		if ( ! $this->is_current() ) {
			return;
		}
		if ( [] === $observations ) {
			if ( 'pending' === $this->coverage ) {
				$this->coverage = 'incomplete';
			}
			return;
		}
		try {
			$logger = $logger ?? Logger::instance();
			foreach ( $observations as $observation ) {
				if ( ! $logger->diagnostic( $this, $observation ) ) {
					$this->coverage = 'incomplete';
				}
			}
			if ( 'pending' === $this->coverage ) {
				$this->coverage = 'sampled';
			}
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			$this->coverage = 'incomplete';
		}
	}

	/**
	 * A local clock/state recheck, not an authentication or delivery permission.
	 *
	 * @phpstan-impure
	 */
	public function is_current(): bool {
		if ( ! $this->valid ) {
			return false;
		}
		$now = $this->now();
		if ( null === $now || $now < $this->last_observed || $this->deadline > $now + JWT::DEFAULT_TTL ) {
			$this->valid    = false;
			$this->coverage = 'incomplete';
			return false;
		}
		if ( $now >= $this->deadline ) {
			$this->valid    = false;
			$this->coverage = 'expired';
			return false;
		}
		$this->last_observed = $now;
		return true;
	}

	public function flow_id(): string {
		return $this->flow_id;
	}

	public function deadline(): int {
		return $this->deadline;
	}

	public function operation(): string {
		return $this->operation;
	}

	public function coverage(): string {
		return $this->coverage;
	}

	/**
	 * One finite catalogue for incoming and historical diagnostic context.
	 * Component and symbolic source are derived, never caller-controlled prose.
	 *
	 * @param array<string,mixed> $context
	 * @return array<string,int|string>
	 */
	public static function sanitize( array $context ): array {
		$stage = $context['stage'] ?? null;
		if ( ! is_string( $stage ) || ! isset( self::STAGES[ $stage ] ) ) {
			return [];
		}
		$safe = [
			'component'  => self::STAGES[ $stage ][0],
			'stage'      => $stage,
			'source_ref' => self::STAGES[ $stage ][1],
		];
		if ( isset( $context['operation'] ) && in_array( $context['operation'], self::OPERATIONS, true ) ) {
			$safe['operation'] = (string) $context['operation'];
		}
		if ( isset( $context['error_code'] ) && in_array( $context['error_code'], self::ERROR_CODES, true ) ) {
			$safe['error_code'] = (string) $context['error_code'];
		}
		foreach ( self::CODE_VALUES as $key => $values ) {
			if ( isset( $context[ $key ] ) && in_array( $context[ $key ], $values, true ) ) {
				$safe[ $key ] = (string) $context[ $key ];
			}
		}
		foreach ( self::INTEGER_LIMITS as $key => [ $minimum, $maximum ] ) {
			if ( isset( $context[ $key ] ) && is_int( $context[ $key ] ) && $minimum <= $context[ $key ] && $maximum >= $context[ $key ] ) {
				$safe[ $key ] = $context[ $key ];
			}
		}
		return $safe;
	}

	private function now(): ?int {
		try {
			$now = call_user_func( $this->clock );
			return is_int( $now ) && 1577836800 <= $now && 4102444799 >= $now ? $now : null;
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return null;
		}
	}
}
