<?php

namespace Pinova\Services;

use Pinova\Gateways\BaseGateway;
use Pinova\Pinova;

class SMSService {

	public function __construct() {
		add_action( 'update_option_pinova_sms', [ $this, 'save_gateway_settings' ], 10, 2 );
	}

	public function save_gateway_settings( $old_value, $value ) {

		$gateway = $value['gateway'];

		try {
			$gateway = self::get_gateway_instance( $gateway );
		} catch ( \Exception $e ) {
			return;
		}

		foreach ( $value as $_key => $_value ) {
			$gateway->set_option( $_key, $_value );
		}
	}

	public static function list(): array {

		$dir       = PINOVA_DIR . '/src/Gateways/';
		$namespace = 'Pinova\Gateways';
		$classes   = self::load_dir( $dir, $namespace );

		unset( $classes['BaseGateway'] );

		/** @var BaseGateway[] $gateways */
		$gateways = array_map( function ( $class ) {
			return new $class;
		}, $classes );

		shuffle( $gateways );

		$list = [];

		foreach ( $gateways as $gateway ) {
			$list[ get_class( $gateway ) ] = $gateway->title();
		}

		return $list;
	}

	protected static function load_dir( string $dir, string $namespace ): array {

		$classes = [];

		$gateways = glob( $dir . '*.php' );

		foreach ( $gateways as $gateway ) {

			$gateway = str_replace( $dir, '', $gateway );
			$class   = $namespace . '\\' . str_replace( '.php', '', $gateway );
			$slug    = str_replace( [ $namespace, '\\' ], '', $class );

			$classes[ $slug ] = $class;
		}

		return $classes;
	}

	/**
	 * @throws \Exception
	 */
	public static function get_gateway_instance( ?string $gateway = null ): BaseGateway {

		if ( empty( $gateway ) ) {
			$gateway = Pinova::get_option( 'sms.gateway' );
		}

		if ( is_a( $gateway, BaseGateway::class, true ) ) {
			return new $gateway();
		}

		$gateway = '\Pinova\Gateways\\' . $gateway;

		if ( is_a( $gateway, BaseGateway::class, true ) ) {
			return new $gateway();
		}

		throw new \Exception( sprintf( 'درگاه پیامکی «%s» یافت نشد.', $gateway ) );
	}

	public static function replace_code( string $message, int $code ): string {
		return str_ireplace( [
			'{{code}}',
			'{{otp}}',
		], (string) $code, $message );
	}
}
