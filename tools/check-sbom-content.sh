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
  (.packages | type == "array" and all(.[]; type == "object"))
' "${sbom_path}" >/dev/null; then
  echo "Release SBOM is not a valid SPDX 2.3 package document." >&2
  exit 1
fi

expected="$(jq -r '.packages[] | [.name, .version] | @tsv' <<< "${installed_json}" | LC_ALL=C sort)"
expected_names="$(jq -c '[.packages[].name | ascii_downcase]' <<< "${installed_json}")"
if ! actual="$(jq -r --argjson expected_names "${expected_names}" '
  def composer_refs:
    [.externalRefs[]? | select((.referenceLocator | type) == "string" and
      (.referenceLocator | ascii_downcase | test("^pkg:composer(?:[/@?#]|$)")))];
  def composer_marked:
    . as $package |
    ((($package.name | type) == "string" and
      (($expected_names | index($package.name | ascii_downcase)) != null)) or
      ((composer_refs | length) > 0));

  .packages[] | select(composer_marked) | . as $package |
  (composer_refs) as $refs |
  if ($package.name | type) != "string" or ($package.name | length) == 0 or
     ($package.versionInfo | type) != "string" or ($package.versionInfo | length) == 0 or
     ($refs | length) != 1 or $refs[0].referenceType != "purl" then
    error("Invalid Composer package identity in release SBOM")
  else
    ($refs[0].referenceLocator |
      (capture("^pkg:composer/(?<name>[^@?#]+)@(?<version>[^?#]+)(?:\\?[^#]*)?(?:#.*)?$") // null)) as $purl |
    if $purl == null or
       (($purl.name | gsub("%2[fF]"; "/") | ascii_downcase) != ($package.name | ascii_downcase)) or
       (($purl.version | gsub("%2[bB]"; "+")) != $package.versionInfo) then
      error("Inconsistent Composer purl in release SBOM")
    else
      [$package.name, $package.versionInfo] | @tsv
    end
  end
' "${sbom_path}" | LC_ALL=C sort)"; then
  echo "Release SBOM contains an invalid Composer package entry." >&2
  exit 1
fi

if [[ "${expected}" != "${actual}" ]]; then
  echo "Release SBOM Composer packages do not match the ZIP inventory." >&2
  diff -u <(printf '%s\n' "${expected}") <(printf '%s\n' "${actual}") >&2 || true
  exit 1
fi

echo "Release SBOM matches $(wc -l <<< "${expected}") installed Composer packages."
