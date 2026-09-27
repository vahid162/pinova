<?php
/** Archived package -> candidate -> archived package -> candidate, on synthetic data. */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'PINOVA_THIRD_PARTY_BASELINE' ) || true !== PINOVA_THIRD_PARTY_BASELINE
	|| 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), [ 'localhost', '127.0.0.1' ], true ) ) {
	throw new RuntimeException( 'Upgrade regressions require the disposable loopback fixture.' );
}
add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'pre_http_request', static fn() => new WP_Error( 'fixture_no_network' ) );
$phase = $args[0] ?? '';
$check = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( 'Upgrade regression failed: ' . $message );
	}
};
$archived = str_contains( PINOVA_DIR, '/.build/third-party/pinova' );
$check( in_array( $phase, [ 'seed', 'upgrade', 'rollback', 'cleanup' ], true ), 'known phase' );
$check( $archived === in_array( $phase, [ 'seed', 'rollback' ], true ), 'correct package for phase' );
$key = 'pinova_upgrade_fixture';
if ( 'seed' === $phase ) {
	$check( false === get_option( $key ), 'fresh disposable fixture' );
	$id = wp_insert_user( [ 'user_login' => 'pinova_upgrade_fixture', 'user_pass' => wp_generate_password( 32 ), 'user_email' => 'upgrade@example.test', 'role' => 'customer' ] );
	$check( ! is_wp_error( $id ), 'synthetic account' );
	update_user_meta( $id, 'pinova_mobile', '09126660001' );
	$order = wc_create_order( [ 'customer_id' => $id ] );
	$check( ! is_wp_error( $order ), 'synthetic order' );
	add_option( $key, [ 'id' => $id, 'order' => $order->get_id(), 'login' => get_userdata( $id )->user_login,
		'general' => get_option( 'pinova_general', null ), 'native' => get_option( 'pinova_native_login', null ),
		'integrations' => get_option( 'pinova_integrations', null ) ] );
} else {
	$fixture = get_option( $key );
	$check( is_array( $fixture ), 'persisted fixture' );
	$id = $fixture['id'];
	$check( get_userdata( $id )->user_login === $fixture['login'] && '+989126660001' === \Pinova\Services\UserService::get_mobile( $id ), 'account and mobile unchanged' );
	$check( wc_get_order( $fixture['order'] )->get_customer_id() === $id, 'order account unchanged' );
	$check( get_option( 'pinova_general', null ) === $fixture['general'] && get_option( 'pinova_native_login', null ) === $fixture['native'], 'saved settings unchanged' );
	$check( \Pinova\Install::migrate() && \Pinova\Install::migrate(), 'idempotent schema' );
	if ( 'upgrade' === $phase ) {
		$check( ! \Pinova\Integrations\IntegrationSettings::enabled( 'wpforo' ) && ! \Pinova\Integrations\IntegrationSettings::enabled( 'dokan' ), 'adapters remain off' );
		$check( ! \Pinova\Services\MobileVerificationService::is_verified( $id ), 'legacy mobile is not proof' );
		$otp = \Pinova\Models\OTP::query()->create( [ 'user_id' => $id, 'identifier' => '09126660001', 'type' => 'login', 'code' => '4821',
			'flow_id' => bin2hex( random_bytes( 16 ) ), 'channels' => [ 'sms' => true ] ] );
		\Pinova\Services\OTPService::verify_with_flow( \Pinova\Services\OTPService::signed_state( $otp ), '4821', [ 'login' ] );
		$check( \Pinova\Services\MobileVerificationService::is_verified( $id ), 'candidate proof works after archived package' );
	} elseif ( 'rollback' === $phase ) {
		$check( '' !== get_user_meta( $id, '_pinova_mobile_proof', true ), 'rollback preserves additive metadata' );
		\Pinova\Services\UserService::login( $id, 'otp' );
		$check( get_current_user_id() === $id, 'archived native session remains usable' );
	} else {
		$check( \Pinova\Services\MobileVerificationService::is_verified( $id ), 'candidate reload retains unchanged identity proof' );
		wp_set_current_user( 0 );
		wc_get_order( $fixture['order'] )->delete( true );
		\Pinova\Models\OTP::query()->where( 'user_id', $id )->delete();
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$check( wp_delete_user( $id ), 'synthetic account cleanup' );
		delete_option( $key );
	}
}
WP_CLI::line( 'PINOVA_UPGRADE_COMPLETE ' . $phase );
