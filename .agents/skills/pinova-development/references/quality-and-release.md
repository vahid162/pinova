# Quality, packaging, and release

Read this reference for test changes, dependency updates, CI repair, ZIP building, staging, tags, or releases.

## Quality suite

Install locked dependencies before running checks:

```bash
composer install --no-interaction --prefer-dist
npm install --ignore-scripts
```

Run the PHP gates:

```bash
composer lint
composer test
composer phpstan
composer phpcs
composer audit
```

Run integration tests in an isolated WordPress environment:

```bash
npm run env:configure
npm run env:start -- --update
PINOVA_TEST_HPOS=no npm run test:integration
PINOVA_TEST_HPOS=yes npm run test:integration
npm run env:stop
```

Set `WP_VERSION` and `WC_VERSION` before `env:configure` when testing a compatibility pair. Confirm that the selected WooCommerce release actually supports the selected WordPress release; an impossible matrix pair is an environment failure, not a plugin test failure.

Authentication and identity changes must cover username, email, and mobile resolving to the same User ID, administrator policy, standard login hooks, deterministic conflicts, concurrent registration, migration restart, merge journal, and rollback. WooCommerce ownership changes require HPOS both on and off.

## Current CI caveat

At the `v1.3.0-rc1` snapshot, PHP 8.1–8.5 and WordPress latest jobs passed. WordPress 6.8 integration jobs failed during `wp-env start`, before Pinova tests, because the chosen WooCommerce packages require WordPress 6.9. Fix the compatibility matrix and obtain a fully green run before a stable release.

## Reproducible ZIP

Build with:

```bash
composer build
```

`tools/build.sh` stages `pinova/`, installs production Composer dependencies, removes development manifests, normalizes timestamps, sorts files, and creates `.build/pinova-<version>.zip`.

The ZIP must exclude at least `.git`, `.github`, `.agents`, `AGENTS.md`, development caches, `node_modules`, tests, tools, and development-only Composer packages. Validate it with:

```bash
unzip -tq .build/pinova-<version>.zip
unzip -Z1 .build/pinova-<version>.zip
sha256sum .build/pinova-<version>.zip
```

Build twice from the same tagged tree and require identical SHA-256 values.

## Release sequence

1. Confirm a clean tree, intended base, version headers, changelog, `composer.lock`, and Skill/documentation synchronization.
2. Run required local gates and obtain a green GitHub Actions run for the exact release commit.
3. Create an annotated RC tag. Do not move or rewrite a published tag.
4. Build from a detached worktree at that tag.
5. Push the branch and tags without force; use an atomic push when publishing related refs.
6. Create a GitHub pre-release and attach the installable ZIP plus its SHA-256 in the notes.
7. Download the asset from GitHub, recheck SHA-256 and ZIP integrity, and remove temporary credentials/worktrees.
8. Install on a fresh staging copy. Validate `/login`, `/wp-login.php`, OTP/password flows, administrator 2FA/hooks, WooCommerce checkout/orders, and logs.
9. Canary the security hotfix before the identity release. Apply identity migration only after audit, dry-run, manual conflict decisions, and an immediate database backup.
10. Mark stable/latest only after the agreed canary period and explicit authorization.

## Release lineage snapshot

- `main` / `771b3d5`: imported 1.2.2 baseline.
- `v1.2.3-rc1` / `ce9dc0d`: security hotfix release candidate.
- `v1.3.0-rc1` / `93409a6`: identity and migration release candidate.
- Development branch: `security/v1.2.3-v1.3.0`.

Verify these refs rather than assuming the snapshot is current, and update this reference plus `AGENTS.md` whenever lineage changes.
