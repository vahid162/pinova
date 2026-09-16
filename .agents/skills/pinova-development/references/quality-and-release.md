# Quality, packaging, and release

Read this reference for tests, dependencies, CI, ZIP builds, staging, tags, or releases.

## Execution location gate

Before installing dependencies or running quality tools, determine whether the checkout shares CPU, RAM, swap, I/O, or Docker with a production or multi-tenant workload. Worktree and container separation protect files and databases, not host capacity. Never run the full integration matrix, unrestricted PHPStan/Composer analysis, or reproducible release builds on a shared live host; use GitHub Actions or a dedicated resource-isolated development host. If host ownership is uncertain, perform only lightweight bounded source checks and stop until the boundary is verified. Do not compensate by stopping or pruning environments owned by other tasks.

## Quality suite

Install locked dependencies:

```bash
composer install --no-interaction --prefer-dist
npm install --ignore-scripts
```

Run PHP gates:

```bash
php tools/check-changelog-sync.php
composer lint
composer test
composer phpstan
composer phpcs
composer audit
```

Run the dependency-free browser-helper regression suite with Node.js 20 or newer:

```bash
npm run test:js
```

It evaluates the shipped browser scripts in isolated VMs with mocked REST responses. Keep `global.js` coverage for successful envelopes, WordPress field-validation errors, Pinova 401/403/429/503 envelopes, `Retry-After`, correlation metadata, nonce-header isolation, malformed responses, and network failures. Keep Blocked List coverage for permanent-modal reset, typed add payloads, stable filter parsing, exact pagination, current-page refresh after add/delete, and bounded page navigation. CI runs this suite in its own JavaScript job; it does not require starting wp-env.

Run integration tests only in an isolated WordPress environment:

```bash
npm run env:configure
npm run env:start -- --update
PINOVA_TEST_HPOS=no npm run test:integration
PINOVA_TEST_HPOS=yes npm run test:integration
npm run env:stop
```

Set `WP_VERSION` and `WC_VERSION` before configuration for compatibility pairs. `tools/run-integration.sh` derives the mounted plugin directory from the checkout name and forwards `PINOVA_TEST_HPOS=yes|no` into `tests-cli`; the bootstrap writes that option into the PHPUnit database before Pinova loads. Do not use the ordinary `cli` container to configure HPOS for integration tests because it addresses the separate development database. The test bootstrap loads the mounted WooCommerce plugin before Pinova; a missing WooCommerce class is a test failure, not an acceptable skip. Ensure `pdo_mysql` is installed in `tests-cli` because Pinova's Illuminate connection uses PDO. The wp-env image's in-container sudo does not retain `PHP_INI_DIR`, so pass `PHP_INI_DIR=/usr/local/etc/php` explicitly to `docker-php-ext-install`. Authentication and legacy-identity changes must cover native hooks, role policy, uniform public responses, explicit mobile override of every older alias, deterministic conflicts, invalid mobile objects, and no core-table mutation. WooCommerce changes require HPOS off and on. Checkout lifecycle coverage must prove `woocommerce_checkout_process` does not make Pinova parse the complete payload and that `woocommerce_after_checkout_validation` uses WooCommerce's sanitized data and error container.

Blocked List integration coverage must exercise `manage_options`, all four identifier types, normalization/type mismatch, permanent and expiring rows, duplicate updates, immutable system rows, stable filter and pagination responses, privacy-safe add/remove logs, denial before OTP issuance, denial after OTP issuance but before verification, password/native-hook denial, and non-enumerating public responses. Run the full integration suite with HPOS both off and on after these changes.

