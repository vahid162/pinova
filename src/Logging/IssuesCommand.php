<?php

namespace Pinova\Logging;

/** Read-only report for an already authorized local WP-CLI operator. */
final class IssuesCommand {

	/**
	 * Print the same bounded issue report as Pinova administration.
	 *
	 * ## OPTIONS
	 *
	 * [--from=<date>]
	 * : First UTC calendar date (YYYY-MM-DD).
	 *
	 * [--to=<date>]
	 * : Last UTC calendar date; at most seven days including --from.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pinova logs report
	 *
	 * @param string[] $args
	 * @param array<string,mixed> $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( $args || array_diff( array_keys( $assoc_args ), [ 'from', 'to' ] ) ) {
			\WP_CLI::error( 'Use only --from=YYYY-MM-DD and --to=YYYY-MM-DD; output is JSON.' );
		}
		$report = IssueMonitor::report(
			[
				'created_from' => $assoc_args['from'] ?? '',
				'created_to'   => $assoc_args['to'] ?? '',
			]
		);
		if ( ! $report['coverage']['valid_range'] ) {
			\WP_CLI::error( 'Select both UTC dates, at most seven calendar days, ending no later than today.' );
		}
		$json = wp_json_encode( $report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || strlen( $json ) > 2097152 ) {
			\WP_CLI::error( 'The issue report could not be encoded within its size limit.' );
		}
		\WP_CLI::line( $json );
	}
}
