#!/usr/bin/env bash
# Disposable GHA only. All WordPress/plugin execution stays in loopback-only netns.
set -euo pipefail
umask 077

if [[ "${GITHUB_ACTIONS:-}" != true || "${RUNNER_ENVIRONMENT:-}" != github-hosted \
	|| ! "${GITHUB_RUN_ID:-}" =~ ^[0-9]+$ || ! "${GITHUB_RUN_ATTEMPT:-}" =~ ^[0-9]+$ \
	|| "${EUID}" != 0 ]]; then
	printf 'Full-stack FPM fixture requires root on a disposable GitHub-hosted runner.\n' >&2
	exit 1
fi
run_root="/tmp/pinova-fpm-full-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
if [[ "${PINOVA_FPM_FULL_STACK_ROOT:-}" != "${run_root}" || -L "${run_root}" \
	|| ! -d "${run_root}/wordpress" || -L "${run_root}/wordpress" ]]; then
	printf 'Unexpected full-stack fixture path or ownership.\n' >&2
	exit 1
fi
repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
fixture_root="${repo_root}/tests/fixtures/fpm-otp"
fpm_bin="${PINOVA_FPM_BIN:?Missing native FPM binary}"
php_bin="${PINOVA_FPM_PHP_BIN:-$(command -v php)}"
php_version="${PINOVA_FPM_PHP_VERSION:-8.3}"
nginx_bin="${PINOVA_FPM_NGINX_BIN:?Missing native nginx binary}"
wp_cli="${PINOVA_FPM_WP_CLI:?Missing executable WP-CLI}"
mysql_socket="${PINOVA_FPM_MYSQL_SOCKET:?Missing existing MySQL Unix socket}"
[[ "${php_version}" =~ ^8\.[34]$ && -x "${php_bin}" && -x "${fpm_bin}" && -x "${nginx_bin}" && -x "${wp_cli}" && -S "${mysql_socket}" ]]
[[ "$("${php_bin}" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')" == "${php_version}" ]]
export PINOVA_FPM_PHP_VERSION="${php_version}" PINOVA_FPM_PHP_BIN="${php_bin}"
plugin_root="${PINOVA_FPM_PLUGIN_ROOT:-${repo_root}}"
if [[ "${plugin_root}" != "${repo_root}" ]]; then
	[[ "${plugin_root}" == "${run_root}/runtime" && -d "${plugin_root}" && ! -L "${plugin_root}" \
		&& "${PINOVA_FPM_REVISION_ARM:-}" =~ ^[ab][123]$ && "${PINOVA_FPM_REVISION_SOURCE:-}" =~ ^[a-f0-9]{40}$ ]]
fi
export PINOVA_FPM_FULL_STACK_FIXTURE=1
export PINOVA_FPM_PROVIDER_PORT=18081
export PINOVA_FPM_HTTP_PORT=18080
export PINOVA_FPM_REPO_ROOT="${repo_root}"

diagnostic_checkpoint() {
	[[ -n "${PINOVA_FPM_PROBE_CASE:-}" ]] || return 0
	local phase_name="$1" phase_arm="${PINOVA_FPM_REVISION_ARM:-}" phase_at_ms
	case "${phase_name}" in shell_prepare|shell_database|shell_config|shell_install|shell_activate|shell_setup|native_start) ;; *) return 0 ;; esac
	[[ "${phase_arm}" =~ ^[ab][123]$ ]] || phase_arm=''
	phase_at_ms="$(date +%s%3N)"
	if printf '{"phase":"%s","arm":"%s","at_ms":%s,"pid":%s,"resource_acceptance":false}\n' \
		"${phase_name}" "${phase_arm}" "${phase_at_ms}" "$$" > "${run_root}/phase-checkpoint.json.tmp"; then
		mv -- "${run_root}/phase-checkpoint.json.tmp" "${run_root}/phase-checkpoint.json" || true
	fi
	return 0
}
diagnostic_checkpoint shell_prepare

