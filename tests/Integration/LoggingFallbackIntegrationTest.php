<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Install;
use Pinova\Logging\DatabaseHandler;
use Pinova\Logging\HealthState;
use Pinova\Logging\Logger;
use WP_UnitTestCase;

require_once __DIR__ . '/LoggingFallbackFixture.php';

final class LoggingFallbackIntegrationTest extends WP_UnitTestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	/** @dataProvider fallback_failures */
	public function test_filtered_woocommerce_still_attempts_php_without_exposing_secrets( bool $throws ): void {
		global $wpdb;

		delete_option( 'pinova_logging_fallback_health' );
		$table                                    = $wpdb->prefix . 'pinova_logs';
		$fail                                     = static function ( string $query ) use ( $table ): string {
			return str_starts_with( $query, 'INSERT INTO ' ) && str_contains( $query, $table )
				? 'SELECT `pinova_missing_column` FROM `' . $table . '` LIMIT 1'
				: $query;
		};
		$old_fallback                             = new \ReflectionProperty( DatabaseHandler::class, 'fallback_used' );
		$before                                   = $old_fallback->getValue();
		$previous_errors                          = $wpdb->suppress_errors( true );
		$GLOBALS['pinova_test_fallback_messages'] = [];
		$GLOBALS['pinova_test_fallback_throws']   = $throws;
		add_filter( 'query', $fail );
		add_filter( 'woocommerce_logger_log_message', '__return_null' );
		try {
			$logger = new Logger( null, null, static fn(): array => [ 'minimum_level' => 'info' ] );
			$logger->error(
				'auth.request_failed',
				[
					'password'  => 'never-expose-secret',
					'exception' => new \RuntimeException( 'never-expose-secret' ),
				]
			);
			self::assertTrue( DatabaseHandler::did_fallback() );
			self::assertCount( 1, $GLOBALS['pinova_test_fallback_messages'] );
			self::assertStringNotContainsString( 'never-expose-secret', $GLOBALS['pinova_test_fallback_messages'][0] );
			self::assertSame( 'attempted', HealthState::fallback()['woocommerce'] );
			self::assertSame( 'failed', HealthState::fallback()['php'] );
		} finally {
			remove_filter( 'query', $fail );
			remove_filter( 'woocommerce_logger_log_message', '__return_null' );
			$wpdb->suppress_errors( $previous_errors );
			$old_fallback->setValue( null, $before );
			unset( $GLOBALS['pinova_test_fallback_messages'], $GLOBALS['pinova_test_fallback_throws'] );
			delete_option( 'pinova_logging_fallback_health' );
		}
	}

	public function fallback_failures(): array {
		return [
			'false'     => [ false ],
			'throwable' => [ true ],
		];
	}
}
