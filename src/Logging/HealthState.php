<?php

namespace Pinova\Logging;

use Throwable;

/** Small best-effort maintenance observations, never authentication state. */
final class HealthState {

	public const FALLBACK_INTERVAL = 300;

	private static bool $accessing = false;

	/** @return array{status:string,at:int,deleted:int} */
	public static function cleanup(): array {
		$value = self::read( 'pinova_logging_cleanup_health' );
		return [
			'status'  => in_array( $value['status'] ?? '', [ 'success', 'failed' ], true ) ? $value['status'] : 'unknown',
			'at'      => self::timestamp( $value['at'] ?? null ),
			'deleted' => isset( $value['deleted'] ) && is_int( $value['deleted'] ) ? max( 0, min( 5000, $value['deleted'] ) ) : 0,
		];
	}

	/** @return array{at:int,woocommerce:string,php:string} */
	public static function fallback(): array {
		$value = self::read( 'pinova_logging_fallback_health' );
		return [
			'at'          => self::timestamp( $value['at'] ?? null ),
			'woocommerce' => 'attempted' === ( $value['woocommerce'] ?? '' ) ? 'attempted' : 'not_attempted',
			'php'         => in_array( $value['php'] ?? '', [ 'accepted', 'failed' ], true ) ? $value['php'] : 'not_attempted',
		];
	}

	public static function record_cleanup( bool $success, int $deleted = 0 ): void {
		self::write(
			'pinova_logging_cleanup_health',
			[
				'status'  => $success ? 'success' : 'failed',
				'at'      => time(),
				'deleted' => $success ? max( 0, min( 5000, $deleted ) ) : 0,
			]
		);
	}

	/** A PHP acceptance is not proof the host durably stored the message. */
	public static function record_fallback( bool $woocommerce_attempted, bool $php_accepted ): void {
		$previous = self::fallback();
		if ( $previous['at'] > time() - self::FALLBACK_INTERVAL ) {
			return;
		}
		self::write(
			'pinova_logging_fallback_health',
			[
				'at'          => time(),
				'woocommerce' => $woocommerce_attempted ? 'attempted' : 'not_attempted',
				'php'         => $php_accepted ? 'accepted' : 'failed',
			]
		);
	}

	/** @return array<string,mixed> */
	private static function read( string $option ): array {
		if ( self::$accessing ) {
			return [];
		}
		self::$accessing = true;
		try {
			$value = function_exists( 'get_option' ) ? get_option( $option, [] ) : [];
			return is_array( $value ) ? $value : [];
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return [];
		} finally {
			self::$accessing = false;
		}
	}

	/** @param array<string,int|string> $value */
	private static function write( string $option, array $value ): void {
		if ( self::$accessing ) {
			return;
		}
		self::$accessing = true;
		try {
			if ( function_exists( 'update_option' ) ) {
				update_option( $option, $value, false );
			}
		} catch ( Throwable $throwable ) {
			// The database used for status may be the database that just failed.
			unset( $throwable );
		} finally {
			self::$accessing = false;
		}
	}

	/** @param mixed $value */
	private static function timestamp( $value ): int {
		return is_int( $value ) && 0 < $value && time() >= $value ? $value : 0;
	}
}
