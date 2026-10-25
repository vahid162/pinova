#!/usr/bin/env bash
# Runs only on its own GitHub-hosted runner; never on a production/shared host.
set -euo pipefail

if [[ "${GITHUB_ACTIONS:-}" != true || "${RUNNER_ENVIRONMENT:-}" != github-hosted ]] \
	|| [[ ! "${GITHUB_RUN_ID:-}" =~ ^[0-9]+$ || ! "${GITHUB_RUN_ATTEMPT:-}" =~ ^[0-9]+$ ]]; then
	printf 'FPM characterization requires a GitHub-hosted disposable runner.\n' >&2
	exit 1
fi

run_root="/tmp/pinova-fpm-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
if [[ "${PINOVA_FPM_WORDPRESS_DIR:-}" != "${run_root}/wordpress" \
	|| "${PINOVA_FPM_WORK_DIR:-}" != "${run_root}/receipt" \
	|| -L "${run_root}" || -L "${PINOVA_FPM_WORDPRESS_DIR}" || -L "${PINOVA_FPM_WORK_DIR}" ]]; then
	printf 'Unexpected FPM fixture ownership or path.\n' >&2
	exit 1
fi
repo_root="$(git rev-parse --show-toplevel)"
fixture_root="${repo_root}/tests/fixtures/fpm-otp"
work_dir="${PINOVA_FPM_WORK_DIR}"
fpm_bin="${PINOVA_FPM_BIN:?Missing FPM binary}"
nginx_bin="${PINOVA_FPM_NGINX_BIN:?Missing nginx binary}"
[[ -x "${fpm_bin}" && -x "${nginx_bin}" && -f "${PINOVA_FPM_WORDPRESS_DIR}/wp-includes/version.php" ]]
[[ ! -e "${work_dir}" ]]
mkdir -p "${work_dir}/artifacts" "${work_dir}/pools"
fpm_pid=''
nginx_pid=''

cleanup() {
	local status=$? process_id command_line
	trap - EXIT
	for process_id in "${nginx_pid}" "${fpm_pid}"; do
		if [[ -n "${process_id}" ]] && kill -0 "${process_id}" 2>/dev/null; then
			command_line="$(tr '\0' ' ' < "/proc/${process_id}/cmdline")"
			if [[ "${command_line}" == *"${work_dir}"* ]]; then
				kill -TERM "${process_id}"
				wait "${process_id}" || true
			else
				printf 'Refusing to stop a process without this fixture identity.\n' >&2
				status=1
			fi
		fi
	done
	find "${work_dir}" -maxdepth 1 -type f \( -name '*.log' -o -name '*.jsonl' \) -exec cp -- '{}' "${work_dir}/artifacts/" \;
	exit "${status}"
}
trap cleanup EXIT

cat > "${work_dir}/fpm.conf" <<EOF
[global]
daemonize = no
error_log = ${work_dir}/fpm.log
pid = ${work_dir}/fpm.pid
include = ${work_dir}/pools/*.conf
EOF

for pool in normal untracked tracked unsupported; do
	track_finished=no
	timeout=0
	disabled=''
	if [[ "${pool}" == tracked ]]; then track_finished=yes; fi
	if [[ "${pool}" == tracked || "${pool}" == untracked ]]; then timeout=1s; fi
	if [[ "${pool}" == unsupported ]]; then disabled=fastcgi_finish_request; fi
	cat > "${work_dir}/pools/${pool}.conf" <<EOF
[${pool}]
user = $(id -un)
group = $(id -gn)
listen = ${work_dir}/${pool}.sock
pm = static
pm.max_children = 1
clear_env = yes
catch_workers_output = yes
request_terminate_timeout = ${timeout}
request_terminate_timeout_track_finished = ${track_finished}
env[PINOVA_FPM_FIXTURE] = 1
env[PINOVA_FPM_WORDPRESS_DIR] = ${PINOVA_FPM_WORDPRESS_DIR}
env[PINOVA_FPM_WORK_DIR] = ${work_dir}
php_admin_value[output_buffering] = 4096
php_admin_flag[zlib.output_compression] = off
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_value[error_log] = ${work_dir}/php-${pool}.log
php_admin_value[max_execution_time] = 0
php_admin_value[disable_functions] = ${disabled}
EOF
done

listen_port="$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); if (false === $s) { exit(1); } echo parse_url("tcp://" . stream_socket_get_name($s, false), PHP_URL_PORT);')"
cat > "${work_dir}/nginx.conf" <<EOF
daemon off;
master_process off;
pid ${work_dir}/nginx.pid;
error_log ${work_dir}/nginx.log;
events { worker_connections 128; }
http {
  access_log off;
  client_body_temp_path ${work_dir}/client-body;
  fastcgi_temp_path ${work_dir}/fastcgi;
  server {
    listen 127.0.0.1:${listen_port};
EOF
for pool in normal untracked tracked unsupported; do
	cat >> "${work_dir}/nginx.conf" <<EOF
    location /${pool} {
      include /etc/nginx/fastcgi_params;
      fastcgi_param SCRIPT_FILENAME ${fixture_root}/index.php;
      fastcgi_pass unix:${work_dir}/${pool}.sock;
      fastcgi_buffering off;
      fastcgi_read_timeout 12s;
    }
EOF
done
cat >> "${work_dir}/nginx.conf" <<EOF
  }
}
EOF

"${fpm_bin}" -F -y "${work_dir}/fpm.conf" > "${work_dir}/fpm-start.log" 2>&1 &
fpm_pid=$!
"${nginx_bin}" -p "${work_dir}" -c "${work_dir}/nginx.conf" > "${work_dir}/nginx-start.log" 2>&1 &
nginx_pid=$!

export PINOVA_FPM_BASE_URL="http://127.0.0.1:${listen_port}"
export PINOVA_FPM_FIXTURE=1
php "${repo_root}/tests/Integration/otp-fpm-response-boundary.php"
php "${fixture_root}/index.php" > "${work_dir}/cli-response.log"
php "${repo_root}/tests/Integration/otp-fpm-response-boundary.php" --verify-cli

