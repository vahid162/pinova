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

## Current project snapshot

- Repository: `https://github.com/vahid162/pinova`
- `main` includes the 1.2.3 security line, reproducible-release hardening, structured logging, the 1.2.5 fresh-request database-bootstrap hotfix, the reviewed 1.2.6 account/Blocked List corrections merged by PR #8 at `270ace9`, native-immutable publisher hardening introduced by PR #9 at `cccf1c3`, the WooCommerce checkout-lifecycle correction, the account-brand/native-login and redirect round-trip work merged by PR #15 at `0b89485`, the focused OTP hierarchy follow-up merged by PR #17 at `b65370b`, and RC5 release evidence merged by PR #18 at `b8d59e3`.
- Existing 1.2.3 pre-releases remain immutable. `v1.2.4-rc1` contains the Composer bootstrap regression and is superseded by `v1.2.5-rc1`; no RC is stable/latest.
- `v1.2.6-rc1` is published and checksum-verified but reports `immutable: false` because it predates repository-level native Release Immutability; retain it unchanged as history. RC2–RC4 remain immutable superseded history. `v1.2.6-rc5` is the current published 1.2.6 handoff: annotated tag object `cd1b0e1` targets merged commit `b65370b`, Release `389832419` reports `immutable: true`, all PR/`main`/publish/publisher gates and attestations passed, and an independent redownload verified ZIP SHA-256 `1998a8c4c783f2624f16a650fcd3952a987e02ff9106fb585c7a9a96db3bfd36`. Its subsequent visual acceptance rejected the two-pane account presentation, so retain RC5 unchanged and supersede it with a newly reviewed immutable RC before another production installation.
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

## Next expected milestones

1. Treat `v1.2.4-rc1` as superseded; do not install, retag, or overwrite it.
2. Retain RC1–RC5 unchanged as publication history. RC5 passed publication integrity but failed the requested visual acceptance; finish the single-card account correction through review and Quality, then publish a new immutable RC before another production installation. Never substitute an implementation branch, worktree artifact, or GitHub automatic source archive.
3. Do not install an earlier RC, implementation branch, or worktree artifact for a new canary. Investigate later defects from a separate worktree and uniquely named disposable environment; never reuse preserved logging, RC2, or 1.2.5 verification assets without explicit authorization.
4. Treat structural CI and package verification as distinct from visual acceptance. After separately authorized installation, verify the account flow at desktop/mobile widths, edit destination, configured code length, paste/autofill, resend transition, keyboard focus, errors, success, and overflow before broader use.
5. Keep future publication independent of an Administration-scoped secret and repository Ruleset. After the repository owner confirms native Release Immutability is enabled, publish only from an exact reviewed and green `main` commit and require the post-publication Release API, attestations, and redownload checks to pass. Preserve any failed tag or Release unchanged and use a new RC tag after correcting the cause.
6. Stop before installation. Complete the active stability observation and repeat the read-only production health gate first; production changes then require a fresh backup, an exact rollback plan, and explicit authorization at execution time.
7. After the 1.2.x canary, merge current `main` into the 1.3 development line, resolve drift, retest identity migration, and create a new reviewed 1.3 RC rather than reusing `v1.3.0-rc1`.

## Source routing

- Bootstrap/upgrades: `pinova.php`, `src/Install.php`, `src/Version.php`, `utils/`.
- REST/authentication: `src/API/`, `src/Services/`, `src/Objects/`.
- Structured logging: `src/Logging/`, `src/Admin/Logs.php`, and `.agents/skills/pinova-development/references/logging.md`.
- WordPress profiles/exports: `src/Integrations/Wordpress/`.
- WooCommerce: `src/Integrations/Woocommerce/`.
- Login UI: `templates/`, `assets/`.
- Tests: `tests/`.
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
7. Run `php tools/check-changelog-sync.php`, then `bash .agents/skills/pinova-development/scripts/check-skill-sync.sh --working-tree`, and validate the Skill before handoff.
8. Report exact files/refs/checks, remaining risk, production impact, and next safe step.

## Production boundary

Treat `<production-wordpress-root>/wp-content/plugins/pinova` as read-only unless the user explicitly requests the exact production action with appropriate backup, rollback, and maintenance controls. Resolve the real production root from the authorized host at execution time; never hard-code or publish it here.

Never use the production database for development or automated tests. Never run identity apply/merge/rollback, plugin replacement, web-server edits, or database writes merely because code development, testing, packaging, or GitHub publication was authorized.

Treat existing worktrees, wp-env projects, containers, volumes, and test databases as preserved operational assets. Do not reuse, stop, update, delete, prune, or clean an environment created by another task unless the user explicitly authorizes that exact action. Create a uniquely named disposable environment for new integration work and report whether it was retained or removed.

Never run the full integration matrix, unrestricted PHPStan/Composer work, or release builds on a host that also serves production. Filesystem, worktree, and Docker separation do not prevent CPU, RAM, swap, I/O, or OOM interference. Use the repository's GitHub Actions matrix or a resource-isolated development host; when host ownership is uncertain, stop after lightweight source checks and verify it before continuing.

## Definition of done

The outcome is implemented/evidenced, required checks pass or failures are accurately classified, Skill/docs are synchronized, artifacts are reproducible and installable, the tree contains only intended changes, no unrequested production/external mutation occurred, and handoff names the exact branch/tag/version and remaining action.
