<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__ ) . '/' );

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $value ): string {
		return filter_var( (string) $value, FILTER_VALIDATE_EMAIL ) ? (string) $value : '';
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( $value ) {
		return filter_var( (string) $value, FILTER_VALIDATE_EMAIL ) ? (string) $value : false;
	}
}

if ( ! function_exists( 'sanitize_user' ) ) {
	function sanitize_user( $value, $strict = false ): string {
		$pattern = $strict ? '/[^a-zA-Z0-9 _.@-]/' : '/[^a-zA-Z0-9 _+\-.@]/';

		return (string) preg_replace( $pattern, '', (string) $value );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ): string {
		unset( $domain );

		return (string) $text;
	}
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
