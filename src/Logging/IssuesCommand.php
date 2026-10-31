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
	 * [--flow=<flow>]
	 * : Exact 32-character lowercase flow ID; timeline only.
	 *
	 * [--correlation=<request>]
	 * : Exact server UUIDv4 request reference; choose this or --flow.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pinova logs report
	 *
	 * @param string[] $args
	 * @param array<string,mixed> $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( $args || array_diff( array_keys( $assoc_args ), [ 'from', 'to', 'flow', 'correlation' ] ) ) {
			\WP_CLI::error( 'Use --from/--to and optionally one --flow or --correlation; output is JSON.' );
		}
		$report = IssueMonitor::report(
			[
				'created_from'   => $assoc_args['from'] ?? '',
				'created_to'     => $assoc_args['to'] ?? '',
				'flow_id'        => $assoc_args['flow'] ?? '',
				'correlation_id' => $assoc_args['correlation'] ?? '',
			]
		);
		if ( ! $report['coverage']['valid_selector'] ) {
			\WP_CLI::error( 'Choose one valid flow ID or server UUIDv4 correlation reference.' );
		}
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
