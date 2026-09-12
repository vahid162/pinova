---
name: pinova-development
description: Develop, audit, diagnose, test, package, migrate, and release the Pinova WordPress and WooCommerce authentication plugin. Use for work in this repository involving OTP or password login, legacy identity resolution, account migration or merge, WooCommerce integration, security, CI, installable ZIPs, staging, or GitHub releases; do not use for unrelated WordPress projects.
metadata:
  short-description: Develop and release Pinova safely
---

# Pinova Development

Work from a repository checkout or disposable worktree, never from an installed production copy of the plugin.

## Start every task

1. Read the root [`AGENTS.md`](../../../AGENTS.md). Inspect `git status --short --branch`, the current branch, recent commits, tags, and GitHub release state. Verify snapshots against Git.
2. Read the references needed for the task:
   - [`references/project-map.md`](references/project-map.md) for architecture, legacy identifier behavior, persistence, and source routing.
   - [`references/quality-and-release.md`](references/quality-and-release.md) for CI, tests, packaging, staging, and releases.
   - If identity migration documentation exists on the active 1.3 development branch, read it completely before migration, merge, resume, or rollback work.
3. Classify the request as read-only review, diagnosis, implementation, migration, release, or production deployment. Permission for one class does not authorize another.
4. Preserve unrelated changes. Never clean, reset, overwrite, migrate, publish, or deploy to simplify inspection.

## Non-negotiable invariants

- A WordPress User ID is the account anchor. Never rewrite `wp_users.user_login` as a side effect of OTP login, profile/mobile editing, fallback resolution, or migration.
- In 1.2.3, a valid physical `pinova_mobile` user-meta value is an explicit override and takes precedence over every older fallback alias, including a mobile-shaped legacy username and Digits metadata. Read that physical row with a prepared database query; calling `get_user_meta()` or `get_metadata_raw()` from the virtual `get_user_metadata` path recurses. Once an override exists, an older mobile value must not remain a Pinova login alias.
- If one mobile deterministically matches multiple legacy User IDs, fail closed and emit `pinova/identity_conflict_detected`; never choose the first row. Multi-ID merge belongs to the separately tested 1.3 identity workflow.
- Password authentication must resolve the User ID first, then call `wp_signon()` with the real `user_login`, preserving 2FA, Wordfence, and standard login hooks.
- Public authentication and recovery responses must not reveal whether an identifier exists, has a password, or belongs to a native-only role. Native-only roles still use `wp-login.php`, but that policy is not exposed through account-specific REST output.
- Role allowlists must be revalidated after filters. A filter cannot admit roles with administrative, user-management, plugin-management, WooCommerce-management, or order-edit capabilities.
- Legacy upgrade methods are additive/no-op against WordPress core tables. Do not alter/drop core columns or indexes, rewrite core identity data, or add foreign keys to core tables.
- Spreadsheet formatting must stay within populated ranges. Never style whole worksheet columns such as `A:Z`, because PhpSpreadsheet materializes millions of cells and can exhaust PHP memory even for a tiny export.
- A `pinova_blocks` row with `blocked_until = NULL` is permanent. Expired cleanup must leave permanent blocks intact.
- Integration tests must load the configured WooCommerce checkout before Pinova and exercise real WooCommerce classes. Forward `PINOVA_TEST_HPOS=yes|no` into `tests-cli`; the bootstrap must set the HPOS option in the PHPUnit database, not the separate development database. The disposable `tests-cli` container also needs `pdo_mysql` because Pinova's Illuminate database layer uses PDO even when WordPress itself uses mysqli. When installing it through in-container `sudo`, pass `PHP_INI_DIR=/usr/local/etc/php` explicitly because sudo does not preserve that image environment variable.
- Never log raw identifiers, OTPs, passwords, reset keys, tokens, or full proxy headers. Use IDs, masked values, event types, and correlation IDs.
- Trust only `REMOTE_ADDR` by default. Proxy headers require an immediate peer in an explicitly configured trusted CIDR.

## Workflows

### Review or diagnose

- Keep the task read-only unless the user explicitly asks for a fix.
- Establish claims with source, tests, logs, or a disposable WordPress environment. Separate plugin defects from server rewrites, provider failures, or unsupported WordPress/WooCommerce pairs.
- Report affected version, identifier type, role policy, login method, HPOS mode, and whether failure happens before or after WordPress hooks.

### Change code

1. Branch from the exact affected ref in a separate worktree; do not develop in `/www/wwwroot/gpante.com/wp-content/plugins/pinova`.
2. Add a regression test when practical. Authentication, identifier, database, exports, proxy, redirect, and WooCommerce ownership changes require integration coverage.
3. Make the smallest coherent change and preserve the REST envelope `{success,message,data}` where compatibility requires it.
4. For mobile profile changes, store the normalized value in physical `pinova_mobile` meta, validate ownership/conflicts, and keep `user_login` immutable.
5. Run targeted checks and the proportional suite in the quality reference.
6. Update public documentation and changelog when behavior changes.
7. Meaningfully update this `SKILL.md` in the same change set whenever runtime code, schema, dependencies, tests, tooling, CI, commands, integrations, or release behavior changes.
8. Run `bash .agents/skills/pinova-development/scripts/check-skill-sync.sh --working-tree` before handoff.

### Migrate or merge identities

- Identity-table migration and multi-account merge are 1.3 features, not part of the 1.2.3 hotfix. Do not backport schema or automatic merge behavior casually.
- Use a fresh staging copy, audit, and dry-run before apply. Require an immediate full database backup and explicit canonical User ID for every multi-account merge.
- Block apply on Multisite. Unsupported ownership systems require a preflight stop until an adapter exists.
- Discovery/audit must not write. Apply must be additive, batched, idempotent, resumable, and journaled, with maintenance always cleared in a `finally` path.

### Package or release

- Read the quality/release reference before changing versions, tags, ZIPs, workflows, or GitHub Releases.
- Release candidates remain pre-releases until staging and canary gates pass. Stable/latest publication needs explicit authorization.
- Build twice from the exact tag, compare SHA-256, publish the installable ZIP, download it again, and verify checksum, integrity, and top-level `pinova/`.
- Automated pre-release publication starts only after a successful `Quality` run on an exact `publish/vX.Y.Z-rcN` branch. The publisher validates the plugin version, creates an annotated immutable tag, builds twice, attaches the ZIP and checksum, and redownloads the published asset. Never create that branch until the intended commit is already reviewed and green on `main`.
- GitHub publication never authorizes installation, configuration changes, or database writes on `gpante.com`.

## Documentation synchronization gate

The repository enforces Skill review with [`scripts/check-skill-sync.sh`](scripts/check-skill-sync.sh). For project-affecting changes:

- update this entrypoint with decision-changing guidance;
- place detailed procedures in the relevant reference;
- update `AGENTS.md` when lineage, blockers, milestone, or completion gates change;
- update Persian `README.md` and WordPress `readme.txt` when public behavior, requirements, installation, or releases change.

Do not use a date-only or whitespace-only edit to satisfy the gate.

## Completion gate

Finish only when the requested behavior is evidenced or implemented, required checks pass (or failures are accurately classified), guidance is synchronized, requested artifacts are reproducible, and no production or external state changed outside the user-authorized scope. Handoff must name branch/tag, checks, results, remaining risk, and next safe step.
