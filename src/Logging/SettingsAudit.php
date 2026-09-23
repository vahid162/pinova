<?php

namespace Pinova\Logging;

use Throwable;

/** Audit persisted administrator settings changes without retaining their values. */
final class SettingsAudit {

	/** Only first-party persisted fields may appear as audit keys. */
	private const FIELDS = [
		'pinova_general'             => [ 'wordpress_users_can_register', 'wordpress_default_role', 'woocommerce_checkout_registration_required' ],
		'pinova_sms'                 => [ 'gateway', 'message_code', 'test_mobile' ],
		'pinova_gateway_maxsms'      => [ 'api_key', 'sender' ],
		'pinova_gateway_melipayamak' => [ 'username', 'password', 'sender' ],
		'pinova_gateway_panelchi'    => [ 'api_key', 'source_number' ],
		'pinova_messengers'          => [ 'bale_api_token', 'bale_bot_id' ],
		'pinova_zohal'               => [ 'api_key' ],
		'pinova_design'              => [ 'logo' ],
		'pinova_logging'             => [ 'minimum_level', 'retention_days', 'diagnostic_until' ],
		'pinova_advanced'            => [
			'mobile_possible_meta_keys',
			'code_length',
			'default_login_method',
			'native_only_roles',
			'native_login_slug',
			'block_native_login',
			'trusted_proxy_header',
			'trusted_proxy_cidrs',
			'delete_data_on_uninstall',
		],
	];

	private const MAX_EVENTS_PER_REQUEST = 20;
	private const MAX_KEYS               = 20;

	private static bool $recording = false;
	private static int $emitted    = 0;

	/**
	 * WordPress fires this only after a stored option actually changes.
	 *
	 * @param mixed $old_value
	 * @param mixed $value
	 */
	public static function updated( string $option, $old_value, $value ): void {
		self::record( $option, $old_value, $value );
	}

	/** @param mixed $value */
	public static function added( string $option, $value ): void {
		self::record( $option, [], $value );
	}

	/**
	 * @param mixed $old_value
	 * @param mixed $value
	 * @return string[]
	 */
	public static function changed_keys( string $option, $old_value, $value ): array {
		if ( 'pinova_delete_data_on_uninstall' === $option ) {
			return $old_value !== $value ? [ 'delete_data_on_uninstall' ] : [];
		}

		if ( ! isset( self::FIELDS[ $option ] ) ) {
			return [];
		}

		$old_value = is_array( $old_value ) ? $old_value : [];
		$value     = is_array( $value ) ? $value : [];
		$changed   = [];

		foreach ( self::FIELDS[ $option ] as $key ) {
			$had_key = array_key_exists( $key, $old_value );
			$has_key = array_key_exists( $key, $value );
			if ( $had_key !== $has_key || ( $had_key && $old_value[ $key ] !== $value[ $key ] ) ) {
				$changed[] = $key;
			}
		}

		return $changed;
	}

	/**
	 * Permit only documented first-party setting names in SafeContext.
	 *
	 * @param array<mixed> $keys
	 * @return string[]
	 */
	public static function safe_keys( array $keys ): array {
		$allowed = array_merge( ...array_values( self::FIELDS ) );
		$safe    = [];

		foreach ( array_slice( $keys, 0, self::MAX_KEYS ) as $key ) {
			if ( is_string( $key ) && in_array( $key, $allowed, true ) ) {
				$safe[] = $key;
			}
		}

		return array_values( array_unique( $safe ) );
	}

	/**
	 * @param mixed $old_value
	 * @param mixed $value
	 */
	private static function record( string $option, $old_value, $value ): void {
		if ( self::$recording || self::$emitted >= self::MAX_EVENTS_PER_REQUEST ) {
			return;
		}
		if ( ! isset( self::FIELDS[ $option ] ) && 'pinova_delete_data_on_uninstall' !== $option ) {
			return;
		}

		try {
			if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$actor = get_current_user_id();
			$keys  = self::changed_keys( $option, $old_value, $value );
			if ( $actor <= 0 || ! $keys ) {
				return;
			}

			self::$recording = true;
			++self::$emitted;
			Logger::instance()->audit(
				'notice',
				'settings.updated',
				[
					'operation'    => 'pinova_delete_data_on_uninstall' === $option ? 'pinova_advanced' : $option,
					'result'       => 'success',
					'user_id'      => $actor,
					'changed_keys' => $keys,
				]
			);
		} catch ( Throwable $throwable ) {
			// Audit failure must never interrupt a settings save.
			unset( $throwable );
		} finally {
			self::$recording = false;
		}
	}
}
