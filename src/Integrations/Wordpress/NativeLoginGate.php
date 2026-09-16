<?php

namespace Pinova\Integrations\Wordpress;

use Pinova\Pinova;
use Pinova\Services\UserService;
use WP_Error;
use WP_User;

/**
 * Hide the canonical WordPress login endpoint behind a private site route.
 *
 * The private route still executes wp-login.php for configured native-only
 * roles, so WordPress authentication, Wordfence, two-factor authentication,
 * passkeys, and recovery actions keep their native hooks. The gate is opt-in
 * to prevent an upgrade from locking an administrator out before the private
 * URL has been saved and tested.
 */
final class NativeLoginGate {

	private bool $private_request;

	public function __construct() {
		$this->private_request = $this->request_matches_private_route();

		if ( $this->private_request ) {
			$GLOBALS['pagenow'] = 'wp-login.php';
			$this->prepare_native_login_environment();
		}

		add_action( 'template_redirect', [ $this, 'serve_private_login' ], 0 );
		$enabled = self::is_enabled();

		if ( $this->private_request ) {
			add_filter( 'site_url', [ $this, 'rewrite_site_url' ], 10, 4 );
			add_filter( 'network_site_url', [ $this, 'rewrite_network_site_url' ], 10, 3 );
			add_filter( 'wp_redirect', [ $this, 'rewrite_redirect' ], 10, 2 );
			add_filter( 'authenticate', [ $this, 'enforce_native_only_role' ], PHP_INT_MAX );
			add_filter( 'allow_password_reset', [ $this, 'allow_native_only_password_reset' ], 99, 2 );
			add_action( 'validate_password_reset', [ $this, 'validate_native_only_password_reset' ], PHP_INT_MAX, 2 );
			add_action( 'login_form_register', [ $this, 'redirect_public_registration' ], 0 );
		}

		if ( $enabled ) {
			add_action( 'login_init', [ $this, 'block_canonical_login' ], 0 );
			add_filter( 'login_url', [ $this, 'rewrite_public_login_url' ], 20, 3 );
			add_filter( 'register_url', [ $this, 'rewrite_public_register_url' ], 20 );
			add_filter( 'lostpassword_url', [ $this, 'rewrite_public_lost_password_url' ], 20, 2 );
			add_filter( 'retrieve_password_message', [ $this, 'rewrite_native_reset_message' ], 99, 4 );
			add_filter( 'recovery_mode_email', [ $this, 'rewrite_recovery_mode_email' ], 99 );
		}
	}

	public static function is_enabled(): bool {
		if ( defined( 'PINOVA_BLOCK_NATIVE_LOGIN' ) ) {
			return (bool) constant( 'PINOVA_BLOCK_NATIVE_LOGIN' );
		}

		$enabled = (bool) Pinova::get_option( 'advanced.block_native_login', false );

		return (bool) apply_filters( 'pinova/native_login_gate_enabled', $enabled );
	}

	public static function default_slug(): string {
		$site_key = (string) get_option( 'home', 'pinova' );
		$hash     = hash_hmac( 'sha256', $site_key, wp_salt( 'auth' ) );

		return 'pinova-admin-' . substr( $hash, 0, 16 );
	}

	public static function slug(): string {
		$fallback = self::default_slug();

		if ( defined( 'PINOVA_NATIVE_LOGIN_SLUG' ) ) {
			return self::normalize_slug( (string) constant( 'PINOVA_NATIVE_LOGIN_SLUG' ), $fallback );
		}

		$slug = (string) Pinova::get_option( 'advanced.native_login_slug', $fallback );

		return self::normalize_slug( $slug, $fallback );
	}

	public static function url(): string {
		return home_url( '/' . self::slug() . '/' );
	}

	public static function sanitize_slug( $slug ): string {
		return self::normalize_slug( (string) $slug, self::default_slug() );
	}

	/**
	 * Normalize a private route without depending on WordPress sanitizers.
	 *
	 * @internal Public for deterministic unit coverage.
	 */
	public static function normalize_slug( string $slug, string $fallback ): string {
		$slug = strtolower( trim( $slug ) );
		$slug = (string) preg_replace( '/[^a-z0-9_-]+/', '-', $slug );
		$slug = trim( $slug, '-_' );

		$reserved = [
			'login',
			'logout',
			'my-account',
			'wp-admin',
			'wp-json',
			'wp-login',
			'wp-login-php',
			'wp-login.php',
		];

		if ( strlen( $slug ) < 12 || strlen( $slug ) > 80 || in_array( $slug, $reserved, true ) ) {
			return $fallback;
		}

		return $slug;
	}

	public function block_canonical_login(): void {
		if ( $this->private_request || ! self::is_canonical_login_request() ) {
			return;
		}

		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		esc_html_e( 'Not Found', 'pinova' );
		exit;
	}

	public function serve_private_login(): void {
		if ( ! $this->private_request ) {
			return;
		}

		global $action, $error, $interim_login, $wp_query;

		if ( $wp_query ) {
			$wp_query->is_404 = false;
		}

		status_header( 200 );
		nocache_headers();

		require ABSPATH . 'wp-login.php';
		exit;
	}

