<?php

namespace Pinova\Integrations\Woodmart;

class Load {

	public string $html = '';

	protected static ?Load $_instance = null;

	public static function instance(): ?Load {

		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	private function __construct() {
		add_filter( 'woodmart_get_header_links', [ $this, 'dropdown_login' ], 1 );
		remove_action( 'woodmart_before_wp_footer', 'woodmart_sidebar_login_form', 160 );
		add_action( 'woodmart_before_wp_footer', [ $this, 'sidebar_login' ], 160 );
	}

	public static function get_template(): string {
		$custom_styles =
			'<style>
				.pinova-container .text-primary-500 { color: var(--e-global-color-accent)} 
				.pinova-container .loader { border-color: var(--e-global-color-accent)}
			</style>';

		$data = [
			'form_wrapper_classes'  => 'woocommerce-form-login',
			'submit_button_classes' => 'button',
			'custom_styles'         => $custom_styles
		];

		ob_start();
		extract( $data );
		include PINOVA_DIR . '/templates/login-partial.php';

		return ob_get_clean();
	}

	public function dropdown_login( $links ) {

		if ( is_user_logged_in() ) {
			return $links;
		}

		$settings = whb_get_settings();

		$login_dropdown = ! empty( $settings['account']['login_dropdown'] )
		                  && ( empty( $settings['account']['form_display'] ) || $settings['account']['form_display'] === 'dropdown' );

		if ( ! $login_dropdown ) {
			return $links;
		}

		woodmart_enqueue_js_script( 'login-dropdown' );
		woodmart_enqueue_inline_style( 'header-my-account-dropdown' );

		$links['register']['dropdown'] = '
        <div class="wd-dropdown wd-dropdown-register">
            <div class="login-dropdown-inner woocommerce">
                <span class="wd-heading">
                    <span class="title">' . esc_html( 'ورود | ثبت‌نام' ) . '</span>
                </span>
                ' . self::get_template() . '
            </div>
        </div>
    ';

		return $links;
	}

	public function sidebar_login() {

		if ( ! woodmart_woocommerce_installed() || is_account_page() ) {
			return;
		}

		if ( is_user_logged_in() ) {
			return;
		}

		$settings = whb_get_settings();

		$login_side = isset( $settings['account'] )
		              && $settings['account']['login_dropdown']
		              && $settings['account']['form_display'] === 'side';

		if ( ! $login_side ) {
			return;
		}

		$wrapper_classes = '';

		if ( 'light' === whb_get_dropdowns_color() ) {
			$wrapper_classes .= ' color-scheme-light';
		}

		$position        = is_rtl() ? 'left' : 'right';
		$wrapper_classes .= ' wd-' . $position;

		woodmart_enqueue_inline_style( 'header-my-account-sidebar' );
		woodmart_enqueue_inline_style( 'woo-mod-login-form' );
		?>

		<div class="login-form-side wd-side-hidden woocommerce<?php echo esc_attr( $wrapper_classes ); ?>" role="complementary">
			<div class="wd-heading">
				<span class="title"><?php echo esc_html( 'ورود | ثبت‌نام' ); ?></span>
				<div class="close-side-widget wd-action-btn wd-style-text wd-cross-icon">
					<a href="#" rel="nofollow"><?php esc_html_e( 'Close', 'woodmart' ); ?></a>
				</div>
			</div>
			<div class="woodmart-sidebar-login">
				<?php echo self::get_template(); ?>
			</div>
		</div>

		<?php
	}
}