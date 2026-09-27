<?php
/** Native Dokan conversion regressions. Run only on the marked disposable CI site. */

declare(strict_types=1);

use Pinova\Integrations\Dokan\Load;
use Pinova\Integrations\IntegrationSettings;
use Pinova\Integrations\WpForo\Load as Forum;
use Pinova\Models\OTP;
use Pinova\Objects\Identifier;
use Pinova\Services\MobileVerificationService as Proof;
use Pinova\Services\OTPService;
use Pinova\Services\UserService;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'PINOVA_THIRD_PARTY_BASELINE' ) || true !== PINOVA_THIRD_PARTY_BASELINE
	|| 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), [ 'localhost', '127.0.0.1' ], true ) ) {
	throw new RuntimeException( 'Dokan regressions require the explicitly marked disposable loopback site.' );
}
$scenario = $args[0] ?? '';
if ( ! in_array( $scenario, [ 'normal', 'manual-approval' ], true ) ) {
	throw new RuntimeException( 'Unknown Dokan regression scenario.' );
}
$check = static function ( bool $condition, string $label ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'Dokan regression failed: ' . $label );
	}
};
$check( defined( 'DOKAN_PLUGIN_VERSION' ) && '5.1.3' === DOKAN_PLUGIN_VERSION && defined( 'WC_VERSION' ) && '11.1.2' === WC_VERSION
	&& defined( 'WPFORO_VERSION' ) && '3.2.1' === WPFORO_VERSION && ! defined( 'DOKAN_PRO_PLUGIN_VERSION' ), 'exact characterized dependencies' );
