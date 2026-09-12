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

An installable-ZIP browser lifecycle check also needs `pdo_mysql` in the disposable `wordpress` web container; installing it only in `tests-cli` is sufficient for PHPUnit but not for a real authenticated wp-admin request. Never modify a preserved environment to add it—prepare the task-specific environment and record that difference in the handoff.

## Reproducible installable ZIP

Build with Composer 2.10.3 using `composer build`. `tools/build.sh` rejects other Composer versions, exports `TZ=UTC`, stages `pinova/`, installs production dependencies, removes development-only files, normalizes directory/file modes to `0755`/`0644`, normalizes timestamps, sorts entries, and writes `.build/pinova-<version>.zip`. Pinning Composer, timezone, and file modes is required: Composer versions can format generated autoload files differently, while ZIP records DOS timestamps and Unix permissions from the build host.

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
3. Merge the reviewed PR. Reconfirm `main` and its `Quality` run before selecting the release commit.
4. Create `publish/vX.Y.Z-rcN` from that exact immutable commit. Its successful `Quality` run triggers `.github/workflows/publish-prerelease.yml`.
5. The publisher validates the branch and plugin versions, builds twice at the exact SHA under deliberately different timezone/umask inputs, compares hashes, creates a new annotated tag, and creates a GitHub pre-release with the installable ZIP and `.sha256` asset. Existing tags or releases are never moved or overwritten.
6. The publisher redownloads both assets and verifies the hash, ZIP integrity, and top-level `pinova/`. Confirm the workflow and GitHub Release are green before reporting completion.
7. Stop before production unless installation was separately and explicitly authorized. On staging/production, validate `/login`, `/wp-login.php`, username/email/mobile password paths, OTP, administrator native login/2FA hooks, WooCommerce, and logs.
8. Mark stable/latest only after the agreed canary and explicit authorization.

## Current lineage snapshot

- `main` / `2399eb2`: Pinova 1.2.3 security line plus the reproducible-release correction merged by PR #4.
- `v1.2.3-rc1` / `ce9dc0d`: older security pre-release; it predates later CI, dependency, and build corrections.
- `v1.2.3-rc2` / `a4600eb5`: verified installable pre-release, retained immutably; its build was repeatable on GitHub but exposed unpinned Composer/timezone/file-mode variance across hosts.
- `v1.2.3-rc3` / `2399eb2`: current installable 1.2.3 pre-release with Composer 2.10.3, UTC, and normalized staged permissions.
- `v1.3.0-rc1` / `93409a6`: separate identity/migration pre-release.
- RC2 source PR: `release/v1.2.3-rc2-prep`, merged by PR #2 at `45f9d516`.
- 1.2.4 structured logging is under development on `feature/structured-logging` and is not yet a GitHub release or production deployment.
- 1.3 development branch: `security/v1.2.3-v1.3.0`.

RC3 and its publication workflow were green, and its published ZIP passed checksum, integrity, and top-level checks. Re-verify `main`, the publication workflow, refs, and Release assets before relying on this snapshot.
