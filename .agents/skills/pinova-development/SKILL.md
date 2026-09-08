---
name: pinova-development
description: Develop, audit, diagnose, test, package, migrate, and release the Pinova WordPress and WooCommerce authentication plugin. Use for work in this repository involving OTP or password login, identity resolution, account migration or merge, WooCommerce integration, security, CI, installable ZIPs, staging, or GitHub releases; do not use for unrelated WordPress projects.
metadata:
  short-description: Develop and release Pinova safely
---

# Pinova Development

Work from the repository checkout, never from an installed production copy of the plugin.

## Start every task

1. Read the root [`AGENTS.md`](../../../AGENTS.md) and inspect `git status --short --branch`, the current branch, recent commits, and relevant tags. Treat version and release details in documentation as a snapshot that must be verified against Git.
2. Read only the project references needed for the task:
   - For architecture, identity invariants, data ownership, and source routing, read [`references/project-map.md`](references/project-map.md).
   - For CI, tests, packaging, staging, and GitHub releases, read [`references/quality-and-release.md`](references/quality-and-release.md).
   - Before any identity migration, merge, resume, or rollback work, read [`docs/identity-migration.md`](../../../docs/identity-migration.md) completely.
3. Classify the request as read-only review, diagnosis, implementation, migration, release, or production deployment. Do not turn permission for one class into permission for another.
4. Preserve unrelated user changes. Never clean, reset, overwrite, migrate, publish, or deploy merely to make inspection easier.

## Non-negotiable product invariants

- One verified username, email address, or mobile number resolves to one immutable WordPress User ID. Never rewrite an existing `wp_users.user_login` as a side effect of OTP authentication or migration.
- A deterministic identifier conflict across multiple legacy User IDs blocks login, registration, and automatic migration. Never select the first match. An administrator must choose the canonical target for any merge.
- Password authentication must continue through the native WordPress authentication pipeline so 2FA, Wordfence, and login hooks remain effective.
- Preserve the canonical account's password, roles, and capabilities during merge. Never merge capabilities, sessions, application passwords, reset secrets, or security-plugin data.
- Treat mobile and email as verified only after successful proof. Import legacy values without proof as unverified.
- Keep migrations additive, batched, idempotent, resumable, and journaled. Do not add foreign keys to WordPress core tables or alter/drop core columns and indexes.
- Block migration and merge apply on Multisite. For unsupported ownership systems such as wpForo or membership plugins, preflight must stop production merge until an adapter exists.
- Never log raw identifiers, OTPs, passwords, reset keys, application passwords, tokens, or full proxy headers. Use User IDs, event types, masked identifiers, and correlation IDs.
- Trust `REMOTE_ADDR` by default. Read proxy headers only when the immediate peer is inside an explicitly configured trusted CIDR.

## Workflows

### Review or diagnose

- Keep the work read-only unless the user explicitly requests a fix.
- Reproduce claims with source, tests, logs, or a disposable environment. Distinguish a plugin defect from environment, compatibility-matrix, Nginx, provider, or WordPress configuration failures.
- Report the affected version, identifier path, role, authentication method, HPOS mode, and whether the behavior occurs before or after WordPress hooks.

### Change code

1. Branch from the exact affected tag or agreed base; do not develop directly in the production plugin directory.
2. Add or update a regression test that fails for the reported behavior when practical.
3. Make the narrowest coherent change while preserving the invariants above and the existing REST response envelope where compatibility requires it.
4. Run targeted tests, then the proportional quality suite described in the quality reference. Authentication, identity, database, WooCommerce ownership, proxy, export, or redirect changes require integration coverage.
5. Update user-facing `readme.txt`, Persian `README.md`, migration documentation, schema notes, and changelog when their documented behavior changed.
6. Meaningfully update this `SKILL.md` in the same change set whenever runtime code, schema, dependencies, tests, build tooling, CI, commands, supported integrations, or release behavior changes. Do not satisfy this rule with a date-only or whitespace-only edit.
7. Run `bash .agents/skills/pinova-development/scripts/check-skill-sync.sh --working-tree` before handoff.

### Migrate or merge identities

- Work on a fresh staging copy first. Audit and dry-run are required before apply.
- Require an immediate full database backup before production apply and an explicit canonical User ID for every multi-account merge.
- Put Pinova login and registration into maintenance only for the bounded apply window, and always clear maintenance in a `finally` path.
- Record the run ID, validate ownership counts, test username/email/mobile resolution, and document irreversible session invalidation.
- A dry-run, audit, or account-discovery endpoint must not write identities or change users.

### Package or release

- Read the quality and release reference before changing a version, tag, ZIP, workflow, or GitHub Release.
- Release candidates remain GitHub pre-releases until staging and canary gates pass. Do not mark a release stable or latest without explicit authorization and green required checks.
- Build from the exact tag, download the published asset again, compare SHA-256, and test ZIP integrity and its top-level `pinova/` directory.
- GitHub publication does not authorize installation on `gpante.com` or any other production site.

## Documentation synchronization gate

The repository enforces Skill review for plugin-affecting changes with [`scripts/check-skill-sync.sh`](scripts/check-skill-sync.sh). When the project changes:

- update this entrypoint with any decision-changing guidance;
- update the relevant supporting reference rather than duplicating long procedures here;
- update `AGENTS.md` when the current branch, known blockers, next milestone, or completion gate changes;
- update `README.md` and `readme.txt` when public behavior, requirements, installation, commands, compatibility, or releases change.

If no instruction actually changed, add a concise note explaining what was reviewed and why the existing invariant still applies; do not make a meaningless edit solely to appease CI.

## Completion gate

Finish only when:

- the requested behavior is implemented or the read-only finding is evidenced;
- required tests and static checks pass, or every failure is accurately classified and reported;
- the Skill and affected documentation are synchronized;
- installable ZIPs, if requested, contain production files only and reproduce from the tagged source;
- no production data, files, configuration, external release, or user account was changed without explicit authorization;
- the handoff states the branch/tag, commands run, results, remaining risks, and next safe step.
