#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 2 || ! -f "$1" || ! -f "$2" ]]; then
  echo "Usage: $0 <release-zip> <spdx-json>" >&2
  exit 2
fi

zip_path="$1"
sbom_path="$2"
installed_json="$(unzip -p "${zip_path}" pinova/vendor/composer/installed.json)"

if ! jq -e '
  (.packages | type == "array" and length > 0) and
  all(.packages[]; (.name | type == "string" and length > 0) and
                   (.version | type == "string" and length > 0))
' <<< "${installed_json}" >/dev/null; then
  echo "Release ZIP has no valid Composer production inventory." >&2
  exit 1
fi

if ! jq -e '
  .spdxVersion == "SPDX-2.3" and
  .SPDXID == "SPDXRef-DOCUMENT" and
  (.packages | type == "array")
' "${sbom_path}" >/dev/null; then
  echo "Release SBOM is not a valid SPDX 2.3 package document." >&2
  exit 1
fi

expected="$(jq -r '.packages[] | [.name, .version] | @tsv' <<< "${installed_json}" | LC_ALL=C sort)"
actual="$(jq -r '
  .packages[] | .name as $name | .versionInfo as $version |
  select(any(.externalRefs[]?; .referenceType == "purl" and
         (.referenceLocator | startswith("pkg:composer/")) and
         ((.referenceLocator | split("@")[0] | ltrimstr("pkg:composer/") | gsub("%2[fF]"; "/") | ascii_downcase) == ($name | ascii_downcase)) and
         ((.referenceLocator | split("@")[1] | split("?")[0] | gsub("%2[bB]"; "+")) == $version))) |
  [.name, .versionInfo] | @tsv
' "${sbom_path}" | LC_ALL=C sort)"

if [[ "${expected}" != "${actual}" ]]; then
  echo "Release SBOM Composer packages do not match the ZIP inventory." >&2
  diff -u <(printf '%s\n' "${expected}") <(printf '%s\n' "${actual}") >&2 || true
  exit 1
fi

echo "Release SBOM matches $(wc -l <<< "${expected}") installed Composer packages."
