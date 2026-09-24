<?php
/**
 * Plugin Name: Pinova disposable Cron boundary fixture
 * Description: Intercepts test mail and disables automatic Cron only in wp-env.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', (bool) get_option( 'pinova_cron_boundary_manual_cron', true ) );
}

/** Count only the synthetic recipient without persisting the message or OTP. */
function pinova_cron_boundary_intercept_mail( $pre, array $atts ): bool {
	$recipients = (array) ( $atts['to'] ?? [] );
	if ( array_intersect( [ 'cron-boundary@example.test', 'cron-shutdown@example.test' ], $recipients ) ) {
		update_option( 'pinova_cron_boundary_mail_calls', (int) get_option( 'pinova_cron_boundary_mail_calls', 0 ) + 1, false );
	}

	// No mail from this disposable test site may reach an external recipient.
	return true;
}

add_filter( 'pre_wp_mail', 'pinova_cron_boundary_intercept_mail', 10, 2 );

// The smoke test proves the fixture reached the web process before requesting an OTP.
add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'pinova-cron-boundary/v1',
			'/ready',
			[
				'methods'             => 'GET',
				'callback'            => static fn(): array => [ 'ready' => true ],
				'permission_callback' => '__return_true',
			]
		);
	}
);
