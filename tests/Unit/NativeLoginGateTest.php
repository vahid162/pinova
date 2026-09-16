<?php

declare(strict_types=1);

namespace Pinova\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pinova\Integrations\Wordpress\NativeLoginGate;

final class NativeLoginGateTest extends TestCase {
	public function test_private_slug_is_normalized_and_reserved_or_weak_values_fall_back(): void {
		$fallback = 'pinova-admin-safe1234';

		self::assertSame(
			'my-private-admin-2026',
			NativeLoginGate::normalize_slug( ' My Private Admin 2026 ', $fallback )
		);
		self::assertSame( $fallback, NativeLoginGate::normalize_slug( 'wp-login.php', $fallback ) );
		self::assertSame( $fallback, NativeLoginGate::normalize_slug( 'too-short', $fallback ) );
	}

	public function test_only_the_canonical_wordpress_login_path_is_detected(): void {
		self::assertTrue( NativeLoginGate::is_canonical_login_request( '/wp-login.php?action=login', '/wp-login.php' ) );
		self::assertTrue( NativeLoginGate::is_canonical_login_request( '/shop/wp-login.php/', '/index.php' ) );
		self::assertFalse( NativeLoginGate::is_canonical_login_request( '/pinova-admin-safe1234/', '/index.php' ) );
		self::assertFalse( NativeLoginGate::is_canonical_login_request( '/article-about-wp-login.php', '/index.php' ) );
	}

	public function test_only_non_authentication_core_actions_bypass_account_login_blocking(): void {
		self::assertTrue( NativeLoginGate::is_public_core_action_request( 'confirm_admin_email' ) );
		self::assertTrue( NativeLoginGate::is_public_core_action_request( 'postpass' ) );
		self::assertTrue( NativeLoginGate::is_public_core_action_request( 'logout' ) );
		self::assertTrue( NativeLoginGate::is_public_core_action_request( 'confirmaction' ) );
		self::assertTrue( NativeLoginGate::is_public_core_action_request( 'exit_recovery_mode' ) );
		self::assertFalse( NativeLoginGate::is_public_core_action_request( 'POSTPASS' ) );
		self::assertFalse( NativeLoginGate::is_public_core_action_request( ' postpass ' ) );
		self::assertFalse( NativeLoginGate::is_public_core_action_request( 'login' ) );
		self::assertFalse( NativeLoginGate::is_public_core_action_request( 'lostpassword' ) );
		self::assertFalse( NativeLoginGate::is_public_core_action_request( '' ) );
	}

	public function test_runtime_bypass_uses_the_action_normalized_by_wordpress_core(): void {
		$had_action      = array_key_exists( 'action', $GLOBALS );
		$previous_action = $GLOBALS['action'] ?? null;

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Exercise the core action state and restore it below.
		try {
			$GLOBALS['action'] = 'resetpass';
			self::assertFalse( NativeLoginGate::is_public_core_action_request() );

			$GLOBALS['action'] = 'postpass';
			self::assertTrue( NativeLoginGate::is_public_core_action_request() );
		} finally {
			if ( $had_action ) {
				$GLOBALS['action'] = $previous_action;
			} else {
				unset( $GLOBALS['action'] );
			}
		}
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
	}
}
