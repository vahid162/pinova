<?php
/**
 * Characterize upstream behavior in the disposable, fully activated wp-env site.
 * These assertions describe the baseline, not the intended integration policy.
 * Run through tools/run-third-party-baseline.sh, never against a real site.
 */

declare(strict_types=1);

use Pinova\Services\UserService;

$staging_url = getenv( 'PINOVA_STAGING_BASELINE_URL' );
$local_target = 'local' === wp_get_environment_type() && in_array( wp_parse_url( home_url(), PHP_URL_HOST ), [ 'localhost', '127.0.0.1' ], true );
$staging_target = 'staging' === wp_get_environment_type() && is_string( $staging_url ) && 'https' === wp_parse_url( $staging_url, PHP_URL_SCHEME )
	&& hash_equals( untrailingslashit( $staging_url ), untrailingslashit( home_url() ) );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'PINOVA_THIRD_PARTY_BASELINE' ) || true !== PINOVA_THIRD_PARTY_BASELINE
	|| ( ! $local_target && ! $staging_target ) ) {
	throw new RuntimeException( 'Third-party baseline requires its disposable local site or explicitly selected staging URL.' );
}

$scenario = $args[0] ?? '';
if ( ! in_array( $scenario, [ 'normal', 'manual-approval' ], true ) ) {
	throw new RuntimeException( 'Unknown third-party baseline scenario.' );
}

$check = static function ( bool $condition, string $label ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'Baseline assertion failed: ' . $label );
	}
};

$baseline = json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/.build/third-party/verified.json' ), true, 512, JSON_THROW_ON_ERROR );
$plugin_versions = array_column( $baseline['plugins'], 'version', 'slug' );
$check( defined( 'WPFORO_VERSION' ) && $plugin_versions['wpforo'] === WPFORO_VERSION, 'wpForo fixture version' );
$check( defined( 'DOKAN_PLUGIN_VERSION' ) && $plugin_versions['dokan-lite'] === DOKAN_PLUGIN_VERSION, 'Dokan fixture version' );
$check( defined( 'WC_VERSION' ) && $baseline['woocommerce'] === WC_VERSION, 'WooCommerce fixture version' );
$check( $baseline['wordpress'] === get_bloginfo( 'version' ), 'WordPress fixture version' );
$check( defined( 'PINOVA_VERSION' ) && class_exists( UserService::class ), 'Pinova is active' );
$check( WPF()->is_installed(), 'wpForo installation completed' );
$check( false !== has_action( 'wp_login', [ WPF()->member, 'wp_login' ] ), 'real wpForo login callback' );
$check( false !== has_action( 'after_password_reset', [ WPF()->member, 'after_password_reset' ] ), 'real wpForo reset callback' );

// No mail or HTTP provider request from the synthetic scenarios may leave the site.
add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'pre_http_request', static fn() => new WP_Error( 'pinova_baseline_no_network' ) );

WPF()->settings->authorization['manually_approval'] = 'manual-approval' === $scenario;
WPF()->settings->authorization['user_register_email_confirm'] = true;

$user_id = wp_insert_user(
	[
		'user_login' => 'pinova_baseline_' . wp_generate_password( 12, false ),
		'user_pass'  => wp_generate_password( 32 ),
		'user_email' => '',
		'role'       => 'subscriber',
	]
);
$check( ! is_wp_error( $user_id ), 'synthetic account creation' );
$user = get_userdata( $user_id );
$original_login = $user->user_login;
$cookie_seen = false;
$login_callback_seen = false;
$login_returned = false;
$denial_redirect_seen = false;
$completed = false;

