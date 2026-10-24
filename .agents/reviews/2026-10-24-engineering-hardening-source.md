# Engineering hardening source verification — 2026-10-24

This records the completed source changes and their verified GitHub history before preparing the next release candidate. It does not establish acceptance of an installed package, a production deployment, or a stable/latest release.

## Reviewed and merged source

| Change | Exact PR head and pull-request Quality run | Merged main and its push Quality run |
| --- | --- | --- |
| [PR #90: authentication boundaries and expired-data maintenance](https://github.com/vahid162/pinova/pull/90) | `df1cb0fd1b71e39baa1d5fce7c2bd0cf9c5210be`; [40097383462](https://github.com/vahid162/pinova/actions/runs/40097383462) | `543ae98db259abb7b000ff8eb80db015bd7d2636`; [40098538399](https://github.com/vahid162/pinova/actions/runs/40098538399) |
| [PR #91: durable OTP queue storage](https://github.com/vahid162/pinova/pull/91) | `c08b968231410c452afeb916eccc34e5a2def917`; [40099145925](https://github.com/vahid162/pinova/actions/runs/40099145925) | `e4a1d9a8538ba8ff3171016fd32774a801f8c4d6`; [40100516172](https://github.com/vahid162/pinova/actions/runs/40100516172) |
| [PR #92: independent shared authentication UI state](https://github.com/vahid162/pinova/pull/92) | `a3d6effc7ff34482e4339573480b447508f2661d`; [40100541542](https://github.com/vahid162/pinova/actions/runs/40100541542) | `c29f5bf11422f29cefad2135c85416f1af5b1304`; [40102463895](https://github.com/vahid162/pinova/actions/runs/40102463895) |

All three exact-head `pull_request` runs completed successfully with 35 successful jobs. Each cited merged-main `push` run succeeded; Dependency Review is intentionally skipped outside pull requests. The final main run completed 34 jobs successfully and its Required Quality Gate passed. These source runs do not certify a later release-preparation or evidence commit.

The final source tree is `1526c7e90c2f56466ff183c5923b79705deb0a5d`. Its bytes match the locally reviewed complete series. The original source-review receipt, `20261024-161905-bf1f5884`, completed twelve lenses with zero retained actionable findings. Its verdict was source-review readiness, conditional on the subsequent exact-head integration and browser CI recorded above. A different-provider review was unavailable; an in-process adversarial review was used without claiming cross-model independence.

## Implemented boundaries

- Physical mobile metadata uses a shared failure-aware raw reader. Byte-identical duplicates remain valid; conflicting values and read failures stop dependent authentication or erasure before mutation.
- Browser login and recovery validate configured origins; malformed structured REST values receive controlled validation failures. Headerless nonbrowser clients and supported numeric/localized OTP input remain compatible.
- Provider protocol values remain unchanged during success classification. Diagnostic credentials are redacted afterward.
- Eloquent database settings use WordPress host parsing for ports, Unix sockets and IPv6 while preserving eager, idempotent initialization.
- OTP retention is seven days after UTC expiry. OTP and log cleanup use at most 5,000 rows per pass and deduplicated five-minute continuation while expired rows remain. Null-expiry OTPs and recent channel history survive normal maintenance.
- Schema 4 adds `expiry_cleanup(expires_at,id)` and verifies the required index before advancing schema metadata. The migration is additive and retryable.
- `OTPQueueStore` owns durable queue operations behind the existing `RateLimitService` delegates. Each account controller receives its own fresh shared UI state; request cancellation, modal focus and standalone history stay with their controllers.

## Verification and its limits

The final main CI includes PHP 8.1–8.5, twelve WordPress/WooCommerce/PHP/HPOS combinations, ten real wpForo/Dokan/HPOS profiles, JavaScript, Chromium account acceptance, modal mutation sensitivity, WordPress Plugin Check, supply-chain checks, workflow/shell lint and repository guidance checks.

The PHP 8.1 unit job reports 94 tests and 425 assertions. The latest WordPress/WooCommerce PHP 8.5 HPOS-enabled integration job reports 537 tests and 3,642 assertions. The PHP 8.1 job also built the package and verified its generated SBOM inventory. These are CI package checks, not publication or independent verification of a new release asset.

Independent PHP/wpdb workers prove connection-owned lock exclusion, cancellation/erasure barriers and crash recovery. That fixture deliberately makes no provider calls and orders its claim commands; it does not establish simultaneous end-to-end provider-versus-erasure behavior. Database host tests establish WordPress parsing and DSN generation, not live connectivity through every socket, IPv6 or nondefault-port topology. Genuine client-IP changes during retry/verification and exhaustive provider-specific non-2xx contracts remain additional characterization opportunities, not confirmed defects in this change.

## Remaining package and operational evidence

At this source verification, the newest published candidate is [v1.3.0-rc19](https://github.com/vahid162/pinova/releases/tag/v1.3.0-rc19), source `4949ff131bd92b1f094936ac91a883da8c38c244`, with native `immutable: true`. It predates this hardening. A new candidate must separately record its preparation PR and CI, exact release SHA, publish-branch Quality run, publisher, annotated tag, immutable Release, four asset digests, attestations, independent redownload and predecessor-package comparison.

Before production use, install that verified published ZIP on an authorized staging target after verified backups and a rollback plan. Measure the one-time expiry index build and locking on representative historical volume; bounded cleanup does not bound initial index creation. Exercise upgrade/retry, code rollback, general cron, seven-day retention and backlog continuation, valid/foreign-origin authentication, login/recovery purposes, privacy, WooCommerce and the active theme/cache. Physical provider receipt and Chrome Android/Safari iOS acceptance remain separate from synthetic fixtures and Chromium CI.

Code rollback can retain the additive index, but cannot restore records already deleted by retention. Keep restoration from a verified database backup distinct from code rollback. Record the agreed canary observation window and inspect queue/cron health, errors, log redaction/volume and latency before stable/latest promotion. No site installation, database change, provider message or physical-device acceptance is asserted by this source record.
