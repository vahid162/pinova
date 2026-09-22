<?php

namespace Nabik\Utils\V1;

use Throwable;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Nabik\Utils\V1\Version' ) ) {

	class Version {

		const VERSION = '1.1.0';

		protected string $current_version;
		protected string $default_version = '1.0.0';
		protected string $version_key;

		public function __construct() {
			if ( empty( $this->current_version ) || empty( $this->default_version ) ) {
				return;
			}

			[ , $minor, $patch ] = explode( '.', $this->current_version );

			if ( $minor >= 10 || $patch >= 10 ) {
				return;
			}

			if ( empty( $this->version_key ) ) {
				$this->version_key = strtolower( str_replace( [ '/', '\\' ], '_', get_called_class() ) );
			}
		}

		public function install(): void {
			$installed_version = get_option( $this->version_key );

			if ( empty( $installed_version ) ) {
				update_option( $this->version_key, $this->current_version, false );
			}
		}

		/**
		 * Run versioned data migrations synchronously before runtime services boot.
		 *
		 * @return bool True when the installation is ready for this code version.
		 */
		public function migrate(): bool {
			if ( empty( $this->current_version ) || empty( $this->version_key ) ) {
				return false;
			}

			$installed_version = (string) get_option( $this->version_key, $this->default_version );

			if ( version_compare( $installed_version, $this->current_version, '>=' ) ) {
				return true;
			}

			$installed = (int) str_replace( '.', '', $installed_version );
			$current   = (int) str_replace( '.', '', $this->current_version );

			try {
				for ( $version = $installed + 1; $version <= $current; $version++ ) {
					if ( ! method_exists( $this, "update_{$version}" ) ) {
						continue;
					}

					$this->{"update_{$version}"}();

					$patch = $version % 10;
					$minor = floor( $version / 10 ) % 10;
					$major = floor( $version / 100 );

					update_option( $this->version_key, $major . '.' . $minor . '.' . $patch, false );
				}

				if ( method_exists( $this, 'updated' ) ) {
					$this->updated();
				}
			} catch ( Throwable $throwable ) {
				return false;
			}

			return version_compare( (string) get_option( $this->version_key, $this->default_version ), $this->current_version, '>=' );
		}
	}
}
