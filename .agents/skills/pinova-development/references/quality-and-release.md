# Quality, packaging, and release

Read this reference for tests, dependencies, CI, ZIP builds, staging, tags, or releases.

## Quality suite

Install locked dependencies:

```bash
composer install --no-interaction --prefer-dist
npm install --ignore-scripts
```

Run PHP gates:

```bash
composer lint
composer test
composer phpstan
composer phpcs
composer audit
```

Run integration tests only in an isolated WordPress environment:

```bash
npm run env:configure
npm run env:start -- --update
PINOVA_TEST_HPOS=no npm run test:integration
PINOVA_TEST_HPOS=yes npm run test:integration
npm run env:stop
```

Set `WP_VERSION` and `WC_VERSION` before configuration for compatibility pairs. `tools/run-integration.sh` derives the mounted plugin directory from the checkout name and forwards `PINOVA_TEST_HPOS=yes|no` into `tests-cli`; the bootstrap writes that option into the PHPUnit database before Pinova loads. Do not use the ordinary `cli` container to configure HPOS for integration tests because it addresses the separate development database. The test bootstrap loads the mounted WooCommerce plugin before Pinova; a missing WooCommerce class is a test failure, not an acceptable skip. Ensure `pdo_mysql` is installed in `tests-cli` because Pinova's Illuminate connection uses PDO. The wp-env image's in-container sudo does not retain `PHP_INI_DIR`, so pass `PHP_INI_DIR=/usr/local/etc/php` explicitly to `docker-php-ext-install`. Authentication and legacy-identity changes must cover native hooks, role policy, uniform public responses, explicit mobile override of every older alias, deterministic conflicts, invalid mobile objects, and no core-table mutation. WooCommerce changes require HPOS off and on.

## Reproducible installable ZIP

Build with `composer build`. `tools/build.sh` stages `pinova/`, installs production dependencies, removes development-only files, normalizes timestamps, sorts entries, and writes `.build/pinova-<version>.zip`.

The ZIP must exclude `.git`, `.github`, `.agents`, `AGENTS.md`, caches, `node_modules`, tests, tools, and development Composer packages. Validate with:

```bash
unzip -tq .build/pinova-<version>.zip
unzip -Z1 .build/pinova-<version>.zip
sha256sum .build/pinova-<version>.zip
```

Build twice from the same tagged tree and require identical SHA-256 values.

## Release sequence

1. Confirm clean tree, exact base, version metadata, changelog, locks, and guidance synchronization.
2. Pass local checks and GitHub Actions for the exact release commit.
3. Merge the reviewed PR, then create a new immutable annotated RC tag; never move an existing published tag.
4. Build from a detached worktree at that tag twice and compare hashes.
5. Create a GitHub pre-release and attach the installable ZIP with SHA-256 in its notes.
6. Download the published asset, verify its hash/integrity/top-level directory, and remove temporary release resources.
7. Stop before production unless installation was separately and explicitly authorized. On staging/production, validate `/login`, `/wp-login.php`, username/email/mobile password paths, OTP, administrator native login/2FA hooks, WooCommerce, and logs.
8. Mark stable/latest only after the agreed canary and explicit authorization.

## Current lineage snapshot

- `main` / `a3b1fab`: merged Pinova 1.2.3 security line and repository guidance PR.
- `v1.2.3-rc1` / `ce9dc0d`: older security pre-release; it predates later CI, dependency, and build corrections.
- `v1.3.0-rc1` / `93409a6`: separate identity/migration pre-release.
- RC2 preparation branch: `release/v1.2.3-rc2-prep` based on `a3b1fab`.
- 1.3 development branch: `security/v1.2.3-v1.3.0`.

The post-merge `main` workflow was green for PHP 8.1–8.5 and all six configured WordPress/WooCommerce/HPOS integration pairs. Re-verify refs and checks before relying on this snapshot, and update it when RC2 is merged/tagged.
