<?php

// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledNamespaceName -- Preserve the established public namespace.
namespace Pinova\Integrations\Wordpress;

use Pinova\Logging\Logger;
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
	private const ACTIVATION_KEY = 'native_login_activation';
	private const ARM_OPTION     = 'pinova_native_login_arm';
	private const ARM_TTL        = 1800;

	private bool $private_request;

	private static bool $state_logging_registered    = false;
	private static ?string $state_change_reason      = null;
	private static bool $runtime_invalidation_update = false;

	public function __construct() {
		$this->private_request = $this->request_matches_private_route();

		if ( $this->private_request ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Native login integrations inspect this core request marker.
			$GLOBALS['pagenow'] = 'wp-login.php';
			$this->prepare_native_login_environment();
		}

		add_action( 'template_redirect', [ $this, 'serve_private_login' ], 0 );

		if ( ! self::$state_logging_registered ) {
			add_action( 'update_option_pinova_advanced', [ self::class, 'log_gate_state_change' ], 10, 3 );
			self::$state_logging_registered = true;
		}

		$enabled = self::is_enabled();

		if ( $this->private_request ) {
			add_filter( 'site_url', [ $this, 'rewrite_site_url' ], 10, 4 );
			add_filter( 'network_site_url', [ $this, 'rewrite_network_site_url' ], 10, 3 );
			add_filter( 'wp_redirect', [ $this, 'rewrite_redirect' ], 10, 2 );
			add_filter( 'authenticate', [ $this, 'enforce_native_only_role' ], PHP_INT_MAX );
			add_filter( 'allow_password_reset', [ $this, 'allow_native_only_password_reset' ], 99, 2 );
			add_action( 'validate_password_reset', [ $this, 'validate_native_only_password_reset' ], PHP_INT_MAX, 2 );
			add_action( 'login_form_register', [ $this, 'redirect_public_registration' ], 0 );
			add_action( 'wp_login', [ $this, 'arm_after_private_login' ], PHP_INT_MAX, 2 );
		}

		if ( $enabled ) {
			add_action( 'login_init', [ $this, 'block_canonical_login' ], 0 );
			add_filter( 'login_url', [ $this, 'rewrite_public_login_url' ], 20, 3 );
			add_filter( 'register_url', [ $this, 'rewrite_public_register_url' ], 20 );
			add_filter( 'lostpassword_url', [ $this, 'rewrite_public_lost_password_url' ], 20, 2 );
			add_filter( 'retrieve_password_message', [ $this, 'rewrite_native_reset_message' ], 99, 4 );
			add_filter( 'recovery_mode_email', [ $this, 'rewrite_recovery_mode_email' ], 99 );
			add_filter( 'user_request_action_email_content', [ $this, 'rewrite_privacy_request_email_content' ], 99, 2 );
		}
	}

	public static function is_enabled(): bool {
		$options = self::advanced_options();

		if ( self::invalidate_runtime_activation_if_needed( $options ) ) {
			return false;
		}

		$override = defined( 'PINOVA_BLOCK_NATIVE_LOGIN' )
			? (bool) constant( 'PINOVA_BLOCK_NATIVE_LOGIN' )
			: null;
		$enabled  = self::resolve_gate_state( self::configuration_is_activated( $options ), $override );

		if ( ! $enabled ) {
			return false;
		}

		return (bool) apply_filters( 'pinova/native_login_gate_enabled', true );
	}

	/**
	 * Resolve the emergency constant without allowing a true value to bypass
	 * the persisted setting and matching activation record.
	 *
	 * @internal Public for deterministic unit coverage.
	 */
	public static function resolve_gate_state( bool $configured_and_activated, ?bool $constant_override ): bool {
		return false === $constant_override ? false : $configured_and_activated;
	}

	public static function default_slug(): string {
		$site_key = (string) get_option( 'home', 'pinova' );
		$secret   = defined( 'AUTH_KEY' ) ? (string) constant( 'AUTH_KEY' ) : ABSPATH;
		$hash     = hash_hmac( 'sha256', $site_key, $secret );

		return 'pinova-admin-' . substr( $hash, 0, 16 );
	}

	public static function slug(): string {
		return self::effective_slug_for_options( self::advanced_options() );
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

	/**
	 * Reconcile a complete advanced-settings update without recursively writing
	 * the option being sanitized. The activation record is returned as part of
	 * the same atomic option update as the enable flag.
	 *
	 * @param array<string, mixed> $options
	 * @param array<string, mixed> $previous
	 * @return array<string, mixed>
	 */
	public static function reconcile_advanced_options( array $options, array $previous, ?int $now = null ): array {
		$now              = $now ?? time();
		$requested        = ! empty( $options['block_native_login'] );
		$current_slug     = self::persisted_slug_from_options( $options );
		$previous_slug    = self::persisted_slug_from_options( $previous );
		$slug_changed     = $current_slug !== $previous_slug;
		$was_requested    = ! empty( $previous['block_native_login'] );
		$prior_activation = isset( $previous[ self::ACTIVATION_KEY ] ) && is_array( $previous[ self::ACTIVATION_KEY ] )
			? $previous[ self::ACTIVATION_KEY ]
			: [];

		$options['block_native_login'] = $requested ? '1' : '0';
		unset( $options[ self::ACTIVATION_KEY ] );

		if ( ! $requested || '' === $current_slug || $slug_changed ) {
			$options['block_native_login'] = '0';
			self::clear_arm();
			return $options;
		}

		if ( $was_requested && self::activation_record_matches( $prior_activation, $current_slug ) ) {
			$options[ self::ACTIVATION_KEY ] = $prior_activation;
			return $options;
		}

		$current_user_id = get_current_user_id();

		if ( $current_user_id < 1 || ! current_user_can( 'manage_options' ) ) {
			self::clear_arm();
			$options['block_native_login'] = '0';
			return $options;
		}

		$activation = self::consume_arm( $current_slug, $now, $current_user_id );

		if ( null === $activation ) {
			$options['block_native_login'] = '0';
			return $options;
		}

		$options[ self::ACTIVATION_KEY ] = $activation;

		return $options;
	}

	/**
	 * Whether a complete advanced option contains the setting and matching
	 * durable activation needed by the runtime gate.
	 *
	 * @param array<string, mixed> $options
	 * @internal Public for integration coverage.
	 */
	public static function configuration_is_activated( array $options ): bool {
		if ( empty( $options['block_native_login'] ) ) {
			return false;
		}

		$slug = self::persisted_slug_from_options( $options );

		if ( '' === $slug || ! hash_equals( self::effective_slug_for_options( $options ), $slug ) ) {
			return false;
		}

		$activation = $options[ self::ACTIVATION_KEY ] ?? null;

		return is_array( $activation ) && self::activation_record_matches( $activation, $slug );
	}

	/**
	 * Permanently fail closed when a previously durable activation no longer
	 * matches its effective route, plugin version, or keyed binding. Without
	 * this write, restoring an older constant or version could revive the old
	 * activation without another private login.
	 *
	 * @param array<string, mixed> $options
	 * @internal Public for integration coverage.
	 */
	public static function invalidate_runtime_activation_if_needed(
		array $options,
		?string $effective_slug = null
	): bool {
		$activation = $options[ self::ACTIVATION_KEY ] ?? null;

		if ( empty( $options['block_native_login'] ) ) {
			return false;
		}

		$persisted_slug = self::persisted_slug_from_options( $options );
		$effective_slug = $effective_slug ?? self::effective_slug_for_options( $options );
		$reason         = 'activation_invalid';

		if ( '' === $persisted_slug || ! hash_equals( $effective_slug, $persisted_slug ) ) {
			$reason = 'slug_changed';
		} elseif ( is_array( $activation ) && self::activation_record_matches( $activation, $persisted_slug ) ) {
			return false;
		}

		$options['block_native_login'] = '0';
		unset( $options[ self::ACTIVATION_KEY ] );
		self::clear_arm();
		self::$state_change_reason         = $reason;
		self::$runtime_invalidation_update = true;

		try {
			update_option( 'pinova_advanced', $options );
		} finally {
			self::$state_change_reason         = null;
			self::$runtime_invalidation_update = false;
		}

		return true;
	}

	/**
	 * Identify the narrow internal option write used to fail a stale gate
	 * closed during plugin bootstrap. The value already came from the stored
	 * option and only the gate flag/activation were removed, so rerunning the
	 * complete admin settings sanitizer is neither needed nor bootstrap-safe.
	 *
	 * @internal Used by the Pinova settings sanitizer only.
	 */
	public static function is_runtime_invalidation_update(): bool {
		return self::$runtime_invalidation_update;
	}

	/**
	 * A successful native private-route login creates a short-lived arm. It is
	 * intentionally separate from the durable setting so merely knowing or
	 * saving the route cannot enable canonical login blocking.
	 */
	public function arm_after_private_login( string $user_login, WP_User $user ): void {
		unset( $user_login );

		if (
			! $this->private_request
			|| ! UserService::is_native_only( $user )
			|| ! $user->has_cap( 'manage_options' )
		) {
			return;
		}

		$options        = self::advanced_options();
		$persisted_slug = self::persisted_slug_from_options( $options );

		if (
			'' === $persisted_slug
			|| ! hash_equals( self::effective_slug_for_options( $options ), $persisted_slug )
		) {
			return;
		}

		$now = time();
		$arm = [
			'slug_hmac'      => self::slug_hmac( $persisted_slug ),
			'user_id'        => (int) $user->ID,
			'plugin_version' => self::plugin_version(),
			'expires_at'     => $now + self::ARM_TTL,
		];

		$stored = update_option( self::ARM_OPTION, $arm, false );

		if ( false === $stored && get_option( self::ARM_OPTION, null ) !== $arm ) {
			return;
		}

		Logger::instance()->notice(
			'security.native_login_armed',
			[
				'user_id'   => (int) $user->ID,
				'operation' => 'native_login_arm',
				'status'    => 'ready',
			]
		);
	}

	/**
	 * Log only bounded configuration transitions, never canonical-route probes.
	 *
	 * @param mixed $old_value
	 * @param mixed $value
	 */
	public static function log_gate_state_change( $old_value, $value, string $option ): void {
		unset( $option );

		$old = is_array( $old_value ) ? $old_value : [];
		$new = is_array( $value ) ? $value : [];
		$was = self::configuration_is_activated( $old );
		$is  = self::configuration_is_activated( $new );

		if ( ! $was && null !== self::$state_change_reason ) {
			$was = ! empty( $old['block_native_login'] );
		}

		if ( $was === $is ) {
			return;
		}

		if ( $is ) {
			$activation = is_array( $new[ self::ACTIVATION_KEY ] ?? null ) ? $new[ self::ACTIVATION_KEY ] : [];
			Logger::instance()->audit(
				'warning',
				'security.native_login_gate_enabled',
				[
					'user_id'   => (int) ( $activation['user_id'] ?? 0 ),
					'operation' => 'native_login_gate',
					'status'    => 'enabled',
				]
			);
			return;
		}

		$old_activation = is_array( $old[ self::ACTIVATION_KEY ] ?? null ) ? $old[ self::ACTIVATION_KEY ] : [];
		$current_user   = function_exists( 'wp_get_current_user' ) ? get_current_user_id() : 0;
		$reason         = self::$state_change_reason
			?? ( empty( $new['block_native_login'] ) ? 'setting_disabled' : 'activation_invalid' );

		if (
			null === self::$state_change_reason
			&& self::persisted_slug_from_options( $old ) !== self::persisted_slug_from_options( $new )
		) {
			$reason = 'slug_changed';
		}

		Logger::instance()->audit(
			'warning',
			'security.native_login_gate_disabled',
			[
				'user_id'   => $current_user > 0 ? $current_user : (int) ( $old_activation['user_id'] ?? 0 ),
				'operation' => 'native_login_gate',
				'status'    => 'disabled',
				'reason'    => $reason,
			]
		);
	}

	public function block_canonical_login(): void {
		if (
			$this->private_request
			|| self::is_public_core_action_request()
			|| ! self::is_canonical_login_request()
		) {
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
		if ( $this->private_request ) {
			return $login_url;
		}

		if ( $this->is_logged_in_admin_email_confirmation() ) {
			return $login_url;
		}

		if ( $this->should_use_private_login_url( $force_reauth ) ) {
			return $this->rewrite_login_url( $login_url );
		}

		return Pinova::get_login_url( '' === $redirect ? null : $redirect, $force_reauth );
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

	/**
	 * @param bool|WP_Error $allow Result from earlier password-reset filters.
	 * @return bool|WP_Error
	 */
	public function allow_native_only_password_reset( $allow, int $user_id ) {
		if ( $allow instanceof WP_Error ) {
			return $allow;
		}

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

	/**
	 * Restore the canonical non-authentication endpoint in privacy-request emails.
	 *
	 * Core adds the confirmaction query after calling wp_login_url(), so the
	 * ordinary login_url filter cannot distinguish this flow from authentication.
	 */
	public function rewrite_privacy_request_email_content( string $content, array $email_data ): string {
		if ( ! isset( $email_data['confirm_url'] ) || ! is_string( $email_data['confirm_url'] ) ) {
			return $content;
		}

		$query = [];
		wp_parse_str( (string) wp_parse_url( $email_data['confirm_url'], PHP_URL_QUERY ), $query );

		if (
			'confirmaction' !== ( $query['action'] ?? '' )
			|| ! isset( $query['request_id'], $query['confirm_key'] )
			|| ! is_scalar( $query['request_id'] )
			|| ! is_string( $query['confirm_key'] )
		) {
			return $content;
		}

		$request_id  = absint( $query['request_id'] );
		$confirm_key = sanitize_text_field( $query['confirm_key'] );

		if ( 1 > $request_id || '' === $confirm_key ) {
			return $content;
		}

		$confirm_url = add_query_arg(
			[
				'action'      => 'confirmaction',
				'request_id'  => $request_id,
				'confirm_key' => $confirm_key,
			],
			site_url( 'wp-login.php', 'login' )
		);

		return str_replace( '###CONFIRM_URL###', esc_url_raw( $confirm_url ), $content );
	}

	public static function is_canonical_login_request( ?string $request_uri = null, ?string $script_name = null ): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Only the path is compared to a fixed filename and never rendered or persisted.
		$request_uri = null === $request_uri ? (string) ( $_SERVER['REQUEST_URI'] ?? '' ) : $request_uri;
		$script_name = null === $script_name ? sanitize_text_field( wp_unslash( (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ) ) ) : $script_name;
		$path        = explode( '?', $request_uri, 2 )[0];

		return 1 === preg_match( '#(?:^|/)wp-login\.php(?:/|$)#i', $path )
			|| 'wp-login.php' === basename( $script_name );
	}

	/**
	 * Keep the core actions that do not authenticate or recover an account.
	 * Match exactly because core normalizes unknown variants back to login.
	 */
	public static function is_public_core_action_request( ?string $action = null ): bool {
		if ( null === $action ) {
			$core_action = $GLOBALS['action'] ?? null;

			if ( is_string( $core_action ) ) {
				$action = $core_action;
			} else {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This only selects a core handler; wp-login.php processes the request.
				$request_action = $_REQUEST['action'] ?? '';
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Compared to a fixed allowlist and never rendered or persisted.
				$action = is_string( $request_action ) ? (string) wp_unslash( $request_action ) : '';
			}
		}

		return in_array(
			$action,
			[ 'confirm_admin_email', 'confirmaction', 'exit_recovery_mode', 'logout', 'postpass' ],
			true
		);
	}

	private function should_use_private_login_url( bool $force_reauth ): bool {
		if ( function_exists( 'wp_is_recovery_mode' ) && wp_is_recovery_mode() ) {
			return true;
		}

		if ( ! $force_reauth ) {
			return false;
		}

		$user = wp_get_current_user();

		return $user instanceof WP_User && $user->exists() && UserService::is_native_only( $user );
	}

	private function is_logged_in_admin_email_confirmation(): bool {
		$core_action = $GLOBALS['action'] ?? null;

		return 'confirm_admin_email' === $core_action
			&& is_user_logged_in()
			&& current_user_can( 'manage_options' );
	}

	/** @return array<string, mixed> */
	private static function advanced_options(): array {
		$options = get_option( 'pinova_advanced', [] );

		return is_array( $options ) ? $options : [];
	}

	/** @param array<string, mixed> $options */
	private static function persisted_slug_from_options( array $options ): string {
		if ( ! isset( $options['native_login_slug'] ) || ! is_string( $options['native_login_slug'] ) ) {
			return '';
		}

		return self::normalize_slug( $options['native_login_slug'], '' );
	}

	/** @param array<string, mixed> $options */
	private static function effective_slug_for_options( array $options ): string {
		$fallback = self::default_slug();

		if ( defined( 'PINOVA_NATIVE_LOGIN_SLUG' ) ) {
			return self::normalize_slug( (string) constant( 'PINOVA_NATIVE_LOGIN_SLUG' ), $fallback );
		}

		$persisted = self::persisted_slug_from_options( $options );

		return '' !== $persisted ? $persisted : $fallback;
	}

	private static function plugin_version(): string {
		return defined( 'PINOVA_VERSION' ) ? (string) constant( 'PINOVA_VERSION' ) : 'unknown';
	}

	private static function slug_hmac( string $slug ): string {
		$auth_key  = defined( 'AUTH_KEY' ) ? (string) constant( 'AUTH_KEY' ) : '';
		$auth_salt = defined( 'AUTH_SALT' ) ? (string) constant( 'AUTH_SALT' ) : '';
		$secret    = $auth_key . '|' . $auth_salt;

		if ( '|' === $secret ) {
			$secret = self::default_slug();
		}

		return hash_hmac( 'sha256', 'native-login-gate:' . $slug, $secret );
	}

	/** @param array<string, mixed> $record */
	private static function activation_record_matches( array $record, string $slug ): bool {
		$activated_at = $record['activated_at'] ?? null;

		return self::record_binding_matches( $record, $slug )
			&& is_numeric( $activated_at )
			&& (int) $activated_at > 0;
	}

	/** @param array<string, mixed> $record */
	private static function record_binding_matches( array $record, string $slug ): bool {
		$slug_hmac = $record['slug_hmac'] ?? null;
		$version   = $record['plugin_version'] ?? null;
		$user_id   = $record['user_id'] ?? null;

		return is_string( $slug_hmac )
			&& 1 === preg_match( '/\A[a-f0-9]{64}\z/', $slug_hmac )
			&& hash_equals( self::slug_hmac( $slug ), $slug_hmac )
			&& is_string( $version )
			&& hash_equals( self::plugin_version(), $version )
			&& is_numeric( $user_id )
			&& (int) $user_id > 0;
	}

	/** @return array<string, int|string>|null */
	private static function consume_arm( string $slug, int $now, int $current_user_id ): ?array {
		$arm = get_option( self::ARM_OPTION, null );

		if (
			! is_array( $arm )
			|| ! self::record_binding_matches( $arm, $slug )
			|| (int) ( $arm['user_id'] ?? 0 ) !== $current_user_id
			|| ! isset( $arm['expires_at'] )
			|| ! is_numeric( $arm['expires_at'] )
			|| (int) $arm['expires_at'] <= $now
		) {
			self::clear_arm();
			return null;
		}

		if ( ! delete_option( self::ARM_OPTION ) ) {
			return null;
		}

		return [
			'slug_hmac'      => (string) $arm['slug_hmac'],
			'user_id'        => (int) $arm['user_id'],
			'plugin_version' => (string) $arm['plugin_version'],
			'activated_at'   => $now,
		];
	}

	private static function clear_arm(): void {
		delete_option( self::ARM_OPTION );
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

	private function rewrite_login_url( string $value ): string {
		if ( false === stripos( $value, 'wp-login.php' ) ) {
			return $value;
		}

		$rewritten = preg_replace_callback(
			'~https?://[^\s<>"\']*wp-login\.php[^\s<>"\']*~i',
			function ( array $matches ): string {
				return $this->rewrite_single_login_url( $matches[0] );
			},
			$value
		);

		if ( is_string( $rewritten ) && $rewritten !== $value ) {
			return $rewritten;
		}

		return $this->rewrite_single_login_url( $value );
	}

	private function rewrite_single_login_url( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( 'wp-login.php' !== basename( $path ) ) {
			return $url;
		}

		$query       = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$fragment    = (string) wp_parse_url( $url, PHP_URL_FRAGMENT );
		$private_url = self::url();

		if ( '' !== $query ) {
			$private_url .= '?' . $query;
		}

		if ( '' !== $fragment ) {
			$private_url .= '#' . $fragment;
		}

		return $private_url;
	}
}
