<?php

use Illuminate\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Builder;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Nabik_Net_Database' ) ) {

	/**
	 * Class Nabik_Net_Database
	 *
	 * @author  Nabik
	 */
	class Nabik_Net_Database {

		const VERSION = '1.0.2';

		public function __construct() {
			global $wpdb;

			if ( ! is_null( Model::getConnectionResolver() ) ) {
				return;
			}

			$settings = [
				'driver'   => 'mysql',
				'host'     => DB_HOST,
				'database' => DB_NAME,
				'username' => DB_USER,
				'password' => DB_PASSWORD,
				'charset'  => DB_CHARSET,
				'prefix'   => $wpdb->prefix,
				'strict'   => false,
			];

			if ( DB_COLLATE ) {
				$settings['collation'] = DB_COLLATE;
			}

			$conn = ( new ConnectionFactory( new Container() ) )->make( $settings );

			$resolver = new ConnectionResolver();
			$resolver->addConnection( 'wpdb', $conn );
			$resolver->setDefaultConnection( 'wpdb' );

			Model::setConnectionResolver( $resolver );

			self::Schema()::defaultStringLength( 191 );
		}

		public static function DB(): ConnectionInterface {
			return Model::getConnectionResolver()->connection();
		}

		public static function Schema(): Builder {
			return self::DB()->getSchemaBuilder();
		}

	}

	new Nabik_Net_Database();
}