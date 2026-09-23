#!/usr/bin/env bash
set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fixture_dir="$(mktemp -d)"
trap 'rm -rf -- "${fixture_dir}"' EXIT

mkdir -p "${fixture_dir}/pinova/vendor/composer" "${fixture_dir}/pinova/src"
touch "${fixture_dir}/pinova/pinova.php" "${fixture_dir}/pinova/readme.txt" \
  "${fixture_dir}/pinova/LICENSE" "${fixture_dir}/pinova/uninstall.php" \
  "${fixture_dir}/pinova/build-info.json" "${fixture_dir}/pinova/vendor/autoload.php" \
  "${fixture_dir}/pinova/vendor/composer/autoload_files.php" \
  "${fixture_dir}/pinova/src/Plugin.php"

make_zip() {
  rm -f -- "${fixture_dir}/package.zip"
  (cd "${fixture_dir}" && find pinova -type f -print | sort | zip -Xq package.zip -@)
}

reject_zip() {
  if bash "${project_dir}/tools/check-package-content.sh" "${fixture_dir}/package.zip" >/dev/null 2>&1; then
    echo "Package checker accepted a forbidden or incomplete ZIP." >&2
    exit 1
  fi
}

make_zip
bash "${project_dir}/tools/check-package-content.sh" "${fixture_dir}/package.zip"

for forbidden in \
  pinova/AGENTS.md \
  pinova/src/AGENTS.md \
  pinova/assets/development-notes.md \
  pinova/assets/css/index.scss \
  pinova/assets/css/index.css.map \
  pinova/vendor/package/.github/workflows/ci.yml \
  pinova/vendor/package/tests/fixture.php; do
  mkdir -p "${fixture_dir}/$(dirname "${forbidden}")"
  touch "${fixture_dir}/${forbidden}"
  make_zip
  reject_zip
  rm "${fixture_dir}/${forbidden}"
done

rm "${fixture_dir}/pinova/vendor/autoload.php"
make_zip
reject_zip
