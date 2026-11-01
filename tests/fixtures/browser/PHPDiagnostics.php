<?php

declare(strict_types=1);

namespace PinovaFixture\Browser;

/** Read-only, finite diagnostics for the disposable real-plugin fixture. */
final class PHPDiagnostics {
	public const MAX_BYTES = 262144;
	public const MAX_LINE_BYTES = 8192;
	public const MAX_LINES = 4096;
	public const PROBE = 'PINOVA_BROWSER_PHP_DIAGNOSTICS_READY';

	/** @return array<string,mixed> */
	public static function capture( string $path ): array {
		clearstatcache( true, $path );
		$path_before = @lstat( $path ); // An I/O failure is finite evidence, never a raw path warning.
		if ( false === $path_before ) {
			return [ 'read_success' => false, 'failure_reason' => 'missing' ];
		}
		if ( 0100000 !== ( $path_before['mode'] & 0170000 ) ) {
			return [ 'read_success' => false, 'failure_reason' => 'non_regular' ];
		}
		if ( $path_before['size'] > self::MAX_BYTES ) {
			return [ 'read_success' => false, 'failure_reason' => 'file_bound' ];
		}
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			return [ 'read_success' => false, 'failure_reason' => 'unreadable' ];
		}
		try {
			$before = @fstat( $handle );
			$text = @fread( $handle, self::MAX_BYTES + 1 );
			$rewound = 0 === @fseek( $handle, 0 );
			$confirmed = $rewound ? @fread( $handle, self::MAX_BYTES + 1 ) : false;
			$after = @fstat( $handle );
			clearstatcache( true, $path );
			$path_after = @lstat( $path );
			if ( ! is_string( $text ) || ! is_string( $confirmed ) ) {
				return [ 'read_success' => false, 'failure_reason' => 'unreadable' ];
			}
			if ( ! hash_equals( hash( 'sha256', $text ), hash( 'sha256', $confirmed ) ) ) {
				return [ 'read_success' => false, 'failure_reason' => 'changed_during_read' ];
			}
			return compact( 'path_before', 'before', 'after', 'path_after', 'text' ) + [ 'read_success' => true ];
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * @param array<string,mixed> $capture File observations.
	 * @return array<string,mixed>
	 */
	public static function snapshot( array $capture ): array {
		$unknown = static fn( string $reason ): array => [ 'state' => 'unknown', 'reason' => $reason, 'cursor' => null ];
		if ( true !== ( $capture['read_success'] ?? false ) ) {
			$reason = $capture['failure_reason'] ?? 'unreadable';
			return $unknown( in_array( $reason, [ 'missing', 'unreadable', 'non_regular', 'file_bound', 'changed_during_read' ], true ) ? $reason : 'unreadable' );
		}
		$text = $capture['text'] ?? null;
		$before = $capture['before'] ?? null;
		if ( ! is_string( $text ) || ! is_array( $before ) ) {
			return $unknown( 'invalid_capture' );
		}
		foreach ( [ 'before', 'path_before', 'after', 'path_after' ] as $key ) {
			$stat = $capture[ $key ] ?? null;
			if ( ! is_array( $stat ) ) {
				return $unknown( 'identity_changed' );
			}
			foreach ( [ 'dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime' ] as $field ) {
				if ( ! isset( $stat[ $field ] ) || ! is_int( $stat[ $field ] ) || $stat[ $field ] < 0 ) {
					return $unknown( 'invalid_capture' );
				}
			}
			if ( 0100000 !== ( $stat['mode'] & 0170000 ) || $stat['nlink'] < 1 || $stat['ino'] < 1 ) {
				return $unknown( 'non_regular' );
			}
			if ( $stat['dev'] !== $before['dev'] || $stat['ino'] !== $before['ino'] ) {
				return $unknown( 'identity_changed' );
			}
			if ( $stat['size'] !== $before['size'] || $stat['mtime'] !== $before['mtime'] || $stat['ctime'] !== $before['ctime'] ) {
				return $unknown( 'changed_during_read' );
			}
		}
		if ( strlen( $text ) > self::MAX_BYTES || $before['size'] > self::MAX_BYTES ) {
			return $unknown( 'file_bound' );
		}
		if ( strlen( $text ) !== $before['size'] ) {
			return $unknown( 'read_length' );
		}
		if ( '' !== $text && "\n" !== substr( $text, -1 ) ) {
			return $unknown( 'partial_line' );
		}
		$lines = '' === $text ? [] : explode( "\n", substr( $text, 0, -1 ) );
		if ( count( $lines ) > self::MAX_LINES ) {
			return $unknown( 'line_count_bound' );
		}
		foreach ( $lines as $line ) {
			if ( strlen( $line ) > self::MAX_LINE_BYTES ) {
				return $unknown( 'line_bound' );
			}
		}
		return [ 'state' => 'observed', 'reason' => 'none', 'cursor' => [
			'dev' => (string) $before['dev'], 'ino' => (string) $before['ino'], 'size' => strlen( $text ),
			'sha256' => hash( 'sha256', $text ), 'line_complete' => true,
		] ];
	}

	/**
	 * @param array<string,mixed> $before Before-probe observations.
	 * @param array<string,mixed> $after After-probe observations.
	 */
	public static function readiness( array $before, array $after ): bool {
		$end = self::snapshot( $after );
		if ( 'observed' !== $end['state'] ) {
			return false;
		}
		$start = self::snapshot( $before );
		$text = $after['text'];
		if ( 'observed' === $start['state'] ) {
			$window = self::summarize( $after, $start['cursor'] );
			if ( 'observed' !== $window['state'] ) {
				return false;
			}
			$text = substr( $text, $start['cursor']['size'] );
		} elseif ( 'missing' !== $start['reason'] ) {
			return false;
		}
		return 1 === preg_match( '/(?:^|\n)(?:\[[^\]\r\n]{1,64}\] )?' . self::PROBE . '\r?\n/', $text );
	}

	/**
	 * @param array<string,mixed> $capture File observations.
	 * @param array<string,mixed>|null $baseline Preflight cursor.
	 * @return array<string,mixed>
	 */
	public static function summarize( array $capture, ?array $baseline ): array {
		$snapshot = self::snapshot( $capture );
		if ( 'observed' !== $snapshot['state'] ) {
			return [ 'state' => 'unknown', 'reason' => $snapshot['reason'], 'scope' => 'unavailable', 'bytes' => null, 'lines' => null, 'counts' => null ];
		}
		$text = $capture['text'];
		$cursor = $snapshot['cursor'];
		$reason = null === $baseline ? 'baseline_unknown' : 'cursor_mismatch';
		$matched = is_array( $baseline ) &&
			$cursor['dev'] === ( $baseline['dev'] ?? null ) && $cursor['ino'] === ( $baseline['ino'] ?? null ) &&
			is_int( $baseline['size'] ?? null ) && $baseline['size'] >= 0 && $baseline['size'] <= strlen( $text ) &&
			true === ( $baseline['line_complete'] ?? false ) &&
			( 0 === $baseline['size'] || "\n" === substr( $text, $baseline['size'] - 1, 1 ) ) &&
			is_string( $baseline['sha256'] ?? null ) &&
			1 === preg_match( '/^[a-f0-9]{64}$/D', $baseline['sha256'] ) &&
			hash_equals( $baseline['sha256'], hash( 'sha256', substr( $text, 0, $baseline['size'] ) ) );
		if ( $matched ) {
			$text = substr( $text, $baseline['size'] );
		}
		$counts = array_fill_keys( [ 'deprecated', 'warning', 'fatal', 'notice', 'parse', 'other', 'elementor_false_to_array', 'headers_already_sent' ], 0 );
		$lines = '' === $text ? [] : explode( "\n", substr( $text, 0, -1 ) );
		foreach ( $lines as $line ) {
			$plain = preg_replace( '/^\[[^\]\r\n]{1,64}\] /', '', rtrim( $line, "\r" ) );
			if ( self::PROBE === $plain ) {
				continue;
			}
			$kind = preg_match( '/^PHP (Deprecated|Warning|Fatal error|Notice|Parse error):/', $plain, $match )
				? [ 'Deprecated' => 'deprecated', 'Warning' => 'warning', 'Fatal error' => 'fatal', 'Notice' => 'notice', 'Parse error' => 'parse' ][ $match[1] ] : 'other';
			++$counts[ $kind ];
			if ( 'deprecated' === $kind && str_contains( $plain, 'Automatic conversion of false to array' ) && str_contains( $plain, '/wp-content/plugins/elementor/includes/api.php' ) ) {
				++$counts['elementor_false_to_array'];
			}
			if ( 'warning' === $kind && str_contains( $plain, 'Cannot modify header information' ) && str_contains( $plain, 'headers already sent' ) ) {
				++$counts['headers_already_sent'];
			}
		}
		return [ 'state' => $matched ? 'observed' : 'unknown', 'reason' => $matched ? 'none' : $reason,
			'scope' => $matched ? 'preflight_to_before_cleanup' : 'current_file_only', 'bytes' => strlen( $text ), 'lines' => count( $lines ), 'counts' => $counts ];
	}
}
