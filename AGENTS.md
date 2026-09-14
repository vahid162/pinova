# Pinova repository instructions

These instructions apply to the whole repository. User instructions take precedence. Repository guidance does not authorize production, database, GitHub, release, or account mutations the user did not request.

## Starting point

Before substantive work:

1. Read `.agents/skills/pinova-development/SKILL.md` completely and follow its reference routing.
2. Inspect `git status --short --branch`, branch, recent commits, tags, and relevant GitHub checks/releases. Verify the snapshot below.
3. Read Persian `README.md` for the public overview and `readme.txt` for WordPress.org metadata.
4. Classify the task as review, diagnosis, implementation, migration, packaging/release, or production deployment and stay within that authority.
5. Preserve unrelated changes and secrets. Do not force-push, move published tags, discard work, or clean shared Docker resources.

## Current project snapshot

- Repository: `https://github.com/vahid162/pinova`
- `main` includes the 1.2.3 security line, reproducible-release hardening, structured logging, the 1.2.5 fresh-request database-bootstrap hotfix, the reviewed 1.2.6 account/Blocked List corrections merged by PR #8 at `270ace9`, and native-immutable publisher hardening introduced by PR #9 at `cccf1c3`.
- Existing 1.2.3 pre-releases remain immutable. `v1.2.4-rc1` contains the Composer bootstrap regression and is superseded by `v1.2.5-rc1`; no RC is stable/latest.
- `v1.2.6-rc1` is published and checksum-verified but reports `immutable: false` because it predates repository-level native Release Immutability. Retain it unchanged as history; `v1.2.6-rc2` is the next installation-handoff target after native immutability and attestation verification pass.
- The RC1 1.2.3 asset predates later PHP 8.1 dependency, CI matrix, PHP 8.5, and reproducible-build changes. Do not relabel or overwrite it.
- RC3 is the current verified, installable 1.2.3 pre-release. It pins Composer 2.10.3, UTC, and staged permissions; keep all prior RC tags immutable.
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
2. Use `v1.2.5-rc1` for staging/canary only after PHP, integration, privacy, upgrade, and installable-ZIP gates pass.
3. Investigate any new defect from a separate worktree and uniquely named disposable environment; never reuse preserved logging, RC2, or 1.2.5 verification assets without explicit authorization.
4. Keep the publisher independent of an Administration-scoped secret and repository Ruleset. After the repository owner confirms native Release Immutability is enabled, publish `v1.2.6-rc2` from an exact reviewed and green `main` commit. Require its post-publication Release API to report `immutable: true` plus successful asset-attestation and redownload checks. If it unexpectedly reports mutable, preserve that tag and Release and use a new RC tag after correcting the setting. Only an official GitHub ZIP that passes these gates is an installation handoff; `v1.2.6-rc1` and local ZIPs are retained evidence.
5. Stop before installation. Production changes require fresh backup/rollback controls and explicit authorization at execution time.
6. After the 1.2.x canary, merge current `main` into the 1.3 development line, resolve drift, retest identity migration, and create a new reviewed 1.3 RC rather than reusing `v1.3.0-rc1`.

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

## Definition of done

The outcome is implemented/evidenced, required checks pass or failures are accurately classified, Skill/docs are synchronized, artifacts are reproducible and installable, the tree contains only intended changes, no unrequested production/external mutation occurred, and handoff names the exact branch/tag/version and remaining action.
