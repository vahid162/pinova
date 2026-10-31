<?php

namespace Pinova\Logging;

use Pinova\Helpers\JWT;
use Pinova\Pinova;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

final class Logger extends AbstractLogger {

	private const LEVEL_WEIGHT = [
		LogLevel::DEBUG     => 100,
		LogLevel::INFO      => 200,
		LogLevel::NOTICE    => 250,
		LogLevel::WARNING   => 300,
		LogLevel::ERROR     => 400,
		LogLevel::CRITICAL  => 500,
		LogLevel::ALERT     => 550,
		LogLevel::EMERGENCY => 600,
	];

	private static ?Logger $instance = null;

	private HandlerInterface $handler;

	private SafeContext $safe_context;

	/** @var callable */
	private $config_provider;

	/** @var callable */
	private $correlation_factory;

	/** @var callable */
	private $diagnostic_admission;

	/** @var callable */
	private $metadata_provider;

	private bool $uses_wordpress_config;

	private ?string $correlation_id = null;

	public function __construct(
		?HandlerInterface $handler = null,
		?SafeContext $safe_context = null,
		?callable $config_provider = null,
		?callable $correlation_factory = null,
		?callable $diagnostic_admission = null,
		?callable $metadata_provider = null
	) {
		$this->handler               = $handler ?? new DatabaseHandler();
		$this->safe_context          = $safe_context ?? new SafeContext();
		$this->config_provider       = $config_provider ?? [ $this, 'wordpress_config' ];
		$this->correlation_factory   = $correlation_factory ?? [ $this, 'generate_correlation_id' ];
		$this->diagnostic_admission  = $diagnostic_admission ?? [ EventThrottle::class, 'admit_diagnostic' ];
		$this->metadata_provider     = $metadata_provider ?? [ BuildMetadata::class, 'info' ];
		$this->uses_wordpress_config = null === $config_provider;
	}

