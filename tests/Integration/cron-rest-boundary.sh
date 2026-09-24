#!/usr/bin/env bash
# Embedded PHP is intentionally single-quoted.
# shellcheck disable=SC2016
set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
cd "${repo_root}"

base_url="${PLAYWRIGHT_BASE_URL:-}"
if [[ ! "${base_url}" =~ ^http://(localhost|127\.0\.0\.1):[0-9]+$ ]] \
	|| [[ "${WP_ENV_HOME:-}" != /tmp/pinova-browser-* ]] \
	|| [[ "${COMPOSE_PROJECT_NAME:-}" != pinova-browser-* ]]; then
	printf 'Cron boundary test requires its run-owned, loopback wp-env site.\n' >&2
	exit 1
fi

plugin_directory="$(basename "${repo_root}")"
response_file="$(mktemp)"
fixture_installed=0

wp_site() {
	npx wp-env run cli wp "$@"
}

cleanup() {
	local status=$?
	trap - EXIT
	if [[ "${fixture_installed}" == 1 ]]; then
		if ! wp_site eval 'if ( is_file( WPMU_PLUGIN_DIR . "/pinova-cron-boundary-test.php" ) && ! unlink( WPMU_PLUGIN_DIR . "/pinova-cron-boundary-test.php" ) ) { WP_CLI::error( "Could not remove Cron boundary fixture." ); }' >/dev/null; then
			printf 'Could not remove the disposable Cron fixture.\n' >&2
			status=1
		fi
	fi
	rm -f -- "${response_file}"
	exit "${status}"
}
trap cleanup EXIT

# This MU plugin exists only in the disposable site. It prevents automatic Cron
# and records provider calls without storing or sending any OTP material.
npx wp-env run cli --env-cwd="wp-content/plugins/${plugin_directory}" wp eval '
	if ( ! wp_mkdir_p( WPMU_PLUGIN_DIR ) || ! copy( getcwd() . "/tests/Integration/CronBoundaryFixture.php", WPMU_PLUGIN_DIR . "/pinova-cron-boundary-test.php" ) ) {
		WP_CLI::error( "Could not install the disposable Cron fixture." );
	}
'
fixture_installed=1

wp_site eval '
	if ( ! function_exists( "pinova_cron_boundary_intercept_mail" ) || ! defined( "DISABLE_WP_CRON" ) || ! DISABLE_WP_CRON || ! defined( "PINOVA_VERSION" ) ) {
		WP_CLI::error( "Cron fixture or Pinova did not load." );
	}
	if ( get_user_by( "email", "cron-boundary@example.test" ) ) {
		WP_CLI::error( "Disposable Cron test user already exists." );
	}
	$user_id = wp_insert_user( [ "user_login" => "pinova_cron_boundary_user", "user_pass" => wp_generate_password( 24 ), "user_email" => "cron-boundary@example.test", "role" => "subscriber" ] );
	if ( is_wp_error( $user_id ) ) {
		WP_CLI::error( "Could not create the disposable Cron test user." );
	}
	$shutdown_user = wp_insert_user( [ "user_login" => "pinova_cron_shutdown_user", "user_pass" => wp_generate_password( 24 ), "user_email" => "cron-shutdown@example.test", "role" => "subscriber" ] );
	if ( is_wp_error( $shutdown_user ) ) {
		WP_CLI::error( "Could not create the disposable shutdown-Cron test user." );
	}
	update_option( "pinova_cron_boundary_manual_cron", true, false );
	update_option( "pinova_cron_boundary_mail_calls", 0, false );
'

curl --fail --silent --show-error --max-time 20 \
	"${base_url}/wp-json/pinova-cron-boundary/v1/ready" |
	php -r '
		$data = json_decode( stream_get_contents( STDIN ), true );
		if ( true !== ( $data["ready"] ?? null ) ) {
			fwrite( STDERR, "Cron fixture is not active in the disposable web process.\n" );
			exit( 1 );
		}
'

cron_idle=0
for (( attempt = 0; attempt < 15; attempt++ )); do
	if wp_site eval 'if ( get_transient( "doing_cron" ) ) { exit( 1 ); }' >/dev/null 2>&1; then
		cron_idle=1
		break
	fi
	sleep 1
done
if [[ "${cron_idle}" != 1 ]]; then
	printf 'The disposable site has an active or stuck WordPress Cron lock.\n' >&2
	exit 1
fi

response_status="$(curl --fail --silent --show-error --max-time 20 \
	--header 'Content-Type: application/json' \
	--data '{"identifier":"cron-boundary@example.test","force_otp":true}' \
	--output "${response_file}" \
	--write-out '%{http_code}' \
	"${base_url}/wp-json/pinova/user/authenticate")"
if [[ "${response_status}" != 200 ]]; then
	printf 'Public OTP initiation returned an unexpected HTTP status.\n' >&2
	exit 1
fi

php -r '
	$data = json_decode( (string) file_get_contents( $argv[1] ), true );
	if ( ! is_array( $data ) || true !== ( $data["success"] ?? null ) || "otp" !== ( $data["data"]["login_method"] ?? null ) || ! is_string( $data["data"]["jwt"] ?? null ) || "" === $data["data"]["jwt"] ) {
		fwrite( STDERR, "Public OTP initiation did not return the expected generic state.\n" );
		exit( 1 );
	}
' "${response_file}"

wp_site eval '
	global $wpdb;
	$events = 0;
	foreach ( (array) _get_cron_array() as $hooks ) {
		$events += count( $hooks["pinova_otp_delivery"] ?? [] );
	}
	$records = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE `identifier` = %s", $wpdb->prefix . "pinova_otp", "cron-boundary@example.test" ) );
	if ( 1 !== $events || 0 !== $records || 0 !== (int) get_option( "pinova_cron_boundary_mail_calls", 0 ) ) {
		WP_CLI::error( "The public response crossed the queued-delivery boundary." );
	}
'

# This is a distinct HTTP request to WordPress's real Cron runner, not a direct
# invocation of the Pinova action. An unavailable or non-delivering runner fails.
curl --fail --silent --show-error --max-time 20 \
	--output /dev/null "${base_url}/wp-cron.php"

delivered=0
for (( attempt = 0; attempt < 20; attempt++ )); do
	if wp_site eval '
		$otp = \Pinova\Models\OTP::query()->where( "identifier", "cron-boundary@example.test" )->first();
		if ( 1 !== (int) get_option( "pinova_cron_boundary_mail_calls", 0 ) || ! $otp || true !== ( $otp->channels["email"] ?? null ) ) {
			exit( 1 );
		}
	' >/dev/null 2>&1; then
		delivered=1
		break
	fi
	sleep 1
done
if [[ "${delivered}" != 1 ]]; then
	printf 'Separate WordPress Cron request did not complete one intercepted OTP delivery.\n' >&2
	exit 1
fi

curl --fail --silent --show-error --max-time 20 \
	--output /dev/null "${base_url}/wp-cron.php"

wp_site eval '
	global $wpdb;
	$events = 0;
	foreach ( (array) _get_cron_array() as $hooks ) {
		$events += count( $hooks["pinova_otp_delivery"] ?? [] );
	}
	$records = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE `identifier` = %s", $wpdb->prefix . "pinova_otp", "cron-boundary@example.test" ) );
	if ( 0 !== $events || 1 !== $records || 1 !== (int) get_option( "pinova_cron_boundary_mail_calls", 0 ) ) {
		WP_CLI::error( "Repeating WordPress Cron changed the one-delivery result." );
	}
'

# With normal WordPress Cron enabled, Pinova must spawn a pass after the REST
# callback schedules delivery. The earlier manual phase cannot prove this hook.
wp_site eval 'update_option( "pinova_cron_boundary_manual_cron", false, false );'
response_status="$(curl --fail --silent --show-error --max-time 20 \
	--header 'Content-Type: application/json' \
	--data '{"identifier":"cron-shutdown@example.test","force_otp":true}' \
	--output "${response_file}" \
	--write-out '%{http_code}' \
	"${base_url}/wp-json/pinova/user/authenticate")"
if [[ "${response_status}" != 200 ]]; then
	printf 'Shutdown-Cron OTP initiation returned an unexpected HTTP status.\n' >&2
	exit 1
fi

spawned_delivery=0
for (( attempt = 0; attempt < 20; attempt++ )); do
	if wp_site eval '
		$otp = \Pinova\Models\OTP::query()->where( "identifier", "cron-shutdown@example.test" )->first();
		if ( 2 !== (int) get_option( "pinova_cron_boundary_mail_calls", 0 ) || ! $otp || true !== ( $otp->channels["email"] ?? null ) ) {
			exit( 1 );
		}
	' >/dev/null 2>&1; then
		spawned_delivery=1
		break
	fi
	sleep 1
done
if [[ "${spawned_delivery}" != 1 ]]; then
	printf 'The post-REST shutdown hook did not spawn queued WordPress Cron delivery.\n' >&2
	exit 1
fi

printf 'REST boundary, manual Cron delivery, and post-REST automatic Cron dispatch verified.\n'