Native-login gate coverage must prove that the public account template contains no canonical or private administrator link, the private route submits and redirects back to itself, accepts only native-only roles, and keeps native WordPress/Wordfence/2FA hooks. Account actions on direct `wp-login.php` must remain unchanged while the gate is off and return 404 while it is on. Exact core `postpass`, `logout`, and `confirmaction` actions must continue to support password-protected posts, nonce-protected logout, and keyed privacy confirmation without permitting case/whitespace variants to fall through to login. Public core login/register/lost-password helpers must resolve to Pinova; privacy emails must resolve to the canonical confirmation handler; native administrator reset/recovery emails must resolve to the home-based private route even when the WordPress core URL is in a subdirectory; reserved/weak slugs must fail safely; and `PINOVA_BLOCK_NATIVE_LOGIN=false` must restore emergency canonical access. Do not add one log row per canonical-path probe.

An installable-ZIP browser lifecycle check also needs `pdo_mysql` in the disposable `wordpress` web container; installing it only in `tests-cli` is sufficient for PHPUnit but not for a real authenticated wp-admin request. Never modify a preserved environment to add it—prepare the task-specific environment and record that difference in the handoff.

## Reproducible installable ZIP

Build with Composer 2.10.3 using `composer build`. `tools/build.sh` rejects other Composer versions, exports `TZ=UTC`, stages `pinova/`, installs production dependencies, removes development-only files, normalizes directory/file modes to `0755`/`0644`, normalizes timestamps, sorts entries, and writes `.build/pinova-<version>.zip`. Pinning Composer, timezone, and file modes is required: Composer versions can format generated autoload files differently, while ZIP records DOS timestamps and Unix permissions from the build host.

The ZIP must exclude `.git`, `.github`, `.agents`, `AGENTS.md`, root `README.md` and `CHANGELOG.md`, caches, `node_modules`, tests, tools, and development Composer packages. The WordPress `readme.txt` remains in the package and carries the synchronized public changelog. Validate with:

```bash
unzip -tq .build/pinova-<version>.zip
unzip -Z1 .build/pinova-<version>.zip
sha256sum .build/pinova-<version>.zip
```

Build twice from the same tagged tree and require identical SHA-256 values.

The build must also confirm that `vendor/composer/autoload_files.php` eagerly requires `utils/class-database.php`. Classmap presence alone is insufficient for the normal fresh-request path. Integration tests must additionally cover the CLI/test ordering where Composer loads before WordPress; the explicit idempotent initialization in `pinova.php` must make that path succeed.

## Release sequence

1. Confirm clean tree, exact base, version metadata, locks, and guidance synchronization. Run `php tools/check-changelog-sync.php` and require root `CHANGELOG.md` to match the version order and every entry in the `readme.txt` Changelog section.
2. Pass local checks and GitHub Actions for the exact release commit.
3. Merge the reviewed PR. Reconfirm `main` and its `Quality` run before selecting the release commit.
4. After the repository owner confirms native Release Immutability is enabled, create `publish/vX.Y.Z-rcN` from the exact immutable commit. Its successful `Quality` run triggers `.github/workflows/publish-prerelease.yml`. The publisher uses only the job-scoped `GITHUB_TOKEN`; an Administration-scoped secret and a repository Ruleset are not prerequisites.
5. The publisher validates the branch and plugin versions and confirms the SHA is reachable from `main`. It builds twice under deliberately different timezone/umask inputs, compares hashes, creates a new annotated tag, explicitly creates a draft, uploads both assets, and publishes the draft. Notes attached before the post-publication check state that `immutable: true` and a successful workflow are required installation gates; they do not claim either gate has already passed. A rerun resumes a draft only when GitHub reports `github-actions[bot]` as its author and its name, target SHA, full publisher-marked notes, and asset-name allowlist match this exact release, then reuploads the reproducible assets; an unknown draft is never changed. Published tags and Releases are never moved, deleted, or overwritten. Because no administration credential is supplied to the workflow, native immutability is verified immediately after publication; an unexpected mutable Release is preserved as a hard failure and requires a new RC tag after the repository setting is corrected.
6. Require the published Release API to report `immutable: true`. The publisher redownloads both assets, compares them byte-for-byte with the build outputs, verifies the hash, ZIP integrity, top-level `pinova/`, Release attestation, and both asset attestations. Confirm the workflow and GitHub Release are green before reporting completion.
7. Stop before production unless installation was separately and explicitly authorized. On staging/production, first keep the native-login gate off and validate `/login`, username/email/mobile password paths, OTP, the private administrator route through a logged-out private window, native administrator password/2FA hooks, a password-protected post, logout, a disposable privacy request, WooCommerce, and logs. Only then enable the gate and revalidate a successful private-route login, a 404 from direct account actions on `/wp-login.php`, password-protected-post access, logout, and the privacy confirmation email, while retaining an authenticated session and the documented recovery override.
8. Mark stable/latest only after the agreed canary and explicit authorization.

