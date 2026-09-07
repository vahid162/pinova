<?php

namespace Pinova\Integrations\Woocommerce;

use Exception;
use Pinova\Helper;
use Pinova\Objects\Mobile;
use Pinova\Pinova;
use Pinova\Services\OTPService;
use Pinova\Services\UserService;
use WC_Customer;

class Load {

	protected static ?Load $_instance = null;

	public static function instance(): ?Load {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	public function __construct() {

		new Checkout();
		new Customer();
		new Account();

		if ( self::registration_required() == 'yes_automatic' ) {
			add_filter( 'woocommerce_checkout_customer_id', [ $this, 'checkout_customer_id' ] );
		}

		if ( self::registration_required() == 'yes_redirect' ) {
			new Redirect();
		} else {
			// @todo open pinova modal on click .showlogin, refresh page after login (check back url)
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
			add_action( 'woocommerce_review_order_after_submit', [ $this, 'checkout_login_modal' ] );
			add_filter( 'woocommerce_kses_notice_allowed_tags', [ $this, 'notice_allowed_tags' ] );
		}

		add_filter( 'woocommerce_get_endpoint_url', [ $this, 'wc_logout_to_pinova_logout' ], 10, 2 );

		add_filter( 'woocommerce_checkout_fields', [ $this, 'make_phone_field_required' ] );

		// Add new customer button and modal in order creation page
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ], 1, 10 );
		add_action( 'woocommerce_admin_order_data_after_order_details', [ $this, 'render_create_customer' ], 1, 1 );
	}

	public static function registration_required(): string {
		$default = WC()->checkout()->is_registration_required() ? 'yes_redirect' : 'no';

		return Pinova::get_option( 'general.woocommerce_checkout_registration_required', $default );
	}

	public static function is_registration_required(): bool {
		return self::registration_required() != 'no';
	}

	public function enqueue_scripts() {

		if ( ! is_checkout() ) {
			return;
		}

		wp_enqueue_style( 'pinova-page', PINOVA_URL . 'assets/css/style.css', [], PINOVA_VERSION );
		wp_enqueue_style( 'pinova-notyf', PINOVA_URL . 'assets/css/notyf.min.css', [], PINOVA_VERSION );

		wp_enqueue_script( 'pinova-notyf', PINOVA_URL . 'assets/js/notyf.min.js', [], PINOVA_VERSION );
		wp_enqueue_script( 'pinova-global', PINOVA_URL . 'assets/js/global.js', [ 'pinova-notyf' ], PINOVA_VERSION );
		wp_enqueue_script( 'pinova-login-modal', PINOVA_URL . 'assets/js/pages/login-modal.js', [], PINOVA_VERSION );
		wp_enqueue_script( 'pinova-woocommerce', PINOVA_URL . 'assets/js/woocommerce/checkout.js', [
			'jquery',
			'pinova-login-modal',
		], PINOVA_VERSION, true );

		wp_localize_script( 'pinova-global', 'pinova', [
			'root'        => esc_url_raw( rest_url() ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'code_length' => OTPService::code_length(),
			'logo'        => Pinova::get_option( 'design.logo', admin_url( 'images/wordpress-logo.svg' ) ),
		] );
	}

	public function checkout_login_modal() {
		include PINOVA_DIR . '/templates/login-modal.php';
	}

	/**
	 * @param int $customer_id
	 *
	 * @return int
	 * @throws Exception
	 */
	public function checkout_customer_id( int $customer_id ): int {

		$posted_data = WC()->checkout()->get_posted_data();

		$mobile = new Mobile( $posted_data['billing_phone'] );

		if ( is_user_logged_in() ) {
			return $customer_id;
		}

		$user_id = UserService::get_by_mobile( $mobile->get_formatted() );

		if ( $user_id ) {
			throw new Exception( sprintf(
				'%s <span style="text-align: left !important;" dir="ltr">%s</span> %s <a role="button" tabindex="0" class="pinova-error pinova-open_login__modal" style="cursor:pointer" data-identifier="%s">%s</a>',
				__( 'یک حساب کاربری با تلفن همراه', 'pinova' ),
				$mobile->get_formatted(),
				__( 'وجود دارد،', 'pinova' ),
				$mobile->get_formatted(),
				__( 'لطفاً وارد شوید.', 'pinova' )
			) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$email = strval( $posted_data['billing_email'] );

		$user_id = UserService::get_by_email( $email );

		if ( $user_id ) {
			throw new Exception( sprintf(
				'%s <span style="text-align: left !important;" dir="ltr">%s</span> %s <a role="button" tabindex="0" class="pinova-error pinova-open_login__modal" style="cursor:pointer" data-identifier="%s">%s</a>',
				__( 'یک حساب کاربری با ایمیل', 'pinova' ),
				$email,
				__( 'وجود دارد،', 'pinova' ),
				$email,
				__( 'لطفاً وارد شوید.', 'pinova' )
			) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		if ( ! Pinova::users_can_register() ) {
			throw new Exception( __( 'عضویت در سایت غیرفعال است.', 'pinova' ) );
		}

		$customer_id = UserService::create( $mobile->get_formatted(), $email, [
			'first_name' => $posted_data['billing_first_name'],
			'last_name'  => $posted_data['billing_last_name'],
		] );

		$customer = new WC_Customer( $customer_id );

		foreach ( $posted_data as $key => $value ) {

			if ( is_callable( [ $customer, "set_{$key}" ] ) ) {
				$customer->{"set_{$key}"}( $value );
			} else {
				$customer->update_meta_data( $key, $value );
			}

		}

		$customer->save_meta_data();

		wc_set_customer_auth_cookie( $customer_id );

		// @todo dont need reload, just set a jwt
		// As we are now logged in, checkout will need to refresh to show logged in data.
		WC()->session->set( 'reload_checkout', true );

		// Also, recalculate cart totals to reveal any role-based discounts that were unavailable before registering.
		WC()->cart->calculate_totals();

		return $customer_id;
	}

	public function notice_allowed_tags( array $tags ): array {
		return array_replace_recursive( $tags, [
			'a' => [
				'data-identifier' => true,
				'class'           => true,
				'role'            => true,
				'tabindex'        => true,
				'style'           => true,
			],
		] );
	}

	public function wc_logout_to_pinova_logout( string $url, string $endpoint ): string {

		if ( $endpoint !== 'customer-logout' ) {
			return $url;
		}

		return Pinova::get_logout_url( Helper::get_logout_back_url() );
	}

	public function make_phone_field_required( $fields ): array {
		$fields['billing']['billing_phone']['required'] = true;

		return $fields;
	}

	public function admin_enqueue_scripts( $hook ) {
		$is_single_order_edit_page = in_array( $_GET['action'] ?? '', [ 'edit', 'new' ] );

		if ( 'woocommerce_page_wc-orders' !== $hook || ! $is_single_order_edit_page ) {
			return;
		}

		wp_enqueue_style( 'pinova-page', PINOVA_URL . 'assets/css/style.css', [], PINOVA_VERSION );
		wp_enqueue_style( 'pinova-notyf', PINOVA_URL . 'assets/css/notyf.min.css', [], PINOVA_VERSION );

		wp_enqueue_script( 'pinova-notyf', PINOVA_URL . 'assets/js/notyf.min.js', [], PINOVA_VERSION );
		wp_enqueue_script( 'pinova-global', PINOVA_URL . 'assets/js/global.js', [ 'pinova-notyf' ], PINOVA_VERSION );
		wp_enqueue_script( 'pinova-create-customer-modal', PINOVA_URL . 'assets/js/woocommerce/create-customer-modal.js', [], PINOVA_VERSION );

		wp_localize_script( 'pinova-global', 'pinova', [
			'root'  => esc_url_raw( rest_url() ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		] );
	}

	public function render_create_customer( $order ) {
		$screen_id = is_admin() && function_exists( 'get_current_screen' ) ? get_current_screen()->id : null;

		if ( 'woocommerce_page_wc-orders' !== $screen_id || $order->get_customer_id() > 0 ) {

			return;
		}

		include PINOVA_DIR . '/templates/woocommerce/create-customer-modal.php';
	}
}