$check( WPF()->is_installed(), 'real forum installation' );
add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'pre_http_request', static fn() => new WP_Error( 'pinova_fixture_no_network' ) );
$_SERVER['REMOTE_ADDR'] = '192.0.2.157';
$original_settings = get_option( IntegrationSettings::OPTION, null );
$original_authorization = WPF()->settings->authorization;
$users = [];
$otps = [];
$orders = [];
$completed = false;
$admin = 0;
register_shutdown_function( static function () use ( &$users, &$otps, &$orders, &$completed, &$admin, $scenario, $original_settings, $original_authorization, $check ): void {
	Load::clear_attempt();
	$_POST = [];
	$_GET = [];
	wp_set_current_user( $admin );
	WPF()->settings->authorization = $original_authorization;
	if ( null === $original_settings ) {
		delete_option( IntegrationSettings::OPTION );
	} else {
		update_option( IntegrationSettings::OPTION, $original_settings );
	}
	wp_set_current_user( 0 );
	foreach ( $orders as $order ) {
		$order->delete( true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $id ) {
		$check( wp_delete_user( $id ), 'synthetic user cleanup' );
	}
	foreach ( $otps as $id ) {
		OTP::query()->where( 'id', $id )->delete();
	}
	if ( $completed ) {
		WP_CLI::line( 'PINOVA_DOKAN_COMPLETE ' . $scenario );
	}
} );
$account = static function ( string $role = 'customer', bool $owned = false ) use ( &$users, $check ): int {
	$id = wp_insert_user( [ 'user_login' => 'pinova_dokan_' . wp_generate_password( 12, false ), 'user_pass' => wp_generate_password( 32 ), 'role' => $role ] );
	$check( ! is_wp_error( $id ), 'create synthetic account' );
	$users[] = $id;
	WPF()->member->synchronize_user( $id );
	if ( $owned ) {
		do_action( 'pinova/user_registered', $id );
	}
	return $id;
};
$admin = $account( 'administrator' );
$settings = static function ( bool $on ) use ( $admin ): void {
	$previous = get_current_user_id();
	wp_set_current_user( $admin );
	update_option( IntegrationSettings::OPTION, [ 'wpforo_enabled' => '1', 'dokan_enabled' => $on ? '1' : '0' ] );
	wp_set_current_user( $previous );
};
$settings( true );
WPF()->settings->authorization['manually_approval'] = 'manual-approval' === $scenario;
WPF()->settings->authorization['user_register'] = true;
WPF()->settings->authorization['role_synch'] = true;
$check( IntegrationSettings::enabled( 'dokan' ), 'Dokan adapter enabled' );
WC()->initialize_session();
$handler = dokan_get_container()->get( 'frontend_manager' )->become_a_vendor;
$check( $handler instanceof \WeDevs\Dokan\Frontend\MyAccount\BecomeAVendor, 'real registered migration handler' );

// Throw before the native exit so each actual handler result can be asserted in this CLI fixture.
final class PinovaDokanFixtureRedirect extends RuntimeException {}
final class PinovaDokanFixtureDenied extends RuntimeException {}
$redirect = static function ( string $url ): string { throw new PinovaDokanFixtureRedirect( $url ); };
$die = static fn() => static function (): void { throw new PinovaDokanFixtureDenied(); };
add_filter( 'wp_redirect', $redirect, PHP_INT_MAX );
add_filter( 'wp_die_handler', $die );
$attempt = static function ( int $id, array $overrides = [] ) use ( $handler ): array {
	wp_set_current_user( $id );
	$_POST = array_merge( [ 'dokan_migration' => '1', 'dokan_nonce' => wp_create_nonce( 'account_migration' ), 'fname' => 'Synthetic',
		'lname' => 'Vendor', 'shopname' => 'Fixture shop', 'phone' => '09350000001', 'shopurl' => 'fixture-shop-' . $id ], $overrides );
	wc_clear_notices();
	try {
		Load::guard_migration();
		$handler->become_a_seller_form_handler();
		return [ 'redirect' => '', 'denied' => false ];
	} catch ( PinovaDokanFixtureRedirect $e ) {
		return [ 'redirect' => $e->getMessage(), 'denied' => false ];
	} catch ( PinovaDokanFixtureDenied $e ) {
		return [ 'redirect' => '', 'denied' => true ];
	} finally {
		Load::clear_attempt();
		$_POST = [];
	}
};
$prove = static function ( int $id ) use ( &$otps, $check ): void {
	wp_set_current_user( $id );
	$mobile = UserService::get_mobile( $id );
	$otp = OTP::query()->create( [ 'user_id' => $id, 'identifier' => $mobile, 'type' => OTP::TYPE_VERIFY_MOBILE,
		'code' => '4821', 'flow_id' => bin2hex( random_bytes( 16 ) ), 'channels' => [ 'sms' => true ] ] );
	$otps[] = $otp->id;
	OTPService::verify_with_flow( OTPService::signed_state( $otp ), '4821', [ OTP::TYPE_VERIFY_MOBILE ] );
	$check( Proof::is_verified( $id ), 'real account-bound purpose-specific proof' );
};
$id = $account( 'customer', true );
$login = get_userdata( $id )->user_login;
$order = wc_create_order( [ 'customer_id' => $id ] );
$check( ! is_wp_error( $order ), 'synthetic existing order' );
$orders[] = $order;
$target = home_url( '/checkout/?next=%2Fshop%3Fx%3D1&name=a%2Bb#payment' );
$result = $attempt( $id, [ 'back_url' => $target ] );
$check( str_contains( $result['redirect'], 'pinova_verify_mobile=1' ) && ! dokan_is_user_seller( $id ), 'missing mobile goes to proof without conversion' );
parse_str( wp_parse_url( $result['redirect'], PHP_URL_QUERY ), $proof_query );
parse_str( wp_parse_url( $proof_query['back_url'], PHP_URL_QUERY ), $migration_query );
$check( str_contains( $proof_query['back_url'], 'account-migration' ) && $target === $migration_query['back_url'], 'proof resumes native onboarding before final checkout destination' );
$check( 'pending' === get_user_meta( $id, Load::STATE_META, true ), 'durable onboarding ownership' );
$settings( false );
$check( $attempt( $id )['denied'] && Load::denied( $id ), 'off cannot bypass owned pending' );
$settings( true );
$mobile = '0913' . str_pad( (string) $id, 7, '0', STR_PAD_LEFT );
update_user_meta( $id, 'pinova_mobile', $mobile );
update_user_meta( $id, 'pinova_mobile_verified', '1' );
$check( str_contains( $attempt( $id )['redirect'], 'pinova_verify_mobile=1' ), 'legacy flags and profile phone are not proof' );
$prove( $id );
$duplicate = $account();
update_user_meta( $duplicate, 'pinova_mobile', $mobile );
$check( ! Load::can_convert( $id ) && str_contains( $attempt( $id )['redirect'], 'pinova_verify_mobile=1' ), 'conflicting proof ownership fails closed' );
delete_user_meta( $duplicate, 'pinova_mobile' );
$check( Load::can_convert( $id ), 'unique current proof restores eligibility' );
$check( $attempt( $admin )['denied'], 'native-only administrator cannot lose role through direct native POST' );
$manager = $account( 'shop_manager' );
$check( $attempt( $manager )['denied'] && in_array( 'shop_manager', get_userdata( $manager )->roles, true ), 'commerce manager role is protected' );

wp_set_current_user( $id );
$primary = WPF()->member->get_groupid( $id );
$secondary = 5 === (int) $primary ? 3 : 5;
WPF()->member->set_secondary_groupids( $id, [ $secondary ] );
$status = WPF()->member->get_status( $id );
$membership = get_user_meta( $id, Forum::STATE_META, true );
$email_confirmed = WPF()->member->get_is_email_confirmed( $id );
$result = $attempt( $id, [ 'shopname' => '' ] );
$check( '' === $result['redirect'] && ! $result['denied'] && wc_notice_count( 'error' ) > 0 && ! dokan_is_user_seller( $id ), 'native required-field validation retained' );
$check( [ $secondary ] === array_map( 'intval', WPF()->member->get_secondary_groupids( $id, true ) ), 'failed conversion leaves forum groups intact' );
$check( 'pending' === get_user_meta( $id, Load::STATE_META, true ), 'failed conversion remains pending for retry' );
$check( '' === $attempt( $id, [ 'dokan_nonce' => 'invalid' ] )['redirect'] && ! dokan_is_user_seller( $id ), 'invalid nonce never converts' );

// Warm the real RAM cache and inspect it again after conversion, not just fresh SQL getters.
WPF()->member->get_member( $id );
$activation = 'manually';
$activation_filter = static function () use ( &$activation ): string { return $activation; };
add_filter( 'dokan_new_seller_enable_selling_status', $activation_filter );
$result = $attempt( $id, [ 'back_url' => $target ] );
$check( $target === $result['redirect'], 'explicit checkout continuation takes precedence over vendor wizard' );
$user = get_userdata( $id );
$check( $user->ID === $id && $user->user_login === $login && [ 'seller' ] === array_values( $user->roles ), 'same account and immutable login with native seller-only roles' );
$check( '' === $user->user_email && $email_confirmed === WPF()->member->get_is_email_confirmed( $id ), 'no fabricated email or email proof' );
$check( 'no' === get_user_meta( $id, 'dokan_enable_selling', true ) && 'no' === get_user_meta( $id, 'dokan_publishing', true ), 'native selling activation and product review retained' );
$check( $primary === WPF()->member->get_groupid( $id ) && [ $secondary ] === array_map( 'intval', WPF()->member->get_secondary_groupids( $id, true ) ), 'forum primary and secondary groups preserved' );
$cached_member = WPF()->member->get_member( $id );
$check( in_array( $secondary, array_map( 'intval', $cached_member['secondary_groupids'] ), true ) && in_array( $secondary, array_map( 'intval', WPF()->current_user_secondary_groupids ), true ), 'same-request cached forum permissions refreshed' );
$check( $status === WPF()->member->get_status( $id ) && $membership === get_user_meta( $id, Forum::STATE_META, true ), 'manual approval or owned forum state preserved' );
$check( ( 'manual-approval' === $scenario ) === Forum::denied( $id ), 'actual forum membership guard retains approval policy after vendor conversion' );
$check( 'completed' === get_user_meta( $id, Load::STATE_META, true ), 'owned onboarding completes only after native conversion' );
$check( ( new Identifier( $mobile ) )->get_value() === UserService::get_mobile( $id ) && null === UserService::match( new Identifier( '09350000001' ) ), 'shop contact phone never becomes login alias' );
$check( $id === wc_get_order( $order->get_id() )->get_customer_id(), 'existing order account preserved' );
$shop = get_user_meta( $id, 'dokan_profile_settings', true );
$attempt( $id, [ 'shopname' => 'Must not replace store' ] );
$check( $shop === get_user_meta( $id, 'dokan_profile_settings', true ) && 'no' === get_user_meta( $id, 'dokan_enable_selling', true ), 'existing disabled vendor remains disabled with unchanged store' );

$automatic = $account();
update_user_meta( $automatic, 'pinova_mobile', '0914' . str_pad( (string) $automatic, 7, '0', STR_PAD_LEFT ) );
$prove( $automatic );
$activation = 'automatically';
$result = $attempt( $automatic );
$check( '' !== $result['redirect'] && 'yes' === get_user_meta( $automatic, 'dokan_enable_selling', true )
	&& 'no' === get_user_meta( $automatic, 'dokan_publishing', true ), 'native automatic activation still retains product review' );

// A failed/finished request never restores its snapshot onto an unrelated trusted PHP conversion.
$other = $account();
WPF()->member->set_secondary_groupids( $other, [ 5 ] );
dokan_user_update_to_seller( get_userdata( $other ), [ 'fname' => 'Trusted', 'lname' => 'Fixture', 'shopname' => 'Other', 'phone' => '', 'shopurl' => 'other-' . $other, 'address' => '' ] );
$check( '' === (string) WPF()->member->get_secondary_groupids( $other, false ) && '' === get_user_meta( $other, Load::STATE_META, true ), 'unrelated trusted conversion retains native semantics' );

wp_set_current_user( 0 );
$_POST = [ 'role' => 'seller', 'woocommerce-register-nonce' => wp_create_nonce( 'woocommerce-register' ) ];
$created = wc_create_new_customer( 'pinova-dokan-' . $id . '@example.test', 'public-vendor-' . $id, wp_generate_password( 32 ) );
if ( ! is_wp_error( $created ) ) {
	$users[] = $created;
}
$check( is_wp_error( $created ) && in_array( 'pinova_vendor_login_required', $created->get_error_codes(), true ), 'native WooCommerce public seller registration denied before account creation' );
$check( 'customer' === apply_filters( 'woocommerce_new_customer_data', [ 'role' => 'seller' ] )['role'], 'final pre-insert seller boundary covers checkout bypass' );
$_POST = [];

// Exercise actual AJAX callbacks and JSON envelopes; fixture die exceptions replace only termination.
define( 'DOING_AJAX', true );
$ajax_die = static fn() => static function (): void { throw new PinovaDokanFixtureDenied(); };
add_filter( 'wp_die_ajax_handler', $ajax_die );
$_REQUEST['_wpnonce'] = wp_create_nonce( 'dokan_reviews' );
foreach ( [ 'ajax_form', 'ajax_login' ] as $method ) {
	ob_start();
	try {
		Load::$method();
	} catch ( PinovaDokanFixtureDenied $e ) {
		// wp_send_json emitted the real JSON before asking WordPress to terminate.
	}
	$payload = json_decode( ob_get_clean(), true );
	$check( is_array( $payload ) && isset( $payload['success'], $payload['data'] ), 'AJAX JSON envelope: ' . $method );
	if ( 'ajax_form' === $method ) {
		$check( true === $payload['success'] && isset( $payload['data']['title'], $payload['data']['html'] ) && str_contains( $payload['data']['html'], 'back_url=' ), 'AJAX popup is actionable Pinova link' );
	} else {
		$check( false === $payload['success'] && isset( $payload['data']['message'], $payload['data']['login_url'] ) && 400 === http_response_code(), 'AJAX stale password form returns controlled JSON 400' );
	}
}
$_REQUEST['_wpnonce'] = 'invalid';
$rejected_nonce = false;
try {
	Load::ajax_form();
} catch ( PinovaDokanFixtureDenied $e ) {
	$rejected_nonce = true;
}
$check( $rejected_nonce, 'AJAX retains original Dokan nonce requirement' );
unset( $_REQUEST['_wpnonce'] );
remove_filter( 'wp_die_ajax_handler', $ajax_die );
remove_filter( 'wp_die_handler', $die );
remove_filter( 'wp_redirect', $redirect, PHP_INT_MAX );
remove_filter( 'dokan_new_seller_enable_selling_status', $activation_filter );
$completed = true;