A worktree-local ZIP is never the installation handoff for this project. A test version is ready for installation only when the GitHub branch, commits, pull request, required CI, merge commit, immutable Release, ZIP, and checksum are all present, and the published assets pass a fresh download verification. If authorization stops before GitHub publication, label the result as a local candidate rather than a completed version.

## Current lineage snapshot

- `main`: Pinova 1.2.x security line, reproducible release, structured logging, the 1.2.5 database-bootstrap hotfix, the 1.2.6 account/Blocked List corrections, and the WooCommerce checkout-lifecycle correction.
- `v1.2.3-rc1` / `ce9dc0d`: older security pre-release; it predates later CI, dependency, and build corrections.
- `v1.2.3-rc2` / `a4600eb5`: verified installable pre-release, retained immutably; its build was repeatable on GitHub but exposed unpinned Composer/timezone/file-mode variance across hosts.
- `v1.2.3-rc3` / `2399eb2`: current installable 1.2.3 pre-release with Composer 2.10.3, UTC, and normalized staged permissions.
- `v1.3.0-rc1` / `93409a6`: separate identity/migration pre-release.
- RC2 source PR: `release/v1.2.3-rc2-prep`, merged by PR #2 at `45f9d516`.
- `v1.2.4-rc1` / `5789178`: structured-logging pre-release; superseded because its reconstructed Composer config classmapped but did not eagerly load the database initializer.
- `v1.2.5-rc1`: current hotfix pre-release; restores fresh-request initialization and makes administrator SMS-test audit results visible regardless of the logging threshold.
- `v1.2.6-rc1` / `270ace9`: published and checksum-verified pre-release for the REST error contract, type-authoritative Blocked List enforcement, and accessible responsive account experience. It predates repository-level native Release Immutability (`immutable: false`) and is retained as publication history, not the installation handoff.
- `v1.2.6-rc2` / `714122f`: native-immutable pre-release retained unchanged. Its publication gates passed, but a later read-only production audit found repeated nonfatal WooCommerce lifecycle warnings in its checkout-validation path. It is superseded for new installations; publish a new immutable RC from the reviewed fix instead of moving or editing this tag.
- `v1.2.6-rc3` / `de1a269`: current native-immutable 1.2.6 installation handoff. PR #12 fixed the checkout lifecycle, Quality passed on the implementation branch, PR, merged `main`, and `publish/v1.2.6-rc3`; publisher run `34931316166` verified reproducibility and attestations. Annotated tag object `bb7dcbf` targets `de1a269`; Release `388879417` and an independent redownload verified installable ZIP SHA-256 `7fbdb046ced0ce09ea875eee39e81d363e78852fef81dea04175956c0f30850d`. It remains a pre-release and does not itself authorize production installation.
- CI enforces synchronized release history between `CHANGELOG.md` and the WordPress.org `readme.txt` Changelog section.
- 1.3 development branch: `security/v1.2.3-v1.3.0`.

Never move or overwrite a published tag. Native immutability is proven only when the Release API reports `immutable: true`; older workflow-level no-overwrite behavior is not equivalent. Re-verify `main`, the publication workflow, refs, Release assets, and attestations before relying on this snapshot; a published RC is not authorization to install it on production.
