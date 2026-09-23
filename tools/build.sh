#!/usr/bin/env bash
set -euo pipefail

export TZ=UTC

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
build_dir="${project_dir}/.build"
stage_dir="${build_dir}/pinova"
build_commit="$(git -C "${project_dir}" rev-parse --verify HEAD)"
release_tag="${PINOVA_RELEASE_TAG:-}"

if [[ ! "${build_commit}" =~ ^[0-9a-f]{40}$ ]]; then
  echo "A full Git commit is required for Pinova package metadata." >&2
  exit 1
fi
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

git -C "${project_dir}" archive --format=tar "${build_commit}" | tar -C "${stage_dir}" \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='.agents' \
  --exclude='.gitignore' \
  --exclude='.build' \
  --exclude='build-info.json' \
  --exclude='.phpstan.cache' \
  --exclude='.phpunit.result.cache' \
  --exclude='node_modules' \
  --exclude='playwright-report' \
  --exclude='test-results' \
  --exclude='vendor' \
  --exclude='tests' \
  --exclude='tools' \
  --exclude='package.json' \
  --exclude='package-lock.json' \
  --exclude='AGENTS.md' \
  --exclude='README.md' \
  --exclude='CHANGELOG.md' \
  --exclude='phpunit.xml.dist' \
  --exclude='phpunit.integration.xml.dist' \
  --exclude='phpstan.neon.dist' \
  --exclude='phpcs.xml.dist' \
  --exclude='.wp-env.json' \
  -xf -

version="$(php -r "\$s=file_get_contents(\$argv[1]); preg_match('/Version:\\s*([0-9.]+)/', \$s, \$m); echo \$m[1] ?? 'dev';" "${stage_dir}/pinova.php")"
if [[ -n "${release_tag}" ]]; then
  if [[ ! "${release_tag}" =~ ^v([0-9]+\.[0-9]+\.[0-9]+)-rc[1-9][0-9]*$ ]]; then
    echo "The release tag is invalid." >&2
    exit 1
  fi
  if [[ "${BASH_REMATCH[1]}" != "${version}" ]]; then
    echo "The release tag does not match the package version." >&2
    exit 1
  fi
fi

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

if ! grep -Fq "utils/class-database.php" "${stage_dir}/vendor/composer/autoload_files.php"; then
	echo "Pinova database bootstrap is missing from Composer's eager autoload files." >&2
	exit 1
fi

rm "${stage_dir}/composer.json" "${stage_dir}/composer.lock"

# The single-quoted argument is PHP source and must not be shell-expanded.
# shellcheck disable=SC2016
PINOVA_BUILD_COMMIT="${build_commit}" PINOVA_RELEASE_TAG="${release_tag}" php -r '
  $metadata = [
    "build_commit" => getenv("PINOVA_BUILD_COMMIT"),
    "package_identity" => "pinova-release-zip",
  ];
  if ("" !== getenv("PINOVA_RELEASE_TAG")) {
    $metadata["release_tag"] = getenv("PINOVA_RELEASE_TAG");
  }
  $json = json_encode($metadata, JSON_UNESCAPED_SLASHES);
  if (!is_string($json) || false === file_put_contents($argv[1], $json . "\n")) {
    exit(1);
  }
' "${stage_dir}/build-info.json"

find "${stage_dir}" -type d -exec chmod 0755 {} +
find "${stage_dir}" -type f -exec chmod 0644 {} +

source_date_epoch="${SOURCE_DATE_EPOCH:-946684800}"
find "${stage_dir}" -exec touch -d "@${source_date_epoch}" {} +

(
  cd "${build_dir}"
  LC_ALL=C find pinova -type f -print | LC_ALL=C sort | zip -Xq "pinova-${version}.zip" -@
)
echo "Built ${build_dir}/pinova-${version}.zip"
