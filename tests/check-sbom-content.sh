#!/usr/bin/env bash
set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fixture_dir="$(mktemp -d)"
trap 'rm -rf -- "${fixture_dir}"' EXIT

mkdir -p "${fixture_dir}/pinova/vendor/composer"
printf '%s\n' '{"packages":[{"name":"example/runtime","version":"1.2.3"},{"name":"other/library","version":"v4.5.6"}]}' \
  > "${fixture_dir}/pinova/vendor/composer/installed.json"
(
  cd "${fixture_dir}"
  zip -Xq package.zip pinova/vendor/composer/installed.json
)

write_sbom() {
  jq -n --argjson packages "$1" '{
    spdxVersion: "SPDX-2.3",
    SPDXID: "SPDXRef-DOCUMENT",
    packages: $packages
  }' > "${fixture_dir}/sbom.json"
}

runtime='{"name":"example/runtime","versionInfo":"1.2.3","externalRefs":[{"referenceType":"purl","referenceLocator":"pkg:composer/example%2Fruntime@1.2.3"}]}'
other='{"name":"other/library","versionInfo":"v4.5.6","externalRefs":[{"referenceType":"purl","referenceLocator":"pkg:composer/other%2Flibrary@v4.5.6"}]}'
extra='{"name":"extra/package","versionInfo":"1.0.0","externalRefs":[{"referenceType":"purl","referenceLocator":"pkg:composer/extra%2Fpackage@1.0.0"}]}'
non_composer='{"name":"wordpress-core","versionInfo":"6.8","externalRefs":[{"referenceType":"purl","referenceLocator":"pkg:generic/wordpress@6.8"}]}'
write_sbom "[${runtime},${other}]"
bash "${project_dir}/tools/check-sbom-content.sh" "${fixture_dir}/package.zip" "${fixture_dir}/sbom.json" >/dev/null
write_sbom "[${runtime},${other},${non_composer}]"
bash "${project_dir}/tools/check-sbom-content.sh" "${fixture_dir}/package.zip" "${fixture_dir}/sbom.json" >/dev/null

reject_sbom() {
  local scenario="${1:-invalid Composer inventory}"
  if bash "${project_dir}/tools/check-sbom-content.sh" "${fixture_dir}/package.zip" "${fixture_dir}/sbom.json" >/dev/null 2>&1; then
    echo "SBOM checker accepted ${scenario}." >&2
    exit 1
  fi
}

write_sbom '[]'
reject_sbom
write_sbom "[${runtime}]"
reject_sbom
write_sbom "[${runtime},${runtime},${other}]"
reject_sbom
write_sbom "[${runtime},${other},${extra}]"
reject_sbom
write_sbom "[${runtime},{\"name\":\"other/library\",\"versionInfo\":\"4.5.6\",\"externalRefs\":[{\"referenceType\":\"purl\",\"referenceLocator\":\"pkg:composer/other%2Flibrary@4.5.6\"}]}]"
reject_sbom
write_sbom "[${runtime},{\"name\":\"other/library\",\"versionInfo\":\"v4.5.6\"}]"
reject_sbom
write_sbom "[${runtime},{\"name\":\"other/library\",\"versionInfo\":\"v4.5.6\",\"externalRefs\":[{\"referenceType\":\"purl\",\"referenceLocator\":\"pkg:composer/wrong%2Flibrary@v4.5.6\"}]}]"
reject_sbom
write_sbom "[${runtime},{\"name\":\"other/library\",\"versionInfo\":\"v4.5.6\",\"externalRefs\":[{\"referenceType\":\"purl\",\"referenceLocator\":\"pkg:composer/other%2Flibrary@v4.5.7\"}]}]"
reject_sbom
write_sbom "[${runtime},${other},{\"name\":\"extra/package\",\"versionInfo\":\"1.0.0\",\"externalRefs\":[{\"referenceType\":\"purl\",\"referenceLocator\":\"pkg:composer/extra%2Fpackage@2.0.0\"}]}]"
reject_sbom "an extra Composer entry with a mismatched purl version"
write_sbom "[${runtime},${other},{\"name\":\"extra/package\",\"versionInfo\":\"1.0.0\",\"externalRefs\":[{\"referenceType\":\"purl\",\"referenceLocator\":\"pkg:composer/extra%2Fpackage\"}]}]"
reject_sbom "an extra Composer entry with a malformed purl"
write_sbom "[${runtime},${other},{\"name\":\"extra/package\",\"versionInfo\":\"1.0.0\",\"externalRefs\":[{\"referenceType\":\"OTHER\",\"referenceLocator\":\"pkg:composer/extra%2Fpackage@1.0.0\"}]}]"
reject_sbom "a Composer purl with an inconsistent reference type"
write_sbom "[${runtime},{\"name\":\"other/library\",\"versionInfo\":\"v4.5.6\",\"externalRefs\":[{\"referenceType\":\"purl\",\"referenceLocator\":\"pkg:composer/other%2Flibrary@v4.5.6\"},{\"referenceType\":\"purl\",\"referenceLocator\":\"pkg:composer/other%2Flibrary@v4.5.7\"}]}]"
reject_sbom "a Composer entry with duplicate purl references"

echo "SBOM inventory tests passed."