// exit() in wpForo does not execute finally. Shutdown records that case and
// removes only this process's synthetic user, including its session tokens.
register_shutdown_function(
	static function () use ( $check, $scenario, $user_id, &$cookie_seen, &$login_callback_seen, &$login_returned, &$denial_redirect_seen, &$completed ): void {
		try {
			if ( 'manual-approval' === $scenario ) {
				$check( null === error_get_last() || ! in_array( error_get_last()['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ], true ), 'no fatal error mistaken for denial' );
				$check( $cookie_seen && $login_callback_seen && $denial_redirect_seen && ! $login_returned, 'cookie hook precedes wpForo denial redirect and exit' );
				$check( 'inactive' === WPF()->member->get_status( $user_id ), 'manual approval preserves inactive status' );
				$completed = true;
			}
		} finally {
			wp_set_current_user( 0 );
			require_once ABSPATH . 'wp-admin/includes/user.php';
			$check( wp_delete_user( $user_id ), 'synthetic account cleanup' );
		}
		if ( $completed ) {
			WP_CLI::line( 'PINOVA_BASELINE_COMPLETE ' . $scenario );
		}
	}
);

WPF()->member->synchronize_user( $user_id );
WPF()->member->update_profile_fields( $user_id, [ 'status' => 'inactive', 'is_email_confirmed' => 0 ], false );
$check( 'inactive' === WPF()->member->get_status( $user_id ), 'inactive fixture profile' );
$check( ! WPF()->member->get_is_email_confirmed( $user_id ), 'unconfirmed fixture email' );

add_action( 'set_auth_cookie', static function () use ( &$cookie_seen ): void { $cookie_seen = true; } );
add_filter(
	'wp_redirect',
	static function ( $location ) use ( &$denial_redirect_seen ) {
		$denial_redirect_seen = $location === wpforo_url( '', 'cantlogin' );
		return $location;
	}
);
add_action(
	'wp_login',
	static function () use ( $check, &$cookie_seen, &$login_callback_seen ): void {
		$check( $cookie_seen, 'session cookie hook runs before login callbacks' );
		$login_callback_seen = true;
	},
	0
);
UserService::login( $user_id, 'otp' );
$login_returned = true;
$check( 'normal' === $scenario, 'manual approval must terminate the baseline login' );
$check( $cookie_seen && $login_callback_seen, 'native authentication hooks executed' );
$check( 'active' === WPF()->member->get_status( $user_id ), 'login activates inactive member without manual approval' );
$check( ! WPF()->member->get_is_email_confirmed( $user_id ), 'login alone preserves email flag' );

WPF()->member->update_profile_fields( $user_id, [ 'status' => 'inactive', 'is_email_confirmed' => 0 ], false );
reset_password( $user, wp_generate_password( 32 ) );
$check( WPF()->member->get_is_email_confirmed( $user_id ), 'upstream reset marks email confirmed even without an email' );
$check( 'active' === WPF()->member->get_status( $user_id ), 'upstream reset activates member' );

$user->add_role( 'contributor' );
WPF()->settings->authorization['role_synch'] = true;
$check( WPF()->member->set_secondary_groupids( $user_id, [ 5 ] ), 'synthetic secondary forum group' );
$check( [ 5 ] === array_map( 'intval', WPF()->member->get_secondary_groupids( $user_id ) ), 'secondary forum group persisted' );
dokan_user_update_to_seller(
	$user,
	[
		'shopurl'  => $original_login,
		'fname'    => 'Synthetic',
		'lname'    => 'Baseline',
		'shopname' => 'Synthetic baseline store',
		'phone'    => '',
		'address'  => [],
	]
);
$converted = get_userdata( $user_id );
$check( [ 'seller' ] === array_values( $converted->roles ), 'Dokan replaces all earlier roles' );
$check( '' === (string) WPF()->member->get_secondary_groupids( $user_id, false ), 'native seller role synchronization clears secondary forum groups' );
$check( $original_login === $converted->user_login, 'core account identity stays unchanged' );
$required = apply_filters( 'woocommerce_save_account_details_required_fields', [ 'account_email' => 'Email', 'account_first_name' => 'First name' ] );
$check( ! isset( $required['account_email'] ) && isset( $required['account_first_name'] ), 'Pinova makes account email optional for a seller too' );

$completed = true;
