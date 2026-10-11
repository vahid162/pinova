<?php

namespace Pinova\Integrations;

use Throwable;

/** Saved coordination intent is independent of the currently available APIs. */
final class IntegrationSettings {

	public const OPTION  = 'pinova_integrations';
	public const STATES  = [ 'off', 'active', 'partial', 'unavailable' ];
	public const REASONS = [ 'off', 'ready', 'missing_plugin', 'missing_routing_api', 'missing_membership_api', 'missing_rest_api', 'missing_conversion_api', 'missing_forum_role_api', 'unsupported_extension', 'inspection_failed' ];

	/**
	 * @param mixed $value Submitted or persisted settings.
	 * @return array<string, string>
	 */
	public static function normalize( $value ): array {
		$value = is_array( $value ) ? $value : [];
		$clean = [];
		foreach ( [ 'wpforo_enabled', 'dokan_enabled' ] as $key ) {
			$clean[ $key ] = in_array( $value[ $key ] ?? null, [ true, 1, '1' ], true ) ? '1' : '0';
		}
		return $clean;
	}

	public static function requested( string $slug ): bool {
		return in_array( $slug, [ 'wpforo', 'dokan' ], true ) && '1' === self::normalize( get_option( self::OPTION, [] ) )[ $slug . '_enabled' ];
	}

	/** Routing remains available when a separate membership/conversion operation is unavailable. */
	public static function enabled( string $slug ): bool {
		return self::requested( $slug ) && self::capable( $slug, 'routing' );
	}

	public static function capable( string $slug, string $feature ): bool {
		$snapshot = self::snapshot();
		return true === ( $snapshot[ $slug ]['features'][ $feature ] ?? false );
	}

	public static function status( string $slug ): string {
		return self::snapshot()[ $slug ]['state'] ?? 'unavailable';
	}

