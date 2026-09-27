<?php
/** Real wpForo regression fixture. Only run on the disposable third-party CI site. */

declare(strict_types=1);

use Pinova\Integrations\Continuation;
use Pinova\Integrations\IntegrationSettings;
use Pinova\Integrations\WpForo\Load;
use Pinova\Models\OTP;
use Pinova\Objects\Identifier;
use Pinova\Services\MobileVerificationService as Proof;
use Pinova\Services\OTPService;
use Pinova\Services\UserService;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'PINOVA_THIRD_PARTY_BASELINE' ) || true !== PINOVA_THIRD_PARTY_BASELINE
	|| 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), [ 'localhost', '127.0.0.1' ], true ) ) {
	throw new RuntimeException( 'wpForo regressions require the explicitly marked disposable loopback site.' );
}
$scenario = $args[0] ?? '';
if ( ! in_array( $scenario, [ 'normal', 'manual-approval' ], true ) ) {
	throw new RuntimeException( 'Unknown wpForo regression scenario.' );
}
$check = static function ( bool $condition, string $label ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'wpForo regression failed: ' . $label );
	}
};
$check( defined( 'WPFORO_VERSION' ) && '3.2.1' === WPFORO_VERSION && defined( 'WC_VERSION' ) && '11.1.2' === WC_VERSION, 'exact characterized plugins' );
$check( WPF()->is_installed(), 'real wpForo installation' );
add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'pre_http_request', static fn() => new WP_Error( 'pinova_fixture_no_network' ) );
// WP-CLI loads base services but does not render the forum shortcode.
// Use the native lifecycle to initialize the real forum/topic/post services.
if ( null === WPF()->forum ) {
	WPF()->init();
}
$_SERVER['REMOTE_ADDR'] = '192.0.2.156';
$original_settings = get_option( IntegrationSettings::OPTION, null );
$original_authorization = WPF()->settings->authorization;
$users = [];
$forums = [];
$topics = [];
$otps = [];
$completed = false;
$admin = 0;
register_shutdown_function( static function () use ( &$users, &$forums, &$topics, &$otps, &$completed, &$admin, $scenario, $original_settings, $original_authorization, $check ): void {
	wp_set_current_user( $admin );
	WPF()->settings->authorization = $original_authorization;
	if ( null === $original_settings ) {
		delete_option( IntegrationSettings::OPTION );
	} else {
		update_option( IntegrationSettings::OPTION, $original_settings );
	}
	wp_set_current_user( 0 );
	foreach ( array_reverse( $topics ) as $id ) {
		WPF()->topic->delete( $id, true, false );
	}
	foreach ( array_reverse( $forums ) as $id ) {
		WPF()->forum->delete( $id, false );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $id ) {
		$check( wp_delete_user( $id ), 'synthetic user cleanup' );
	}
	foreach ( $otps as $id ) {
		OTP::query()->where( 'id', $id )->delete();
	}
	// An upstream exit, fatal, or assertion can never emit this success marker.
	if ( $completed ) {
		WP_CLI::line( 'PINOVA_WPFORO_COMPLETE ' . $scenario );
	}
} );
$admin = wp_insert_user( [ 'user_login' => 'pinova_wpf_admin_' . wp_generate_password( 12, false ), 'user_pass' => wp_generate_password( 32 ), 'role' => 'administrator' ] );
$check( ! is_wp_error( $admin ), 'synthetic settings administrator' );
$users[] = $admin;
WPF()->member->synchronize_user( $admin );
$settings = static function ( array $value ) use ( $admin ): void {
	$previous = get_current_user_id();
	wp_set_current_user( $admin );
	try {
		update_option( IntegrationSettings::OPTION, $value );
	} finally {
		wp_set_current_user( $previous );
	}
};
$settings( [ 'wpforo_enabled' => '1', 'dokan_enabled' => '0' ] );
WPF()->settings->authorization['manually_approval'] = 'manual-approval' === $scenario;
WPF()->settings->authorization['user_register'] = true;
WPF()->settings->authorization['user_register_email_confirm'] = true;
$check( IntegrationSettings::enabled( 'wpforo' ), 'adapter enabled' );

