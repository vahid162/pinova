<?php

declare(strict_types=1);

namespace Pinova\Tests\Unit;

use Illuminate\Database\Connectors\MySqlConnector;
use PHPUnit\Framework\TestCase;

final class DatabaseConnectionTest extends TestCase {

	/** @dataProvider parsed_hosts */
	public function test_wordpress_host_parts_produce_the_expected_pdo_dsn( string $raw_host, array $parsed, array $expected, string $dsn ): void {
		$had_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		$original = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new class( $raw_host, $parsed ) {
			private string $expected;
			private array $parsed;
			public array $calls = [];
			public function __construct( string $expected, array $parsed ) {
				$this->expected = $expected;
				$this->parsed   = $parsed;
			}
			public function parse_db_host( string $raw_host ): array {
				TestCase::assertSame( $this->expected, $raw_host );
				$this->calls[] = $raw_host;
				return $this->parsed;
			}
		};

		try {
			$method = new \ReflectionMethod( \Nabik_Net_Database::class, 'host_settings' );
			$method->setAccessible( true );
			$config = $method->invoke( null, $raw_host );
			self::assertSame( $expected, $config );
			self::assertSame( [ $raw_host ], $GLOBALS['wpdb']->calls );

			$dsn_method = new \ReflectionMethod( MySqlConnector::class, 'getDsn' );
			$dsn_method->setAccessible( true );
			self::assertSame( $dsn, $dsn_method->invoke( new MySqlConnector(), $config + [ 'database' => 'synthetic_db' ] ) );
		} finally {
			if ( $had_wpdb ) {
				$GLOBALS['wpdb'] = $original;
			} else {
				unset( $GLOBALS['wpdb'] );
			}
		}
	}

	public static function parsed_hosts(): array {
		$ipv6 = extension_loaded( 'mysqlnd' ) ? '[::1]' : '::1';

		return [
			'plain hostname' => [ 'db.example', [ 'db.example', null, null, false ], [ 'host' => 'db.example' ], 'mysql:host=db.example;dbname=synthetic_db' ],
			'explicit port'  => [ '127.0.0.1:3307', [ '127.0.0.1', 3307, null, false ], [ 'host' => '127.0.0.1', 'port' => 3307 ], 'mysql:host=127.0.0.1;port=3307;dbname=synthetic_db' ],
			'explicit socket' => [ 'localhost:/tmp/mysql.sock', [ 'localhost', null, '/tmp/mysql.sock', false ], [ 'host' => 'localhost', 'unix_socket' => '/tmp/mysql.sock' ], 'mysql:unix_socket=/tmp/mysql.sock;dbname=synthetic_db' ],
			'socket and port' => [ 'localhost:3307:/tmp/mysql.sock', [ 'localhost', 3307, '/tmp/mysql.sock', false ], [ 'host' => 'localhost', 'port' => 3307, 'unix_socket' => '/tmp/mysql.sock' ], 'mysql:unix_socket=/tmp/mysql.sock;dbname=synthetic_db' ],
			'ipv6 and port'  => [ '[::1]:3307', [ '::1', 3307, null, true ], [ 'host' => $ipv6, 'port' => 3307 ], 'mysql:host=' . $ipv6 . ';port=3307;dbname=synthetic_db' ],
			'ipv6 only'      => [ '::1', [ '::1', null, null, true ], [ 'host' => $ipv6 ], 'mysql:host=' . $ipv6 . ';dbname=synthetic_db' ],
		];
	}

	public function test_unparseable_host_fails_without_exposing_configuration(): void {
		$had_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		$original = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new class() {
			public function parse_db_host( string $raw_host ): bool {
				unset( $raw_host );
				return false;
			}
		};

		try {
			$method = new \ReflectionMethod( \Nabik_Net_Database::class, 'host_settings' );
			$method->setAccessible( true );
			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'Unsupported database host configuration.' );
			$method->invoke( null, 'private-host::invalid' );
		} finally {
			if ( $had_wpdb ) {
				$GLOBALS['wpdb'] = $original;
			} else {
				unset( $GLOBALS['wpdb'] );
			}
		}
	}
}
