<?php

declare(strict_types=1);

namespace Pinova\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pinova\Tests\fixtures\browser\PHPDiagnostics;

require_once dirname( __DIR__ ) . '/fixtures/browser/PHPDiagnostics.php';

final class PHPDiagnosticsTest extends TestCase {

	public function test_stable_append_counts_native_signals_without_exporting_log_contents(): void {
		$before = "PINOVA_BROWSER_PHP_DIAGNOSTICS_READY\n[31-Oct-2026 00:00:00 UTC] PHP Notice: Earlier entry\n";
		$append = implode(
			"\n",
			[
				'[31-Oct-2026 00:00:01 UTC] PHP Deprecated: Automatic conversion of false to array is deprecated in /var/www/private-site/wp-content/plugins/elementor/includes/api.php on line 42',
				'[31-Oct-2026 00:00:02 UTC] PHP Warning: Cannot modify header information - headers already sent by (output started at /var/www/private-site/index.php:1) in /var/www/private-site/index.php on line 2',
				'[31-Oct-2026 00:00:03 UTC] PHP Fatal error: Uncaught Error sensitive-token in /var/www/private-site/private.php on line 3',
				'[31-Oct-2026 00:00:04 UTC] PHP Notice: Undefined variable private@example.test in /var/www/private-site/private.php on line 4',
				'[31-Oct-2026 00:00:05 UTC] PHP Parse error: syntax error in /var/www/private-site/private.php on line 5',
				'Unrecognized diagnostic sensitive-token private@example.test',
			]
		) . "\n";
		$result = PHPDiagnostics::summarize( $this->capture( $before . $append ), $this->baseline( $before ) );

		self::assertSame( 'observed', $result['state'] );
		self::assertSame( 'none', $result['reason'] );
		self::assertSame( 'preflight_to_before_cleanup', $result['scope'] );
		self::assertSame( strlen( $append ), $result['bytes'] );
		self::assertSame( 6, $result['lines'] );
		self::assertSame(
			[
				'deprecated'               => 1,
				'warning'                  => 1,
				'fatal'                    => 1,
				'notice'                   => 1,
				'parse'                    => 1,
				'other'                    => 1,
				'elementor_false_to_array'  => 1,
				'headers_already_sent'     => 1,
			],
			$result['counts']
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure unit test runs without WordPress.
		$encoded = (string) json_encode( $result );
		foreach ( [ 'private-site', 'sensitive-token', 'private@example.test', 'Automatic conversion', 'Earlier entry' ] as $private_value ) {
			self::assertStringNotContainsString( $private_value, $encoded );
		}
	}

	public function test_empty_window_is_observed_and_readiness_probe_has_no_signal_counts(): void {
		$text     = "PINOVA_BROWSER_PHP_DIAGNOSTICS_READY\n";
		$baseline = $this->baseline( $text );
		$result   = PHPDiagnostics::summarize( $this->capture( $text ), $baseline );

		self::assertSame(
			[
				'dev'           => '1',
				'ino'           => '2',
				'size'          => strlen( $text ),
				'sha256'        => hash( 'sha256', $text ),
				'line_complete' => true,
			],
			$baseline
		);
		self::assertSame( 'observed', $result['state'] );
		self::assertSame( 'preflight_to_before_cleanup', $result['scope'] );
		self::assertSame( 0, $result['bytes'] );
		self::assertSame( 0, $result['lines'] );
		self::assertSame( array_fill_keys( [ 'deprecated', 'warning', 'fatal', 'notice', 'parse', 'other', 'elementor_false_to_array', 'headers_already_sent' ], 0 ), $result['counts'] );

		$current_only = PHPDiagnostics::summarize( $this->capture( $text ), null );
		self::assertSame( 'unknown', $current_only['state'] );
		self::assertSame( 'baseline_unknown', $current_only['reason'] );
		self::assertSame( 'current_file_only', $current_only['scope'] );
		self::assertSame( $result['counts'], $current_only['counts'] );
	}

	public function test_missing_or_unreadable_capture_cannot_produce_an_observed_snapshot(): void {
		foreach ( [ 'missing', 'unreadable' ] as $failure_reason ) {
			$capture                   = $this->capture( '' );
			$capture['read_success']    = false;
			$capture['failure_reason']  = $failure_reason;
			$snapshot                  = PHPDiagnostics::snapshot( $capture );
			$result                    = PHPDiagnostics::summarize( $capture, null );

			self::assertSame( 'unknown', $snapshot['state'], $failure_reason );
			self::assertSame( 'unknown', $result['state'], $failure_reason );
			self::assertNull( $result['counts'], $failure_reason );
		}
	}

	public function test_file_line_count_line_size_and_complete_line_bounds_fail_closed(): void {
		$cases = [
			'oversized_file' => str_repeat( str_repeat( 'x', 8191 ) . "\n", 33 ),
			'too_many_lines' => str_repeat( "x\n", 4097 ),
			'oversized_line' => str_repeat( 'x', 8193 ) . "\n",
			'partial_line'   => '[31-Oct-2026 00:00:00 UTC] PHP Warning: Incomplete record',
		];
		foreach ( $cases as $case => $text ) {
			$capture  = $this->capture( $text );
			$snapshot = PHPDiagnostics::snapshot( $capture );
			$result   = PHPDiagnostics::summarize( $capture, null );

			self::assertSame( 'unknown', $snapshot['state'], $case );
			self::assertSame( 'unknown', $result['state'], $case );
			self::assertNull( $result['counts'], $case );
		}
	}

	public function test_rotation_path_descriptor_mismatch_and_changed_size_fail_closed(): void {
		$rotated                      = $this->capture( "entry\n" );
		$rotated['after']['ino']       = 3;
		$path_mismatch                = $this->capture( "entry\n" );
		$path_mismatch['path_before']['ino'] = 3;
		$changed_size                 = $this->capture( "entry\n" );
		++$changed_size['after']['size'];
		$path_after                   = $this->capture( "entry\n" );
		$path_after['path_after']['ino'] = 3;

		foreach ( [ $rotated, $path_mismatch, $changed_size, $path_after ] as $capture ) {
			self::assertSame( 'unknown', PHPDiagnostics::snapshot( $capture )['state'] );
			$result = PHPDiagnostics::summarize( $capture, null );
			self::assertSame( 'unknown', $result['state'] );
			self::assertNull( $result['counts'] );
		}
	}

	public function test_truncation_rewrite_identity_change_and_missing_baseline_remain_unknown(): void {
		$text             = "current log record\n";
		$baseline         = $this->baseline( $text );
		$changed_identity = $baseline;
		$changed_identity['ino'] = '3';
		$changed_device   = $baseline;
		$changed_device['dev'] = '3';
		$cases = [
			[ $this->capture( "x\n" ), $baseline ],
			[ $this->capture( "changed log record\n" ), $baseline ],
			[ $this->capture( $text ), $changed_identity ],
			[ $this->capture( $text ), $changed_device ],
		];
		foreach ( $cases as [ $capture, $cursor ] ) {
			$result = PHPDiagnostics::summarize( $capture, $cursor );
			self::assertSame( 'unknown', $result['state'] );
			self::assertSame( 'cursor_mismatch', $result['reason'] );
			self::assertSame( 'current_file_only', $result['scope'] );
			self::assertSame( 1, $result['counts']['other'] );
		}

		$missing = PHPDiagnostics::summarize( $this->capture( $text ), null );
		self::assertSame( 'unknown', $missing['state'] );
		self::assertSame( 'baseline_unknown', $missing['reason'] );
		self::assertSame( 'current_file_only', $missing['scope'] );
		self::assertSame( 1, $missing['counts']['other'] );

		$baseline['line_complete'] = false;
		$partial = PHPDiagnostics::summarize( $this->capture( $text ), $baseline );
		self::assertSame( 'unknown', $partial['state'] );
		self::assertSame( 'current_file_only', $partial['scope'] );
	}

	public function test_readiness_requires_a_new_marker_and_rejects_historical_or_rotated_markers(): void {
		$marker = "PINOVA_BROWSER_PHP_DIAGNOSTICS_READY\n";
		$old = $this->capture( $marker );
		self::assertFalse( PHPDiagnostics::readiness( $old, $old ) );
		self::assertTrue( PHPDiagnostics::readiness( $old, $this->capture( $marker . $marker ) ) );
		self::assertTrue( PHPDiagnostics::readiness( [ 'read_success' => false, 'failure_reason' => 'missing' ], $old ) );
		self::assertFalse( PHPDiagnostics::readiness( [ 'read_success' => false, 'failure_reason' => 'unreadable' ], $old ) );
		$rotated = $this->capture( $marker . $marker );
		foreach ( [ 'before', 'path_before', 'after', 'path_after' ] as $key ) {
			$rotated[ $key ]['ino'] = 3;
		}
		self::assertFalse( PHPDiagnostics::readiness( $old, $rotated ) );
		$malformed = $this->capture( $marker );
		unset( $malformed['before']['dev'] );
		self::assertSame( 'unknown', PHPDiagnostics::snapshot( $malformed )['state'] );
	}

	private function capture( string $text ): array {
		$stat = [
			'dev'   => 1,
			'ino'   => 2,
			'size'  => strlen( $text ),
			'mtime' => 3,
			'ctime' => 3,
			'mode'  => 0100600,
			'nlink' => 1,
		];
		return [
			'read_success' => true,
			'path_before'  => $stat,
			'before'       => $stat,
			'after'        => $stat,
			'path_after'   => $stat,
			'text'         => $text,
		];
	}

	private function baseline( string $text ): array {
		$snapshot = PHPDiagnostics::snapshot( $this->capture( $text ) );
		self::assertSame( 'observed', $snapshot['state'] );
		self::assertSame( 'none', $snapshot['reason'] );
		return $snapshot['cursor'];
	}
}
