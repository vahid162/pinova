# Quality, packaging, and release

Read this reference for tests, dependencies, CI, ZIP builds, staging, tags, or releases. Live branch, run, release, and deployment state must be discovered from Git, GitHub, and the authorized environment; exact historical evidence belongs under `.agents/reviews/`.

## Execution location gate

Before installing dependencies or running quality tools, determine whether the checkout shares CPU, RAM, swap, I/O, or Docker with production or another live tenant. Worktree and container separation protect files and databases, not host capacity. Never run the full integration matrix, unrestricted PHPStan/Composer analysis, browser environments, or reproducible release builds on a shared live host. Use GitHub Actions or a dedicated resource-isolated development host and never stop or prune environments owned by another task.

## Quality suite

Install dependencies using the repository lockfiles and commands documented by the active package manifests. Run lightweight gates before pushing:

```bash
php tools/check-ai-governance.php
php tools/check-changelog-sync.php
bash .agents/skills/pinova-development/scripts/check-skill-sync.sh --working-tree
npm run test:js
composer lint
composer test
composer phpstan
composer phpcs
composer audit
```

Run only the subset that is safe on the current host. GitHub Actions remains authoritative for the complete compatibility matrix.

The dependency-free JavaScript suite evaluates shipped browser scripts in isolated VMs. It covers successful and non-success REST envelopes, WordPress field validation, `Retry-After`, correlation metadata, nonce-header isolation, malformed responses, network failures, caller cancellation, bounded timeouts, Blocked List behavior, modal structure, request locking, OTP normalization, and safe dismissal. These source-level tests do not replace real browser acceptance.

## Isolated integration and browser environments

Create a task-specific wp-env with unique ports, `COMPOSE_PROJECT_NAME`, and `WP_ENV_HOME`. Never reuse or mutate an environment from another task. Configure supported WordPress/WooCommerce pairs before start and install `pdo_mysql` only in the disposable web, CLI, and tests containers that need it.

Integration coverage must load WooCommerce before Pinova, exercise HPOS both enabled and disabled, and fail rather than skip when required WooCommerce classes or Pinova tables are missing. Authentication and identity changes must cover native hooks, role policy, uniform public responses, explicit mobile override, deterministic conflict handling, immutable core identity, and purpose-bound OTP behavior.

Logout tests must cover empty-target fallbacks, the canonical route, session destruction, invalid/stale nonce handling, rejected redirects, and the race where headers become committed after an apparently successful redirect. Stored failure context must exclude URLs, queries, nonces, identifiers, cookies, and tokens.

Blocked List tests cover every supported identifier type, normalization/type mismatch, permanent and expiring rows, system-block immutability, capability denial, filtering, pagination, privacy-safe logs, and checks at issuance and verification time.

Native-login tests cover the private native pipeline, role restriction, upstream password-reset denials, activation-arm lifetime and binding, single-use consumption, durable activation, invalidation, emergency override, preserved non-authentication core actions, home/subdirectory URLs, return-target encoding, reauthentication, and privacy-safe audit events.

Real browser acceptance covers the standalone account screen and checkout modal at supported responsive widths, landscape/reduced-height/enlarged-text states, configured OTP lengths, localized digits, paste/autofill/WebOTP, request locking, focus transitions, trapping/restoration, inertness, closing during requests, safe areas, theme stylesheet order, and horizontal overflow. Physical Chrome Android and iOS Safari acceptance remains a separate staging gate.

### Modal reliability and sensitivity

Synchronize keyboard assertions with product-driven heading focus, not fixed sleeps or test-created focus. Keep whole-test retries disabled. The mutation audit must run healthy controls and verify that each isolated product mutation reaches its intended assertion; runner errors, unrelated failures, timeouts, skips, retries, and surviving mutations fail the gate.

The browser geometry matrix selects presentation states; it is not a count of authentication transactions. Preserve small nonsensitive evidence summaries under `.agents/reviews/`, but never commit raw traces, credentials, sessions, or private host coordinates.

### Environment cleanup

The CI cleanup script is restricted to the exact run-owned Compose project and work directory. It validates run identity, rejects symlinks and ambiguous paths, tears down only labeled resources, preserves shared images, verifies no owned resources remain, and fails visibly on errors. It is not a desktop or global Docker cleanup command.

## Coding and compatibility gates

- PHP lint, PHPUnit, PHPStan, WPCS, dependency audit, and package build are required where configured.
- JavaScript unit/lint gates and browser acceptance are distinct.
- WordPress, WooCommerce, PHP, and HPOS matrix jobs must represent supported combinations without connecting to production.
- A source assertion is not visual acceptance, and a green pull-request run is not merged-main or release evidence.
- A logging change requires no-secret unit coverage, persistence/upgrade/retention integration coverage, viewer authorization/escaping coverage, and package verification.

## Reproducible installable ZIP

Build with the Composer version pinned by `tools/build.sh`. The build exports a fixed timezone, stages one top-level `pinova/` directory, installs production dependencies from `composer.lock`, removes development-only files, normalizes permissions and timestamps, sorts entries, and writes the versioned ZIP.

The ZIP excludes repository metadata, GitHub and agent instructions, root development documentation, caches, `node_modules`, tests, tools, and development Composer packages. It retains WordPress `readme.txt` and all production runtime dependencies.

The build must confirm that Composer eagerly requires `utils/class-database.php`; classmap presence alone is insufficient. Build twice from the exact release tree under deliberately different environment inputs and require identical hashes. Validate ZIP integrity, file allowlists, executable modes, and the single top-level directory.

## Pull-request and release sequence

1. Confirm a clean tree, exact remote base, lockfiles, metadata, and durable guidance. Run governance and changelog checks.
2. Open a focused pull request and require the complete applicable GitHub Actions suite on its exact head.
3. Merge only after review and required checks. Reconfirm the merge commit and its separate default-branch run.
4. Select a new unused release-candidate version only after the intended commit is merged and green. Never reuse or move a published tag.
5. Create the controlled publish branch from that exact commit. The publisher verifies reachability and version metadata, builds twice, creates an annotated tag, and uses an explicit draft/upload/publish sequence.
6. Require native Release immutability, release and asset attestations, independent redownload, checksum agreement, ZIP integrity, and exact package content. Preserve any failed published candidate unchanged and correct the issue in a new candidate.
7. Record exact publication evidence under `.agents/reviews/` in a follow-up documentation pull request. Evidence never changes durable instructions or grants installation authority.
8. Test the published ZIP on production-like staging before production. Keep risk-increasing gates disabled until their private recovery path and protected WordPress actions pass acceptance.
9. Mark a release stable/latest only after the agreed canary and explicit authorization.

A local ZIP is intermediate evidence, never the installation handoff. A package is ready for installation only when its branch, commits, pull request, exact-head CI, merge, merged-main CI, immutable GitHub release, ZIP, checksum, attestations, and fresh-download verification are all present.

## Production acceptance boundary

Before installation, identify the exact site, verify a database and plugin backup, define rollback, and preserve an authenticated administrator recovery path. Validate public login methods, OTP purposes, the private administrator route, security-plugin hooks, logout, privacy actions, WooCommerce, cron, logs, and database schema first on staging.

After deployment, verify schema version, scheduled events, REST headers, authentication flows, logging volume/redaction, PHP and web-server errors, database growth, and response latency for the agreed observation window. Publishing or passing CI never authorizes installation, settings changes, database migration, or native-login gate activation by itself.
