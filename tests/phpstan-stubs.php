<?php

namespace {
	const PINOVA_DIR = __DIR__ . '/..';
	const PINOVA_FILE = '';
	const PINOVA_URL = '';
	const PINOVA_VERSION = '1.3.0';
	const DB_HOST = '';
	const DB_NAME = '';
	const DB_USER = '';
	const DB_PASSWORD = '';
	const DB_CHARSET = 'utf8mb4';
	define( 'DB_COLLATE', (string) getenv( 'DB_COLLATE' ) );

	function PWSMS() {
		return null;
	}

	function PWS() {
		return null;
	}

	function PW() {
		return null;
	}

	function rgar( array $array, string $key ) {
		return $array[ $key ] ?? null;
	}

	function whb_get_settings() {
		return null;
	}

	function woodmart_enqueue_js_script( string $handle ): void {}
	function woodmart_enqueue_inline_style( string $handle ): void {}
	function whb_get_dropdowns_color(): string {
		return '';
	}
	function woodmart_woocommerce_installed(): bool {
		return true;
	}

	class GFAPI {
		public static function get_form( int $id ) {
			return null;
		}
	}

	class WP_CLI {
		public static function add_command( string $name, string $class_name ): void {}

		public static function error( string $message ): never {
			throw new \RuntimeException( $message );
		}

		public static function success( string $message ): void {}

		public static function log( string $message ): void {}
	}
}

namespace WP_CLI\Utils {
	function format_items( string $format, array $items, array $fields ): void {}
}

namespace Automattic\Jetpack {
	class Constants {
		public static function get_constant( string $name ) {
			return null;
		}
	}
}
