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
- `main` is currently `2399eb22f051d24bf9d749a98c2936713ef75a3c`; it includes the 1.2.3 RC2 regression hardening and the reproducible-release correction merged by PR #4.
- RC2 source branch: `release/v1.2.3-rc2-prep`.
- Existing pre-releases: `v1.2.3-rc1` at `ce9dc0d`, `v1.2.3-rc2` at `a4600eb5`, `v1.2.3-rc3` at `2399eb2`, and `v1.3.0-rc1` at `93409a6`; none is stable/latest.
- The RC1 1.2.3 asset predates later PHP 8.1 dependency, CI matrix, PHP 8.5, and reproducible-build changes. Do not relabel or overwrite it.
- RC3 is the current verified, installable 1.2.3 pre-release. It pins Composer 2.10.3, UTC, and staged permissions; keep all prior RC tags immutable.
- Post-merge main CI was green for PHP 8.1–8.5 and all configured WordPress/WooCommerce/HPOS pairs.
- Structured persistent logging is being developed as 1.2.4 on `feature/structured-logging`; it is not released or installed on production until reviewed, merged, packaged, and separately authorized.
- A task-specific logging worktree, Node toolchain, and uniquely named wp-env project are retained on the development host. Discover their current paths, ports, container state, and volumes with read-only commands before acting; do not publish host-local coordinates in this public repository. The stored test database has the final 1.2.4 ZIP as slug `pinova`, the source mount inactive, WooCommerce 10.7.0 active, and logging defaults restored to `warning`/14 days/debug off.
- An earlier RC2 wp-env project and worktree are separately preserved. Never use, stop, update, or remove their containers, volumes, database, or ports for 1.2.4 work. Identify ownership from the project labels and task handoff rather than assuming a directory name.
- The separate 1.3 development branch is `security/v1.2.3-v1.3.0`. Its identity schema/migration/merge features are not shipped by main 1.2.3.

Verify all facts before acting and update this section whenever lineage, release, compatibility, blocker, or milestone changes.

## Next expected milestones

1. Review and test the 1.2.4 structured-logging change without touching production or any preserved test environment.
2. Merge only after PHP, integration, privacy, retention, upgrade, and installable-ZIP gates pass.
3. If requested, create a new immutable 1.2.4 release candidate from the exact reviewed commit; never move or overwrite any 1.2.3 RC.
4. Stop. Installation on the production site is a separate operation requiring fresh backup/rollback controls and explicit authorization at execution time.
5. After the 1.2.x canary, merge current `main` into the 1.3 development line, resolve drift, retest identity migration, and create a new reviewed 1.3 RC rather than reusing `v1.3.0-rc1`.

## Source routing

- Bootstrap/upgrades: `pinova.php`, `src/Install.php`, `src/Version.php`, `utils/`.
- REST/authentication: `src/API/`, `src/Services/`, `src/Objects/`.
- Structured logging: `src/Logging/`, `src/Admin/Logs.php`, and `.agents/skills/pinova-development/references/logging.md`.
- WordPress profiles/exports: `src/Integrations/Wordpress/`.
- WooCommerce: `src/Integrations/Woocommerce/`.
- Login UI: `templates/`, `assets/`.
- Tests: `tests/`.
- Reproducible package: `tools/build.sh`.
- CI and pre-release publication: `.github/workflows/quality.yml`, `.github/workflows/publish-prerelease.yml`.
- Agent guidance: `.agents/skills/pinova-development/`.

## Required workflow for changes

1. Reproduce the issue or establish the requirement from source/tests.
2. Add or update a regression test when practical.
3. Implement the smallest coherent fix while preserving the Skill invariants.
4. Run targeted tests, then proportional unit, integration, HPOS, static, coding-standard, dependency, and packaging checks.
5. Meaningfully update `.agents/skills/pinova-development/SKILL.md` in the same change set for every plugin-affecting change.
6. Update references, this snapshot, `README.md`, `readme.txt`, and changelog where behavior or operations changed.
7. Run `bash .agents/skills/pinova-development/scripts/check-skill-sync.sh --working-tree` and validate the Skill before handoff.
8. Report exact files/refs/checks, remaining risk, production impact, and next safe step.

## Production boundary

Treat `<production-wordpress-root>/wp-content/plugins/pinova` as read-only unless the user explicitly requests the exact production action with appropriate backup, rollback, and maintenance controls. Resolve the real production root from the authorized host at execution time; never hard-code or publish it here.

Never use the production database for development or automated tests. Never run identity apply/merge/rollback, plugin replacement, web-server edits, or database writes merely because code development, testing, packaging, or GitHub publication was authorized.

Treat existing worktrees, wp-env projects, containers, volumes, and test databases as preserved operational assets. Do not reuse, stop, update, delete, prune, or clean an environment created by another task unless the user explicitly authorizes that exact action. Create a uniquely named disposable environment for new integration work and report whether it was retained or removed.

## Definition of done

The outcome is implemented/evidenced, required checks pass or failures are accurately classified, Skill/docs are synchronized, artifacts are reproducible and installable, the tree contains only intended changes, no unrequested production/external mutation occurred, and handoff names the exact branch/tag/version and remaining action.
