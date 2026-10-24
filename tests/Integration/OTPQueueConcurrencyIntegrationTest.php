<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Install;
use Pinova\Services\RateLimitService;
use WP_UnitTestCase;

/** Independent PHP workers, real wpdb connections, and no provider calls. */
final class OTPQueueConcurrencyIntegrationTest extends WP_UnitTestCase {
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function test_separate_workers_preserve_erasure_barriers_and_recover_a_crash(): void {
		self::assertTrue( function_exists( 'proc_open' ), 'Queue concurrency requires PHP process support.' );
		global $wpdb;
		$original = $wpdb;
		// PHPUnit wraps its primary connection in a transaction. Seed and inspect
		// only our flows through an independent autocommit test connection so that
		// both workers can see them without committing the test runner's state.
		$database = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$database->set_prefix( $original->prefix );
		$database->suppress_errors();
		$flows   = [];
		$workers = [];
		try {
			$wpdb = $database;
			$identifier = 'queue-erase-' . wp_generate_uuid4() . '@example.test';
			[ $flow ] = RateLimitService::decoy_flow( $identifier, 'authenticate', '192.0.2.241', null, true );
			$flows[] = $flow;
			self::assertTrue( RateLimitService::acknowledge_queued_otp( $flow ) );
			$workers[] = $this->start_worker( $flow, $database->prefix );
			$workers[] = $this->start_worker( $flow, $database->prefix );
			self::assertTrue( $this->command( $workers[0], 'claim' ) );
			self::assertFalse( $this->command( $workers[1], 'claim' ), 'The second database connection must not share the first claim.' );
			self::assertTrue( RateLimitService::retire_queued_otp( $flow ) );
			self::assertFalse( $this->command( $workers[0], 'active' ), 'Cancellation must stop the holder before provider delivery.' );
			self::assertFalse( RateLimitService::delete_queued_for_identifiers( [ $identifier ] ), 'Erasure remains incomplete while the cancelled holder owns its lock.' );
			self::assertTrue( $this->command( $workers[0], 'finish' ) );
			self::assertTrue( RateLimitService::delete_queued_for_identifiers( [ $identifier ] ) );
			self::assertFalse( $this->command( $workers[1], 'claim' ) );
			foreach ( $workers as $worker ) {
				$this->stop_worker( $worker );
			}
			$workers = [];

			[ $flow ] = RateLimitService::decoy_flow( 'queue-crash-' . wp_generate_uuid4() . '@example.test', 'authenticate', '192.0.2.242', null, true );
			$flows[] = $flow;
			self::assertTrue( RateLimitService::acknowledge_queued_otp( $flow ) );
			$before = $database->get_row( $database->prepare( 'SELECT `payload`, `reset_at` FROM %i WHERE `scope` = %s', $database->prefix . 'pinova_rate_limits', $flow ), ARRAY_A );
			self::assertIsArray( $before );
			$workers[] = $this->start_worker( $flow, $database->prefix );
			$workers[] = $this->start_worker( $flow, $database->prefix );
			self::assertTrue( $this->command( $workers[0], 'claim' ) );
			self::assertSame( 1, $database->update( $database->prefix . 'pinova_rate_limits', [ 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 61 ) ], [ 'scope' => $flow ] ) );
			self::assertFalse( $this->command( $workers[1], 'claim' ), 'A stale lease must not steal a living worker connection lock.' );
			$this->stop_worker( $workers[0] );
			unset( $workers[0] );
			$deadline = microtime( true ) + 2;
			do {
				$holder = $database->get_var( $database->prepare( 'SELECT IS_USED_LOCK(%s)', 'pinova_otp_' . $flow ) );
				if ( null === $holder ) {
					break;
				}
				usleep( 10000 );
			} while ( microtime( true ) < $deadline );
			self::assertNull( $holder, 'The terminated worker must release its database connection lock.' );
			self::assertTrue( $this->command( $workers[1], 'claim' ), 'Closing the crashed worker connection must permit stale-lease recovery.' );
			$after = $database->get_row( $database->prepare( 'SELECT `payload`, `reset_at` FROM %i WHERE `scope` = %s', $database->prefix . 'pinova_rate_limits', $flow ), ARRAY_A );
			self::assertIsArray( $after );
			self::assertSame( $before['reset_at'], $after['reset_at'] );
			self::assertStringEndsWith( ':' . $before['payload'], $after['payload'], 'Recovery must retain the original authenticated ciphertext.' );
			self::assertTrue( $this->command( $workers[1], 'finish' ) );
			self::assertSame( 'delivered', $database->get_var( $database->prepare( 'SELECT `payload` FROM %i WHERE `scope` = %s', $database->prefix . 'pinova_rate_limits', $flow ) ) );
		} finally {
			foreach ( $workers as $worker ) {
				$this->stop_worker( $worker );
			}
			foreach ( $flows as $flow ) {
				RateLimitService::retire_queued_otp( $flow );
			}
			$wpdb = $original;
			$database->close();
		}
	}

	private function start_worker( string $flow, string $prefix ): array {
		$pipes   = [];
		$process = proc_open( [ PHP_BINARY, __DIR__ . '/QueueWorkerFixture.php' ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		self::assertIsResource( $process, 'Could not start the bounded queue worker.' );
		fwrite( $pipes[0], wp_json_encode( [ 'marker' => 'pinova-queue-test-worker', 'wordpress' => ABSPATH, 'repository' => dirname( __DIR__, 2 ), 'user' => DB_USER, 'password' => DB_PASSWORD, 'database' => DB_NAME, 'host' => DB_HOST, 'prefix' => $prefix, 'salt' => wp_salt( 'auth' ), 'flow' => $flow ] ) . PHP_EOL );
		return [ 'process' => $process, 'pipes' => $pipes ];
	}

	private function command( array $worker, string $command ): bool {
		fwrite( $worker['pipes'][0], $command . PHP_EOL );
		$read   = [ $worker['pipes'][1] ];
		$write  = null;
		$except = null;
		self::assertSame( 1, stream_select( $read, $write, $except, 5 ), 'The queue worker did not respond within five seconds.' );
		$reply = json_decode( (string) fgets( $worker['pipes'][1] ), true );
		self::assertIsArray( $reply, 'The queue worker returned no valid bounded result.' );
		self::assertIsBool( $reply['result'] ?? null );
		return $reply['result'];
	}

	private function stop_worker( array $worker ): void {
		if ( proc_get_status( $worker['process'] )['running'] ) {
			// A forced exit, rather than finish(), proves connection-owned locks
			// release after a worker crash while the durable claim remains intact.
			proc_terminate( $worker['process'], 9 );
		}
		foreach ( $worker['pipes'] as $pipe ) {
			fclose( $pipe );
		}
		proc_close( $worker['process'] );
	}
}