$account = static function ( bool $owned = true, string $role = 'subscriber', string $email = '' ) use ( &$users, $check ): int {
	$id = wp_insert_user( [ 'user_login' => 'pinova_wpf_' . wp_generate_password( 12, false ), 'user_pass' => 'Pinova-fixture-password-894!', 'user_email' => $email, 'role' => $role ] );
	$check( ! is_wp_error( $id ), 'create synthetic user' );
	$users[] = $id;
	WPF()->member->synchronize_user( $id );
	update_user_meta( $id, 'pinova_mobile', '0912' . str_pad( (string) $id, 7, '0', STR_PAD_LEFT ) );
	if ( $owned ) {
		do_action( 'pinova/user_registered', $id );
	}
	return $id;
};
$proof = static function ( int $id ) use ( &$otps, $check ): void {
	$otp = OTP::query()->create( [ 'user_id' => $id, 'identifier' => UserService::get_mobile( $id ), 'type' => OTP::TYPE_LOGIN,
		'code' => '4821', 'flow_id' => bin2hex( random_bytes( 16 ) ), 'channels' => [ 'sms' => true ] ] );
	$otps[] = $otp->id;
	[ $verified_user ] = OTPService::verify_with_flow( OTPService::signed_state( $otp ), '4821', [ OTP::TYPE_LOGIN ] );
	$check( $verified_user instanceof WP_User && $id === $verified_user->ID && Proof::is_verified( $id ), 'real purpose-bound mobile OTP' );
};
$set_user = static function ( int $id ): void {
	wp_set_current_user( $id );
	WPF()->member->init_current_user();
	WPF()->current_user_accesses = [];
};
$set_user( $admin );
$category = WPF()->forum->add( [ 'title' => 'Pinova regression category ' . $admin, 'layout' => 1 ], false );
$check( (bool) $category, 'create disposable category' );
$forums[] = $category;
$forum = WPF()->forum->add( [ 'title' => 'Pinova regression forum ' . $admin, 'parentid' => $category ], false );
$check( (bool) $forum, 'create disposable forum' );
$forums[] = $forum;
$add_topic = static function () use ( $forum ) {
	return WPF()->topic->add( [ 'forumid' => $forum, 'title' => 'Pinova fixture ' . wp_generate_password( 10, false ), 'body' => 'Synthetic integration regression content for forum permissions.', 'is_ai_generated' => 1 ] );
};
$seed = $add_topic();
$check( (bool) $seed, 'real seed topic' );
$topics[] = $seed;
$add_reply = static function ( bool $guest = false ) use ( $seed ) {
	$args = [ 'topicid' => $seed, 'body' => 'Synthetic integration regression reply content.', 'is_ai_generated' => 1 ];
	if ( $guest ) {
		$args += [ 'userid' => 0, 'name' => 'Synthetic', 'email' => 'synthetic@example.test' ];
	}
	return WPF()->post->add( $args );
};
$id = $account();
$login = get_userdata( $id )->user_login;
$set_user( $id );
$check( 'pending' === get_user_meta( $id, Load::STATE_META, true ) && 'inactive' === WPF()->member->get_status( $id ), 'new Pinova account is explicitly pending' );
$check( ! $add_topic() && ! $add_reply() && ! $add_reply( true ), 'real topic, reply and guest-shortcut boundaries deny pending' );
$cookie = 0;
$security = 0;
add_action( 'set_auth_cookie', static function () use ( &$cookie ): void { ++$cookie; } );
add_action( 'wp_login', static function () use ( &$security ): void { ++$security; }, 20 );
UserService::login( $id, 'otp' );
$check( $cookie > 0 && $security > 0 && 'inactive' === WPF()->member->get_status( $id ), 'pending user retains native store session and security callbacks without activation or exit' );
$check( 10 === has_action( 'wp_login', [ WPF()->member, 'wp_login' ] ), 'login callback restored' );
$proof( $id );
$check( ! WPF()->member->get_is_email_confirmed( $id ) && '' === get_userdata( $id )->user_email && $login === get_userdata( $id )->user_login, 'mobile proof preserves empty email and immutable login' );
if ( 'manual-approval' === $scenario ) {
	$check( 'inactive' === WPF()->member->get_status( $id ) && ! $add_topic() && ! $add_reply(), 'manual membership approval still required after proof' );
	$set_user( $admin );
	WPF()->member->update_profile_fields( $id, [ 'status' => 'active' ], false );
	$set_user( $id );
}
$topic = $add_topic();
$check( (bool) $topic && (bool) $add_reply(), 'real topic and reply work with mobile proof and unconfirmed empty email' );
$topics[] = $topic;
$upstream_denial = static fn() => false;
add_filter( 'wpforo_permissions_forum_can', $upstream_denial, 5 );
$check( ! $add_topic() && ! $add_reply(), 'adapter never overrides another permission denial' );
remove_filter( 'wpforo_permissions_forum_can', $upstream_denial, 5 );
Proof::revoke( $id );
$settings( [ 'wpforo_enabled' => '0' ] );
$check( ! $add_topic() && ! $add_reply() && ! $add_reply( true ), 'revocation remains enforced while switch off' );
$native_url = add_query_arg( 'redirect_to', rawurlencode( home_url( '/topic/' ) ), wpforo_url( '', 'login' ) );
$check( $native_url === Load::login_url( $native_url ), 'off preserves native forum login URL' );
$settings( [ 'wpforo_enabled' => '1' ] );
$proof( $id );
$check( (bool) $add_reply(), 'new valid proof restores previously authorized active member' );