if [[ "${1:-}" != --isolated ]]; then
	[[ ! -e "${run_root}/fixture-owned" && ! -e "${run_root}/receipt" ]]
	node "${repo_root}/tests/Integration/otp-fpm-full-stack.mjs" --verify-profile
	mkdir "${run_root}/receipt" "${run_root}/private"
	# Only sanitized receipts are readable by the unprivileged artifact uploader.
	chmod 0755 "${run_root}/receipt"
	printf '%s\n' "${GITHUB_RUN_ID}:${GITHUB_RUN_ATTEMPT}" > "${run_root}/fixture-owned"
	diagnostic_checkpoint shell_database
	database="pinova_fpm_${GITHUB_RUN_ID}_${GITHUB_RUN_ATTEMPT}"
	[[ "${database}" =~ ^pinova_fpm_[0-9]+_[0-9]+$ && ${#database} -le 32 ]]
	mysql_args=(--protocol=socket --socket="${mysql_socket}" --user=root --batch --skip-column-names)
	if [[ -n "${PINOVA_FPM_MYSQL_DEFAULTS_FILE:-}" ]]; then
		[[ -f "${PINOVA_FPM_MYSQL_DEFAULTS_FILE}" && ! -L "${PINOVA_FPM_MYSQL_DEFAULTS_FILE}" ]]
		mysql_args=(--defaults-extra-file="${PINOVA_FPM_MYSQL_DEFAULTS_FILE}" "${mysql_args[@]}")
	fi
	if mysql "${mysql_args[@]}" -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${database}'; SELECT User FROM mysql.user WHERE User='${database}'" | grep -q .; then
		printf 'Refusing to reuse an existing fixture database.\n' >&2
		exit 1
	fi
	password="$(openssl rand -hex 24)"
	# Both identifiers and password are constrained above; never print SQL/config.
	# ShellCheck cannot follow this function's EXIT trap across the branch.
	# shellcheck disable=SC2317
	cleanup_database() {
		local status=$?
		trap - EXIT
		mysql "${mysql_args[@]}" <<SQL
DROP DATABASE IF EXISTS \`${database}\`;
DROP USER IF EXISTS '${database}'@'localhost';
SQL
		exit "${status}"
	}
	trap cleanup_database EXIT
	mysql "${mysql_args[@]}" <<SQL
CREATE DATABASE \`${database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '${database}'@'localhost' IDENTIFIED BY '${password}';
GRANT ALL PRIVILEGES ON \`${database}\`.* TO '${database}'@'localhost';
SQL
	[[ -f "${repo_root}/vendor/autoload.php" ]]
	mkdir -p "${run_root}/wordpress/wp-content/mu-plugins" "${run_root}/wordpress/wp-content/plugins"
	cp "${fixture_root}/full-stack-mu.php" "${run_root}/wordpress/wp-content/mu-plugins/pinova-fpm-fixture.php"
	for plugin in woocommerce wpforo dokan-lite elementor; do
		[[ ! -e "${run_root}/wordpress/wp-content/plugins/${plugin}" ]]
		cp -a "${repo_root}/.build/third-party/${plugin}" "${run_root}/wordpress/wp-content/plugins/${plugin}"
	done
	[[ ! -e "${run_root}/wordpress/wp-content/plugins/pinova" ]]
	ln -s "${plugin_root}" "${run_root}/wordpress/wp-content/plugins/pinova"
	printf 'off\n' > "${run_root}/mode"
	export PINOVA_FPM_DB_NAME="${database}" PINOVA_FPM_DB_PASSWORD="${password}"
	# A new namespace has no external interfaces/routes. The Unix DB socket is
	# shared explicitly; no global firewall, host networking, or foreign daemon changes.
	unshare --net -- bash "${BASH_SOURCE[0]}" --isolated
	exit
fi

[[ "$(cat "${run_root}/fixture-owned")" == "${GITHUB_RUN_ID}:${GITHUB_RUN_ATTEMPT}" ]]
ip link set lo up
if ip -o link show | grep -Ev '^[0-9]+: lo:' | grep -q . || ip route show | grep -q .; then
	printf 'Full-stack fixture requires a loopback-only network namespace.\n' >&2
	exit 1
fi
export PINOVA_FPM_NETWORK_ISOLATED=1
wp=("${php_bin}" "${wp_cli}" --allow-root --path="${run_root}/wordpress")
diagnostic_checkpoint shell_config
"${wp[@]}" config create --skip-check --dbname="${PINOVA_FPM_DB_NAME}" --dbuser="${PINOVA_FPM_DB_NAME}" \
	--dbpass="${PINOVA_FPM_DB_PASSWORD}" --dbhost="localhost:${mysql_socket}" --dbprefix=wp_fpm_ \
	> "${run_root}/private/config-create.log" 2>&1
# Config insertion uses WP-CLI's parser, before wp-settings.php executes.
"${wp[@]}" config set PINOVA_FPM_FULL_STACK_SITE true --raw > /dev/null
"${wp[@]}" config set DISABLE_WP_CRON true --raw > /dev/null
"${wp[@]}" config set WP_ENVIRONMENT_TYPE local > /dev/null
"${wp[@]}" config set WP_DEBUG false --raw > /dev/null
"${wp[@]}" config set WP_AUTO_UPDATE_CORE false --raw > /dev/null
"${wp[@]}" config set AUTOMATIC_UPDATER_DISABLED true --raw > /dev/null
"${wp[@]}" config set PINOVA_OTP_IMMEDIATE_DELIVERY "('on' === trim(file_get_contents('${run_root}/mode')))" --raw > /dev/null
diagnostic_checkpoint shell_install
"${wp[@]}" core install --url="http://127.0.0.1:${PINOVA_FPM_HTTP_PORT}" --title='Disposable FPM fixture' \
	--admin_user=fixture_admin --admin_password="${PINOVA_FPM_DB_PASSWORD}" --admin_email=admin@example.test --skip-email \
	> "${run_root}/private/install.log" 2>&1
diagnostic_checkpoint shell_activate
for plugin in woocommerce wpforo dokan-lite elementor pinova; do
	"${wp[@]}" plugin activate "${plugin}" >> "${run_root}/private/activate.log" 2>&1
done
unset PINOVA_FPM_DB_PASSWORD
diagnostic_checkpoint shell_setup
"${wp[@]}" eval-file "${fixture_root}/full-stack-site.php" --use-include \
	<<< '{"action":"setup"}' > "${run_root}/private/setup.log" 2>&1

cat > "${run_root}/fpm.conf" <<EOF
[global]
daemonize = no
error_log = ${run_root}/private/fpm.log
pid = ${run_root}/fpm.pid
; Ten fixed application workers plus one native status worker. This bounds
; instrumentation inventory identically in both revision arms; no host tuning.
process.max = 11
[fixture]
user = root
group = root
listen = ${run_root}/fpm.sock
pm = static
pm.max_children = 10
pm.status_path = /fpm-status
pm.status_listen = ${run_root}/status.sock
clear_env = yes
catch_workers_output = yes
request_terminate_timeout = 20s
request_terminate_timeout_track_finished = yes
env[GITHUB_RUN_ID] = ${GITHUB_RUN_ID}
env[GITHUB_RUN_ATTEMPT] = ${GITHUB_RUN_ATTEMPT}
env[PINOVA_FPM_FULL_STACK_ROOT] = ${run_root}
env[PINOVA_FPM_FULL_STACK_FIXTURE] = 1
env[PINOVA_FPM_PROVIDER_PORT] = ${PINOVA_FPM_PROVIDER_PORT}
php_admin_value[memory_limit] = 512M
php_admin_value[max_execution_time] = 0
php_admin_value[output_buffering] = 4096
; Match a restricted site policy without admitting the rest of procfs. The
; installed plugin symlink resolves outside the temporary WordPress tree.
php_admin_value[open_basedir] = ${run_root}:${repo_root}:/tmp:${mysql_socket}:/proc/meminfo
php_admin_flag[zlib.output_compression] = off
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_value[error_log] = ${run_root}/private/php.log
EOF
cat > "${run_root}/nginx.conf" <<EOF
daemon off;
master_process off;
pid ${run_root}/nginx.pid;
error_log ${run_root}/private/nginx.log;
events { worker_connections 256; }
http {
  access_log off;
  client_body_temp_path ${run_root}/client-body;
  fastcgi_temp_path ${run_root}/fastcgi;
  server {
    listen 127.0.0.1:${PINOVA_FPM_HTTP_PORT};
    root ${run_root}/wordpress;
    location = /fpm-status {
      include /etc/nginx/fastcgi_params;
      fastcgi_param SCRIPT_FILENAME \$document_root/index.php;
      fastcgi_pass unix:${run_root}/status.sock;
    }
    location / {
      include /etc/nginx/fastcgi_params;
      fastcgi_param SCRIPT_FILENAME \$document_root/index.php;
      fastcgi_param SCRIPT_NAME /index.php;
      fastcgi_pass unix:${run_root}/fpm.sock;
      fastcgi_buffering off;
      fastcgi_read_timeout 25s;
    }
  }
}
EOF
fpm_pid='' nginx_pid=''
cleanup_processes() {
	local status=$? pid command_line
	trap - EXIT
	for pid in "${nginx_pid}" "${fpm_pid}"; do
		if [[ -n "${pid}" ]] && kill -0 "${pid}" 2>/dev/null; then
			command_line="$(tr '\0' ' ' < "/proc/${pid}/cmdline")"
			if [[ "${command_line}" != *"${run_root}"* ]]; then
				printf 'Refusing to stop a process without this run identity.\n' >&2
				status=1
				continue
			fi
			kill -TERM "${pid}"
			wait "${pid}" || true
		fi
	done
	exit "${status}"
}
trap cleanup_processes EXIT
diagnostic_checkpoint native_start
"${fpm_bin}" --allow-to-run-as-root -F -y "${run_root}/fpm.conf" > "${run_root}/private/fpm-start.log" 2>&1 &
fpm_pid=$!
export PINOVA_FPM_MASTER_PID="${fpm_pid}"
"${nginx_bin}" -p "${run_root}" -c "${run_root}/nginx.conf" > "${run_root}/private/nginx-start.log" 2>&1 &
nginx_pid=$!
node "${repo_root}/tests/Integration/otp-fpm-full-stack.mjs"