	public function rewrite_site_url( string $url, string $path, ?string $scheme, ?int $blog_id ): string {
		unset( $path, $scheme, $blog_id );

		return $this->rewrite_login_url( $url );
	}

	public function rewrite_network_site_url( string $url, string $path, ?string $scheme ): string {
		unset( $path, $scheme );

		return $this->rewrite_login_url( $url );
	}

	public function rewrite_redirect( string $location, int $status ): string {
		unset( $status );

		return $this->rewrite_login_url( $location );
	}

	public function rewrite_public_login_url( string $login_url, string $redirect, bool $force_reauth ): string {
		unset( $force_reauth );

		if ( $this->private_request ) {
			return $login_url;
		}

		return Pinova::get_login_url( '' === $redirect ? null : $redirect );
	}

	public function rewrite_public_register_url( string $register_url ): string {
		return $this->private_request ? $register_url : Pinova::get_login_url();
	}

	public function rewrite_public_lost_password_url( string $lost_password_url, string $redirect ): string {
		if ( $this->private_request ) {
			return $lost_password_url;
		}

		return Pinova::get_login_url( '' === $redirect ? null : $redirect );
	}

	/**
	 * @param WP_User|WP_Error|null $user
	 * @return WP_User|WP_Error|null
	 */
	public function enforce_native_only_role( $user ) {
		if ( ! $user instanceof WP_User || UserService::is_native_only( $user ) ) {
			return $user;
		}

		return new WP_Error(
			'pinova_native_login_role_required',
			__( 'نام کاربری یا رمز عبور نادرست است.', 'pinova' )
		);
	}

	public function allow_native_only_password_reset( $allow, int $user_id ): bool {
		$user = get_userdata( $user_id );

		return $user instanceof WP_User && UserService::is_native_only( $user ) && (bool) $allow;
	}

	/**
	 * @param WP_User|WP_Error $user
	 */
	public function validate_native_only_password_reset( WP_Error $errors, $user ): void {
		if ( $user instanceof WP_User && ! UserService::is_native_only( $user ) ) {
			$errors->add(
				'pinova_native_reset_role_required',
				__( 'درخواست بازنشانی رمز عبور معتبر نیست.', 'pinova' )
			);
		}
	}

	public function redirect_public_registration(): void {
		wp_safe_redirect( Pinova::get_login_url() );
		exit;
	}

	public function rewrite_native_reset_message( string $message, string $key, string $user_login, WP_User $user ): string {
		unset( $key, $user_login );

		return UserService::is_native_only( $user ) ? $this->rewrite_login_url( $message ) : $message;
	}

	public function rewrite_recovery_mode_email( array $email ): array {
		if ( isset( $email['message'] ) && is_string( $email['message'] ) ) {
			$email['message'] = $this->rewrite_login_url( $email['message'] );
		}

		return $email;
	}

	public static function is_canonical_login_request( ?string $request_uri = null, ?string $script_name = null ): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Only the path is compared to a fixed filename and never rendered or persisted.
		$request_uri = null === $request_uri ? (string) ( $_SERVER['REQUEST_URI'] ?? '' ) : $request_uri;
		$script_name = null === $script_name ? sanitize_text_field( wp_unslash( (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ) ) ) : $script_name;
		$path         = explode( '?', $request_uri, 2 )[0];

		return 1 === preg_match( '#(?:^|/)wp-login\.php(?:/|$)#i', $path )
			|| 'wp-login.php' === basename( $script_name );
	}

	private function request_matches_private_route(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- The decoded path is only matched with hash_equals against the sanitized configured slug.
		$request_uri  = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		$request_path = trim( rawurldecode( (string) wp_parse_url( $request_uri, PHP_URL_PATH ) ), '/' );
		$home_path    = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

		if ( '' !== $home_path && str_starts_with( $request_path, $home_path . '/' ) ) {
			$request_path = substr( $request_path, strlen( $home_path ) + 1 );
		}

		return hash_equals( self::slug(), trim( $request_path, '/' ) );
	}

	private function prepare_native_login_environment(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Preserve the exact core login query (reset keys, actions, and redirects); it is parsed and handled by wp-login.php.
		$request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		$query       = (string) wp_parse_url( $request_uri, PHP_URL_QUERY );
		$script_name = sanitize_text_field( wp_unslash( (string) ( $_SERVER['SCRIPT_NAME'] ?? '/index.php' ) ) );
		$script_name = str_replace( '\\', '/', $script_name );
		$base_path   = rtrim( dirname( $script_name ), '/.' );

		$_SERVER['REQUEST_URI'] = ( '' === $base_path ? '' : $base_path ) . '/wp-login.php';

		if ( '' !== $query ) {
			$_SERVER['REQUEST_URI'] .= '?' . $query;
		}
	}

	private function rewrite_login_url( string $url ): string {
		if ( ! str_contains( $url, 'wp-login.php' ) ) {
			return $url;
		}

		return (string) preg_replace( '#wp-login\.php(?=([/?#]|$))#i', self::slug(), $url, 1 );
	}
}
