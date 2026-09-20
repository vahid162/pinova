<?php

namespace Pinova;

use Pinova\Logging\Logger;

defined( 'ABSPATH' ) || exit;

class Helper {

	/**
	 * @param string $url       Redirect destination.
	 * @param string $operation Bounded operation code for failure diagnostics.
	 *
	 * @return no-return
	 */
	public static function redirect_to( string $url, string $operation = 'redirect' ) {
		$url = wp_validate_redirect( $url, site_url() );

		if ( wp_safe_redirect( $url ) ) {
			exit;
		}

		Logger::instance()->warning(
			'auth.redirect_failed',
			[
				'operation'   => $operation,
				'reason'      => 'safe_redirect_rejected',
				'status'      => headers_sent() ? 'headers_sent' : 'headers_available',
				'http_status' => 503,
			]
		);

		wp_die(
			__( 'انتقال خودکار انجام نشد. برای ادامه از پیوند امن زیر استفاده کنید.', 'pinova' ),
			__( 'انتقال انجام نشد', 'pinova' ),
			[
				'response'  => 503,
				'link_url'  => $url,
				'link_text' => __( 'ادامه', 'pinova' ),
			]
		);

		exit;
	}

	/**
	 * @param string $url
	 *
	 * @return no-return
	 */
	public static function js_redirect_to( string $url ) {
		?>
		<script>
            window.location.replace('<?php echo esc_url( $url ); ?>');
		</script>
		<?php
		exit;
	}

	/**
	 * @param string $dateTime
	 * @param string $format
	 *
	 * @return false|string
	 */
	public static function date( string $dateTime, string $format = 'Y/m/d H:i:s' ) {
		return wp_date( $format, strtotime( $dateTime ) );
	}

	public static function get_login_back_url( ?string $back_url = null ): string {

		if ( ! filter_var( $back_url, FILTER_VALIDATE_URL ) ) {
			$back_url = sanitize_url( $_POST['back_url'] ?? $_GET['back_url'] ?? '' );
		}

		$fallback = current_user_can( 'manage_options' ) ? admin_url() : site_url();
		$back_url = wp_validate_redirect( (string) $back_url, $fallback );
		$back_url = apply_filters( 'pinova/login_back_url', $back_url );

		return wp_validate_redirect( (string) $back_url, $fallback );
	}

	public static function get_logout_back_url( ?string $back_url = null ): string {

		if ( ! filter_var( $back_url, FILTER_VALIDATE_URL ) ) {
			$back_url = sanitize_url( $_POST['back_url'] ?? $_GET['back_url'] ?? '' );
		}

		$fallback = site_url();
		$back_url = wp_validate_redirect( (string) $back_url, $fallback );
		$back_url = apply_filters( 'pinova/logout_back_url', $back_url );

		return wp_validate_redirect( (string) $back_url, $fallback );
	}

}