// Historical active users are never enrolled; ambiguous inactive states are not autoactivated.
$historical = $account( false );
$set_user( $admin );
WPF()->member->update_profile_fields( $historical, [ 'status' => 'active' ], false );
$set_user( $historical );
$check( ! Load::denied( $historical ) && (bool) $add_reply(), 'historical active native account retains permission without mobile proof' );
WPF()->member->update_profile_fields( $historical, [ 'status' => 'inactive' ], false );
$proof( $historical );
UserService::login( $historical, 'otp' );
$check( 'inactive' === WPF()->member->get_status( $historical ) && '' === get_user_meta( $historical, Load::STATE_META, true ), 'historical inactive proof/login never enrolls or activates' );

$held = $account();
$set_user( $admin );
WPF()->member->update_profile_fields( $held, [ 'status' => 'inactive' ], false ); // Deliberate same-value hold.
$set_user( $held );
WPF()->member->update_profile_fields( $held, [ 'status' => 'active' ], false ); // Self-service/native activation bypass.
$proof( $held );
$check( 'held' === get_user_meta( $held, Load::STATE_META, true ) && 'inactive' === WPF()->member->get_status( $held ) && ! $add_reply(), 'same-value admin hold survives nonadmin active write and mobile proof' );
$set_user( $admin );
WPF()->member->update_profile_fields( $held, [ 'status' => 'active' ], false );
$set_user( $held );
$check( ! Load::denied( $held ) && (bool) $add_reply(), 'explicit administrator approval restores proved held member' );
foreach ( [ 'banned', 'trashed' ] as $status ) {
	$set_user( $admin );
	WPF()->member->update_profile_fields( $held, [ 'status' => $status ], false );
	$set_user( $held );
	$proof( $held );
	UserService::login( $held, 'otp' );
	$check( $status === WPF()->member->get_status( $held ) && ! $add_topic() && ! $add_reply(), 'restricted forum status survives proof and store login: ' . $status );
}
$registration_off = $account();
WPF()->settings->authorization['user_register'] = false;
$proof( $registration_off );
$check( 'inactive' === WPF()->member->get_status( $registration_off ), 'forum registration disabled prevents automatic forum activation' );
WPF()->settings->authorization['user_register'] = true;

