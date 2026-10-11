<?php

namespace Pinova\Integrations\WpForo;

use Pinova\Helper;
use Pinova\Integrations\Continuation;
use Pinova\Integrations\IntegrationSettings;
use Pinova\Logging\EventThrottle;
use Pinova\Pinova;
use Pinova\Services\MobileVerificationService;
use Throwable;

/** wpForo membership is separate from the shared WordPress/store session. */
final class Load {
	public const STATE_META = '_pinova_wpforo_membership';

	private static bool $booted         = false;
	private static ?int $writing        = null;
	private static array $suspended     = [];
	private static array $resets        = [];
	private static array $locks         = [];
	private static array $profile_locks = [];

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_filter( 'rest_authentication_errors', [ self::class, 'rest_initialize' ], PHP_INT_MAX );
		add_filter( 'wpforo_login_url', [ self::class, 'login_url' ] );
		add_filter( 'wpforo_register_url', [ self::class, 'login_url' ] );
		add_action( 'wpforo_core_inited', [ self::class, 'route' ], 0 );
		add_action( 'wpforo_action_login', [ self::class, 'route_action' ], 0 );
		add_action( 'wpforo_action_registration', [ self::class, 'route_action' ], 0 );
		add_action( 'pinova/user_registered', [ self::class, 'registered' ] );
		add_action( 'pinova/mobile_verified', [ self::class, 'verified' ] );
		add_filter( 'wpforo_before_update_profile_fields', [ self::class, 'profile_fields' ], PHP_INT_MAX, 2 );
		add_action( 'wpforo_update_profile_fields', [ self::class, 'profile_written' ], PHP_INT_MAX );
		foreach ( [ 'activate', 'unban', 'deactivate', 'ban' ] as $action ) {
			add_action( 'wpforo_after_' . $action . '_user', [ self::class, 'native_status_changed' ], 0 );
		}
		add_filter( 'wpforo_permissions_forum_can', [ self::class, 'forum_can' ], PHP_INT_MAX, 2 );
		add_filter( 'wpforo_add_topic_data_filter', [ self::class, 'posting_data' ], PHP_INT_MAX );
		add_filter( 'wpforo_add_post_data_filter', [ self::class, 'posting_data' ], PHP_INT_MAX );
		add_action( 'pinova/authentication_start', [ self::class, 'authentication_start' ] );
		add_action( 'pinova/authentication_end', [ self::class, 'authentication_end' ] );
		add_action( 'pinova/password_reset_start', [ self::class, 'reset_start' ], 10, 3 );
		add_action( 'pinova/password_reset_end', [ self::class, 'reset_end' ] );
		// These guards remain installed when routing is disabled or its supported dependency is lost.
		add_action( 'wp_login', [ self::class, 'native_login_start' ], -PHP_INT_MAX, 2 );
		add_action( 'wp_login', [ self::class, 'native_login_end' ], PHP_INT_MAX );
		add_action( 'after_password_reset', [ self::class, 'native_reset_start' ], -PHP_INT_MAX );
		add_action( 'after_password_reset', [ self::class, 'native_reset_end' ], PHP_INT_MAX );
	}

	/** wpForo recognizes pretty REST URLs but misses query-based REST dispatch. */
	public static function rest_initialize( $result ) {
		if ( ! is_wp_error( $result ) && wp_is_serving_rest_request() && IntegrationSettings::capable( 'wpforo', 'rest' ) ) {
			try {
				if ( null === ( WPF()->topic ?? null ) ) {
					WPF()->init();
					if ( null === ( WPF()->topic ?? null ) ) {
						self::failure( 'rest_initialization_failed' );
					}
				}
			} catch ( Throwable $error ) {
				self::failure( 'rest_initialization_failed' );
			}
		}
		return $result;
	}

	private static function available(): bool {
		try {
			return function_exists( 'WPF' ) && is_callable( [ WPF()->member ?? null, 'get_status' ] );
		} catch ( Throwable $error ) {
			return false;
		}
	}

	private static function failure( string $reason ): void {
		EventThrottle::log(
			'integration.operation_failed',
			[
				'operation' => 'wpforo',
				'reason'    => $reason,
			]
		);
	}

	/**
	 * Fresh database state can change between calls.
	 * @phpstan-impure
	 */
	private static function status( int $user_id ): string {
		try {
			return self::available() ? (string) WPF()->member->get_status( $user_id ) : 'unknown';
		} catch ( Throwable $error ) {
			self::failure( 'membership_failed' );
			return 'unknown';
		}
	}

	private static function state( int $user_id ): string {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE user_id = %d AND meta_key = %s LIMIT 2', $wpdb->usermeta, $user_id, self::STATE_META ) );
		return $wpdb->last_error || ! is_array( $rows ) || count( $rows ) > 1 ? 'held' : (string) ( $rows[0] ?? '' );
	}

	/** Serialize Pinova activation and native administrator status writes on this site/account. */
	private static function lock( int $user_id ): bool {
		if ( isset( self::$locks[ $user_id ] ) ) {
			++self::$locks[ $user_id ]['depth'];
			return true;
		}
		global $wpdb;
		$key = 'pinova-forum:' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->usermeta . ':' . $user_id ), 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) ) ) {
			return false;
		}
		self::$locks[ $user_id ] = [
			'key'   => $key,
			'depth' => 1,
		];
		return true;
	}

	private static function unlock( int $user_id ): void {
		if ( ! isset( self::$locks[ $user_id ] ) || --self::$locks[ $user_id ]['depth'] > 0 ) {
			return;
		}
		global $wpdb;
		$key = self::$locks[ $user_id ]['key'];
		unset( self::$locks[ $user_id ] );
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
	}

	public static function profile_written( int $user_id ): void {
		if ( ! empty( self::$profile_locks[ $user_id ] ) ) {
			if ( array_pop( self::$profile_locks[ $user_id ] ) ) {
				self::unlock( $user_id );
			}
			if ( [] === self::$profile_locks[ $user_id ] ) {
				unset( self::$profile_locks[ $user_id ] );
			}
		}
	}

	private static function protected_user( int $user_id ): bool {
		return '' !== self::state( $user_id ) || ( IntegrationSettings::requested( 'wpforo' ) && self::available() );
	}

	/** Never enroll historical users on login, proof, or an integration toggle. */
	public static function registered( int $user_id ): void {
		if ( ! IntegrationSettings::requested( 'wpforo' ) || '' !== self::state( $user_id ) ) {
			return;
		}
		// Persist ownership first: even an interrupted profile write must deny forum posting.
		if ( ! add_user_meta( $user_id, self::STATE_META, 'pending', true ) ) {
			return;
		}
		if ( ! IntegrationSettings::capable( 'wpforo', 'membership' ) ) {
			self::failure( 'membership_failed' );
			return;
		}
		try {
			WPF()->member->synchronize_user( $user_id );
			$status = self::status( $user_id );
			if ( ! in_array( $status, [ 'active', 'inactive' ], true ) ) {
				update_user_meta( $user_id, self::STATE_META, 'held' );
				return;
			}
			self::write_status( $user_id, 'inactive', 'active', 'pending' );
		} catch ( Throwable $error ) {
			self::failure( 'membership_failed' );
		}
	}

	/** Keep native filters, cache invalidation and hooks, but make our status write conditional. */
	private static function write_status( int $user_id, string $status, string $previous, string $state ): bool {
		if ( ! IntegrationSettings::capable( 'wpforo', 'membership' ) ) {
			self::failure( 'membership_failed' );
			return false;
		}
		global $wpdb;
		$table       = WPF()->tables->profiles;
		$expected    = $wpdb->prepare( 'UPDATE %i SET `status` = %s WHERE `userid` = %d', $table, $status, $user_id );
		$conditional = $wpdb->prepare(
			'UPDATE %i AS p INNER JOIN %i AS m ON m.user_id = p.userid AND m.meta_key = %s SET p.status = %s WHERE p.userid = %d AND p.status = %s AND m.meta_value = %s',
			$table,
			$wpdb->usermeta,
			self::STATE_META,
			$status,
			$user_id,
			$previous,
			$state
		);
		$matched     = false;
		$rejected    = false;
		// wpForo exposes no conditional-update argument. Scope the guard to its profile-write boundary.
		// Added fields or a changed statement must fail closed, never fall through unconditionally.
		$guard    = static function ( string $query ) use ( $table, $expected, $conditional, &$matched, &$rejected ): string {
			global $wp_current_filter;
			if ( 1 !== count( array_keys( (array) $wp_current_filter, 'query', true ) ) || ! self::profile_write( $query, $table ) ) {
				return $query;
			}
			if ( $rejected || $query !== $expected ) {
				$rejected = true;
				return 'SELECT 0';
			}
			$matched = true;
			return $conditional;
		};
		$arm      = static function ( array $fields ) use ( $guard ): array {
			add_filter( 'query', $guard, PHP_INT_MAX );
			return $fields;
		};
		$complete = static function ( int $written_user, array $data, $result ) use ( $user_id, $guard, $arm, &$matched, &$rejected ): void {
			unset( $data );
			if ( $written_user === $user_id && $matched && ! $rejected && 1 === $result ) {
				// Only the native successful-write boundary releases retries and later admin hooks.
				remove_filter( 'query', $guard, PHP_INT_MAX );
				remove_filter( 'wpforo_before_update_profile_fields', $arm, PHP_INT_MAX );
			}
		};

		self::$writing = $user_id;
		$depth         = count( self::$profile_locks[ $user_id ] ?? [] );
		add_filter( 'wpforo_before_update_profile_fields', $arm, PHP_INT_MAX );
		add_action( 'wpforo_update_profile_fields', $complete, -PHP_INT_MAX, 3 );
		try {
			$result = WPF()->member->update_profile_fields( $user_id, [ 'status' => $status ], false );
			if ( $rejected || ! $matched ) {
				self::failure( 'profile_write_changed' );
			} elseif ( false === $result ) {
				self::failure( 'membership_failed' );
			}
			return $matched && ! $rejected && 1 === $result;
		} catch ( Throwable $error ) {
			self::failure( 'membership_failed' );
			return false;
		} finally {
			remove_filter( 'wpforo_before_update_profile_fields', $arm, PHP_INT_MAX );
			remove_action( 'wpforo_update_profile_fields', $complete, -PHP_INT_MAX );
			remove_filter( 'query', $guard, PHP_INT_MAX );
			$remaining_depth = count( self::$profile_locks[ $user_id ] ?? [] );
			while ( $remaining_depth > $depth ) {
				self::profile_written( $user_id );
				$remaining_depth = count( self::$profile_locks[ $user_id ] ?? [] );
			}
			self::$writing = null;
		}
	}

	/** Identify only writes targeting our profile table, ignoring comments and SQL values. */
	private static function profile_write( string $query, string $table ): bool {
		$values = <<<'SQL'
~'(?:[^'\\]|\\.|'')*'|"(?:[^"\\]|\\.|"")*"|/\*.*?\*/|--(?=[\x00-\x20])[^\r\n]*|\#[^\r\n]*~s
SQL;
		$plain  = preg_replace( $values, ' ', $query );
		if ( null === $plain ) {
			return true;
		}
		if ( ! preg_match( '/\A\s*(UPDATE\b.*?)\bSET\b/is', $plain, $parts ) ) {
			return false;
		}
		$identifier = '(?:`(?:[^`]|``)+`|[A-Za-z0-9_$]+)';
		$target     = '(?:`' . preg_quote( str_replace( '`', '``', $table ), '~' ) . '`|' . preg_quote( $table, '~' ) . '(?![A-Za-z0-9_$]))';
		return 1 === preg_match( '~(?:\b(?:UPDATE|JOIN)\s+|,\s*)(?:(?:LOW_PRIORITY|IGNORE)\s+)*(?:' . $identifier . '\s*\.\s*)?' . $target . '~i', $parts[1] );
	}

	/** Native member buttons and bulk/API methods do not use the profile-fields hook. */
	public static function native_status_changed( int $user_id ): void {
		if ( '' === self::state( $user_id ) ) {
			return;
		}
		$hook = current_filter();
		if ( in_array( $hook, [ 'wpforo_after_deactivate_user', 'wpforo_after_ban_user' ], true ) ) {
			update_user_meta( $user_id, self::STATE_META, 'held' );
			if ( 'wpforo_after_deactivate_user' === $hook ) {
				self::write_status( $user_id, 'inactive', 'active', 'held' );
			}
		} elseif ( self::native_can_approve( $user_id, $hook ) ) {
			update_user_meta( $user_id, self::STATE_META, 'approved' );
		}
	}

	private static function native_can_approve( int $user_id, string $hook ): bool {
		if ( self::administrator() ) {
			return true;
		}
		$actor = get_current_user_id();
		if ( ! $actor || $actor === $user_id || ! is_callable( [ WPF()->usergroup ?? null, 'can' ] )
			|| ! is_callable( [ WPF()->perm ?? null, 'user_can_manage_user' ] ) || ! WPF()->usergroup->can( 'bm' ) ) {
			return false;
		}
		return 'wpforo_after_unban_user' === $hook
			? (bool) WPF()->perm->user_can_manage_user( $actor, $user_id ) : (bool) WPF()->usergroup->can( 'vm' );
	}

	private static function administrator(): bool {
		return current_user_can( 'edit_users' ) || ( 0 < get_current_user_id() && function_exists( 'WPF' )
			&& is_callable( [ WPF()->usergroup ?? null, 'can' ] ) && WPF()->usergroup->can( 'em' ) );
	}

	public static function verified( int $user_id ): void {
		if ( ! IntegrationSettings::requested( 'wpforo' ) || ! IntegrationSettings::capable( 'wpforo', 'membership' ) || ! self::lock( $user_id ) ) {
			return;
		}
		try {
			self::activate_pending( $user_id );
		} finally {
			self::unlock( $user_id );
		}
	}

	private static function activate_pending( int $user_id ): void {
		if ( ! IntegrationSettings::requested( 'wpforo' ) || ! IntegrationSettings::capable( 'wpforo', 'membership' )
			|| 'pending' !== self::state( $user_id ) || ! wpforo_setting( 'authorization', 'user_register' )
			|| wpforo_setting( 'authorization', 'manually_approval' ) || ! MobileVerificationService::is_verified( $user_id ) ) {
			return;
		}
		if ( 'inactive' !== self::status( $user_id ) ) {
			update_user_meta( $user_id, self::STATE_META, 'held', 'pending' );
			return;
		}
		if ( self::write_status( $user_id, 'active', 'inactive', 'pending' ) && 'active' === self::status( $user_id ) ) {
			update_user_meta( $user_id, self::STATE_META, 'verified', 'pending' );
		}
	}

	/** Same-value administrative writes also revoke our authority to activate automatically. */
	public static function profile_fields( array $fields, int $user_id ): array {
		$locked = array_key_exists( 'status', $fields ) && '' !== self::state( $user_id );
		if ( $locked && ! self::lock( $user_id ) ) {
			wp_die( esc_html__( 'وضعیت عضویت در حال تغییر است. لطفاً دوباره تلاش کنید.', 'pinova' ), '', [ 'response' => 409 ] );
		}
		self::$profile_locks[ $user_id ][] = $locked;
		if ( self::$writing === $user_id ) {
			return $fields;
		}
		if ( array_key_exists( 'status', $fields ) && '' !== self::state( $user_id ) && 'unknown' === self::status( $user_id ) ) {
			unset( $fields['status'] );
			self::failure( 'membership_failed' );
		}
		$reset = end( self::$resets );
		if ( $reset && $reset['user_id'] === $user_id && 'email' === $reset['origin']
			&& doing_action( 'after_password_reset' ) && isset( $fields['is_email_confirmed'] ) && 1 === (int) $fields['is_email_confirmed'] ) {
			clean_user_cache( $user_id );
			$user = get_userdata( $user_id );
			if ( ! $user || $reset['email'] !== $user->user_email ) {
				$fields['is_email_confirmed'] = 0;
			}
			// Genuine email recovery can confirm email; it still cannot approve forum membership.
			if ( isset( $fields['status'] ) ) {
				$fields['status'] = self::status( $user_id );
			}
			return $fields;
		}
		if ( array_key_exists( 'status', $fields ) && '' !== self::state( $user_id ) ) {
			$administrator = self::administrator();
			if ( 'active' === $fields['status'] && ! $administrator ) {
				$fields['status'] = self::status( $user_id );
			} else {
				update_user_meta( $user_id, self::STATE_META, 'active' === $fields['status'] ? 'approved' : 'held' );
			}
		}
		return $fields;
	}

	/** This is a forum-only restriction, never a store-wide authentication policy. */
	public static function denied( int $user_id ): bool {
		if ( $user_id < 1 ) {
			return false;
		}
		$state = self::state( $user_id );
		if ( '' === $state ) {
			return IntegrationSettings::requested( 'wpforo' ) && self::available()
				&& 'active' !== self::status( $user_id );
		}
		return ! in_array( $state, [ 'verified', 'approved' ], true ) || ! self::available()
			|| 'active' !== self::status( $user_id ) || ! MobileVerificationService::is_verified( $user_id );
	}

	public static function forum_can( $result, string $permission ) {
		return in_array( $permission, [ 'ct', 'cr', 'ocr' ], true ) && self::denied( get_current_user_id() ) ? false : $result;
	}

	/** Final API boundary also covers wpForo's explicit guest-posting permission shortcut. */
	public static function posting_data( array $data ): array {
		return ( self::denied( get_current_user_id() ) || self::denied( (int) ( $data['userid'] ?? 0 ) ) ) ? [] : $data;
	}

	/** Only the incompatible callback is removed; its exact registration is restored. */
	private static function suspend( string $scope, string $hook, string $method, bool $needed ): void {
		global $wp_filter;
		$removed = [];
		$member  = null;
		try {
			$member = function_exists( 'WPF' ) ? ( WPF()->member ?? null ) : null;
		} catch ( Throwable $error ) {
			unset( $error );
		}
		if ( $needed && isset( $wp_filter[ $hook ] ) ) {
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $entry ) {
					$callback = $entry['function'];
					if ( is_array( $callback ) && is_object( $callback[0] ) && $method === $callback[1]
						&& ( $member === $callback[0] || is_a( $callback[0], 'wpforo\classes\Members' ) ) ) {
						$removed[] = [ $callback, $priority, $entry['accepted_args'] ];
						remove_action( $hook, $callback, $priority );
					}
				}
			}
		}
		self::$suspended[ $scope ][] = [ $hook, $removed ];
	}

	private static function restore( string $scope ): void {
		if ( empty( self::$suspended[ $scope ] ) ) {
			return;
		}
		[ $hook, $removed ] = array_pop( self::$suspended[ $scope ] );
		foreach ( $removed as [ $callback, $priority, $accepted ] ) {
			add_action( $hook, $callback, $priority, $accepted );
		}
	}

	public static function authentication_start( int $user_id ): void {
		self::suspend( 'authentication', 'wp_login', 'wp_login', self::protected_user( $user_id ) );
	}

	public static function authentication_end(): void {
		self::restore( 'authentication' );
	}

	public static function native_login_start( $login, $user ): void {
		unset( $login );
		self::suspend( 'native_login', 'wp_login', 'wp_login', '' !== self::state( (int) $user->ID ) );
	}

	public static function native_login_end(): void {
		self::restore( 'native_login' );
	}

	public static function reset_start( int $user_id, $flow, string $origin = 'unknown' ): void {
		unset( $flow );
		$user   = get_userdata( $user_id );
		$origin = 'email' === $origin && $user && '' !== $user->user_email ? 'email' : 'unknown';

		self::$resets[] = [
			'user_id' => $user_id,
			'origin'  => $origin,
			'email'   => $user ? $user->user_email : '',
		];
		self::suspend( 'reset', 'after_password_reset', 'after_password_reset', 'email' !== $origin );
	}

	public static function reset_end(): void {
		self::restore( 'reset' );
		array_pop( self::$resets );
	}

	public static function native_reset_start( $user ): void {
		$reset = end( self::$resets );
		$email = $reset && $reset['user_id'] === (int) $user->ID && 'email' === $reset['origin'];
		self::suspend( 'native_reset', 'after_password_reset', 'after_password_reset', ! $email && '' !== self::state( (int) $user->ID ) );
	}

	public static function native_reset_end(): void {
		self::restore( 'native_reset' );
	}

	private static function excluded(): array {
		return [ wpforo_url( '', 'login' ), wpforo_url( '', 'register' ), wpforo_url( '', 'cantlogin' ) ];
	}

	public static function continuation( $target ): string {
		return Continuation::validate( $target, wpforo_home_url(), self::excluded() );
	}

	public static function login_url( string $url ): string {
		if ( ! IntegrationSettings::enabled( 'wpforo' ) ) {
			return $url;
		}
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		return Pinova::get_login_url( self::continuation( $query['redirect_to'] ?? '' ) );
	}

	/** Runs before wpForo initializes an auth template, including its logged-in redirects. */
	public static function route(): void {
		if ( ! IntegrationSettings::enabled( 'wpforo' ) || wp_is_serving_rest_request() ) {
			return;
		}
		$path = untrailingslashit( (string) wp_parse_url( WPF()->current_url, PHP_URL_PATH ) );
		foreach ( [ 'login', 'register' ] as $route ) {
			if ( untrailingslashit( (string) wp_parse_url( wpforo_url( '', $route ), PHP_URL_PATH ) ) === $path ) {
				self::route_action();
			}
		}
	}

	/** Priority zero prevents both GET and POST dispatch from reaching native mutations. */
	public static function route_action(): void {
		if ( ! IntegrationSettings::enabled( 'wpforo' ) ) {
			return;
		}
		// Navigation input only; this handler never accepts credentials or creates an account.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
		$target = wp_unslash( $_POST['redirect_to'] ?? $_GET['redirect_to'] ?? '' );
		Helper::redirect_to( Pinova::get_login_url( self::continuation( $target ) ), 'login' );
	}
}
