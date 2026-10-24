<?php

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Builder;

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
				'database' => DB_NAME,
				'username' => DB_USER,
				'password' => DB_PASSWORD,
				'charset'  => DB_CHARSET,
				'prefix'   => $wpdb->prefix,
				'strict'   => false,
			] + self::host_settings( DB_HOST );

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

		/** @return array<string, string|int> */
		private static function host_settings( string $db_host ): array {
			global $wpdb;

			$parsed = $wpdb->parse_db_host( $db_host );
			if ( false === $parsed ) {
				throw new \RuntimeException( 'Unsupported database host configuration.' );
			}

			[ $host, $port, $socket, $is_ipv6 ] = $parsed;
			// Match WordPress: mysqlnd requires brackets around an IPv6 host.
			$settings = [ 'host' => $is_ipv6 && extension_loaded( 'mysqlnd' ) ? '[' . $host . ']' : $host ];
			if ( null !== $port ) {
				$settings['port'] = $port;
			}
			if ( null !== $socket ) {
				$settings['unix_socket'] = $socket;
			}

			return $settings;
		}

		public static function DB(): Connection {
			$connection = Model::getConnectionResolver()->connection();

			if ( ! $connection instanceof Connection ) {
				throw new \RuntimeException( 'Unsupported database connection.' );
			}

			return $connection;
		}

		public static function Schema(): Builder {
			return self::DB()->getSchemaBuilder();
		}
	}

	if (
		isset( $GLOBALS['wpdb'] )
		&& is_object( $GLOBALS['wpdb'] )
		&& defined( 'DB_HOST' )
		&& defined( 'DB_NAME' )
		&& defined( 'DB_USER' )
		&& defined( 'DB_PASSWORD' )
	) {
		new Nabik_Net_Database();
	}
}
