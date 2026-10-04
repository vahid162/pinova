<?php

namespace Pinova\Integrations;

/** Pinova-owned routing switches; pending-account guards must not depend on these. */
final class IntegrationSettings {

	public const OPTION    = 'pinova_integrations';
	private const VERSIONS = [
		'wpforo' => [ '3.2.1', '3.2.2' ],
		'dokan'  => [ '5.1.3', '5.2.1' ],
	];

	/** Exact characterized APIs; this is not a routing or account-approval decision. */
	public static function supports_version( string $slug, string $version ): bool {
		return in_array( $version, self::VERSIONS[ $slug ] ?? [], true );
	}

	/**
	 * Drop unknown fields and fail closed on malformed checkbox values.
	 *
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

	/** Routing eligibility only, never an approval or mobile-proof decision. */
	public static function enabled( string $slug ): bool {
		return 'active' === self::status( $slug );
	}

	/** A bounded read-only status suitable for administrator diagnostics. */
	public static function status( string $slug ): string {
		$dependency = self::dependency_status( $slug, self::versions() );
		if ( 'supported' !== $dependency ) {
			return $dependency;
		}
		$options = self::normalize( get_option( self::OPTION, [] ) );
		return '1' === $options[ $slug . '_enabled' ] ? 'active' : 'off';
	}

	/**
	 * Inspect loaded APIs without loading inactive third-party plugins.
	 *
	 * @return array{woocommerce: string, wpforo: string, dokan: string, dokan_pro: bool}
	 */
	private static function versions(): array {
		return [
			'woocommerce' => function_exists( 'WC' ) && defined( 'WC_VERSION' ) ? (string) constant( 'WC_VERSION' ) : '',
			'wpforo'      => function_exists( 'WPF' ) && defined( 'WPFORO_VERSION' ) ? (string) constant( 'WPFORO_VERSION' ) : '',
			'dokan'       => function_exists( 'dokan' ) && defined( 'DOKAN_PLUGIN_VERSION' ) ? (string) constant( 'DOKAN_PLUGIN_VERSION' ) : '',
			'dokan_pro'   => defined( 'DOKAN_PRO_PLUGIN_VERSION' ) || class_exists( 'Dokan_Pro', false ),
		];
	}

	/**
	 * Limit compatibility to the characterized dependency versions.
	 *
	 * @param array{woocommerce: string, wpforo: string, dokan: string, dokan_pro: bool} $versions Loaded dependencies.
	 */
	private static function dependency_status( string $slug, array $versions ): string {
		if ( ! isset( self::VERSIONS[ $slug ] ) ) {
			return 'unsupported';
		}
		if ( '' === $versions['woocommerce'] || '' === $versions[ $slug ] ) {
			return 'missing';
		}
		if ( '11.1.2' !== $versions['woocommerce'] || ! self::supports_version( $slug, $versions[ $slug ] )
			|| ( 'dokan' === $slug && ( $versions['dokan_pro'] || ( '' !== $versions['wpforo'] && ! self::supports_version( 'wpforo', $versions['wpforo'] ) ) ) ) ) {
			return 'unsupported';
		}
		return 'supported';
	}

	/** Read-only Persian status; no third-party settings or user state are changed. */
	public static function description( string $slug ): string {
		$labels   = [
			'missing'     => 'وابستگی لازم نصب و فعال نیست؛ هماهنگی اجرا نمی‌شود.',
			'unsupported' => 'ترکیب نسخه‌ها پشتیبانی نمی‌شود؛ هماهنگی اجرا نمی‌شود.',
			'off'         => 'وابستگی‌ها سازگارند؛ هماهنگی خاموش است.',
			'active'      => 'هماهنگی فعال است.',
		];
		$versions = self::versions();
		$version  = $versions[ $slug ] ?? '';
		$profile  = 'wpforo' === $slug ? 'wpForo 3.2.1 یا 3.2.2' : 'Dokan Lite 5.1.3 یا 5.2.1 بدون Dokan Pro؛ wpForo فعال نیز باید از نسخه‌های پشتیبانی‌شده باشد';
		return $labels[ self::status( $slug ) ] . ' نسخهٔ جاری: ' . ( $version ?: '—' ) . '. ترکیب پشتیبانی‌شده: ' . $profile . '، WooCommerce 11.1.2.';
	}
}
