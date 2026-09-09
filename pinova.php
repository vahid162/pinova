<?php
/**
 * Plugin Name: پینوا
 * Plugin URI: https://wordpress.org/plugins/pinova
 * Description: ورود و عضویت با تلفن همراه و کد یکبار مصرف، با قابلیت ارسال کد تایید از طریق پیامک، ایمیل، پیام صوتی و پیام رسان بله
 * Version: 1.2.3
 * Author: ووکامرس فارسی
 * Author URI: https://woosupport.ir
 *
 * License URI:  https://www.gnu.org/licenses/gpl-3.0.html
 * License:      GPLv3
 *
 * Requires at least: 6.8
 * Requires PHP: 8.1
 *
 * WC requires at least: 7.6.0
 * WC tested up to: 10.9.4
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PINOVA_VERSION' ) ) {
	define( 'PINOVA_VERSION', '1.2.3' );
}

if ( ! defined( 'PINOVA_DIR' ) ) {
	define( 'PINOVA_DIR', __DIR__ );
}

if ( ! defined( 'PINOVA_FILE' ) ) {
	define( 'PINOVA_FILE', __FILE__ );
}

if ( ! defined( 'PINOVA_URL' ) ) {
	define( 'PINOVA_URL', plugin_dir_url( __FILE__ ) );
}

require __DIR__ . '/vendor/autoload.php';

new \Pinova\Install();
new \Pinova\Notice();
new \Pinova\Version();

\Pinova\Pinova::instance();

\Pinova\Integrations\Wordpress\Load::instance();

\Pinova\Integrations\WPRocket\Load::instance();
\Pinova\Integrations\Perfmatters\Load::instance();
\Pinova\Integrations\GravityForms\Load::instance();

add_action( 'plugins_loaded', function () {
	\Pinova\Integrations\PMP\Load::instance();
} );

add_action( 'woocommerce_loaded', function () {
	\Pinova\Integrations\Woocommerce\Load::instance();
} );

add_action( 'woodmart_after_body_open', function () {
	\Pinova\Integrations\Woodmart\Load::instance();
} );

add_action( 'after_setup_theme', function () {
	Pinova\Integrations\Flatsome\Load::instance();
} );

register_activation_hook( PINOVA_FILE, function () {
	file_put_contents( PINOVA_DIR . '/.activated', '' );
} );

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__ );
	}
} );
