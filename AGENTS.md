# Pinova repository instructions

These instructions apply to the whole repository. User instructions take precedence. Repository guidance does not authorize production, database, GitHub, release, or account mutations the user did not request.

## Starting point

Before substantive work:

1. Read `.agents/skills/pinova-development/SKILL.md` completely and follow its reference routing.
2. Inspect `git status --short --branch`, branch, recent commits, tags, and relevant GitHub checks/releases. Verify the snapshot below.
3. Read Persian `README.md` for the public overview and `readme.txt` for WordPress.org metadata.
4. Classify the task as review, diagnosis, implementation, migration, packaging/release, or production deployment and stay within that authority.
5. Preserve unrelated changes and secrets. Do not force-push, move published tags, discard work, or clean shared Docker resources.
6. Establish the compute boundary before running dependency installation, static analysis, packaging, or wp-env. A separate worktree/container is not resource isolation; if the checkout shares a host with production or another live tenant, keep resource-intensive quality work on GitHub Actions or a dedicated development host.

## Current RC10 release state

As verified on 2026-09-19, [PR #28](https://github.com/vahid162/pinova/pull/28) merged the bounded modal-focus lifecycle correction as `f8edf82a5981935f1f38d604014cd9272af854ba`. Its exact-head PR Quality run `35428597771`, merged-main run `35432423954`, and exact `publish/v1.2.6-rc10` run `35433083071` each passed all 16 jobs. Publisher run `35433368070` built the package twice, published annotated tag `v1.2.6-rc10`, and passed Release plus both asset-attestation checks. Release `392004930` reports `draft:false`, `prerelease:true`, and native `immutable:true`; an independent redownload verified installable ZIP SHA-256 `989746e26ee4e01ce4e8427ac0d556dfa79c1ca651c72d50b2f29dbe04ee8909`, ZIP integrity, its single `pinova/` root, and expected package contents. See [the RC10 release record](.agents/reviews/v1.2.6-rc10-release.md).

RC10 is the newest published candidate, not stable/latest and not a live-deployment assertion. RC1–RC9 remain unchanged history. The next boundary is separately authorized staging/physical-device acceptance and then, if approved, installation with backup and rollback controls while the native-login gate remains off. Publication did not install the plugin, change a site/database/option, or authorize gate activation.

## Historical snapshot before PR27 merge

Snapshot checked on 2026-09-19 before the documentation follow-up was merged. Verify current refs and releases before acting; this snapshot is not a live deployment record.

- Repository: `https://github.com/vahid162/pinova`
- `main` at `1d39ec04d5f6293d4ea3511480980fbc8912938d` includes the 1.2.3 security line, reproducible-release hardening, structured logging, the 1.2.5 fresh-request database-bootstrap hotfix, the reviewed 1.2.6 account/Blocked List corrections merged by PR #8 at `270ace9`, native-immutable publisher hardening introduced by PR #9 at `cccf1c3`, the WooCommerce checkout-lifecycle correction, the account-brand/native-login and redirect round-trip work merged by PR #15 at `0b89485`, the focused OTP hierarchy follow-up merged by PR #17 at `b65370b`, the single-card account-interface correction merged by PR #19 at `c327c715`, RC6 release evidence merged by PR #20, the account-accessibility/native-login activation correction merged by PR #21 at `539f49c`, the final login layout/copy correction merged by PR #23 at `9d7f70b`, the checkout-modal/trigger correction merged by PR #25 at `6425c82`, and PR #26's OTP-purpose, recovery-resend, and busy-modal-dismissal correction. PR #25 and #26 changes shipped in RC9; they are not unreleased work.
- Existing 1.2.3 pre-releases remain immutable. `v1.2.4-rc1` contains the Composer bootstrap regression and is superseded by `v1.2.5-rc1`; no RC is stable/latest.
- Latest published candidate at this snapshot: `v1.2.6-rc9`, target `1d39ec04d5f6293d4ea3511480980fbc8912938d`, Release `391638137`, `draft: false`, `prerelease: true`, `immutable: true`. The Release API reports ZIP digest `4c34353127cfed47cc0dfd42f042debe67727279370afbfe34e756e6f176ed5d`; this metadata check is not a fresh independent asset/attestation audit. Preserve RC9 unchanged. The installed production version was not determined in this documentation task.
- PR #27 (`fix/checkout-modal-corner-controls`) contains the unpublished modal-control fix and focus-test/CI-cleanup hardening. Verified code head `1423de55639ec3a50bfb50119e0a44ecc7926d6a` passed all 16 jobs in exact-head `pull_request` Quality run `35424813233`. A subsequent documentation commit requires its own CI and review. See [.agents/reviews/checkout-modal-controls-pr27.md](.agents/reviews/checkout-modal-controls-pr27.md) for scoped evidence and remaining gates.
- Historical release evidence: `v1.2.6-rc1` is published and checksum-verified but reports `immutable: false` because it predates repository-level native Release Immutability. RC2–RC8 are retained unchanged. RC8 annotated tag object `1c77883` targets merged commit `9d7f70b`; Release `390600592` reported `immutable: true`, its exact-head PR, merged `main`, publish-branch, publisher, reproducibility, and release/asset-attestation gates passed, and two independent redownloads verified ZIP SHA-256 `343d92e7e6919a045ba530cbe73910271bcc0dd7596a7d6a05f7ef6bf94c698d`. An earlier operator report identified RC8 as the then-active production baseline. That historical report does not establish today's installed version. RC8 did not contain the later checkout-modal correction; neither publication nor installation authorized native-login gate activation.
- The RC1 1.2.3 asset predates later PHP 8.1 dependency, CI matrix, PHP 8.5, and reproducible-build changes. Do not relabel or overwrite it.
- `v1.2.3-rc3` is the current verified, installable 1.2.3 pre-release. It pins Composer 2.10.3, UTC, and staged permissions; keep all prior RC tags immutable.
- The quality matrix covers PHP 8.1–8.5, WordPress 6.8/latest/7.1, WooCommerce fixed/latest/11.1.0, and HPOS on/off where configured.
- Release history is mirrored between root `CHANGELOG.md` and the WordPress.org `readme.txt` Changelog section. CI compares release order and every entry; update both in the same change set.
- Structured persistent logging shipped in the 1.2.4 line. In 1.2.5, the bounded administrator SMS test is an audit event that bypasses the minimum threshold, while the viewer reports table availability and the effective minimum level.
- `utils/class-database.php` is both classmapped and eagerly loaded through Composer `autoload.files`. Removing it from `vendor/composer/autoload_files.php` breaks fresh REST requests before authentication, OTP, SMS, block, and logging operations can run.
- Task-specific historical worktrees, toolchains, and wp-env projects are retained on the development host. Discover their current paths, ports, container state, and volumes with read-only commands before acting; do not publish host-local coordinates in this public repository.
- An earlier RC2 wp-env project and worktree are separately preserved. Never use, stop, update, or remove their containers, volumes, database, or ports for 1.2.4 work. Identify ownership from the project labels and task handoff rather than assuming a directory name.
- The separate 1.3 development branch is `security/v1.2.3-v1.3.0`. Its identity schema/migration/merge features are not shipped by main 1.2.3.

Verify all facts before acting and update this section whenever lineage, release, compatibility, blocker, or milestone changes.

## Historical checkout-modal positioning follow-up

PR #27 starts from RC9's `main` commit. It moves only the existing close control to the card corner, scopes modal close/back positioning against generic theme CSS, and advances the shared UI asset revision to `.5`. Authentication JavaScript, logo/form markup, standalone layout rules, and the plugin version are preserved. The subsequent test work waits for actual product-driven heading focus and verifies five isolated product faults without weakening assertions; CI cleanup is guarded to its own browser-run resources.

The operator has now authorized documentation synchronization, reviewed merge, and publication of a new installable candidate after all required gates pass. This supersedes the earlier stop-at-PR boundary, not the production boundary. Select the next unused RC at publication. Never merge into, move, or overwrite the published RC9 tag or assets. A green PR run is not a merged-main check, release attestation, installation, or staging acceptance.

## Next expected milestones

1. Treat `v1.2.4-rc1` as superseded; do not install, retag, or overwrite it.
2. Preserve RC1–RC10 and the completed PR27/PR28 chain as immutable history. Use only the `pinova-1.2.6.zip` asset attached to `v1.2.6-rc10` for the next separately authorized acceptance step; never substitute an implementation branch, worktree-local ZIP, or GitHub automatic source archive.
3. Keep the native-login gate off during the first staging pass. Verify the exact production security integrations and preserved core-action matrix before a fresh private administrator login may arm the gate; gate activation remains a later, separately authorized boundary.
4. Treat structural CI and package verification as distinct from physical-device and staging acceptance. Complete Chrome Android and iOS Safari checks for the standalone page and checkout modal, including keyboard behavior, autofill, paste, live validation, dialog focus/closing, safe areas, enlarged text, and 4/5/6-digit OTP flows before production use of a follow-up candidate.
5. Keep publication independent of an Administration-scoped secret and repository Ruleset. With the repository owner's native Release Immutability setting confirmed, publish only from an exact reviewed and green `main` commit and require post-publication Release API, attestations, and independent redownload checks to pass. Preserve any failed tag or Release unchanged and use a new RC after correcting the cause. Record publication evidence separately; do not predeclare success in a pre-publication snapshot.
6. Stop before installation. A production plugin replacement, the initial 48-hour gate-off canary, later gate activation with two break-glass methods, and the second 48-hour canary each require the plan's separate authorization and rollback controls.
7. Rebuild 1.3 from corrected `main` only after the corrected 1.2.6 baseline completes its separately authorized staging and production canaries. Semantically port reviewed identity concepts; do not merge the obsolete branch wholesale or reuse `v1.3.0-rc1`.

## Source routing

- Bootstrap/upgrades: `pinova.php`, `src/Install.php`, `src/Version.php`, `utils/`.
- REST/authentication: `src/API/`, `src/Services/`, `src/Objects/`.
- Structured logging: `src/Logging/`, `src/Admin/Logs.php`, and `.agents/skills/pinova-development/references/logging.md`.
- WordPress profiles/exports: `src/Integrations/Wordpress/`.
- WooCommerce: `src/Integrations/Woocommerce/`.
- Login UI: `templates/`, `assets/`.
- Tests: `tests/`; real browser acceptance: `tests/browser/account-ui.spec.mjs`.
- Targeted fault detection: `tools/check-modal-test-sensitivity.mjs` and `tests/js/modal-test-sensitivity.test.mjs`.
- GitHub browser-job-only cleanup: `tools/cleanup-browser-env.sh` and `tests/js/browser-cleanup.test.mjs`; not a general desktop cleanup command.
- PR27 evidence: `.agents/reviews/checkout-modal-controls-pr27.md`.
- RC10 publication evidence: `.agents/reviews/v1.2.6-rc10-release.md`.
- Reproducible package: `tools/build.sh`.
- Release history: `CHANGELOG.md`, the `readme.txt` Changelog section, and `tools/check-changelog-sync.php`.
- CI and pre-release publication: `.github/workflows/quality.yml`, `.github/workflows/publish-prerelease.yml`.
- Agent guidance: `.agents/skills/pinova-development/`.

## Required workflow for changes

1. Reproduce the issue or establish the requirement from source/tests.
2. Add or update a regression test when practical.
3. Implement the smallest coherent fix while preserving the Skill invariants.
4. Run targeted tests, then proportional unit, integration, HPOS, static, coding-standard, dependency, and packaging checks.
5. Meaningfully update `.agents/skills/pinova-development/SKILL.md` in the same change set for every plugin-affecting change.
6. Update references, this snapshot, `README.md`, `CHANGELOG.md`, and the `readme.txt` Changelog section where behavior or operations changed.
7. Run `php tools/check-changelog-sync.php`, then `bash .agents/skills/pinova-development/scripts/check-skill-sync.sh --working-tree`, and validate the Skill before handoff. The Skill gate checks changed paths, not the semantic truth of documentation; review status claims and relative links separately.
8. Report exact files/refs/checks, remaining risk, production impact, and next safe step.

## Production boundary

Treat `<production-wordpress-root>/wp-content/plugins/pinova` as read-only unless the user explicitly requests the exact production action with appropriate backup, rollback, and maintenance controls. Resolve the real production root from the authorized host at execution time; never hard-code or publish it here.

Never use the production database for development or automated tests. Never run identity apply/merge/rollback, plugin replacement, web-server edits, or database writes merely because code development, testing, packaging, or GitHub publication was authorized.

Treat existing worktrees, wp-env projects, containers, volumes, and test databases as preserved operational assets. Do not reuse, stop, update, delete, prune, or clean an environment created by another task unless the user explicitly authorizes that exact action. Create a uniquely named disposable environment for new integration work and report whether it was retained or removed.

Never run the full integration matrix, unrestricted PHPStan/Composer work, or release builds on a host that also serves production. Filesystem, worktree, and Docker separation do not prevent CPU, RAM, swap, I/O, or OOM interference. Use the repository's GitHub Actions matrix or a resource-isolated development host; when host ownership is uncertain, stop after lightweight source checks and verify it before continuing.

## Definition of done

- Code/documentation: the requested change is evidenced, exact-head checks and review pass or failures are accurately classified, guidance is synchronized, and the diff contains only intended changes. This does not mean a release exists.
- Installable package: additionally require a reviewed merged-main commit and green CI, the successful publisher, a new immutable pre-release, verified release/asset attestations, and independently redownloaded ZIP/checksum. Report exact refs and hashes; a pre-release is not stable or production acceptance.
- Installation: additionally require explicit target/backup/rollback authorization and the agreed staging, device, and post-installation checks. A ready package never grants permission to replace production files or activate the native-login gate.

Every handoff states the achieved boundary, unchanged external/production state, and the next outstanding gate.
