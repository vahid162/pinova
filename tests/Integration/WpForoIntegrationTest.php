<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Integrations\Continuation;
use Pinova\Integrations\IntegrationSettings;
use Pinova\Integrations\WpForo\Load;
use Pinova\Pinova;

/** Dependency-absent regression coverage; real-plugin cases live in wpforo-integration.php. */
final class WpForoIntegrationTest extends \WP_UnitTestCase {
	public function tear_down(): void {
		delete_option( IntegrationSettings::OPTION );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_continuation_preserves_one_decoded_nested_query_and_fragment(): void {
		$target = home_url( '/community/topic/?next=%2Fshop%3Fsort%3Dprice&name=a%2Bb#reply-2' );
		self::assertSame( $target, Continuation::validate( $target ) );
		parse_str( wp_parse_url( Pinova::get_login_url( Continuation::validate( $target ) ), PHP_URL_QUERY ), $query );
		self::assertSame( $target, $query['back_url'] );
		self::assertSame( home_url( '/community/?page=2#reply' ), Continuation::validate( '/community/?page=2#reply' ) );
	}

	public function test_unsafe_empty_and_auth_loop_targets_use_explicit_safe_fallback(): void {
		$fallback = home_url( '/community/' );
		foreach ( [ '', null, [], 'https://outside.invalid/topic', '//outside.invalid', 'javascript:alert(1)',
			rawurlencode( $fallback ), home_url( '/login/?next=1' ), home_url( '/logout/' ), site_url( '/wp-login.php?action=register' ),
			home_url( '/a/../login' ), home_url( '/%6Cogin' ), home_url( '/%2flogin' ), home_url( '/?pinova_verify_mobile=1' ),
			home_url( '/?wpfaction[]=registration' ), home_url( '/?wpfaction[x][]=login' ), home_url( '/?wpfaction=LOGIN' ), home_url( '/topic/?q=%0aLocation' ),
		] as $target ) {
			self::assertSame( $fallback, Continuation::validate( $target, $fallback ), var_export( $target, true ) );
		}
		self::assertSame( home_url( '/' ), Continuation::validate( '', 'https://outside.invalid' ) );
		self::assertSame( $fallback, Continuation::validate( home_url( '/forum-signin/?x=1' ), $fallback, [ home_url( '/forum-signin/' ) ] ) );
	}

	public function test_missing_dependency_never_enrolls_or_rewrites_native_links(): void {
		self::assertFalse( function_exists( 'WPF' ), 'This PHPUnit bootstrap must not load real third-party plugins.' );
		update_option( IntegrationSettings::OPTION, [ 'wpforo_enabled' => '1' ] );
		$id = self::factory()->user->create();
		do_action( 'pinova/user_registered', $id );
		self::assertSame( '', get_user_meta( $id, Load::STATE_META, true ) );
		self::assertSame( 'native', Load::login_url( 'native' ) );
		self::assertFalse( Load::denied( $id ) );
	}

	public function test_owned_pending_or_verified_cannot_bypass_guard_without_dependency_or_switch(): void {
		$id = self::factory()->user->create();
		wp_set_current_user( $id );
		foreach ( [ 'pending', 'verified', 'approved', 'held' ] as $state ) {
			update_user_meta( $id, Load::STATE_META, $state );
			self::assertTrue( Load::denied( $id ) );
			self::assertFalse( apply_filters( 'wpforo_permissions_forum_can', null, 'ct', 1, null ) );
			self::assertSame( [], apply_filters( 'wpforo_add_post_data_filter', [ 'userid' => $id, 'body' => 'attempt' ] ) );
		}
		self::assertFalse( Load::forum_can( false, 'vr' ) );
		self::assertNull( Load::forum_can( null, 'vr' ) );
		delete_user_meta( $id, Load::STATE_META );
		self::assertNull( Load::forum_can( null, 'ct' ) );
		self::assertFalse( Load::forum_can( false, 'ct' ) );
	}
}
