<?php

namespace Pinova;

defined( 'ABSPATH' ) || exit;

class Helper {

	/**
	 * @param string $url
	 *
	 * @return no-return
	 */
	public static function redirect_to( string $url ) {
		wp_safe_redirect( $url );
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

		if ( ! filter_var( $back_url, FILTER_VALIDATE_URL ) ) {
			$back_url = current_user_can( 'manage_options' ) ? admin_url() : site_url();
		}

		return apply_filters( 'pinova/login_back_url', sanitize_url( $back_url ) );
	}

	public static function get_logout_back_url( ?string $back_url = null ): string {

		if ( ! filter_var( $back_url, FILTER_VALIDATE_URL ) ) {
			$back_url = sanitize_url( $_POST['back_url'] ?? $_GET['back_url'] ?? '' );
		}

		if ( ! filter_var( $back_url, FILTER_VALIDATE_URL ) ) {
			$back_url = site_url();
		}

		return apply_filters( 'pinova/logout_back_url', sanitize_url( $back_url ) );
	}

}