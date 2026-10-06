<?php

namespace Pinova\Integrations\Dokan;

use Pinova\Helper;
use Pinova\Integrations\Continuation;
use Pinova\Integrations\IntegrationSettings;
use Pinova\Pinova;
use Pinova\Services\MobileVerificationService;
use Pinova\Services\UserService;
use WP_Error;

/** Authenticate once, then let Dokan validate and convert the existing account. */
final class Load {

	public const STATE_META = '_pinova_dokan_onboarding';

	private static bool $booted      = false;
	private static ?int $authorized  = null;
	private static array $role_hooks = [];

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'template_redirect', [ self::class, 'guard_migration' ], 8 );
		add_action( 'template_redirect', [ self::class, 'route' ], 9 );
		add_action( 'template_redirect', [ self::class, 'clear_attempt' ], 11 );
		add_action( 'shutdown', [ self::class, 'clear_attempt' ], 0 );
		add_filter( 'dokan_customer_migration_required_fields', [ self::class, 'capture_forum' ], PHP_INT_MAX );
		add_action( 'dokan_new_seller_created', [ self::class, 'converted' ], PHP_INT_MAX );
		add_filter( 'dokan_customer_migration_redirect', [ self::class, 'migration_redirect' ], PHP_INT_MAX );
		add_filter( 'woocommerce_process_registration_errors', [ self::class, 'registration_errors' ], PHP_INT_MAX );
		add_filter( 'woocommerce_registration_errors', [ self::class, 'registration_errors' ], PHP_INT_MAX );
		add_filter( 'woocommerce_new_customer_data', [ self::class, 'customer_data' ], PHP_INT_MAX );
		add_filter( 'pre_do_shortcode_tag', [ self::class, 'shortcode' ], 10, 2 );
		add_filter( 'woocommerce_reports_get_order_report_query', [ self::class, 'report_query' ], 11 );
		add_action( 'wp_ajax_nopriv_dokan_get_login_form', [ self::class, 'ajax_form' ], 0 );
		add_action( 'wp_ajax_nopriv_dokan_login_user', [ self::class, 'ajax_login' ], 0 );
	}

	/** Preserve Dokan's native parent-order restriction on WooCommerce HPOS reports. */
	public static function report_query( array $query ): array {
		global $wpdb;
		if ( ! defined( 'DOKAN_PLUGIN_VERSION' ) || ! IntegrationSettings::supports_version( 'dokan', DOKAN_PLUGIN_VERSION )
			|| ! isset( $query['from'], $query['where'] ) || ! is_string( $query['where'] )
			|| 'FROM ' . $wpdb->prefix . 'wc_orders AS orders' !== $query['from'] ) {
			return $query;
		}
		// Skip SQL literals; only the legacy column token changes, never its value or operator.
		$pattern = <<<'SQL'
~'(?:[^'\\]|\\.|'')*'(*SKIP)(*F)|"(?:[^"\\]|\\.|"")*"(*SKIP)(*F)|\bposts\.post_parent\b~
SQL;
		$query['where'] = preg_replace( $pattern, 'orders.parent_order_id', $query['where'] ) ?? $query['where'];
		return $query;
	}

	/** Previously owned pending attempts cannot escape by disabling routing. */
	public static function denied( int $user_id ): bool {
		return 'pending' === get_user_meta( $user_id, self::STATE_META, true )
			&& ( ! IntegrationSettings::enabled( 'dokan' ) || ! MobileVerificationService::is_verified( $user_id ) );
	}

	public static function can_convert( int $user_id ): bool {
		$user = get_userdata( $user_id );
		return IntegrationSettings::enabled( 'dokan' ) && $user && ! self::privileged( $user )
			&& ! dokan_is_user_seller( $user_id ) && MobileVerificationService::is_verified( $user_id );
	}

	private static function privileged( \WP_User $user ): bool {
		if ( UserService::is_native_only( $user ) ) {
			return true;
		}
		$capabilities = [
			'manage_options',
			'manage_woocommerce',
			'edit_users',
			'promote_users',
			'delete_users',
			'create_users',
			'install_plugins',
			'activate_plugins',
			'edit_plugins',
			'edit_theme_options',
			'edit_shop_orders',
		];
		foreach ( $capabilities as $capability ) {
			if ( user_can( $user, $capability ) ) {
				return true;
			}
		}
		return false;
	}

	private static function protected_request(): bool {
		return IntegrationSettings::enabled( 'dokan' ) || 'pending' === get_user_meta( get_current_user_id(), self::STATE_META, true );
	}

	private static function migration_url(): string {
		return function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'account-migration' ) : home_url( '/' );
	}

	/** Finish onboarding before following the user's final destination. */
	private static function onboarding_url(): string {
		$target = self::requested_target();
		if ( '' === $target ) {
			return self::migration_url();
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode
		return add_query_arg( 'back_url', rawurlencode( self::continuation( $target ) ), self::migration_url() );
	}

	public static function continuation( $target, ?string $fallback = null ): string {
		$fallback = $fallback ?? ( function_exists( 'dokan_get_navigation_url' ) ? dokan_get_navigation_url() : home_url( '/' ) );
		$safe     = Continuation::validate( $target, $fallback );
		$post_id  = url_to_postid( $safe );
		$post     = $post_id ? get_post( $post_id ) : null;
		// These shortcodes may be placed on any page, without a Dokan page-setting entry.
		if ( $post && ( has_shortcode( $post->post_content, 'dokan-vendor-registration' ) || has_shortcode( $post->post_content, 'dokan-vendor-onboarding-registration' ) ) ) {
			return Continuation::validate( '', $fallback, [ get_permalink( $post ) ] );
		}
		return $safe;
	}

	private static function requested_target() {
		// Navigation only; validation below rejects malformed values and unsafe destinations.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
		return wp_unslash( $_POST['back_url'] ?? $_POST['redirect_to'] ?? $_GET['back_url'] ?? $_GET['redirect_to'] ?? '' );
	}

	/** Nonce and eligibility are checked before Dokan's priority-ten handler can mutate roles. */
	public static function guard_migration(): void {
		self::clear_attempt();
		if ( ! self::protected_request() || ! isset( $_POST['dokan_migration'], $_POST['dokan_nonce'] )
			|| ! is_string( $_POST['dokan_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['dokan_nonce'] ) ), 'account_migration' ) ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			Helper::redirect_to( Pinova::get_login_url( self::onboarding_url() ), 'login' );
		}
		$user = get_userdata( $user_id );
		if ( ! $user || self::privileged( $user ) ) {
			self::unavailable();
		}
		// Existing and disabled vendors stay under Dokan's own policy and are never reactivated here.
		if ( function_exists( 'dokan_is_user_seller' ) && dokan_is_user_seller( $user_id ) ) {
			return;
		}
		if ( ! IntegrationSettings::enabled( 'dokan' ) ) {
			self::unavailable();
		}
		update_user_meta( $user_id, self::STATE_META, 'pending' );
		if ( 'pending' !== get_user_meta( $user_id, self::STATE_META, true ) ) {
			self::unavailable();
		}
		if ( ! self::can_convert( $user_id ) ) {
			Helper::redirect_to( MobileVerificationService::url( self::onboarding_url() ), 'login' );
		}
		self::$authorized = $user_id;
	}

	private static function unavailable(): void {
		wp_die( esc_html__( 'ادامهٔ درخواست فروشندگی اکنون ممکن نیست. لطفاً با پشتیبانی تماس بگیرید.', 'pinova' ), '', [ 'response' => 403 ] );
	}

	/** Called by the native handler after its nonce check; do not replace its field requirements. */
	public static function capture_forum( array $fields ): array {
		if ( null === self::$authorized || get_current_user_id() !== self::$authorized ) {
			return $fields;
		}
		if ( ! self::can_convert( self::$authorized ) ) {
			self::unavailable();
		}
		if ( function_exists( 'WPF' ) && defined( 'WPFORO_VERSION' ) && IntegrationSettings::supports_version( 'wpforo', WPFORO_VERSION )
			&& [] === self::$role_hooks ) {
			global $wp_filter;
			$callback = 'wpforo_update_usergroup_on_role_change';
			if ( ! function_exists( $callback ) ) {
				return $fields;
			}
			foreach ( [ 'add_user_role', 'set_user_role' ] as $hook ) {
				$priority = has_action( $hook, $callback );
				if ( ! is_int( $priority ) ) {
					continue;
				}
				$accepted = $wp_filter[ $hook ]->callbacks[ $priority ][ $callback ]['accepted_args'];
				$wrapper  = static function ( $user_id, $role, $old_roles = [] ) use ( $callback ): void {
					if ( self::$authorized === (int) $user_id && get_current_user_id() === (int) $user_id && 'seller' === $role ) {
						return;
					}
					$callback( $user_id, $role, $old_roles );
				};

				self::$role_hooks[ $hook ] = [ $wrapper, $priority, $accepted ];
				remove_action( $hook, $callback, $priority );
				add_action( $hook, $wrapper, $priority, $accepted );
			}
		}
		return $fields;
	}

	public static function clear_attempt(): void {
		foreach ( self::$role_hooks as $hook => [ $wrapper, $priority, $accepted ] ) {
			remove_action( $hook, $wrapper, $priority );
			if ( function_exists( 'wpforo_update_usergroup_on_role_change' ) ) {
				add_action( $hook, 'wpforo_update_usergroup_on_role_change', $priority, $accepted );
			}
		}
		self::$role_hooks = [];
		self::$authorized = null;
	}

	/** Prevent destructive role synchronization instead of depending on a second database write. */
	public static function converted( int $user_id ): void {
		if ( self::$authorized !== $user_id || get_current_user_id() !== $user_id || ! dokan_is_user_seller( $user_id ) ) {
			return;
		}
		$scoped = [] !== self::$role_hooks;
		self::clear_attempt();
		if ( $scoped ) {
			WPF()->ram_cache->reset( [ get_class( WPF()->member ) . '::_get_member', [ $user_id ] ] );
			WPF()->member->init_current_user();
			WPF()->current_user_accesses = [];
		}
		update_user_meta( $user_id, self::STATE_META, 'completed' );
	}

	public static function migration_redirect( string $url ): string {
		return self::protected_request() ? self::continuation( self::requested_target(), $url ) : $url;
	}

	public static function registration_errors( WP_Error $errors ): WP_Error {
		// This is denial only, including checkout where Dokan skips its own role validation.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( self::protected_request() && isset( $_POST['role'] ) && 'seller' === $_POST['role'] ) {
			$errors->add( 'pinova_vendor_login_required', __( 'ابتدا وارد حساب خود شوید و تلفن همراه را تأیید کنید؛ سپس از بخش «فروشنده شوید» ادامه دهید.', 'pinova' ) );
		}
		return $errors;
	}

	/** Final pre-insert boundary also covers programmatic WooCommerce checkout creation. */
	public static function customer_data( array $data ): array {
		if ( self::protected_request() && 'seller' === ( $data['role'] ?? '' ) ) {
			$data['role'] = 'customer';
		}
		return $data;
	}

	public static function route(): void {
		if ( ! IntegrationSettings::enabled( 'dokan' ) || is_admin() ) {
			return;
		}
		$post         = get_queried_object();
		$content      = $post instanceof \WP_Post ? $post->post_content : '';
		$registration = has_shortcode( $content, 'dokan-vendor-registration' ) || has_shortcode( $content, 'dokan-vendor-onboarding-registration' );
		$dashboard    = has_shortcode( $content, 'dokan-dashboard' );
		if ( ! $registration && ! $dashboard ) {
			return;
		}
		// Preserve a directly requested dashboard subpage as well as an explicit return parameter.
		$fallback = $registration ? self::migration_url() : self::continuation( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
		$target   = $registration ? self::onboarding_url() : self::continuation( self::requested_target(), $fallback );
		if ( ! is_user_logged_in() ) {
			Helper::redirect_to( Pinova::get_login_url( $target ), 'login' );
		} elseif ( $registration ) {
			Helper::redirect_to( dokan_is_user_seller( get_current_user_id() ) ? self::continuation( self::requested_target() ) : $target, 'login' );
		}
	}

	/** Embedded shortcodes still get a working link when a theme renders them outside the main page. */
	public static function shortcode( $output, string $tag ) {
		if ( false !== $output || ! IntegrationSettings::enabled( 'dokan' )
			|| ! in_array( $tag, [ 'dokan-dashboard', 'dokan-vendor-registration', 'dokan-vendor-onboarding-registration' ], true )
			|| ( 'dokan-dashboard' === $tag && is_user_logged_in() ) ) {
			return $output;
		}
		$target = 'dokan-dashboard' === $tag ? self::continuation( self::requested_target() ) : self::onboarding_url();
		$url    = is_user_logged_in() ? $target : Pinova::get_login_url( $target );
		return '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'ورود و ادامه', 'pinova' ) . '</a></p>';
	}

	public static function ajax_form(): void {
		if ( ! IntegrationSettings::enabled( 'dokan' ) ) {
			return;
		}
		check_ajax_referer( 'dokan_reviews' );
		$url = Pinova::get_login_url( self::continuation( self::requested_target(), self::continuation( wp_get_referer() ) ) );
		wp_send_json_success(
			[
				'title' => __( 'برای ادامه وارد شوید', 'pinova' ),
				'html'  => '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'ورود و ادامه', 'pinova' ) . '</a></p>',
			]
		);
	}

	public static function ajax_login(): void {
		if ( ! IntegrationSettings::enabled( 'dokan' ) ) {
			return;
		}
		check_ajax_referer( 'dokan_reviews' );
		$url = Pinova::get_login_url( self::continuation( self::requested_target(), self::continuation( wp_get_referer() ) ) );
		wp_send_json_error(
			[
				'message'   => __( 'برای ورود از پیوند ورود پینوا استفاده کنید.', 'pinova' ),
				'login_url' => $url,
			],
			400
		);
	}
}
