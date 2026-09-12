<?php

namespace Pinova\Logging;

use Throwable;

final class DatabaseHandler implements HandlerInterface {

	/**
	 * @param array<string, mixed> $record
	 */
	public function write( array $record ): void {
		global $wpdb;

		try {
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
				$this->fallback( $record );
				return;
			}

			$context = isset( $record['context'] ) && is_array( $record['context'] ) ? $record['context'] : [];
			$user_id = isset( $context['user_id'] ) ? (int) $context['user_id'] : null;
			unset( $context['user_id'] );

			$encoded = $this->encode( $context );

			$result = $wpdb->insert(
				$wpdb->prefix . 'pinova_logs',
				[
					'created_at'     => gmdate( 'Y-m-d H:i:s' ),
					'level'          => (string) $record['level'],
					'event'          => (string) $record['event'],
					'correlation_id' => (string) $record['correlation_id'],
					'user_id'        => $user_id ?: null,
					'context'        => '' !== $encoded ? $encoded : '{}',
				],
				[ '%s', '%s', '%s', '%s', '%d', '%s' ]
			);

			if ( false === $result ) {
				$this->fallback( $record );
			}
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			$this->fallback( $record );
		}
	}

	/**
	 * Fall back to the host's managed logger without exposing database errors.
	 *
	 * @param array<string, mixed> $record
	 */
	private function fallback( array $record ): void {
		$encoded = $this->encode( $record );
		$line    = '' !== $encoded ? $encoded : '{"event":"logging.write_failed"}';

		try {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->log(
					(string) ( $record['level'] ?? 'error' ),
					$line,
					[ 'source' => 'pinova' ]
				);
				return;
			}
		} catch ( Throwable $throwable ) {
			unset( $throwable );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Deliberate last-resort production logger.
		error_log( '[pinova] ' . $line );
	}

	/** @param array<string, mixed> $value */
	private function encode( array $value ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			return is_string( $encoded ) ? $encoded : '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is unavailable in this last-resort path.
		$encoded = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $encoded ) ? $encoded : '';
	}
}
