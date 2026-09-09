<?php

namespace Pinova;

use Pinova\Admin\Menu;
use Pinova\Admin\Settings;
use Pinova\Services\APIService;
use Pinova\Services\RateLimitService;
use Pinova\Services\SMSService;
use Pinova\Services\UserService;

class Pinova {

	protected static ?Pinova $_instance = null;

	/**
	 * @var Settings
	 */
	public Settings $settings;

	public static function instance(): ?Pinova {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	private function __construct() {
		$this->autoload();
		$this->init_hooks();
	}

	public function autoload() {
		new Menu();
		new APIService();
		new SMSService();
	}

	public function init_hooks() {
		add_action( 'init', [ $this, 'register_rewrite_rules' ] );
		add_action( 'init', [ $this, 'schedule_cleanup' ] );
		add_action( 'pinova_rate_limit_cleanup', [ RateLimitService::class, 'cleanup' ] );
		add_action( 'template_redirect', [ $this, 'handle_urls' ] );
		add_filter( 'logout_url', [ $this, 'logout_url' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin' ] );
		add_filter( 'wp_script_attributes', [ $this, 'add_script_type_module' ], 99 );
	}

	public function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( 'pinova_rate_limit_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'pinova_rate_limit_cleanup' );
		}
	}

	public function register_rewrite_rules(): void {
		add_rewrite_rule( '^login/?$', 'index.php?pinova_login=1', 'top' );
		add_rewrite_rule( '^logout/?$', 'index.php?pinova_logout=1', 'top' );
		add_filter( 'query_vars', static function ( array $vars ): array {
			$vars[] = 'pinova_login';
			$vars[] = 'pinova_logout';

			return $vars;
		} );
	}

	public static function handle_urls() {
		global $wp;
		global $wp_query;

		if ( function_exists( 'is_account_page' ) && is_account_page() ) {

			if ( get_current_user_id() ) {
				return;
			}

			include PINOVA_DIR . '/templates/login-form.php';
			exit;
		}

		if ( $wp->request === 'login' || get_query_var( 'pinova_login' ) ) {

			if ( get_current_user_id() ) {
				Helper::redirect_to( Helper::get_login_back_url() );
			}

			$wp_query->is_404 = false;

			include PINOVA_DIR . '/templates/login-form.php';
			exit;
		}

		if ( $wp->request === 'logout' || get_query_var( 'pinova_logout' ) ) {
			self::logout();
		}

	}

	/**
	 * @return no-return
	 */
	public static function logout() {

		if ( ! wp_verify_nonce( $_GET['_pinova_nonce'] ?? '', 'logout' ) ) {
			wp_die( __( 'توکن امنیتی شما معتبر نمی‌باشد.', 'pinova' ) );
		}

		UserService::logout();

		Helper::redirect_to( Helper::get_logout_back_url( $_GET['redirect_to'] ?? null ) );
	}

	public function logout_url( $url, $back_url ): string {
		return self::get_logout_url( $back_url );
	}

	public function enqueue_admin( $hook ) {

		if ( $hook !== 'toplevel_page_pinova' ) {
			return;
		}

		wp_enqueue_style( 'pinova-admin', PINOVA_URL . 'assets/css/admin.css', [], PINOVA_VERSION );

		wp_enqueue_script( 'pinova-admin', PINOVA_URL . 'assets/js/admin.js', [ 'jquery' ], PINOVA_VERSION );
		wp_localize_script( 'pinova-admin', 'pinova', [
			'root'  => esc_url_raw( rest_url() ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		] );
	}

	public function add_script_type_module( array $attributes ): array {

		$modules = [
			'pinova-login-modal-js',
			'pinova-blocks-js',
			'pinova-create-customer-modal-js'
		];

		$id = $attributes['id'] ?? null;

		if ( in_array( $id, $modules ) ) {
			$attributes['type'] = 'module';
		}

		return $attributes;
	}

	public static function get_login_url( ?string $back_url = null ): string {
		$url = site_url( 'login' );

		if ( $back_url ) {
			$url = add_query_arg( 'back_url', urlencode( $back_url ), $url );
		}

		return $url;
	}

	public static function get_logout_url( ?string $back_url = null ): string {
		$url = site_url( 'logout' );

		if ( $back_url ) {
			$url = add_query_arg( 'back_url', urlencode( $back_url ), $url );
		}

		return wp_nonce_url( $url, 'logout', '_pinova_nonce' );
	}

	public static function users_can_register(): bool {
		return (bool) self::get_option( 'general.wordpress_users_can_register', get_option( 'users_can_register' ) == 1 );
	}

	/**
	 *
	 * Get options based on pinova prefix
	 *
	 * @param string $option_name
	 *
	 * @param ?mixed $default
	 *
	 * @return mixed
	 */
	public static function get_option( string $option_name, $default = null ) {

		[ $section, $option ] = explode( '.', $option_name );

		$options = get_option( 'pinova_' . $section, [] );

		if ( isset( $options[ $option ] ) ) {
			return $options[ $option ];
		}

		return $default;
	}

	/**
	 * Set options manually
	 *
	 * @param string $option_name
	 *
	 * @param mixed  $value
	 *
	 * @return void
	 */
	public static function set_option( string $option_name, $value ): void {

		[ $section, $option ] = explode( '.', $option_name );

		$options = get_option( 'pinova_' . $section, [] );
		$options = empty( $options ) ? [] : $options;

		$options[ $option ] = $value;

		update_option( 'pinova_' . $section, $options );
	}

}
