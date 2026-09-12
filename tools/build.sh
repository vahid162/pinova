#!/usr/bin/env bash
set -euo pipefail

export TZ=UTC

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
build_dir="${project_dir}/.build"
stage_dir="${build_dir}/pinova"
version="$(php -r '$s=file_get_contents($argv[1]); preg_match("/Version:\\s*([0-9.]+)/", $s, $m); echo $m[1] ?? "dev";' "${project_dir}/pinova.php")"

if [[ -n "${COMPOSER_PHAR:-}" ]]; then
  php_binary="${PHP_BINARY:-php}"
  composer_command=("${php_binary}" "${COMPOSER_PHAR}")
else
  composer_command=(composer)
fi

required_composer_version="2.10.3"
composer_version="$("${composer_command[@]}" --no-ansi --version | sed -n 's/^Composer version \([^ ]*\).*/\1/p' | head -n 1)"
if [[ "${composer_version}" != "${required_composer_version}" ]]; then
  echo "Pinova release builds require Composer ${required_composer_version}; found ${composer_version:-unknown}." >&2
  exit 1
fi

rm -rf "${build_dir}"
mkdir -p "${stage_dir}"

tar -C "${project_dir}" \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='.agents' \
  --exclude='.gitignore' \
  --exclude='.activated' \
  --exclude='.build' \
  --exclude='.phpstan.cache' \
  --exclude='.phpunit.result.cache' \
  --exclude='node_modules' \
  --exclude='vendor' \
  --exclude='tests' \
  --exclude='tools' \
  --exclude='package.json' \
  --exclude='package-lock.json' \
  --exclude='AGENTS.md' \
  --exclude='README.md' \
  --exclude='phpunit.xml.dist' \
  --exclude='phpunit.integration.xml.dist' \
  --exclude='phpstan.neon.dist' \
  --exclude='phpcs.xml.dist' \
  --exclude='.wp-env.json' \
  -cf - . | tar -C "${stage_dir}" -xf -

install_args=(
  "--working-dir=${stage_dir}"
  --no-dev
  --no-interaction
  --no-scripts
  --prefer-dist
  --optimize-autoloader
)

if [[ "${PINOVA_IGNORE_FILEINFO:-0}" == "1" ]]; then
  install_args+=(--ignore-platform-req=ext-fileinfo)
fi

if [[ "${PINOVA_IGNORE_GD:-0}" == "1" ]]; then
  install_args+=(--ignore-platform-req=ext-gd)
fi

COMPOSER_ROOT_VERSION="${COMPOSER_ROOT_VERSION:-${version}}" "${composer_command[@]}" install "${install_args[@]}"

rm "${stage_dir}/composer.json" "${stage_dir}/composer.lock"

find "${stage_dir}" -type d -exec chmod 0755 {} +
find "${stage_dir}" -type f -exec chmod 0644 {} +

source_date_epoch="${SOURCE_DATE_EPOCH:-946684800}"
find "${stage_dir}" -exec touch -d "@${source_date_epoch}" {} +

(
  cd "${build_dir}"
  LC_ALL=C find pinova -type f -print | LC_ALL=C sort | zip -Xq "pinova-${version}.zip" -@
)
echo "Built ${build_dir}/pinova-${version}.zip"
