<?php

namespace Nabik\Utils\V1;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Nabik\Utils\V1\Install' ) ) {

	/**
	 * Class Nabik_Net_Install
	 *
	 * @author  Nabik
	 */
	abstract class Install {

		const VERSION = '1.1.0';

		public function __construct() {
			global $pagenow;

			if ( ! in_array( $pagenow, [ 'index.php', 'update.php', 'plugins.php', 'plugin-install.php' ] ) ) {
				return;
			}

			add_action( 'admin_init', [ $this, 'run' ], 50 );
		}

		public function run() {
			global $wpdb;

			$directory = dirname( ( new \ReflectionClass( $this ) )->getFileName(), 2 );

			if ( ! file_exists( $directory . '/.activated' ) ) {
				return;
			}

			$wpdb->show_errors = false;

			static::tasks();

			flush_rewrite_rules();
		}

		abstract public function tasks();
	}

}
