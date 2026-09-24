#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 || ! -f "$1" ]]; then
  echo "Usage: $0 <release-zip>" >&2
  exit 2
fi

zip_path="$1"
entries="$(unzip -Z1 "${zip_path}")"

while IFS= read -r entry; do
  case "${entry}" in
    */AGENTS.md|*/CLAUDE.md|*/GEMINI.md|*/.github/*|*/.agents/*|*/.cursor/*|*/.codex/*|*/test/*|*/tests/*|*/Test/*|*/Tests/*|*/docs/*|*/examples/*|pinova/vendor/bin/*|*/.gitignore|*/.gitattributes|*/.editorconfig|*/CONTRIBUTING*|*/phpunit.xml*|*/phpstan.neon*|*/psalm.xml*|*.scss|*.map)
      echo "Unexpected release ZIP entry: ${entry}" >&2
      exit 1
      ;;
    pinova/assets/*.css|pinova/assets/*.js|pinova/assets/*.png|pinova/assets/*.svg|pinova/assets/*.woff2|pinova/src/*.php|pinova/templates/*.php|pinova/utils/*.php|pinova/vendor/*|pinova/LICENSE|pinova/build-info.json|pinova/pinova.php|pinova/readme.txt|pinova/uninstall.php)
      ;;
    *)
      echo "Unexpected release ZIP entry: ${entry}" >&2
      exit 1
      ;;
  esac
done <<< "${entries}"

for required in pinova/pinova.php pinova/readme.txt pinova/LICENSE pinova/uninstall.php pinova/build-info.json pinova/vendor/autoload.php pinova/vendor/composer/autoload_files.php; do
  if ! grep -Fxq "${required}" <<< "${entries}"; then
    echo "Missing release ZIP entry: ${required}" >&2
    exit 1
  fi
done
