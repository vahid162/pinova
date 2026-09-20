<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Helper;
use Pinova\Install;
use Pinova\Logging\LogRepository;
use Pinova\Pinova;
use WP_UnitTestCase;

final class LogoutDieException extends \RuntimeException {
	/** @var array<string, mixed> */
	public array $die_args;

	public string $die_title;

	/**
	 * @param mixed                $message
	 * @param mixed                $title
	 * @param array<string, mixed> $args
	 */
	public function __construct( $message, $title, array $args ) {
		parent::__construct( is_string( $message ) ? $message : 'Pinova wp_die response' );
		$this->die_title = is_string( $title ) ? $title : '';
		$this->die_args  = $args;
	}
}

final class LogoutIntegrationTest extends WP_UnitTestCase {
	/** @var array<string, mixed> */
	private array $original_get = [];

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();

		$this->original_get = $_GET;
		$_GET              = [];
		LogRepository::delete_all();
		update_option(
			'pinova_logging',
			[
				'minimum_level'    => 'info',
				'retention_days'   => 14,
				'diagnostic_until' => 0,
			]
		);
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$_GET = $this->original_get;
		LogRepository::delete_all();
		delete_option( 'pinova_logging' );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_generated_logout_url_uses_the_canonical_trailing_slash_route(): void {
		$url = html_entity_decode( Pinova::get_logout_url(), ENT_QUOTES, 'UTF-8' );

		self::assertSame( '/logout/', wp_parse_url( $url, PHP_URL_PATH ) );
		self::assertNotEmpty( wp_parse_url( $url, PHP_URL_QUERY ) );
	}

	public function test_empty_return_targets_use_explicit_safe_fallbacks(): void {
		$_GET['back_url'] = '';

		self::assertSame( site_url(), Helper::get_login_back_url() );
		self::assertSame( site_url(), Helper::get_logout_back_url() );

		$administrator_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $administrator_id );

		self::assertSame( admin_url(), Helper::get_login_back_url() );
		self::assertSame( site_url(), Helper::get_logout_back_url() );
	}

	public function test_invalid_logout_nonce_returns_a_controlled_403_without_destroying_the_session(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );
		$_GET['_pinova_nonce'] = 'invalid-logout-nonce';

		$exception = $this->capture_wp_die(
			static function (): void {
				Pinova::logout();
			}
		);

		self::assertSame( 403, $exception->die_args['response'] ?? null );
		self::assertSame( site_url(), $exception->die_args['link_url'] ?? null );
		self::assertSame( $user_id, get_current_user_id() );
		self::assertSame( 0, LogRepository::paginate()['total'] );
	}

	public function test_failed_logout_redirect_never_returns_a_blank_success_response_or_logs_secrets(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );
		$target = home_url( '/after-logout/?email=private@example.test&token=secret-token' );

		$_GET['_pinova_nonce'] = wp_create_nonce( 'logout' );
		$_GET['back_url']      = $target;

		$reject_redirect = static function () {
			return false;
		};
		add_filter( 'wp_redirect', $reject_redirect, PHP_INT_MAX );

		try {
			$exception = $this->capture_wp_die(
				static function (): void {
					Pinova::logout();
				}
			);
		} finally {
			remove_filter( 'wp_redirect', $reject_redirect, PHP_INT_MAX );
		}

		self::assertSame( 503, $exception->die_args['response'] ?? null );
		self::assertSame( $target, $exception->die_args['link_url'] ?? null );
		self::assertNotSame( '', $exception->getMessage() );
		self::assertSame( 0, get_current_user_id() );

		$records  = LogRepository::paginate( 1, 10 )['rows'];
		$redirect = null;
		foreach ( $records as $record ) {
			if ( 'auth.redirect_failed' === ( $record['event'] ?? '' ) ) {
				$redirect = $record;
				break;
			}
		}

		self::assertIsArray( $redirect );
		self::assertSame( 'warning', $redirect['level'] ?? null );
		self::assertStringContainsString( '"operation":"logout"', $redirect['context'] ?? '' );
		self::assertStringContainsString( '"reason":"safe_redirect_rejected"', $redirect['context'] ?? '' );
		self::assertStringNotContainsString( 'private@example.test', $redirect['context'] ?? '' );
		self::assertStringNotContainsString( 'secret-token', $redirect['context'] ?? '' );
		self::assertStringNotContainsString( '_pinova_nonce', $redirect['context'] ?? '' );
	}

	/** @param callable():void $callback */
	private function capture_wp_die( callable $callback ): LogoutDieException {
		$handler = static function (): callable {
			return static function ( $message, $title, $args ): void {
				throw new LogoutDieException( $message, $title, is_array( $args ) ? $args : [] );
			};
		};

		add_filter( 'wp_die_handler', $handler );

		try {
			$callback();
			self::fail( 'Expected the logout flow to terminate through wp_die().' );
		} catch ( LogoutDieException $exception ) {
			return $exception;
		} finally {
			remove_filter( 'wp_die_handler', $handler );
		}
	}
}
