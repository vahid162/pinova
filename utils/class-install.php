<?php

namespace Nabik\Utils\V1;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Nabik\Utils\V1\Install' ) ) {

	abstract class Install {

		const VERSION = '1.2.0';

		/**
		 * The legacy installer no longer performs request- or filesystem-based
		 * activation detection. Concrete plugins own their explicit lifecycle.
		 */
		public function __construct() {
		}

		public function run(): void {
			static::tasks();
		}

		abstract public function tasks(): void;
	}
}
