# RC21 publication evidence — 2026-10-26

RC21 contains the reviewed opt-in immediate OTP dispatcher. Eligible requests keep their durable encrypted queue entry and dispatch their exact acknowledged flow in the originating PHP-FPM process after the final healthy REST response and successful native `fastcgi_finish_request()`. The public response, absolute expiry, purpose binding and uncertain-delivery no-resend policy remain intact. Unsupported or resource-constrained requests retain the existing minute-runner fallback.

`PINOVA_OTP_IMMEDIATE_DELIVERY` is disabled by default. This public record establishes publication and package integrity only. It does not establish installation, activation, physical receipt, live latency or a completed 24-hour observation window. RC21 remains a prerelease; stable/latest promotion requires separate authorization.

## Source and publication provenance

[Implementation PR #95](https://github.com/vahid162/pinova/pull/95) was independently reviewed against the authentication, queue, privacy, response-boundary and resource invariants. The final six-lens source review had no open findings. The following are separate gates, all on the named source rather than a later documentation commit.

| Gate | Identity and result |
| --- | --- |
| Implementation head | `7caad8f8678520a6bd03ab628515e7419d005bcc`; [push Quality 40254227303](https://github.com/vahid162/pinova/actions/runs/40254227303) succeeded, 37 applicable jobs |
| PR exact-head checks | `pull_request` at that head; [Quality 40255742522](https://github.com/vahid162/pinova/actions/runs/40255742522) succeeded, 38 jobs including Dependency Review |
| Merged main | `96386b9fdd803086e9ecfe2af421034976dc185d`; [Quality 40257028825](https://github.com/vahid162/pinova/actions/runs/40257028825) succeeded after the bounded retry described below |
| Controlled publish branch | `push` on `publish/v1.3.0-rc21` at the same merged source; [Quality 40259077436](https://github.com/vahid162/pinova/actions/runs/40259077436) succeeded |
| Authorized dispatcher | [40259957488](https://github.com/vahid162/pinova/actions/runs/40259957488) succeeded; this dispatch result alone does not establish publication |
| Actual publisher | `workflow_dispatch` on the exact publish branch/source; [40259963578](https://github.com/vahid162/pinova/actions/runs/40259963578) succeeded, including reproducible builds, attestations and post-publication redownload |

The initial merged-main browser job for current Dokan with HPOS enabled failed before its fixture WordPress command, with Node TLS `ERR_SOCKET_CLOSED_BEFORE_CONNECTION`. Seven subsequent tests in that job passed. The failed job alone was retried without a source change or weakened assertions; its retry and the dependent required gate passed. The overall run reports attempt 2; other successful jobs were retained rather than rerun.

The source tree is `dc72906dfd80f4d254fb99b94e495995a2e8fe7c`, identical for the final implementation head and merged release source. The owner-confirmed native Release Immutability setting was recorded before creating the publish branch. The publisher built twice from the exact source later targeted by the annotated tag and required identical output, a production SPDX inventory, exact-source attestations, and native immutable publication.

## Native immutable release and assets

[Release v1.3.0-rc21](https://github.com/vahid162/pinova/releases/tag/v1.3.0-rc21), ID `423807439`, was independently read with `immutable: true`, `prerelease: true`, and `draft: false`. Annotated tag object `5eb9e91e9450947c86c8ecc7331ac8d43f225b19` targets `96386b9fdd803086e9ecfe2af421034976dc185d`. Plugin metadata remains `1.3.0`; `build-info.json` identifies this RC and source.

| Published asset | Bytes | SHA-256 |
| --- | ---: | --- |
| `pinova-1.3.0.zip` | 5,072,084 | `2cd4d3a6b380b24bed9c5fc38ebe0655bade3a180605c63624a03f3027aa9a43` |
| `pinova-1.3.0.zip.sha256` | 83 | `0b11e2d62ac20c37e284429455b61cda7747687b66341624bbd856ae930c7e7c` |
| `pinova-1.3.0.spdx.json` | 97,939 | `09418acfe9f58f2ea634d699ec94a71ae48668813766e9b6b8c44f2e81b3ae35` |
| `pinova-1.3.0.spdx.json.sha256` | 89 | `7e9ac077ba905fe5aa76416c8eb5e85e7189b2bc34fd083576afc140996820d0` |

## Independent published-package verification

A separate verification unit downloaded all four assets afresh after publication and matched native API digests. The pinned, unmodified GitHub CLI verified the native release and all four asset proofs, including their signed tag-plus-assets inventory. An intentionally altered checksum was rejected. No real credentials, shared CLI configuration, installed-site files, database operations or provider requests were used by this verification.

The retained signed TUF trust state was checked unexpired; it was not refreshed for RC21. The earliest retained Sigstore expiry was 2026-10-30 19:24:56 UTC, and the earliest GitHub expiry was 2026-10-31 07:27:25 UTC. Expired trust was not bypassed.

The inline SPDX attestation verified with the exact source digest, `refs/heads/publish/v1.3.0-rc21`, the publisher workflow certificate identity and a GitHub-hosted runner. Its signed predicate equals the freshly downloaded SPDX SBOM, and its subject equals the fresh ZIP digest. ZIP checksums, CRC, bounds, traversal/symlink/duplicate rejection, source allowlist, build identity and eager database autoload checks passed.

All 170 first-party packaged files and their inventory byte-match the pinned Git source. The 44 production Composer names and versions match that source's lockfile and the signed SBOM inventory. All 3,636 packaged files have 0644 modes; uncompressed content totals 17,063,977 bytes. Dependency source bytes were not independently reconstructed. Every asset was rehashed after verification.

The private verification receipt SHA-256 is `de9691378a99e0416d96bd8c10ade7e4823a14eb78f5a6f04a6ba968aaec4222`; the complete package-file manifest digest is `88c220e436345b0a47296b773274332b78b2b84fc138ce437f033c5ac4c036d5`. The release-verifier preparation passed 28 isolated cases and independent review. That verifier checks package provenance and integrity; CI provenance, source correctness and two-build reproducibility remain the separate gates recorded above.

## Resource evidence and its limits

The final exact-head resource artifact is from [Quality 40254227303](https://github.com/vahid162/pinova/actions/runs/40254227303), artifact `12622435556`, archive SHA-256 `8a94a8839e67c406523baf9ebc0e507974e5832657f1d3b1a8c50a2f2df4e602`. Unlike the earlier response-boundary-only [characterization](2026-10-26-otp-fpm-characterization.md), this uses real WordPress, queue ownership, first-party provider adapter and pinned representative WooCommerce/wpForo/Dokan/Elementor plugins on isolated GitHub-hosted compute. Provider requests go only to a nonbillable loopback stub.

OFF/ON workloads retain the same minute-runner opportunities and observer count. Ten normal requests used 16.84/16.62 CPU seconds, 22.9405/22.6985 sampled FPM-plus-CLI occupied seconds, and 466.821/506.795 ms ordinary-page p95. Retained summed RSS was 1,751,216/1,785,820 KiB; this duplicates shared pages and is not unique physical RAM. The provider was entered 56.26–60.23 ms after response completion. Request-to-provider acceptance was 957–1,013 ms with a 200 ms synthetic provider.

Normal, idle, slow-provider and concurrent workloads passed the predeclared CPU, worker-occupancy, page-latency and memory gates; queue depth and new max-children events were zero. Tests cover slow/uncertain acceptance, expiry, cancellation, malformed final responses, two-slot overflow and a real CLI claim race. Native PHP-FPM 8.3 and 8.4 tests also proved exact memory-file access, denial of a neighboring procfs file, completion before dispatch, blocked-memory fallback and state reset on a reused child.

The retained minute runner still incurs its existing bootstrap cost. These results do not establish production savings, phone receipt, target cadence or live latency. The eight-second limit is an aggregate provider HTTP budget, not a hard lifetime bound on arbitrary PHP hooks. Future resource evaluation must account for shared workloads and sample volume.

## Next acceptance boundary

Installation and feature activation are separate from publication. Follow the public installation and immediate-delivery prerequisites in [README.md](../../README.md) and the [development skill](../skills/pinova-development/SKILL.md), preserving the default-off state until the separately authorized staging and administrator-access checks pass.

A target acceptance record must separately establish effective PHP-FPM capability and resource guards, backup/rollback readiness, correct response completion, provider acceptance, human physical receipt, ordinary-page/resource behavior and a full 86,400-second observation window. Missing or interrupted coverage is incomplete. The original expiry and uncertain-delivery no-resend policy still apply. No site inspection, configuration, access or recipient details are published in this record.
