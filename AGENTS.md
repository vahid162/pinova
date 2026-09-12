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
- Pinova 1.2.3 RC2 regression hardening was merged by PR #2 at `45f9d516`; verify the current `main` SHA before relying on it.
- RC2 source branch: `release/v1.2.3-rc2-prep`.
- Existing pre-releases: `v1.2.3-rc1` at `ce9dc0d` and `v1.3.0-rc1` at `93409a6`; neither is stable/latest.
- The RC1 1.2.3 asset predates later PHP 8.1 dependency, CI matrix, PHP 8.5, and reproducible-build changes. Do not relabel or overwrite it.
- Post-merge main CI was green for PHP 8.1–8.5 and all configured WordPress/WooCommerce/HPOS pairs.
- The separate 1.3 development branch is `security/v1.2.3-v1.3.0`. Its identity schema/migration/merge features are not shipped by main 1.2.3.

Verify all facts before acting and update this section whenever lineage, release, compatibility, blocker, or milestone changes.

## Next expected milestones

1. Keep the reviewed RC2 regression fixes and release automation green on `main` without touching production.
2. Create `publish/v1.2.3-rc2` only from the exact reviewed `main` commit. Wait for its `Quality` run; the successful run creates the annotated tag, builds twice, publishes the GitHub pre-release, and redownloads/verifies the ZIP.
3. Verify the immutable `v1.2.3-rc2` tag, Release assets, checksum, and installable top-level `pinova/`.
4. Stop. Installation on `gpante.com` is a separate production operation requiring fresh backup/rollback controls and explicit authorization at execution time.
5. After the 1.2.3 canary, merge current `main` into the 1.3 development line, resolve drift, retest identity migration, and create a new reviewed 1.3 RC rather than reusing `v1.3.0-rc1`.

## Source routing

- Bootstrap/upgrades: `pinova.php`, `src/Install.php`, `src/Version.php`, `utils/`.
- REST/authentication: `src/API/`, `src/Services/`, `src/Objects/`.
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

The known gpante production path is `/www/wwwroot/gpante.com/wp-content/plugins/pinova`. Treat it as read-only unless the user explicitly requests the exact production action with appropriate backup, rollback, and maintenance controls.

Never use the production database for development or automated tests. Never run identity apply/merge/rollback, plugin replacement, Nginx edits, or database writes merely because code development, testing, packaging, or GitHub publication was authorized.

## Definition of done

The outcome is implemented/evidenced, required checks pass or failures are accurately classified, Skill/docs are synchronized, artifacts are reproducible and installable, the tree contains only intended changes, no unrequested production/external mutation occurred, and handoff names the exact branch/tag/version and remaining action.
