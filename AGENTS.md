# Pinova repository instructions

These instructions apply to the entire repository. User instructions always take precedence. Repository guidance does not authorize production, database, GitHub, release, or account mutations that the user did not request.

## Starting point

Before doing substantive work:

1. Read `.agents/skills/pinova-development/SKILL.md` completely and follow its routing to supporting references.
2. Run `git status --short --branch`, inspect the current branch and recent commits, and verify relevant tags. Do not assume the snapshot below is current.
3. Read `README.md` for the public project overview and `readme.txt` for WordPress.org metadata. For identity migration or merge work, read `docs/identity-migration.md` completely.
4. Classify the request as read-only review, diagnosis, implementation, migration, packaging/release, or production deployment. Stay inside that scope.
5. Preserve unrelated changes and secrets. Do not force-push, rewrite published tags, discard work, or clean shared Docker resources without explicit authority.

## Current project snapshot

- Repository: `https://github.com/vahid162/pinova`
- Baseline: `main` at `771b3d5`, Pinova 1.2.2.
- Development branch: `security/v1.2.3-v1.3.0`.
- Security RC: `v1.2.3-rc1` at `ce9dc0d`.
- Identity RC: `v1.3.0-rc1` at `93409a6`.
- Both RC tags have installable GitHub pre-release ZIPs; neither is stable/latest.
- PHP 8.1–8.5 CI jobs pass. WordPress-latest integration jobs pass. WordPress 6.8 matrix rows currently fail before Pinova tests because the selected WooCommerce packages require WordPress 6.9.
- `Autoptimize` and `LiteSpeedCache` compatibility classes exist, but the current bootstrap does not instantiate them; consider those integrations unverified until covered and fixed in a separately authorized runtime change.

Verify all refs and CI status before relying on this snapshot. Update this section whenever branch, release, compatibility, blocker, or milestone state changes.

## Next expected milestones

Unless the user changes priorities, the safe sequence is:

1. Correct the GitHub Actions compatibility matrix so every configured WordPress/WooCommerce pair is installable, then obtain a fully green run.
2. Decide whether to restore and test the currently unbootstrapped `Autoptimize` and `LiteSpeedCache` compatibility loaders before the stable release.
3. Validate `v1.2.3-rc1` on a fresh staging copy and complete the agreed 48-hour security canary.
4. Refresh staging from production, validate `v1.3.0-rc1`, run identity audit and migration dry-run, and resolve deterministic conflicts manually.
5. Back up production immediately before any approved apply, run the bounded migration/merge window, and complete the seven-day identity canary.
6. Create stable tags/releases only after the gates pass and the user explicitly authorizes publication.

Do not silently skip ahead in this sequence. Diagnose and report a blocker without mutating production.

## Source routing

- Bootstrap and upgrade: `pinova.php`, `src/Install.php`, `src/Version.php`, `utils/`.
- REST/authentication: `src/API/`, `src/Services/`, `src/Objects/`.
- Identity and merge: `src/Identity/`, `src/CLI/IdentityCommand.php`.
- WordPress integration and exports: `src/Integrations/Wordpress/`.
- WooCommerce integration and ownership: `src/Integrations/Woocommerce/`.
- Login UI: `templates/`, `assets/`.
- Unit and integration coverage: `tests/`.
- Reproducible package: `tools/build.sh`.
- CI: `.github/workflows/quality.yml`.

## Required workflow for changes

1. Reproduce the issue or establish the requested behavior from source and tests.
2. Add or update a regression test when practical.
3. Implement the smallest coherent fix while preserving the identity and security invariants in the Pinova Skill.
4. Run targeted tests, then proportional unit, static, coding-standard, dependency, integration, HPOS, and packaging checks.
5. Meaningfully update `.agents/skills/pinova-development/SKILL.md` in the same change set for every plugin-affecting code, schema, dependency, test, tooling, CI, command, integration, or release change.
6. Update the Skill references, this snapshot, `README.md`, `readme.txt`, migration runbook, and changelog wherever behavior or operating guidance changed.
7. Run `bash .agents/skills/pinova-development/scripts/check-skill-sync.sh --working-tree` and the Skill validator before handoff.
8. Report files changed, commands run, test results, known failures, production impact, and the next safe step.

## Production boundary

The known gpante production plugin path is `/www/wwwroot/gpante.com/wp-content/plugins/pinova`. Treat it as read-only unless the user explicitly asks for a production change and the exact action has appropriate backup, rollback, and maintenance controls.

Never use the production database for development or automated tests. Never run `wp pinova identity migrate --apply`, merge, rollback, plugin replacement, Nginx edits, or database writes on production based only on permission to develop, test, package, or publish GitHub code.

## Definition of done

A task is complete only when:

- the requested outcome is implemented or the finding is supported by evidence;
- required checks pass, or failures are accurately classified with links/logs and a clear blocker;
- Skill and documentation synchronization passes;
- the working tree contains only intended changes;
- any ZIP is reproducible, installable, and free of development-only files;
- no unrequested production or external mutation occurred;
- the handoff names the exact branch/tag/version, remaining risks, and next action.