// Native entry points cannot lift owned pending, even when Pinova routing is off.
$pending = $account();
$set_user( $pending );
$settings( [ 'wpforo_enabled' => '0' ] );
do_action( 'wp_login', get_userdata( $pending )->user_login, get_userdata( $pending ) );
reset_password( get_userdata( $pending ), 'Pinova-fixture-password-895!' );
$check( 'inactive' === WPF()->member->get_status( $pending ) && ! WPF()->member->get_is_email_confirmed( $pending ), 'unscoped native login/reset cannot bypass pending state or fake email' );
$settings( [ 'wpforo_enabled' => '1' ] );
foreach ( [ 'mobile', 'unknown', 'email' ] as $origin ) {
	do_action( 'pinova/password_reset_start', $pending, null, $origin );
	try {
		reset_password( get_userdata( $pending ), 'Pinova-fixture-password-896!' );
	} finally {
		do_action( 'pinova/password_reset_end', $pending, null, $origin );
	}
	$check( 'inactive' === WPF()->member->get_status( $pending ) && ! WPF()->member->get_is_email_confirmed( $pending ), 'reset without email preserves pending and email flag: ' . $origin );
	$check( 10 === has_action( 'after_password_reset', [ WPF()->member, 'after_password_reset' ] ), 'reset callback restored' );
}
$email_user = $account( true, 'subscriber', 'pinova-fixture-' . $pending . '@example.test' );
$set_user( $email_user );
do_action( 'pinova/password_reset_start', $email_user, null, 'email' );
try {
	reset_password( get_userdata( $email_user ), 'Pinova-fixture-password-897!' );
} finally {
	do_action( 'pinova/password_reset_end', $email_user, null, 'email' );
}
$check( 'inactive' === WPF()->member->get_status( $email_user ) && WPF()->member->get_is_email_confirmed( $email_user ), 'genuine email recovery confirms email without approving forum membership' );

$changed_email = static function ( $user ) use ( $email_user ): void {
	if ( $email_user === (int) $user->ID ) {
		wp_update_user( [ 'ID' => $email_user, 'user_email' => 'changed-' . $email_user . '@example.test' ] );
	}
};
add_action( 'after_password_reset', $changed_email, 1 );
do_action( 'pinova/password_reset_start', $email_user, null, 'email' );
try {
	reset_password( get_userdata( $email_user ), 'Pinova-fixture-password-898!' );
} finally {
	do_action( 'pinova/password_reset_end', $email_user, null, 'email' );
	remove_action( 'after_password_reset', $changed_email, 1 );
}
$check( ! WPF()->member->get_is_email_confirmed( $email_user ), 'email change inside reset cannot inherit earlier email proof' );
$unowned_reset = $account( false );
$settings( [ 'wpforo_enabled' => '0' ] );
foreach ( [ 'mobile', 'unknown' ] as $origin ) {
	do_action( 'pinova/password_reset_start', $unowned_reset, null, $origin );
	try {
		reset_password( get_userdata( $unowned_reset ), 'Pinova-fixture-password-899!' );
	} finally {
		do_action( 'pinova/password_reset_end', $unowned_reset, null, $origin );
	}
	$check( ! WPF()->member->get_is_email_confirmed( $unowned_reset ), 'off/unowned mobile or unknown reset never confirms email' );
}
$settings( [ 'wpforo_enabled' => '1' ] );

// Custom priorities/accepted arguments and upstream security errors must survive scoping.
$callback = [ WPF()->member, 'wp_login' ];
remove_action( 'wp_login', $callback, 10 );
add_action( 'wp_login', $callback, 17, 2 );
$denied = new WP_Error( 'pinova_fixture_security_denial' );
$deny_auth = static fn() => $denied;
add_filter( 'authenticate', $deny_auth, PHP_INT_MAX );
$result = UserService::authenticate_password( $pending, 'Pinova-fixture-password-896!' );
remove_filter( 'authenticate', $deny_auth, PHP_INT_MAX );
$check( $denied === $result && 17 === has_action( 'wp_login', $callback ), 'native WP_Error and original callback priority restored after failed authentication' );
global $wp_filter;
$entry = $wp_filter['wp_login']->callbacks[17];
$check( 2 === reset( $entry )['accepted_args'], 'original accepted argument count' );
remove_action( 'wp_login', $callback, 17 );
add_action( 'wp_login', $callback, 10, 2 );

