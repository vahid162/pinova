# Pinova 1.2.6 Account and Blocked List — Release Candidate Review

Date: 2026-09-15 (Asia/Tehran)

## Delivery standard

- Development branch: `fix/1.2.6-account-block-ui`, based on `main` commit `c54f442dff0b3edc3476a6587c883f16e59fc486`.
- The historical Phase 1–4 reports describe the local sequence from the 1.2.5 runtime. This document records the normalized 1.2.6 release candidate.
- The project Skill now defines local changes and local ZIPs as intermediate evidence. A test version is ready for installation only after a GitHub branch, reviewable commits, pull request, required CI, merge, immutable pre-release, and fresh download verification of its ZIP and checksum.
- `v1.2.6-rc1` was published from PR #8 merge commit `270ace9` and its checksum was independently verified, but the Release API reports `immutable: false` because repository-level native immutability was enabled afterward. It remains untouched as publication history. `v1.2.6-rc2` is now the verified installation handoff; it remains a staging/canary pre-release, not stable/latest.
- Production installation, production data, the running 48-hour test, and the separate 1.3 identity line remain outside this release operation.

## Candidate contents

- Shared REST error normalization preserves valid Pinova and WordPress 400/401/403/429/503 JSON messages, `Retry-After`, HTTP status, and correlation metadata while limiting the generic helper notification to transport or malformed responses.
- Blocked List administration requires an explicit mobile/email/username/IP type, canonicalizes each value, preserves permanent and system-managed rows correctly, offers stable filters and pagination, and records privacy-safe add/remove events.
- Authentication checks identifier and client-IP blocks before processing and again at OTP verification, including blocks added after code issuance.
- The `/my-account/` experience is responsive and RTL, uses semantic forms and accessible controls, exposes inline status, supports Persian/Arabic digits, clears sensitive state, integrates browser history, and provides a no-JavaScript/native-login fallback.
- The WooCommerce login modal now follows the non-enumerating `login_method` API contract rather than the removed `has_account` field, restarts the server-TTL countdown deterministically, and accepts valid legacy WordPress passwords without imposing the new-password length rule.
- The Blocked List administrator filter now sends the selected administrator User ID rather than posting a display-name string to an ID filter.

## Local evidence before GitHub publication

- Pinova Skill package validation and working-tree synchronization: passed.
- Changelog synchronization: passed for 13 releases.
- Full PHP syntax lint: passed.
- PHPUnit unit suite: 17 tests, 41 assertions, passed.
- PHPStan 2.2.13 single-process debug analysis: passed with no errors. The default parallel process could not bind its sandbox-local worker socket.
- PHPCS: 10/10 configured files passed.
- JavaScript VM regression suite: all five test files passed, covering the shared REST helper, Blocked List, full-page login, WooCommerce login modal, and template/CSS contracts.
- Existing isolated Phase 3/4 integration evidence: HPOS off and on each passed 44 tests and 170 assertions against WordPress 7.1/WooCommerce 11.1/PHP 8.1. The release PR's required GitHub matrix must rerun integration across every configured WordPress/WooCommerce/HPOS pair before merge.
- Read-only HTTP check of the retained Phase 4 environment: HTTP 200 on the Pinova login route, PHP 8.1, versioned 1.2.6 account stylesheet, Persian RTL document, native WordPress login link, and `noscript` fallback.
- Two local Composer 2.10.3 builds of the current publisher-policy candidate were byte-identical across different timezone/umask inputs. Candidate SHA-256: `dc6a918c6c9df67010038004ac1b5ce8b16b091fda4d3f7135ec81bb7d4fbfc9`; archive integrity, top-level `pinova/`, version 1.2.6, and required runtime files passed.
- A fresh local online Composer advisory query timed out. The GitHub PHP 8.1 job must complete `composer audit` online before merge; cached dependency installation was sufficient for local static/test/build checks.

## Publication and verification evidence

PR #8 and all 15 required jobs passed before merge. The `v1.2.6-rc1` publisher also passed reproducible build, publication, fresh download, checksum, ZIP-integrity, and top-level-directory checks, producing SHA-256 `cf830bcdf6ba3716c278d14291c34d3fadc63350e843e8400567c1c7113fc781`. Because that Release is not GitHub-native immutable, it is not the installation handoff under the strengthened standard.

PR #10 removed the Administration-secret and Ruleset prerequisites, retained all post-publication safeguards, and fixed its one valid P1 review finding by making pre-publication Release notes conditional on successful verification. Both commits received full 15-job Quality runs; the final review found no further issues. PR #10 merged at `714122f147d0144eea070414e57defe5d85d788d`, whose independent `main` Quality run `34893410349` also passed all 15 jobs.

Branch `publish/v1.2.6-rc2` was created from that exact merge commit. Its 15-job Quality run `34893768634` passed and triggered publisher run `34894088713`. The publisher built twice, created annotated tag object `b240f84fafec3f49dba72e0f5e2d5009c142c73b` targeting `714122f`, published GitHub Release `388696877`, confirmed `draft: false`, `prerelease: true`, and native `immutable: true`, and successfully verified the Release plus both asset attestations. A separate fresh download matched the workflow build byte-for-byte, passed the published checksum and ZIP integrity checks, contained 3,684 entries entirely under `pinova/`, reported plugin version 1.2.6, and included the required account, login, Blocked List, and `BlockedException` files. The official installable ZIP SHA-256 is `dc6a918c6c9df67010038004ac1b5ce8b16b091fda4d3f7135ec81bb7d4fbfc9`; the checksum asset SHA-256 is `95bce42e5be60d45eaf07478ed32d1f6f1d14dcc9cbdaeaaa6c5c2bca80999fe`.

The official handoff is the `pinova-1.2.6.zip` asset from `v1.2.6-rc2`. No site installation, production configuration, database change, or modification to the running 48-hour test occurred. Installing this pre-release for the combined staging/canary test is a separate operational action requiring explicit authorization and rollback controls.
