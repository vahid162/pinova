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

	class WP_CLI {
		public static function add_command( string $name, callable|string $callback ): void {}
		public static function success( string $message ): void {}
		public static function line( string $message ): void {}
		public static function error( string $message ): never { throw new \RuntimeException( 'CLI terminates on error.' ); }
	}

	class wpforo extends \stdClass {
		public function init(): void {}
	}
	function WPF(): wpforo { return new wpforo(); }
	function wpforo_setting( string $group, string $key ) {}
	function wpforo_url( string $path = '', ?string $route = null ): string { return ''; }
	function wpforo_home_url(): string { return ''; }
	function wpforo_update_usergroup_on_role_change( $userid, $new_role, $old_roles = [] ): void {}
	function dokan_is_user_seller( int $user_id ): bool { return false; }
	function dokan_get_navigation_url( string $name = '' ): string { return ''; }
	function dokan_get_option( string $key, string $section, $default = '' ) { return $default; }

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
}

namespace Automattic\Jetpack {
	class Constants {
		public static function get_constant( string $name ) {
			return null;
		}
	}
}

namespace ElementorPro\Modules\Woocommerce\Documents {
	class Product {
		public function get_post(): ?\WP_Post { return null; }
		public function enqueue_scripts(): void {}
	}
	class Product_Archive extends Product {}
}
