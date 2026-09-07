<?php

namespace Pinova\Gateways;


use Exception;

abstract class BaseGateway {

	protected string $name;

	protected string $url;

	public string $video_url = '';

	public array $options = [];

	/**
	 * @throws Exception
	 */
	public function __construct() {
		if ( empty( $this->name ) || empty( $this->url ) ) {
			throw new Exception( sprintf( 'Class %s was not initiate properties.', get_called_class() ) );
		}

		$this->options = get_option( $this->option_key(), [] );
	}

	public function title(): string {
		return $this->name . ' - ' . $this->url;
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_url(): string {
		return $this->url;
	}

	/**
	 * @param string $mobile
	 * @param string $message
	 *
	 * @return bool
	 *
	 * @throws Exception
	 */
	abstract public function send( string $mobile, string $message ): bool;

	abstract public function is_enable(): bool;

	abstract public function options(): array;

	public function get_option( string $option_name, $default = null ) {
		return $this->options[ $option_name ] ?? $default;
	}

	/**
	 * Set options manually
	 *
	 * @param string $option_name
	 *
	 * @param mixed  $value
	 *
	 * @return void
	 */
	public function set_option( string $option_name, $value ): void {

		if ( in_array( $option_name, [ 'gateway', 'message_code', 'test_mobile' ] ) ) {
			return;
		}

		$options                 = get_option( $this->option_key(), [] );
		$options[ $option_name ] = $value;

		update_option( $this->option_key(), $options );
	}

	public function option_key(): string {

		$class_name = str_replace( __NAMESPACE__ . '\\', '', get_called_class() );

		return 'pinova_gateway_' . strtolower( $class_name );
	}


	public function is_pattern( string $message ): bool {
		return str_starts_with( $message, 'pattern:' );
	}

	/**
	 * $message format:
	 *
	 * pattern:<PatternCode>
	 * <Var1>:<Val1>
	 * <Var2>:<Val2>
	 * ...
	 * ...
	 *
	 * @param string $message
	 *
	 * @return array
	 */
	public function parse_pattern( string $message ): array {

		$result = [
			'code' => '',
			'vars' => [],
		];

		$message = str_replace( [ "\r\n", "\n", "\\r\\n", "\\n" ], '~', $message );
		$parts   = explode( '~', $message );

		foreach ( $parts as $part ) {

			[ $key, $value ] = explode( ':', $part, 2 );

			$key   = trim( $key, "}{% \n\r\t\v\x00" );
			$value = trim( $value );

			if ( $key === 'pattern' ) {
				$result['code'] = $value;
			} elseif ( strlen( $key ) ) {
				$result['vars'][ $key ] = $value;
			}

		}

		return $result;
	}
}