	public static function instance(): Logger {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * @param mixed                $level
	 * @param string|Stringable    $message A stable event name, not user-provided prose.
	 * @param array<string, mixed> $context
	 */
	public function log( $level, $message, array $context = [] ): void {
		$level = strtolower( (string) $level );

		if ( ! isset( self::LEVEL_WEIGHT[ $level ] ) ) {
			throw new InvalidLogLevelException( 'Unknown Pinova log level.' );
		}

		try {
			if ( ! $this->is_enabled( $level ) ) {
				return;
			}

			$this->write( $level, $message, $context );
		} catch ( Throwable $throwable ) {
			// Logging is observational. It must never break the operation being observed.
			unset( $throwable );
		}
	}

	/**
	 * Persist a security/control-plane audit event regardless of the configured
	 * minimum level. Use only for bounded, administrator-triggered actions.
	 *
	 * @param mixed                $level
	 * @param string|Stringable    $message
	 * @param array<string, mixed> $context
	 */
	public function audit( $level, $message, array $context = [] ): void {
		$level = strtolower( (string) $level );

		if ( ! isset( self::LEVEL_WEIGHT[ $level ] ) ) {
			throw new InvalidLogLevelException( 'Unknown Pinova log level.' );
		}

		try {
			$this->write( $level, $message, $context );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
		}
	}

	public function correlation_id(): string {
		if ( null === $this->correlation_id ) {
			$this->correlation_id = (string) call_user_func( $this->correlation_factory );
		}

		return $this->correlation_id;
	}

	/**
	 * Emit only explicitly scoped, budgeted detail outside delivery ownership.
	 * A false result means observation was omitted, never permission to resend.
	 * True means a budgeted handler invocation, not proof of durable storage.
	 *
	 * @param array<string,mixed> $observation
	 */
	public function diagnostic( DiagnosticContext $diagnostic, array $observation ): bool {
		try {
			if ( ! $diagnostic->is_current() ) {
				return false;
			}
			if ( ! $this->matches_diagnostic_scope( $this->diagnostic_configuration(), $diagnostic ) ) {
				return false;
			}
			$context = $this->safe_context->sanitize( $observation, DiagnosticContext::EVENT );
			if ( ! isset( $context['stage'], $context['observed_at'] ) || ! is_int( $context['observed_at'] )
				|| $context['observed_at'] < $diagnostic->deadline() - JWT::DEFAULT_TTL
				|| $context['observed_at'] >= $diagnostic->deadline() || $context['observed_at'] > time() ) {
				return false;
			}
			$context['operation']     = $diagnostic->operation();
			$context['flow_deadline'] = $diagnostic->deadline();
			$context                  = array_merge( $context, call_user_func( $this->metadata_provider ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure logger contract also runs without WordPress.
			$encoded = json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
			if ( ! is_string( $encoded ) || DiagnosticContext::MAX_CONTEXT_BYTES < strlen( $encoded )
				|| ! call_user_func( $this->diagnostic_admission, $diagnostic->flow_id(), $diagnostic->deadline() ) ) {
				return false;
			}
			// Admission may wait or invoke hooks. Losing an observation is safe;
			// a stale scope/flow must not become permission to write or retry.
			$this->correlation_id();
			$config = $this->diagnostic_configuration();
			if ( ! $diagnostic->is_current() || ! $this->matches_diagnostic_scope( $config, $diagnostic ) ) {
				return false;
			}
			$this->write_record( LogLevel::DEBUG, DiagnosticContext::EVENT, $context, $diagnostic->flow_id() );
			return true;
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}

	/** @return array<string,mixed> */
	private function diagnostic_configuration(): array {
		$config = call_user_func( $this->config_provider );
		if ( ! is_array( $config ) ) {
			return [];
		}
		if ( $this->uses_wordpress_config && class_exists( Pinova::class ) ) {
			// New scope reads belong only to the post-claim diagnostic path.
			$config['diagnostic_scope'] = Pinova::get_option( 'logging.diagnostic_scope', null );
		}
		return $config;
	}

	/** @param array<string,mixed> $config */
	private function matches_diagnostic_scope( array $config, DiagnosticContext $diagnostic ): bool {
		$until = $config['diagnostic_until'] ?? 0;
		$scope = $config['diagnostic_scope'] ?? null;
		if ( ! is_int( $until ) || $until <= time() || $until > time() + 86400 || ! is_array( $scope ) || 2 !== count( $scope ) ) {
			return false;
		}
		if ( 'operation' === ( $scope['type'] ?? '' ) ) {
			return ( $scope['value'] ?? null ) === $diagnostic->operation();
		}
		return 'flow' === ( $scope['type'] ?? '' ) && ( $scope['value'] ?? null ) === $diagnostic->flow_id();
	}

	public function fingerprint( string $value, string $purpose = 'identifier' ): string {
		try {
			return $this->safe_context->fingerprint( $value, $purpose );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return '';
		}
	}

	/**
	 * Reproduce the legacy case-sensitive HMAC for privacy erasure only.
	 */
	public function legacy_fingerprint( string $value, string $purpose = 'identifier' ): string {
		try {
			return $this->safe_context->legacy_fingerprint( $value, $purpose );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return '';
		}
	}

	/**
	 * @param mixed                $message
	 * @param array<string, mixed> $context
	 */
	private function write( string $level, $message, array $context ): void {
		$event = $this->sanitize_event( $message );
		if ( DiagnosticContext::EVENT === $event ) {
			// Neither PSR-3 calls nor the administrative audit bypass admit detail.
			return;
		}
		$context = $this->safe_context->sanitize( $context, $event );
		$flow_id = isset( $context['flow_id'] ) && is_string( $context['flow_id'] ) ? $context['flow_id'] : null;
		unset( $context['flow_id'] );
		$this->write_record( $level, $event, array_merge( $context, call_user_func( $this->metadata_provider ) ), $flow_id );
	}

	/** @param array<string,mixed> $context */
	private function write_record( string $level, string $event, array $context, ?string $flow_id ): void {
		$this->handler->write(
			[
				'schema'         => 'pinova.event.v1',
				'level'          => $level,
				'event'          => $event,
				'correlation_id' => $this->correlation_id(),
				'flow_id'        => $flow_id,
				'context'        => $context,
			]
		);
	}

	public function is_enabled( string $level ): bool {
		$level = strtolower( $level );
		if ( ! isset( self::LEVEL_WEIGHT[ $level ] ) ) {
			return false;
		}

		try {
			return $this->meets_threshold( $level );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}

	private function meets_threshold( string $level ): bool {
		$config = call_user_func( $this->config_provider );
		$config = is_array( $config ) ? $config : [];
		$until  = isset( $config['diagnostic_until'] ) ? (int) $config['diagnostic_until'] : 0;

		if ( $until > time() ) {
			$minimum = LogLevel::DEBUG;
		} else {
			$minimum = isset( $config['minimum_level'] ) ? strtolower( (string) $config['minimum_level'] ) : LogLevel::WARNING;
			$minimum = in_array( $minimum, [ LogLevel::INFO, LogLevel::NOTICE, LogLevel::WARNING, LogLevel::ERROR ], true )
				? $minimum
				: LogLevel::WARNING;
		}

		return self::LEVEL_WEIGHT[ $level ] >= self::LEVEL_WEIGHT[ $minimum ];
	}

	/** @return array{minimum_level:string, diagnostic_until:int} */
	private function wordpress_config(): array {
		$minimum = class_exists( Pinova::class )
			? (string) Pinova::get_option( 'logging.minimum_level', LogLevel::WARNING )
			: LogLevel::WARNING;
		$until   = class_exists( Pinova::class )
			? (int) Pinova::get_option( 'logging.diagnostic_until', 0 )
			: 0;

		if ( function_exists( 'apply_filters' ) ) {
			$minimum = (string) apply_filters( 'pinova/logging_minimum_level', $minimum );
			$until   = (int) apply_filters( 'pinova/logging_diagnostic_until', $until );
		}

		return [
			'minimum_level'    => $minimum,
			'diagnostic_until' => $until,
		];
	}

	/** @param mixed $event */
	private function sanitize_event( $event ): string {
		try {
			$event = is_string( $event ) || $event instanceof Stringable
				? strtolower( trim( (string) $event ) )
				: '';
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			$event = '';
		}

		$event = preg_replace( '/[^a-z0-9_.-]+/', '_', $event );
		$event = is_string( $event ) ? trim( $event, '_.-' ) : '';

		return '' !== $event ? substr( $event, 0, 100 ) : 'logging.unknown_event';
	}

	private function generate_correlation_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return hash( 'sha256', uniqid( 'pinova-', true ) );
		}
	}
}
