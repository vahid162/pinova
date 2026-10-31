<?php

namespace Pinova\Logging;

use Throwable;

/** Finite best-effort retention observations, separate from delivery and identity. */
final class OTPHealthState {
	private const OPTION = 'pinova_otp_cleanup_health';

	private static bool $accessing = false;

	/** @return array<string,int|string|bool|null> */
	public static function snapshot(): array {
		$health = [
			'status'                 => 'unknown',
			'at'                     => 0,
			'deleted'                => null,
			'backlog'                => 'unknown',
			'continuation'           => 'unknown',
			'read_success'           => false,
			'cleanup_scheduled'      => false,
			'cleanup_due_at'         => 0,
			'cleanup_overdue'        => false,
			'continuation_scheduled' => false,
			'continuation_due_at'    => 0,
			'continuation_overdue'   => false,
			'continuation_missing'   => false,
		];
		if ( self::$accessing ) {
			return $health;
		}
		self::$accessing = true;
		try {
			$value                  = self::read_current( static fn() => get_option( self::OPTION, [] ) );
			$value                  = is_array( $value ) ? $value : [];
			$health['status']       = in_array( $value['status'] ?? '', [ 'success', 'failed' ], true ) ? $value['status'] : 'unknown';
			$health['at']           = isset( $value['at'] ) && is_int( $value['at'] ) && 0 < $value['at'] && time() >= $value['at'] ? $value['at'] : 0;
			$health['deleted']      = isset( $value['deleted'] ) && is_int( $value['deleted'] ) && 0 <= $value['deleted'] && 5000 >= $value['deleted'] ? $value['deleted'] : null;
			$health['backlog']      = in_array( $value['backlog'] ?? '', [ 'clear', 'pending' ], true ) ? $value['backlog'] : 'unknown';
			$health['continuation'] = in_array( $value['continuation'] ?? '', [ 'not_needed', 'scheduled', 'failed' ], true ) ? $value['continuation'] : 'unknown';
			if ( 0 === $health['at'] || 'unknown' === $health['status'] ) {
				$health['status']       = 'unknown';
				$health['deleted']      = null;
				$health['backlog']      = 'unknown';
				$health['continuation'] = 'unknown';
			}
			$health['cleanup_due_at']         = (int) self::read_current( static fn() => wp_next_scheduled( 'pinova_otp_cleanup' ) );
			$health['cleanup_scheduled']      = 0 < $health['cleanup_due_at'];
			$health['cleanup_overdue']        = $health['cleanup_scheduled'] && time() - HOUR_IN_SECONDS > $health['cleanup_due_at'];
			$health['continuation_due_at']    = (int) self::read_current( static fn() => wp_next_scheduled( 'pinova_otp_cleanup_continue' ) );
			$health['continuation_scheduled'] = 0 < $health['continuation_due_at'];
			$health['continuation_overdue']   = $health['continuation_scheduled'] && time() - HOUR_IN_SECONDS > $health['continuation_due_at'];
			$health['continuation_missing']   = 'pending' === $health['backlog'] && ! $health['continuation_scheduled'];
			$health['read_success']           = true;
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			$health['status']       = 'unknown';
			$health['deleted']      = null;
			$health['backlog']      = 'unknown';
			$health['continuation'] = 'unknown';
		} finally {
			self::$accessing = false;
		}
		return $health;
	}

	/** An options SQL failure must not masquerade as a successful missing schedule. */
	private static function read_current( callable $read ): mixed {
		global $wpdb;
		$before = is_object( $wpdb ) ? (int) ( $wpdb->num_queries ?? 0 ) : 0;
		$value  = $read();
		if ( is_object( $wpdb ) && $before < (int) ( $wpdb->num_queries ?? 0 ) && '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException( 'Cleanup health read is unavailable.' );
		}
		return $value;
	}

	public static function record_cleanup( bool $success, ?int $deleted, string $backlog = 'unknown', string $continuation = 'unknown' ): void {
		if ( self::$accessing ) {
			return;
		}
		self::$accessing = true;
		try {
			update_option(
				self::OPTION,
				[
					'status'       => $success ? 'success' : 'failed',
					'at'           => time(),
					'deleted'      => null === $deleted ? null : max( 0, min( 5000, $deleted ) ),
					'backlog'      => in_array( $backlog, [ 'clear', 'pending' ], true ) ? $backlog : 'unknown',
					'continuation' => in_array( $continuation, [ 'not_needed', 'scheduled', 'failed' ], true ) ? $continuation : 'unknown',
				],
				false
			);
		} catch ( Throwable $throwable ) {
			unset( $throwable );
		} finally {
			self::$accessing = false;
		}
	}
}
