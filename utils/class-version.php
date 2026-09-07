<?php

namespace Nabik\Utils\V1;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Nabik\Utils\V1\Version' ) ) {

	/**
	 * Class Nabik_Net_Version
	 *
	 * @author  Nabik
	 */
	class Version {

		const VERSION = '1.0.0';

		protected string $current_version;

		protected string $default_version = '1.0.0';

		protected string $version_key;

		public function __construct() {
			global $pagenow;

			if ( ! in_array( $pagenow, [ 'index.php', 'update.php', 'plugins.php', 'plugin-install.php' ] ) ) {
				return;
			}

			if ( empty( $this->current_version ) || empty( $this->default_version ) ) {
				wp_die( sprintf( 'Class %s was not initiate properties.', esc_html( get_called_class() ) ) );
			}

			[ , $minor, $patch ] = explode( '.', $this->current_version );

			if ( $minor >= 10 || $patch >= 10 ) {
				wp_die( sprintf( 'Invalid minor and patch (%s) in %s.', $this->current_version, esc_html( get_called_class() ) ) );
			}

			if ( empty( $this->version_key ) ) {
				$this->version_key = strtolower( str_replace( [ '/', '\\' ], '_', get_called_class() ) );
			}

			add_action( 'admin_init', [ $this, 'migrate' ], 110 );
		}

		public function install() {

			$installed_version = get_option( $this->version_key );

			if ( empty( $installed_version ) ) {
				update_option( $this->version_key, $this->current_version, false );
			}
		}

		public function migrate(): void {
			global $wpdb;

			$wpdb->show_errors = false;

			$directory = dirname( ( new \ReflectionClass( $this ) )->getFileName(), 2 );

			if ( file_exists( $directory . '/.activated' ) ) {

				wp_delete_file( $directory . '/.activated' );

				$this->install();

			}

			$installed_version = get_option( $this->version_key, $this->default_version );

			if ( $installed_version == $this->current_version ) {
				return;
			}

			$installed_version = (int) str_replace( '.', '', $installed_version );
			$current_version   = (int) str_replace( '.', '', $this->current_version );

			for ( $version = $installed_version + 1; $version <= $current_version; $version ++ ) {
				if ( method_exists( $this, "update_{$version}" ) ) {

					try {
						$this->{"update_{$version}"}();

						$patch = $version % 10;
						$minor = ( floor( $version / 10 ) % 10 );
						$major = floor( $version / 100 );

						update_option( $this->version_key, $major . '.' . $minor . '.' . $patch, false );

					} catch ( Exception $e ) {
						wp_die( $e->getMessage() );
					}

				}
			}

			if ( method_exists( $this, 'updated' ) ) {
				$this->updated();
			}
		}

	}

}
