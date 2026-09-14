# Pinova 1.2.6 Account and Blocked List — Release Candidate Review

Date: 2026-09-14 (Asia/Tehran)

## Delivery standard

- Development branch: `fix/1.2.6-account-block-ui`, based on `main` commit `c54f442dff0b3edc3476a6587c883f16e59fc486`.
- The historical Phase 1–4 reports describe the local sequence from the 1.2.5 runtime. This document records the normalized 1.2.6 release candidate.
- The project Skill now defines local changes and local ZIPs as intermediate evidence. A test version is ready for installation only after a GitHub branch, reviewable commits, pull request, required CI, merge, immutable pre-release, and fresh download verification of its ZIP and checksum.
- `v1.2.6-rc1` was published from PR #8 merge commit `270ace9` and its checksum was independently verified, but the Release API reports `immutable: false` because repository-level native immutability was enabled afterward. It remains untouched as publication history. The new installation-handoff target is `v1.2.6-rc2`; it is a staging/canary pre-release, not stable/latest.
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

## Publication history and next gate

PR #8 and all 15 required jobs passed before merge. The `v1.2.6-rc1` publisher also passed reproducible build, publication, fresh download, checksum, ZIP-integrity, and top-level-directory checks, producing SHA-256 `cf830bcdf6ba3716c278d14291c34d3fadc63350e843e8400567c1c7113fc781`. Because that Release is not GitHub-native immutable, it is not the installation handoff under the strengthened standard.

The official handoff must be the `pinova-1.2.6.zip` asset from `v1.2.6-rc2`. The repository owner confirmed native Release Immutability before publication; the workflow intentionally uses only its job-scoped `GITHUB_TOKEN` and requires neither an Administration-scoped secret nor a repository Ruleset. Its pre-publication notes describe immutability and workflow success as required gates and do not claim they have already passed. It may resume an interrupted unpublished draft only when GitHub reports `github-actions[bot]` as its author and its name, target, complete publisher-marked notes, and allowed assets identify this exact release; unknown drafts and every published Release remain untouched. Handoff additionally requires the post-publication Release API to report `immutable: true`, GitHub to verify the Release and both asset attestations, and a fresh download to match the build outputs byte-for-byte. An unexpected mutable result is preserved as a failed RC and requires a new tag after the repository setting is corrected.
