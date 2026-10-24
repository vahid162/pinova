<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Illuminate\Database\Eloquent\Model;
use WP_UnitTestCase;

final class DatabaseConnectionIntegrationTest extends WP_UnitTestCase {

	/** @dataProvider wordpress_hosts */
	public function test_real_wordpress_parser_supplies_connection_settings( string $host, array $expected ): void {
		$method = new \ReflectionMethod( \Nabik_Net_Database::class, 'host_settings' );
		$method->setAccessible( true );
		self::assertSame( $expected, $method->invoke( null, $host ) );
	}

	public static function wordpress_hosts(): array {
		$ipv6 = extension_loaded( 'mysqlnd' ) ? '[::1]' : '::1';

		return [
			'hostname' => [ 'db.example', [ 'host' => 'db.example' ] ],
			'port'     => [ '127.0.0.1:3307', [ 'host' => '127.0.0.1', 'port' => 3307 ] ],
			'socket'   => [ 'localhost:/tmp/mysql.sock', [ 'host' => 'localhost', 'unix_socket' => '/tmp/mysql.sock' ] ],
			'ipv6'     => [ '::1', [ 'host' => $ipv6 ] ],
			'ipv6 port' => [ '[::1]:3307', [ 'host' => $ipv6, 'port' => 3307 ] ],
		];
	}

	public function test_reinitialization_preserves_the_eager_resolver_and_connection(): void {
		$resolver   = Model::getConnectionResolver();
		$connection = \Nabik_Net_Database::DB();
		self::assertNotNull( $resolver );

		new \Nabik_Net_Database();

		self::assertSame( $resolver, Model::getConnectionResolver() );
		self::assertSame( $connection, \Nabik_Net_Database::DB() );
	}
}