$reset_callback = [ WPF()->member, 'after_password_reset' ];
remove_action( 'after_password_reset', $reset_callback, 10 );
add_action( 'after_password_reset', $reset_callback, 23, 1 );
do_action( 'pinova/password_reset_start', $pending, null, 'mobile' );
try {
	$check( false === has_action( 'after_password_reset', $reset_callback ), 'exact reset callback absent inside mobile reset' );
	reset_password( get_userdata( $pending ), 'Pinova-fixture-password-900!' );
} finally {
	do_action( 'pinova/password_reset_end', $pending, null, 'mobile' );
}
$entry = $wp_filter['after_password_reset']->callbacks[23];
$check( 23 === has_action( 'after_password_reset', $reset_callback ) && 1 === reset( $entry )['accepted_args'], 'reset callback original priority and accepted arguments restored' );
remove_action( 'after_password_reset', $reset_callback, 23 );
add_action( 'after_password_reset', $reset_callback, 10, 1 );

// One URL decode, including nested query/fragment, plus early real action dispatch.
$target = home_url( '/community/topic/?next=%2Fshop%3Fx%3D1&literal=a%2Bb#reply-2' );
foreach ( [ 'wpforo_login_url', 'wpforo_register_url' ] as $helper ) {
	parse_str( wp_parse_url( $helper( $target ), PHP_URL_QUERY ), $query );
	$check( $target === $query['back_url'], 'one decode exact continuation: ' . $helper );
}
foreach ( [ '', 'https://external.invalid/topic', wpforo_url( '', 'login' ), wpforo_url( '', 'register' ), wpforo_url( '', 'cantlogin' ), home_url( '/login' ) ] as $bad ) {
	$check( wpforo_home_url() === Load::continuation( $bad ), 'invalid continuation has forum fallback' );
}
$check( home_url( '/' ) === Continuation::validate( '', 'https://external.invalid/' ), 'external fallback cannot escape origin' );
$set_user( 0 );
$original_get = $_GET;
$original_post = $_POST;
$original_url = WPF()->current_url;
$original_wpf_get = WPF()->GET;
$redirected = '';
$redirect = static function ( $url ) use ( &$redirected ) {
	$redirected = $url;
	throw new RuntimeException( 'pinova_fixture_redirect' );
};
add_filter( 'wp_redirect', $redirect );
$native_mutated = false;
$mutation = static function () use ( &$native_mutated ): void { $native_mutated = true; };
add_action( 'wpforo_action_login', $mutation, 1 );
add_action( 'wpforo_action_registration', $mutation, 1 );
foreach ( [ 'GET', 'POST' ] as $method ) {
	foreach ( [ 'login', 'registration' ] as $action ) {
		$_GET = 'GET' === $method ? [ 'redirect_to' => $target ] : [];
		$_POST = 'POST' === $method ? [ 'wpfaction' => $action, 'redirect_to' => $target, 'wpforologin' => '1', 'log' => 'ignored', 'pwd' => 'ignored', 'wpfreg' => [ 'user_login' => 'ignored' ] ] : [];
		WPF()->GET = 'GET' === $method ? [ 'wpfaction' => $action ] : [];
		$redirected = '';
		try {
			WPF()->action->do_actions();
		} catch ( RuntimeException $error ) {
			$check( 'pinova_fixture_redirect' === $error->getMessage(), 'only controlled route exception' );
		}
		parse_str( (string) wp_parse_url( $redirected, PHP_URL_QUERY ), $query );
		$check( ! $native_mutated && ( $query['back_url'] ?? null ) === $target, 'original ' . $method . ' ' . $action . ' intercepted before native mutation' );
	}
}
foreach ( [ 'login', 'register' ] as $route ) {
	$_GET = [ 'redirect_to' => $target ];
	$_POST = [];
	WPF()->current_url = wpforo_url( '', $route );
	$redirected = '';
	try {
		do_action( 'wpforo_core_inited' );
	} catch ( RuntimeException $error ) {
		$check( 'pinova_fixture_redirect' === $error->getMessage(), 'only controlled template redirect' );
	}
	$check( '' !== $redirected, 'original GET template intercepted: ' . $route );
}
remove_filter( 'wp_redirect', $redirect );
remove_action( 'wpforo_action_login', $mutation, 1 );
remove_action( 'wpforo_action_registration', $mutation, 1 );
$_GET = $original_get;
$_POST = $original_post;
WPF()->current_url = $original_url;
WPF()->GET = $original_wpf_get;
$completed = true;
