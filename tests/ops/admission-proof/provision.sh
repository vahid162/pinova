#!/bin/bash
# NEVER MERGE. Download/extract an Ubuntu package; do not install or start a service.
set -euo pipefail
[[ "$EUID" == 0 && "${GITHUB_ACTIONS:-}" == true && "${RUNNER_ENVIRONMENT:-}" == github-hosted ]]
[[ "${GITHUB_REF:-}" == refs/heads/proof/native-admission-20261101 && "${GITHUB_RUN_ATTEMPT:-}" == 1 ]]
[[ "${GITHUB_RUN_ID:-}" =~ ^[0-9]+$ ]]
proof_root="/tmp/pinova-admission-${GITHUB_RUN_ID}-1"
[[ "${PINOVA_ADMISSION_PROOF_ROOT:-}" == "$proof_root" && ! -e "$proof_root" && ! -L "$proof_root" ]]
[[ -f /etc/apt/sources.list.d/ubuntu.sources ]]
umask 022
mkdir -m 0755 -- "$proof_root"
mkdir -m 0700 -- "$proof_root/package"
mkdir -m 0755 -- "$proof_root/package/lists" "$proof_root/package/cache"
exec >"$proof_root/package/setup.log" 2>&1
chmod 0600 "$proof_root/package/setup.log"
apt_options=(
  -o "Dir::State::lists=$proof_root/package/lists"
  -o "Dir::Cache=$proof_root/package/cache"
  -o Dir::Etc::sourcelist=/etc/apt/sources.list.d/ubuntu.sources
  -o Dir::Etc::sourceparts=-
  -o Acquire::Retries=0
)
/usr/bin/timeout --signal=TERM --kill-after=5s 150s apt-get "${apt_options[@]}" update -qq
cd -- "$proof_root/package"
/usr/bin/timeout --signal=TERM --kill-after=5s 60s apt-get "${apt_options[@]}" download nginx
packages=("$proof_root"/package/nginx_*.deb)
[[ ${#packages[@]} == 1 && -f "${packages[0]}" ]]
[[ "$(dpkg-deb --field "${packages[0]}" Package)" == nginx ]]
[[ "$(dpkg-deb --field "${packages[0]}" Architecture)" == amd64 ]]
dpkg-deb --extract "${packages[0]}" "$proof_root/package/root"
[[ "$PINOVA_NGINX_BINARY" == "$proof_root/package/root/usr/sbin/nginx" && -x "$PINOVA_NGINX_BINARY" ]]
exec /usr/bin/python3 -B "$PINOVA_PROOF_SOURCE/proof.py"
