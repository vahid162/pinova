# OTP queue recovery: release and staging evidence, 2026-10-12

This dated record covers the reviewed queue and logging changes, their immutable RC17 package, and acceptance on the authorized test site. Publication, independent package verification, installation, rollback and immediate staging acceptance passed. Production remains unchanged on RC16; this record does not authorize production installation, settings changes, or a stable/latest release.

## Reviewed implementation

- Implementation: [PR #80](https://github.com/vahid162/pinova/pull/80).
- Final reviewed head: `e87244ee1d6a34b8fc67ff7f3a7f1d40067f5b1d`.
- Source tree: `b44b5368def1b8dab83938bf332098279d891984`.
- Merge and release source: `1a249ee980f915aedfc443080db7f65ad1d66120`. The merged tree matches the reviewed implementation tree.

Pinova prepares the signed public OTP response before accepting the native scheduled job and durably acknowledging its encrypted queue payload. The shared bounded dispatcher can recover an acknowledged, unexpired job whose native schedule was lost. It excludes unacknowledged reservations, expired work, rejected requests, held claims, and unsupported legacy orphans. The native watchdog runs at a 60-second interval; external scheduling and disabled automatic cron remain operator-controlled.

Provider-outcome uncertainty preserves the original OTP and does not trigger automatic resending or rotation. Current queue health appears in Logs and Issues and its read-only JSON/WP-CLI report. Finite scan limits, truncation and read failures are explicit. Historical sampled events remain separate from current queue facts and do not become delivery counters or proof of a confirmed code defect.

Runtime version remains `1.3.0`, database schema remains `3`, and dependency lockfiles are unchanged. Theme, Dokan, wpForo and WooCommerce source changes are outside this implementation.

## Review and CI

The completed `ce-code-review` receipt is `.agents/reviews/2026-10-12-otp-queue-recovery-code-review.json`, run `20261012-074824-4bed127e`, with `status=complete`. The documented findings were resolved before the final head; the follow-up review found no outstanding required corrections. The associated resolution record is `.agents/reviews/2026-10-12-otp-queue-review-resolution.md`.

| Gate | GitHub event | Exact source/head | Result |
| --- | --- | --- | --- |
| [Quality 38243110467](https://github.com/vahid162/pinova/actions/runs/38243110467) | `push` | `e87244ee1d6a34b8fc67ff7f3a7f1d40067f5b1d` | Success |
| [Quality 38243115677](https://github.com/vahid162/pinova/actions/runs/38243115677) | `pull_request` | `e87244ee1d6a34b8fc67ff7f3a7f1d40067f5b1d` | Success, including Dependency Review and complete PR skill synchronization |
| [Quality 38244506530](https://github.com/vahid162/pinova/actions/runs/38244506530) | `push` to `main` | `1a249ee980f915aedfc443080db7f65ad1d66120` | Success, independent of the PR run |
| [Quality 38245564557](https://github.com/vahid162/pinova/actions/runs/38245564557) | `push` to `publish/v1.3.0-rc17` | `1a249ee980f915aedfc443080db7f65ad1d66120` | Success; authorizes publication |

The complete suite covered PHP 8.1–8.5, JavaScript, eight WordPress/WooCommerce/HPOS configurations, ten real Dokan/wpForo profile/HPOS jobs, Chromium acceptance and modal sensitivity, package/SBOM checks, dependency security, Plugin Check, workflow/shell validation and documentation governance. Heavy matrices and reproducible builds ran on GitHub Actions, not the shared live host.

Earlier CI failures were corrected on new commits. Static-analysis assumptions, coding-standard layout and a stale CLI smoke expectation were repaired without lowering thresholds, suppressing required checks, or rerunning an unchanged failing head as acceptance.

## Publication chain

- Publish branch: `publish/v1.3.0-rc17`, at the exact merged source above.
- [Publisher 38246489618](https://github.com/vahid162/pinova/actions/runs/38246489618): success, including reproducible builds and post-publication verification.
- Annotated tag object: `c65bb48088d4e515aaa9915cad8839721b42c41c`.
- Tag target: `1a249ee980f915aedfc443080db7f65ad1d66120`.
- [RC17 release](https://github.com/vahid162/pinova/releases/tag/v1.3.0-rc17), ID `410559079`: native `immutable=true`, `prerelease=true`, `draft=false`.

| Asset | Bytes | SHA-256 |
| --- | ---: | --- |
| pinova-1.3.0.zip | 5045725 | `906d0ed76543e29e2f89f65f6f2f3747d6d8f3d56a53c80968cdeaa8b508d0af` |
| pinova-1.3.0.zip.sha256 | 83 | `07ec6a8e2c916c02312c757bca938f7e99be1996bb9d44d71b600cd9a7c7ed55` |
| pinova-1.3.0.spdx.json | 97939 | `6f2d134ba570ee68a69822720edc76a4ebc46fa3552ed51afc905666869953e9` |
| pinova-1.3.0.spdx.json.sha256 | 89 | `7c1a39e9151fc772c2fdaae4dfe67a3807755e5706bb48002618d1d7acb1cec4` |

Independent fresh downloads matched all four asset digests and both checksum files. ZIP CRC, paths, runtime allowlist, package identity and the production Composer inventory passed: 3,628 entries, 16,977,809 uncompressed bytes and 44 Composer packages. Native GitHub release and asset verification passed for all four assets against an authenticated trusted root. A deliberately changed synthetic checksum was rejected.

Separate native SPDX attestation verification pinned the release source digest, publish ref, GitHub-hosted signer, OIDC issuer and publisher workflow identity. The entire signed SPDX predicate matched the downloaded SBOM. This is SPDX/source-identity evidence, not an additional SLSA provenance claim.

## Authorized staging boundary

The operator authorized installation only on the test site and declined a new database backup. The completed operation was a same-schema, files-only package replacement with scoped maintenance and worker draining, performed through direct SSH. No database export or wp-config copy was made. The independently verified public RC16 ZIP supplied rollback package bytes; existing ownership, modes, configuration, unrelated files and stored settings were preserved.

The pre-install baseline identified RC16 at source `22be045b500add17d0dbe606fdb30efb728166c4`, schema `3`, with no queued OTP payloads or native OTP jobs on staging. The source fingerprint, stored-settings fingerprint and configuration fingerprint were recorded privately for post-install comparison. They are not copied into public evidence.

| Installed-site gate | Observed result |
| --- | --- |
| Fresh pre-install emptiness and configuration checks | Passed; schema 3 and no pending OTP work |
| Verified RC16 → RC17 file replacement | Passed; 17 files changed, none removed, 3,628 installed files verified |
| Published-package synthetic queue/REST/logging acceptance | Passed; all seven scenarios below |
| Synthetic account, session, OTP, event and throttle cleanup | Passed; complete owned-record cleanup, original user count preserved |
| Worker drain and published RC17 → RC16 rollback | Passed; 16 files changed and only the single RC17-added file removed; exact old inventory restored |
| RC16 → RC17 reinstall and final CLI/watchdog smoke | Passed; 17 files changed, none removed; registered runner processed zero jobs |
| Public-page smoke and bounded new-error review | Passed; all three HTTP checks returned 200; error-log append was zero bytes |
| Final staging preservation and live unchanged checks | Passed; private before/after fingerprints matched the intended boundaries |

Strict empty-queue checks passed before downgrade. After the verified package downgrade and renewed empty-queue checks, exactly one candidate-owned watchdog event was removed through a process with plugins and themes skipped. No other scheduled hook was removed. Worker draining preceded package replacement. Reinstalling RC17 restored its native 60-second watchdog, and the final real registered `pinova otp run` command completed successfully with zero jobs processed. Scoped maintenance was cleared.

The guarded installer and acceptance runner were independently reviewed. Eighteen isolated installer cases and five isolated runner cases passed before site execution, including failure and foreign-state preservation controls. These helper checks supplement the actual published-package installation and rollback; they are not substitutes for those completed site operations.

## Native test-site acceptance

The published package passed seven synthetic scenarios with the site's active plugins loaded:

| Scenario | Observed result |
| --- | --- |
| `lost_job_recovered_once` | Acknowledged unexpired work whose schedule was removed recovered exactly once |
| `recovered_otp_verified_same_account` | Actual REST OTP verification retained the same account and native session hook |
| `expired_orphan_visible_never_sent` | Expired accepted work remained visible in queue health and was never sent |
| `rejected_schedule_not_sent` | Scheduling rejection did not produce accepted recovery or delivery |
| `legacy_only_with_scheduled_callback` | Legacy work was not recovered as an orphan; its explicit scheduled callback remained effective |
| `finite_report_without_identifiers_or_otp` | The bounded report exposed queue facts without identifiers or OTP secrets |
| `native_watchdog_registered` | The native recurring watchdog was registered at 60 seconds |

All real outbound email and WordPress HTTP were suppressed in the fixture. Two test email calls were intercepted in memory; zero real messages were sent. CLI stderr was empty. Cleanup verified removal of only the fixture's own accounts, sessions, OTP work, logs and temporary throttle state. The original single-user count, stored settings and schema 3 were preserved.

The final read-only report identified RC17, reported a successful current queue read, zero in all five queue counts, and no truncation. Its inspected current-day historical coverage was complete and contained zero issues. The logging database was readable, cleanup was scheduled and not overdue, and the configured minimum logging level was warning. These are bounded observations: absent retained events do not establish total activity, durable logging writes for every request, or absence of unobserved failures.

Public home and login GET requests and authentication OPTIONS returned HTTP 200; login retained `no-store`. The three cold smoke requests took 3,907, 2,649 and 3,159 milliseconds respectively. These single observations are not a performance benchmark. The complete bounded scan of the staging error-log append found zero new bytes, and no new PHP warnings were observed in the inspected execution output.

Final private fingerprint comparisons confirmed preservation of staging configuration, settings, schema and user count. Production's Pinova files, RC16 build identity, configuration, settings and child-theme source matched their pre-operation fingerprints. Normal production database activity was excluded from this unchanged-state claim. No theme, dependency-plugin source or shared service was changed.

## Limits and next boundary

Publication, exact-source CI, reproducible packaging, independent supply-chain verification, staging installation, synthetic acceptance, actual package rollback, reinstall and immediate post-install observations passed. Only the authorized test site received RC17; no new version was installed on production during this work.

Physical SMS receipt, physical-device acceptance and a 24-hour canary have not been exercised. Synthetic delivery interception cannot establish provider acceptance or a customer's receipt. The updated queue cannot recover an OTP whose signed lifetime has already expired, and it intentionally does not resend when prior provider acceptance is uncertain.

RC17 remains a prerelease. Production installation is the owner's separate next action. This evidence-only change has no runtime effect and requires no additional operational monitoring. Its documentation CI is a separate pending gate; the release-source runs above do not certify a later documentation commit.
