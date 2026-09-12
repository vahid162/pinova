<?php

namespace Pinova\Logging;

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

	private ?string $correlation_id = null;

	public function __construct(
		?HandlerInterface $handler = null,
		?SafeContext $safe_context = null,
		?callable $config_provider = null,
		?callable $correlation_factory = null
	) {
		$this->handler             = $handler ?? new DatabaseHandler();
		$this->safe_context        = $safe_context ?? new SafeContext();
		$this->config_provider     = $config_provider ?? [ $this, 'wordpress_config' ];
		$this->correlation_factory = $correlation_factory ?? [ $this, 'generate_correlation_id' ];
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

	public function fingerprint( string $value, string $purpose = 'identifier' ): string {
		try {
			return $this->safe_context->fingerprint( $value, $purpose );
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
		$this->handler->write(
			[
				'schema'         => 'pinova.event.v1',
				'level'          => $level,
				'event'          => $event,
				'correlation_id' => $this->correlation_id(),
				'context'        => $this->safe_context->sanitize( $context ),
			]
		);
	}

	private function is_enabled( string $level ): bool {
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