	/**
	 * Fresh loaded objects only; never initialize a dependency or cache an early absence.
	 * Versions describe the observation and never decide whether an API can be used.
	 *
	 * @return array<string, mixed>
	 */
	public static function snapshot(): array {
		$forum        = null;
		$forum_failed = false;
		$forum_loaded = false;
		$forum_route  = false;
		$membership   = false;
		$rest         = false;
		$groups       = false;
		try {
			$forum        = function_exists( 'WPF' ) ? WPF() : null;
			$forum_loaded = is_object( $forum );
			$forum_route  = $forum_loaded && self::accepts( 'wpforo_url', 2 ) && self::accepts( 'wpforo_home_url', 0 );
			$membership   = $forum_loaded && self::accepts( 'wpforo_setting', 2 )
				&& self::accepts( [ $forum->member ?? null, 'get_status' ], 1 )
				&& self::accepts( [ $forum->member ?? null, 'synchronize_user' ], 1 )
				&& self::accepts( [ $forum->member ?? null, 'update_profile_fields' ], 3 )
				&& self::accepts( [ $forum->usergroup ?? null, 'can' ], 1 )
				&& self::accepts( [ $forum->perm ?? null, 'user_can_manage_user' ], 2 )
				&& is_string( $forum->tables->profiles ?? null ) && '' !== $forum->tables->profiles;
			$rest         = $forum_loaded && isset( $forum->member ) && self::accepts( [ $forum, 'init' ], 0 );
			$groups       = ! function_exists( 'WPF' ) || ( $forum_loaded && self::accepts( 'wpforo_update_usergroup_on_role_change', 3 )
				&& self::role_hook( 'add_user_role', 2 ) && self::role_hook( 'set_user_role', 3 )
				&& self::accepts( [ $forum->member ?? null, 'init_current_user' ], 0 )
				&& self::accepts( [ $forum->ram_cache ?? null, 'reset' ], 1 ) );
		} catch ( Throwable $error ) {
			unset( $error );
			$forum_failed = true;
			$forum_route  = false;
			$membership   = false;
			$rest         = false;
			$groups       = false;
		}
		$dokan_loaded = function_exists( 'dokan' );
		$dokan_route  = $dokan_loaded && self::accepts( 'dokan_is_user_seller', 1 )
			&& self::accepts( 'dokan_get_navigation_url', 0 ) && self::accepts( 'wc_get_account_endpoint_url', 1 );
		$pro          = defined( 'DOKAN_PRO_PLUGIN_VERSION' ) || class_exists( 'Dokan_Pro', false );
		$conversion   = $dokan_route && ! $pro && $groups && self::accepts( 'dokan_user_update_to_seller', 2 ) && self::migration_api();
		$versions     = [
			'woocommerce' => self::version( 'WC_VERSION' ),
			'wpforo'      => self::version( 'WPFORO_VERSION' ),
			'dokan'       => self::version( 'DOKAN_PLUGIN_VERSION' ),
			'dokan_pro'   => self::version( 'DOKAN_PRO_PLUGIN_VERSION' ),
		];
		return [
			'observed_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'wpforo'      => self::entry(
				'wpforo',
				$forum_loaded,
				[
					'routing'    => $forum_route,
					'membership' => $membership,
					'rest'       => $rest,
				],
				$forum_failed ? 'inspection_failed' : ( ! $membership ? 'missing_membership_api' : ( $rest ? 'ready' : 'missing_rest_api' ) ),
				[ 'wpforo' => $versions['wpforo'] ]
			),
			'dokan'       => self::entry(
				'dokan',
				$dokan_loaded,
				[
					'routing'      => $dokan_route,
					'conversion'   => $conversion,
					'forum_groups' => $groups,
					'reports'      => $dokan_loaded && function_exists( 'WC' ),
				],
				$conversion ? 'ready' : ( $pro ? 'unsupported_extension' : ( $groups ? 'missing_conversion_api' : 'missing_forum_role_api' ) ),
				$versions
			),
		];
	}

	/** Callable existence plus an invocable signature; semantic writes have their own operation guards. */
	private static function accepts( $callback, int $arguments ): bool {
		if ( ! is_callable( $callback ) ) {
			return false;
		}
		try {
			$reflection = is_array( $callback ) ? new \ReflectionMethod( $callback[0], $callback[1] ) : new \ReflectionFunction( $callback );
			return $reflection->getNumberOfRequiredParameters() <= $arguments;
		} catch ( Throwable $error ) {
			return false;
		}
	}

	/** Declared API is observable in admin/cron requests without constructing a frontend service. */
	private static function migration_api(): bool {
		try {
			$class = 'WeDevs\Dokan\Frontend\MyAccount\BecomeAVendor';
			if ( ! class_exists( $class ) ) {
				return false;
			}
			$method = new \ReflectionMethod( $class, 'become_a_seller_form_handler' );
			return $method->isPublic() && 0 === $method->getNumberOfRequiredParameters();
		} catch ( Throwable $error ) {
			return false;
		}
	}

	/** Actual callback/order is checked at mutation time, never required on admin/cron reads. */
	public static function conversion_boundary(): bool {
		global $wp_filter;
		$found = 0;
		foreach ( $wp_filter['template_redirect']->callbacks ?? [] as $priority => $callbacks ) {
			foreach ( $callbacks as $entry ) {
				$callback = $entry['function'];
				if ( ! is_array( $callback ) || ! is_object( $callback[0] )
					|| ! is_a( $callback[0], 'WeDevs\Dokan\Frontend\MyAccount\BecomeAVendor' ) || 'become_a_seller_form_handler' !== $callback[1] ) {
					continue;
				}
				// The proof guard precedes native handlers; cleanup at 11 must run after mutation.
				if ( $priority <= -PHP_INT_MAX || $priority >= 11 || ! self::accepts( $callback, 0 ) ) {
					return false;
				}
				++$found;
			}
		}
		return 1 === $found;
	}

	/** Only the identified role callback may be scoped; duplicate registrations are ambiguous. */
	private static function role_hook( string $hook, int $arguments ): bool {
		global $wp_filter;
		$found = 0;
		foreach ( $wp_filter[ $hook ]->callbacks ?? [] as $callbacks ) {
			foreach ( $callbacks as $entry ) {
				if ( 'wpforo_update_usergroup_on_role_change' === $entry['function']
					|| \Pinova\Integrations\Dokan\Load::is_scoped_role_callback( $hook, $entry['function'], (int) $entry['accepted_args'] ) ) {
					if ( $arguments !== (int) $entry['accepted_args'] ) {
						return false;
					}
					++$found;
				}
			}
		}
		return 1 === $found;
	}

	private static function version( string $constant ): string {
		$value = defined( $constant ) ? constant( $constant ) : '';
		return is_string( $value ) && strlen( $value ) <= 48 && preg_match( '/\A[0-9]+(?:\.[0-9]+){1,3}(?:[-+][0-9A-Za-z.-]+)?\z/D', $value ) ? $value : '';
	}

	private static function entry( string $slug, bool $loaded, array $features, string $operation_reason, array $versions ): array {
		$requested = self::requested( $slug );
		$reason    = 'inspection_failed' === $operation_reason ? $operation_reason : ( ! $loaded ? 'missing_plugin' : ( ! $features['routing'] ? 'missing_routing_api' : $operation_reason ) );
		$state     = ! $features['routing'] ? 'unavailable' : ( 'ready' === $reason ? 'active' : 'partial' );
		return [
			'requested' => $requested,
			'state'     => $requested ? $state : 'off',
			'reason'    => $requested ? $reason : 'off',
			'features'  => $features,
			'versions'  => $versions,
		];
	}

	/** Administrator summary; no third-party settings or account state are changed. */
	public static function description( string $slug ): string {
		$entry    = self::snapshot()[ $slug ] ?? [
			'state'  => 'unavailable',
			'reason' => 'missing_plugin',
		];
		$versions = [];
		foreach ( $entry['versions'] ?? [] as $plugin => $version ) {
			$versions[] = $plugin . ': ' . ( '' !== $version ? $version : '—' );
		}
		return self::description_for( $entry ) . ( $versions ? ' نسخه‌های مشاهده‌شده؛ فقط اطلاعات محیط: ' . implode( '، ', $versions ) . '.' : '' );
	}

	/** Reuse one captured read-only observation throughout administrator reports. */
	public static function description_for( array $entry ): string {
		$labels  = [
			'off'         => 'هماهنگی خاموش است.',
			'active'      => 'هماهنگی روشن و فعال است.',
			'partial'     => 'مسیریابی ورود فعال است؛ برخی عملیات هماهنگی در دسترس نیستند.',
			'unavailable' => 'هماهنگی روشن است، اما رابط لازم برای مسیریابی در دسترس نیست.',
		];
		$reasons = [
			'off'                    => '',
			'ready'                  => '',
			'missing_plugin'         => 'افزونهٔ لازم نصب و فعال نیست.',
			'missing_routing_api'    => 'رابط لازم برای ورود و ادامهٔ مسیر در دسترس نیست.',
			'missing_membership_api' => 'رابط لازم برای مدیریت امن عضویت انجمن در دسترس نیست.',
			'missing_rest_api'       => 'رابط راه‌اندازی بومی انجمن برای رندر REST در دسترس نیست.',
			'missing_conversion_api' => 'مرز امن فرم بومی «فروشنده شوید» در دسترس نیست.',
			'missing_forum_role_api' => 'رابط لازم برای حفظ گروه‌های انجمن هنگام فروشنده‌شدن در دسترس نیست.',
			'unsupported_extension'  => 'تبدیل فروشنده با افزونهٔ Dokan Pro هنوز بررسی نشده است.',
			'inspection_failed'      => 'بررسی رابط‌های افزونه کامل نشد؛ دوباره بررسی کنید.',
		];
		return trim( $labels[ $entry['state'] ] . ' ' . $reasons[ $entry['reason'] ] );
	}
}
