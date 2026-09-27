<?php

namespace Pinova\Services;

use Exception;
use Pinova\Integrations\IntegrationSettings;
use Pinova\Integrations\Continuation;
use Pinova\Objects\Identifier;
use Pinova\Objects\Mobile;
use Throwable;

/** Possession evidence belongs to one account and its current unique mobile. */
final class MobileVerificationService {
	public const PROOF_META   = '_pinova_mobile_proof';
	public const EPOCH_META   = '_pinova_mobile_epoch';
	public const PENDING_META = '_pinova_mobile_flow';

	private static bool $booted    = false;
	private static ?int $assigning = null;
	private static array $locks    = [];

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		foreach ( [ 'add_user_metadata', 'update_user_metadata', 'delete_user_metadata' ] as $hook ) {
			add_filter( $hook, [ self::class, 'before_meta_change' ], 10, 5 );
		}
		foreach ( [ 'added_user_meta', 'updated_user_meta', 'deleted_user_meta' ] as $hook ) {
			add_action( $hook, [ self::class, 'after_meta_change' ], 10, 4 );
		}
		add_shortcode( 'pinova_verify_mobile', [ self::class, 'render' ] );
		add_action( 'template_redirect', [ self::class, 'route' ], 1 );
		add_action( 'woocommerce_account_dashboard', [ self::class, 'render_link' ] );
		add_action( 'woocommerce_before_edit_account_form', [ self::class, 'render_link' ] );
	}

	/** Read physical rows; duplicates and database failures are never evidence. */
	private static function meta( int $user_id, string $key ): ?string {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE user_id = %d AND meta_key = %s LIMIT 2', $wpdb->usermeta, $user_id, $key ) );
		if ( $wpdb->last_error || ! is_array( $rows ) || count( $rows ) > 1 ) {
			throw new Exception( 'Mobile evidence is unavailable.' );
		}
		return [] === $rows ? null : (string) $rows[0];
	}

	/** Resolve the same precedence as Pinova without trusting the metadata cache. */
	public static function current_mobile( int $user_id ): ?string {
		global $wpdb;
		$override = new Mobile( self::meta( $user_id, 'pinova_mobile' ) ?? '' );
		if ( $override->is_valid() ) {
			return $override->get_formatted();
		}
		$login = $wpdb->get_var( $wpdb->prepare( 'SELECT user_login FROM %i WHERE ID = %d', $wpdb->users, $user_id ) );
		if ( $wpdb->last_error || ! is_string( $login ) ) {
			throw new Exception( 'Mobile account is unavailable.' );
		}
		$mobile = new Mobile( $login );
		if ( $mobile->is_valid() ) {
			return $mobile->get_formatted();
		}
		foreach ( array_diff( self::mobile_keys(), [ 'pinova_mobile' ] ) as $key ) {
			$mobile = new Mobile( self::meta( $user_id, $key ) ?? '' );
			if ( $mobile->is_valid() ) {
				return $mobile->get_formatted();
			}
		}
		return null;
	}

	public static function identity_digest( int $user_id, Identifier $identifier ): string {
		return hash_hmac( 'sha256', $user_id . ':' . $identifier->get_type() . ':' . $identifier->get_value(), wp_salt( 'auth' ) );
	}

	/** Obtain a revocation generation before OTP consumption. */
	public static function epoch( int $user_id ): string {
		$epoch = self::meta( $user_id, self::EPOCH_META );
		if ( null === $epoch ) {
			add_user_meta( $user_id, self::EPOCH_META, bin2hex( random_bytes( 16 ) ), true );
			$epoch = self::meta( $user_id, self::EPOCH_META );
		}
		if ( ! is_string( $epoch ) || ! preg_match( '/\A[a-f0-9]{32}\z/', $epoch ) ) {
			throw new Exception( 'Mobile evidence generation is unavailable.' );
		}
		return $epoch;
	}

	public static function revoke( int $user_id ): bool {
		$epoch = bin2hex( random_bytes( 16 ) );
		update_user_meta( $user_id, self::EPOCH_META, $epoch );
		delete_user_meta( $user_id, self::PROOF_META );
		try {
			return $epoch === self::meta( $user_id, self::EPOCH_META ) && null === self::meta( $user_id, self::PROOF_META );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}

	private static function mobile_keys(): array {
		return array_diff( UserService::mobile_possible_meta_keys(), [ self::PROOF_META, self::EPOCH_META, self::PENDING_META ] );
	}

	/** Metadata hooks revoke before and after writes, including delete/re-add. */
	public static function before_meta_change( $check, $user_id, $key, $value, $extra ) {
		if ( in_array( $key, self::mobile_keys(), true ) ) {
			if ( 'delete_user_metadata' === current_filter() && $extra ) {
				return false; // A global deletion cannot safely invalidate bounded per-account evidence.
			}
			if ( 'update_user_metadata' === current_filter() && is_scalar( $value ) ) {
				try {
					if ( (string) $value === self::meta( (int) $user_id, $key ) ) {
						return $check;
					}
				} catch ( Throwable $throwable ) {
					unset( $throwable );
					return false;
				}
			}
		}
		if ( null === $check && self::$assigning !== (int) $user_id && in_array( $key, self::mobile_keys(), true ) ) {
			return self::revoke( (int) $user_id ) ? $check : false;
		}
		return $check;
	}

	public static function after_meta_change( $meta_ids, $user_id, $key, $value ): void {
		unset( $meta_ids, $value );
		if ( self::$assigning !== (int) $user_id && in_array( $key, self::mobile_keys(), true ) ) {
			self::revoke( (int) $user_id );
		}
	}

	public static function is_verified( int $user_id ): bool {
		try {
			$epoch = self::meta( $user_id, self::EPOCH_META );
			$raw   = self::meta( $user_id, self::PROOF_META );
			$proof = null === $raw ? null : json_decode( $raw, true );
			if ( ! is_array( $proof ) || 1 !== ( $proof['version'] ?? null ) || ! is_int( $proof['at'] ?? null )
				|| $proof['at'] > time() || $proof['at'] < 1 || ! is_string( $proof['mac'] ?? null ) || ! is_string( $epoch ) ) {
				return false;
			}
			$identifier = new Identifier( self::current_mobile( $user_id ) ?? '' );
			if ( ! $identifier->is_mobile() || UserService::match( $identifier ) !== $user_id
				|| is_wp_error( AuthenticationPolicy::session( $user_id, 'mobile_verification', $identifier ) ) ) {
				return false;
			}
			$expected = self::signature( $user_id, $identifier, $epoch, $proof['at'] );
			return hash_equals( $expected, $proof['mac'] ) && $epoch === self::meta( $user_id, self::EPOCH_META );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}

	private static function signature( int $user_id, Identifier $identifier, string $epoch, int $timestamp ): string {
		return hash_hmac( 'sha256', 'mobile-proof:1:' . $user_id . ':' . $identifier->get_value() . ':' . $epoch . ':' . $timestamp, wp_salt( 'auth' ) );
	}

	/** Internal successful-OTP boundary; recovery never calls this method. */
	public static function record( int $user_id, Identifier $identifier, string $epoch ): void {
		self::with_lock(
			$user_id,
			static function () use ( $user_id, $identifier, $epoch ): void {
				self::record_locked( $user_id, $identifier, $epoch );
			}
		);
	}

	private static function record_locked( int $user_id, Identifier $identifier, string $epoch ): void {
		if ( ! $identifier->is_mobile() || $epoch !== self::meta( $user_id, self::EPOCH_META )
			|| $identifier->get_value() !== self::current_mobile( $user_id ) || UserService::match( $identifier ) !== $user_id
			|| is_wp_error( AuthenticationPolicy::session( $user_id, 'mobile_verification', $identifier ) ) ) {
			throw new Exception( __( 'تأیید تلفن همراه معتبر نمی‌باشد. دوباره تلاش کنید.', 'pinova' ) );
		}
		$timestamp = time();
		$proof     = wp_json_encode(
			[
				'version' => 1,
				'at'      => $timestamp,
				'mac'     => self::signature( $user_id, $identifier, $epoch, $timestamp ),
			]
		);
		if ( ! is_string( $proof ) ) {
			throw new Exception( 'Mobile evidence could not be encoded.' );
		}
		update_user_meta( $user_id, self::PROOF_META, $proof );
		if ( ! self::is_verified( $user_id ) ) {
			throw new Exception( __( 'تأیید تلفن همراه معتبر نمی‌باشد. دوباره تلاش کنید.', 'pinova' ) );
		}
		do_action( 'pinova/mobile_verified', $user_id );
	}

	public static function can_assign( int $user_id, Identifier $identifier ): bool {
		return $user_id > 0 && $identifier->is_mobile() && ! FirewallService::is_blocked( $identifier->get_value() )
			&& ! is_wp_error( AuthenticationPolicy::session( $user_id, 'mobile_verification' ) )
			&& UserService::mobile_is_available_for_user( $identifier->get_value(), $user_id );
	}

	/** Called only after a purpose-bound, authenticated OTP was atomically consumed. */
	public static function complete( int $user_id, Identifier $identifier, string $epoch ): void {
		self::with_lock(
			$user_id,
			static function () use ( $user_id, $identifier, $epoch ): void {
				self::complete_locked( $user_id, $identifier, $epoch );
			}
		);
	}

	private static function complete_locked( int $user_id, Identifier $identifier, string $epoch ): void {
		if ( $user_id !== get_current_user_id() || ! self::can_assign( $user_id, $identifier ) || $epoch !== self::meta( $user_id, self::EPOCH_META ) ) {
			throw new Exception( __( 'تأیید تلفن همراه معتبر نمی‌باشد.', 'pinova' ) );
		}
		// Rotate before replacement so an eligibility read started on the old number fails.
		$epoch = bin2hex( random_bytes( 16 ) );
		update_user_meta( $user_id, self::EPOCH_META, $epoch );
		delete_user_meta( $user_id, self::PROOF_META );
		if ( $epoch !== self::meta( $user_id, self::EPOCH_META ) ) {
			throw new Exception( 'Mobile identity changed during verification.' );
		}
		self::$assigning = $user_id;
		try {
			update_user_meta( $user_id, 'pinova_mobile', $identifier->get_value() );
		} finally {
			self::$assigning = null;
		}
		self::record( $user_id, $identifier, $epoch );
	}

	/** Serialize proof-only changes with approved privacy erasure, never wait on a busy account. */
	public static function with_lock( int $user_id, callable $callback ) {
		global $wpdb;
		if ( isset( self::$locks[ $user_id ] ) ) {
			return $callback();
		}
		$key = 'pinova-mobile:' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->usermeta . ':' . $user_id ), 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) ) ) {
			throw new Exception( 'Mobile identity is busy.' );
		}
		self::$locks[ $user_id ] = true;
		try {
			return $callback();
		} finally {
			unset( self::$locks[ $user_id ] );
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
		}
	}

	/** Keep one opaque flow and keyed target per account; a different number requires a fresh flow. */
	public static function bind_flow( int $user_id, string $flow, Identifier $identifier ): void {
		$raw     = self::meta( $user_id, self::PENDING_META );
		$old     = null === $raw ? null : json_decode( $raw, true );
		$binding = self::identity_digest( $user_id, $identifier );
		if ( is_array( $old ) && $flow === ( $old['flow'] ?? null ) && $binding !== ( $old['target'] ?? null ) ) {
			throw new Exception( 'Wait for the existing mobile verification flow to expire.' );
		}
		$value = wp_json_encode(
			[
				'flow'   => $flow,
				'target' => $binding,
			]
		);
		update_user_meta( $user_id, self::PENDING_META, $value );
		if ( $value !== self::meta( $user_id, self::PENDING_META ) ) {
			throw new Exception( 'Mobile flow binding could not be persisted.' );
		}
	}

	/** Physical presence only; privacy must not export proof or queue-binding material.
	 * @return array{proof:bool,pending:bool}
	 */
	public static function private_data_present( int $user_id ): array {
		return [
			'proof'   => null !== self::meta( $user_id, self::PROOF_META ),
			'pending' => null !== self::meta( $user_id, self::PENDING_META ),
		];
	}

	/** Cancel before deleting binding; preserve the active-worker barrier on retry. */
	public static function erase_pending( int $user_id ): bool {
		$raw     = self::meta( $user_id, self::PENDING_META );
		$binding = null === $raw ? null : json_decode( $raw, true );
		$flow    = is_array( $binding ) && is_string( $binding['flow'] ?? null ) ? $binding['flow'] : null;
		if ( ! RateLimitService::delete_queued_for_user( $user_id, $flow ) ) {
			return false;
		}
		delete_user_meta( $user_id, self::PENDING_META );
		return null === self::meta( $user_id, self::PENDING_META );
	}

	public static function requires_proof_for_change( int $user_id, string $mobile ): bool {
		$settings = IntegrationSettings::normalize( get_option( IntegrationSettings::OPTION, [] ) );
		if ( ( '1' !== $settings['wpforo_enabled'] && '1' !== $settings['dokan_enabled'] ) || current_user_can( 'manage_options' ) ) {
			return false;
		}
		try {
			return $mobile !== self::current_mobile( $user_id );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return true;
		}
	}

	public static function url( ?string $back_url = null ): string {
		$url = add_query_arg( 'pinova_verify_mobile', '1', home_url( '/' ) );
		if ( null !== $back_url ) {
			// Encode the nested destination once; request parsing performs the corresponding decode.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode
			$url = add_query_arg( 'back_url', urlencode( Continuation::validate( $back_url ) ), $url );
		}
		return $url;
	}

	public static function render_link(): void {
		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'تأیید یا تغییر تلفن همراه', 'pinova' ) . '</a></p>';
	}

	public static function render(): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'برای تأیید تلفن همراه ابتدا وارد حساب خود شوید.', 'pinova' ) . '</p>';
		}
		wp_enqueue_script( 'pinova-mobile-verification', PINOVA_URL . 'assets/js/mobile-verification.js', [], PINOVA_VERSION, true );
		wp_enqueue_style( 'pinova-mobile-verification', PINOVA_URL . 'assets/css/mobile-verification.css', [], PINOVA_VERSION );
		ob_start();
		require PINOVA_DIR . '/templates/mobile-verification.php';
		return (string) ob_get_clean();
	}

	public static function route(): void {
		// This read-only route does not authorize a mutation; REST requires the session nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '1' !== ( $_GET['pinova_verify_mobile'] ?? null ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		nocache_headers();
		status_header( 200 );
		$content = self::render();
		require PINOVA_DIR . '/templates/mobile-verification-page.php';
		exit;
	}
}
