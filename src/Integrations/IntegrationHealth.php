<?php

namespace Pinova\Integrations;

use Pinova\Logging\Logger;
use Throwable;

/** Bounded observations, separate from read-only status and account policy. */
final class IntegrationHealth {

	private const OPTION_PREFIX    = 'pinova_integration_health_';
	private static bool $observing = false;

	/** Report views and local report commands never persist observations. */
	public static function observe_admin(): void {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- These exclusions prevent writes on read-only report requests.
		if ( 'pinova-logs' === ( $_GET['page'] ?? '' )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Actions perform their own authorization; observation skips all report actions.
			|| in_array( $_REQUEST['action'] ?? '', [ 'pinova_export_issues', 'pinova_export_incident', 'pinova_review_issue', 'pinova_clear_logs' ], true ) ) {
			return;
		}
		self::observe();
	}

	/** Called only after native plugin initialization by admin or maintenance. */
	public static function observe(): void {
		if ( self::$observing ) {
			return;
		}
		self::$observing = true;
		try {
			$snapshot = IntegrationSettings::snapshot();
			foreach ( [ 'wpforo', 'dokan' ] as $slug ) {
				$entry = $snapshot[ $slug ];
				$next  = [
					'state'  => $entry['state'],
					'reason' => $entry['reason'],
				];
				try {
					if ( self::claim( $slug, $next ) ) {
						Logger::instance()->log(
							in_array( $next['state'], [ 'partial', 'unavailable' ], true ) ? 'warning' : 'notice',
							'integration.' . $slug . '_status_changed',
							[
								'status' => $next['state'],
								'reason' => $next['reason'],
							]
						);
					}
				} catch ( Throwable $throwable ) {
					unset( $throwable );
				}
			}
		} catch ( Throwable $throwable ) {
			// An observation failure cannot change login, membership or onboarding.
			unset( $throwable );
		} finally {
			self::$observing = false;
		}
	}

	/** One fixed option per plugin, atomically claimed before best-effort logging.
	 * @param array{state:string,reason:string} $next
	 */
	private static function claim( string $slug, array $next ): bool {
		global $wpdb;
		$name     = self::OPTION_PREFIX . $slug;
		$previous = get_option( $name, false );
		if ( $previous === $next || ( false === $previous && 'off' === $next['state'] ) ) {
			return false;
		}
		if ( false === $previous ) {
			return add_option( $name, $next, '', false );
		}
		$suppressed = $wpdb->suppress_errors();
		try {
			$changed = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, maybe_serialize( $next ), $name, maybe_serialize( $previous ) ) );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
		if ( 1 === $changed ) {
			wp_cache_delete( $name, 'options' );
			return true;
		}
		return false;
	}
}
