<?php
/** WP-CLI-only setup and finite observations on this disposable run's database. */

declare(strict_types=1);

use Pinova\Helpers\JWT;
use Pinova\Models\OTP;
use Pinova\Services\RateLimitService;

$root = (string) getenv( 'PINOVA_FPM_FULL_STACK_ROOT' );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'PINOVA_FPM_FULL_STACK_FIXTURE' )
	|| $root !== '/tmp/pinova-fpm-full-' . getenv( 'GITHUB_RUN_ID' ) . '-' . getenv( 'GITHUB_RUN_ATTEMPT' )
	|| realpath( ABSPATH ) !== $root . '/wordpress' || ! defined( 'PINOVA_FPM_FULL_STACK_SITE' ) || ! PINOVA_FPM_FULL_STACK_SITE
	|| ! str_starts_with( DB_NAME, 'pinova_fpm_' ) || is_link( $root ) ) {
	throw new RuntimeException( 'Invalid full-stack fixture ownership.' );
}

$input = json_decode( stream_get_contents( STDIN ), true, 32, JSON_THROW_ON_ERROR );
$action = $input['action'] ?? '';
global $wpdb;
$result = [];
switch ( $action ) {
	case 'setup':
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WPF' ) || ! class_exists( 'WeDevs_Dokan' ) || ! did_action( 'elementor/loaded' ) ) {
			throw new RuntimeException( 'The pinned representative plugins did not load.' );
		}
		update_option( 'users_can_register', 0 );
		update_option( 'pinova_sms', [ 'gateway' => 'MeliPayamak', 'message_code' => '' ] );
		update_option( 'pinova_gateway_melipayamak', [ 'username' => 'fixture-user', 'password' => 'fixture-secret', 'sender' => 'fixture-sender' ] );
		update_option( 'pinova_logging', [ 'minimum_level' => 'info', 'retention_days' => 14 ] );
		for ( $i = 1; $i <= 80; ++$i ) {
			$id = wp_insert_user( [ 'user_login' => 'fixture_' . $i, 'user_email' => 'fixture_' . $i . '@example.test', 'user_pass' => wp_generate_password( 32 ), 'role' => 80 === $i ? 'administrator' : 'customer' ] );
			if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Synthetic account setup failed.' ); }
			update_user_meta( $id, 'pinova_mobile', '0910000' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ) );
		}
		$page = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Fixture ordinary page', 'post_content' => 'Fixture ordinary content.' ], true );
		if ( is_wp_error( $page ) ) { throw new RuntimeException( 'Synthetic page setup failed.' ); }
		WC_Install::create_pages();
		$product = new WC_Product_Simple();
		$product->set_name( 'Fixture product' );
		$product->set_regular_price( '10' );
		$product->set_status( 'publish' );
		$product->save();
		$result = [ 'ordinary' => '/?page_id=' . $page, 'shop' => '/?post_type=product', 'cart' => '/?page_id=' . wc_get_page_id( 'cart' ), 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION,
			'hpos_enabled' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
		];
		break;
	case 'inspect':
		$token = $input['jwt'] ?? null;
		if ( ! is_string( $token ) ) { throw new RuntimeException( 'Expected signed state.' ); }
		$payload = JWT::decode( $token );
		$keys = isset( $payload['purpose'] ) ? [ 'flow_id', 'user_id', 'purpose', 'exp' ] : [ 'flow_id', 'exp' ];
		if ( array_keys( $payload ) !== $keys || ! is_string( $payload['flow_id'] ) || ! preg_match( '/\A[a-f0-9]{32}\z/D', $payload['flow_id'] )
			|| ( isset( $payload['purpose'] ) && ( 'verify_mobile' !== $payload['purpose'] || ! is_int( $payload['user_id'] ) || $payload['user_id'] <= 0 ) ) ) {
			throw new RuntimeException( 'Unexpected signed-state contract.' );
		}
		$rows = OTP::query()->where( 'flow_id', $payload['flow_id'] )->get();
		$queue = $wpdb->get_row( $wpdb->prepare( 'SELECT payload, reset_at FROM %i WHERE scope = %s', $wpdb->prefix . 'pinova_rate_limits', $payload['flow_id'] ), ARRAY_A );
		$stored = (string) ( $queue['payload'] ?? '' );
		$result = [
			'signature_valid' => true, 'expiry' => $payload['exp'], 'rows' => $rows->count(),
			'purpose' => 1 === $rows->count() ? $rows->first()->type : null,
			'accepted_channels' => 1 === $rows->count() ? array_keys( array_filter( $rows->first()->channels, static fn( $v ): bool => true === $v ) ) : [],
			'otp_expiry' => 1 === $rows->count() ? $rows->first()->expires_at->getTimestamp() : null,
			'queue_ready' => str_starts_with( $stored, 'ready:' ),
			'queue_encrypted' => '' !== $stored && ! str_contains( $stored, '0910000' ) && ! str_contains( $stored, '+98910000' ) && ! str_contains( $stored, 'example.test' ),
			'scheduled' => false !== wp_next_scheduled( 'pinova_otp_delivery', [ $payload['flow_id'] ] ),
		];
		break;
	case 'stats':
		$stats = $wpdb->get_results( "SHOW GLOBAL STATUS WHERE Variable_name IN ('Questions','Queries','Rows_read','Rows_sent','Rows_tmp_read')", ARRAY_A );
		$due = 0;
		foreach ( wp_get_ready_cron_jobs() as $hooks ) { $due += count( $hooks['pinova_otp_delivery'] ?? [] ); }
		$result = [ 'db' => array_column( $stats, 'Value', 'Variable_name' ), 'otp_rows' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'pinova_otp' ), 'due_jobs' => $due, 'redis' => 'not_applicable_not_installed' ];
		break;
	case 'nonce':
		$cookie = $input['logged_in_cookie'] ?? '';
		$user_id = is_string( $cookie ) ? wp_validate_auth_cookie( $cookie, 'logged_in' ) : false;
		if ( ! is_int( $user_id ) || $user_id <= 0 || ! str_starts_with( (string) get_userdata( $user_id )->user_login, 'fixture_' ) ) {
			throw new RuntimeException( 'Missing real synthetic login session.' );
		}
		$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
		wp_set_current_user( $user_id );
		$result = [ 'nonce' => wp_create_nonce( 'wp_rest' ) ];
		break;
	case 'expire':
		$payload = JWT::decode( (string) ( $input['jwt'] ?? '' ) );
		$wpdb->update( $wpdb->prefix . 'pinova_rate_limits', [ 'reset_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ], [ 'scope' => $payload['flow_id'] ] );
		$result = [ 'expired' => true ];
		break;
	case 'cancel':
		$payload = JWT::decode( (string) ( $input['jwt'] ?? '' ) );
		RateLimitService::retire_queued_otp( $payload['flow_id'] );
		$result = [ 'retired' => true ];
		break;
	default:
		throw new RuntimeException( 'Unknown finite fixture action.' );
}
echo 'PINOVA_FULL_STACK_RESULT=' . json_encode( $result, JSON_THROW_ON_ERROR ) . "\n";
