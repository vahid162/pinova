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

It evaluates the shipped browser scripts in isolated VMs with mocked REST responses. Keep `global.js` coverage for successful envelopes, WordPress field-validation errors, Pinova 401/403/429/503 envelopes, `Retry-After`, correlation metadata, nonce-header isolation, malformed responses, network failures, caller cancellation, and the timeout that remains bounded when a caller supplies an abort signal. Keep Blocked List coverage for permanent-modal reset, typed add payloads, stable filter parsing, exact pagination, current-page refresh after add/delete, and bounded page navigation. Checkout coverage must prove that generic checkout errors keep Pinova closed and focus an invalid billing-phone field, while explicit login and scoped account-conflict actions open the modal with the exact clicked identifier. Modal structure coverage must prove it renders from `wp_footer`, outside the checkout form and replaceable fragments. Modal-controller coverage must prove dialog focus/inert cleanup, request locking, safe inline errors, configured-length localized OTP normalization, exactly one autosubmit, and safe dismissal during a pending request with cancellation and late-response suppression. Template coverage must keep standalone, checkout-modal, and Woodmart/Flatsome partial recovery resend controls explicitly in the recovery workflow. CI runs this suite in its own JavaScript job; it does not require starting wp-env.

Run the real standalone-account browser acceptance only in a task-specific isolated wp-env after installing the pinned Playwright browser:

```bash
npm run env:configure
npm run env:start -- --update
npx playwright install --with-deps chromium
npm run test:browser
npm run env:stop
```

The `Account UI / Stage 1 Chromium` CI job owns this lifecycle for pull requests and release branches, including a unique Compose project/home, the web- and development-CLI-container `pdo_mysql` prerequisite, failure diagnostics, and unconditional environment teardown. Keep the Playwright base URL on `localhost`, matching wp-env's generated WordPress URLs, so module scripts and fonts stay same-origin. The pinned wp-env 10.35.0 has no `destroy --force` option; use the run-guarded `bash tools/cleanup-browser-env.sh` in the disposable GitHub browser job. Do not start it on the shared production host.

### Modal test reliability and sensitivity

Wait for the actual step heading to own focus after opening and each state transition. Do not replace that contract with a fixed sleep, extra retries, test-side heading focus, or a disabled product assertion. Avoid scheduling a duplicate transition for the already settled initial step. Animation-frame waits are only for layout. Both theme stylesheet orders retain every geometry, focus-trap, Escape, close-click, and focus-restoration assertion.

After the normal browser suite, run `node tools/check-modal-test-sensitivity.mjs` against the same disposable loopback WordPress site. It runs three healthy repetitions without retries, then five independent response-only mutations: missing heading focus, broken reverse trap, broken forward trap, relative close position, and relative back position. Each mutated response must reach the exact intended failing product assertion in the same corner-control test. Runner/setup errors, missing tests, unrelated failures, whole-test timeouts, skips, retries, and surviving mutations are rejected. A final healthy run confirms isolation. No plugin file or server is changed by these mutations. This is targeted sensitivity evidence, not proof that every possible plugin defect is covered.

The browser job retains normal acceptance reports and separate JSON reports under `test-results/modal-sensitivity`, including successful healthy controls and expected mutant assertion failures. The ordinary suite still fails normally for broken unmodified code; the sensitivity wrapper accepts a mutant failure only after its identity and assertion are verified. Unit tests exercise the report verifier with malformed, skipped, retried, unrelated, and infrastructure-failure reports.

### Non-interactive browser CI cleanup

wp-env 10.35.0's `destroy` always calls an interactive confirmation; `--force` was added in 10.39.0. See upstream `packages/env/lib/commands/destroy.js` and `lib/cli.js` at Gutenberg commit `17abf988aef8045a2783612660b1f5bf650dba87`. Do not upgrade development dependencies merely to silence this prompt.

`tools/cleanup-browser-env.sh` requires GitHub CI plus an exact numeric run/attempt identity, the matching `pinova-browser-<run>-<attempt>` Compose project, and its exact `/tmp` work directory. It rejects symlinks and ambiguous configurations before using scoped `docker compose down --volumes --remove-orphans`; it neither prunes global resources nor removes shared images. It checks every Docker query's exit status and requires zero containers, volumes, and networks with that project's label before removing its own work directory. Missing configuration is not success while resources remain. Cleanup runs with `always()` and a bounded step timeout, without `continue-on-error`; the log and browser evidence are uploaded afterward even on failure. The cleanup unit tests use a fake Docker executable in isolated temporary directories and never act on an actual Docker daemon.

Run integration tests only in an isolated WordPress environment:

```bash
npm run env:configure
npm run env:start -- --update
PINOVA_TEST_HPOS=no npm run test:integration
PINOVA_TEST_HPOS=yes npm run test:integration
npm run env:stop
```

Set `WP_VERSION` and `WC_VERSION` before configuration for compatibility pairs. `tools/run-integration.sh` derives the mounted plugin directory from the checkout name and forwards `PINOVA_TEST_HPOS=yes|no` into `tests-cli`; the bootstrap writes that option into the PHPUnit database before Pinova loads. Do not use the ordinary `cli` container to configure HPOS for integration tests because it addresses the separate development database. The test bootstrap loads the mounted WooCommerce plugin before Pinova; a missing WooCommerce class is a test failure, not an acceptable skip. Ensure `pdo_mysql` is installed in `tests-cli` because Pinova's Illuminate connection uses PDO. The wp-env image's in-container sudo does not retain `PHP_INI_DIR`, so pass `PHP_INI_DIR=/usr/local/etc/php` explicitly to `docker-php-ext-install`. Authentication and legacy-identity changes must cover native hooks, role policy, uniform public responses, explicit mobile override of every older alias, deterministic conflicts, invalid mobile objects, no core-table mutation, purpose-scoped active-OTP reuse, and bidirectional rejection between login/registration OTPs and password-recovery OTPs. A purpose mismatch must leave the OTP unverified and must not create a user, session, or reset key. WooCommerce changes require HPOS off and on. Checkout lifecycle coverage must prove `woocommerce_checkout_process` does not make Pinova parse the complete payload and that `woocommerce_after_checkout_validation` uses WooCommerce's sanitized data and error container.

Blocked List integration coverage must exercise `manage_options`, all four identifier types, normalization/type mismatch, permanent and expiring rows, duplicate updates, immutable system rows, stable filter and pagination responses, privacy-safe add/remove logs, denial before OTP issuance, denial after OTP issuance but before verification, password/native-hook denial, and non-enumerating public responses. Run the full integration suite with HPOS both off and on after these changes.

Native-login gate coverage must prove that the public account template contains no canonical or private administrator link, the private route submits and redirects back to itself, accepts only native-only roles, preserves upstream `WP_Error` password-reset denials, and keeps native WordPress/Wordfence/2FA hooks. A computed fallback slug must not arm the gate. Test a successful private login by a `manage_options` native user, the 30-minute expiry, User ID/slug-HMAC/version mismatch, single-use consumption, durable activation, disabling, option or effective-constant slug changes, durable runtime invalidation, and failed re-enablement without a fresh login. Runtime blocking must require the setting plus the matching activation record, while `PINOVA_BLOCK_NATIVE_LOGIN=false` remains an unconditional emergency off switch. Account actions on direct `wp-login.php` must remain unchanged while the gate is off and return 404 while it is on. Exact core `postpass`, `logout`, `confirmaction`, `confirm_admin_email`, and `exit_recovery_mode` actions must continue to support password-protected posts, nonce-protected logout, keyed privacy confirmation, administrator-email confirmation, and nonce-protected Recovery Mode exit without permitting case/whitespace variants to fall through to login. Build the administrator-email confirmation test in core order—call `wp_login_url()` before adding its action—and prove a logged-out canonical request cannot learn the private slug. Public core login/register/lost-password helpers must resolve from `home_url()` and round-trip raw return targets after one query decode. Forced reauthentication must retain `reauth=1`, clear a public-role session before Pinova renders, and use the private core route for native-only roles. Privacy emails must resolve to the canonical confirmation handler; native administrator reset/recovery emails must resolve to the home-based private route even when the WordPress core URL is in a subdirectory; reserved/weak slugs must fail safely. Arming and gate-state logs must contain bounded IDs/codes only; do not expose the slug, URL, arm record, or slug HMAC, and do not add one log row per canonical-path probe.

Standalone-account browser coverage must assert computed Yekan Bakh typography, paragraph spacing, at least 3:1 control-boundary contrast, a single visible focus treatment, native required semantics, live field-error announcements, first-invalid focus, no input focus on initial render or step transitions, inert non-loader content while busy, and one-request-only behavior across submit, resend, and alternate actions. It must also verify geometric logo centering and its separate desktop/mobile sizes, the top-header/centered-mobile-content composition, non-duplicated identifier/password instructions, LTR password fields with right-side visibility toggles, and the equal-width normal-weight underlined password alternatives with their pipe separator. Preserve one real OTP input and prove Persian/Arabic digit normalization, paste, WebOTP/autofill compatibility, and exactly one exact-length autosubmit for 4-, 5-, and 6-digit codes. Apply the same visual, responsive, focus, busy-state, and OTP assertions to the checkout modal, plus Escape/overlay closing, focus restoration, background inertness, scroll locking, cleanup on reopen, and an operable close control during a delayed request that restores checkout immediately without accepting a late response. Run layout checks at 320, 360, 390, 412, 420, and 421 CSS pixels plus landscape, reduced-height, enlarged-text, and desktop cases; require safe-area support and no horizontal overflow. Source-level Node tests are necessary regressions but do not replace physical Chrome Android and iOS Safari acceptance for keyboard, autofill, assistive-technology announcements, and notched safe areas.

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

- `main`: Pinova 1.2.x security line, reproducible release, structured logging, the 1.2.5 database-bootstrap hotfix, the 1.2.6 account/Blocked List and WooCommerce checkout-lifecycle corrections, the RC4 account-brand/native-login and redirect round-trip hardening, the RC5 OTP hierarchy follow-up, the RC6 single-card account-interface correction, the RC7 account-accessibility/native-login activation correction, the RC8 final login layout/copy correction, the post-RC8 checkout-modal/trigger correction, and the unreleased OTP-purpose, recovery-resend, and busy-modal-dismissal correction.
- `v1.2.3-rc1` / `ce9dc0d`: older security pre-release; it predates later CI, dependency, and build corrections.
- `v1.2.3-rc2` / `a4600eb5`: verified installable pre-release, retained immutably; its build was repeatable on GitHub but exposed unpinned Composer/timezone/file-mode variance across hosts.
- `v1.2.3-rc3` / `2399eb2`: current installable 1.2.3 pre-release with Composer 2.10.3, UTC, and normalized staged permissions.
- `v1.3.0-rc1` / `93409a6`: separate identity/migration pre-release.
- RC2 source PR: `release/v1.2.3-rc2-prep`, merged by PR #2 at `45f9d516`.
- `v1.2.4-rc1` / `5789178`: structured-logging pre-release; superseded because its reconstructed Composer config classmapped but did not eagerly load the database initializer.
- `v1.2.5-rc1`: current hotfix pre-release; restores fresh-request initialization and makes administrator SMS-test audit results visible regardless of the logging threshold.
- `v1.2.6-rc1` / `270ace9`: published and checksum-verified pre-release for the REST error contract, type-authoritative Blocked List enforcement, and accessible responsive account experience. It predates repository-level native Release Immutability (`immutable: false`) and is retained as publication history, not the installation handoff.
- `v1.2.6-rc2` / `714122f`: native-immutable pre-release retained unchanged. Its publication gates passed, but a later read-only production audit found repeated nonfatal WooCommerce lifecycle warnings in its checkout-validation path. It is superseded for new installations; publish a new immutable RC from the reviewed fix instead of moving or editing this tag.
- `v1.2.6-rc3` / `de1a269`: native-immutable checkout-lifecycle correction retained unchanged and superseded by RC4 for new installations.
- `v1.2.6-rc4` / `0b89485`: native-immutable account-brand/native-login correction retained unchanged and superseded by RC5 for new installations.
- `v1.2.6-rc5` / `b65370b`: native-immutable 1.2.6 pre-release retained unchanged. PR #17, merged `main`, and `publish/v1.2.6-rc5` each passed all 15 Quality jobs in runs `35084748629`, `35085373875`, and `35085672774`. Publisher run `35085930703` verified reproducibility, Release and asset attestations, and published-asset redownload. Annotated tag object `cd1b0e1` targets `b65370b`; Release `389832419` is a non-draft pre-release with `immutable: true`. An independent redownload verified installable ZIP SHA-256 `1998a8c4c783f2624f16a650fcd3952a987e02ff9106fb585c7a9a96db3bfd36`. Later visual acceptance rejected its two-pane account presentation; preserve the Release and tag as history and use a new reviewed immutable RC for the single-card correction before another production installation. Publication never authorized native-login gate activation.
- `v1.2.6-rc6` / `c327c715`: native-immutable 1.2.6 baseline retained unchanged. PR #19, merged `main`, and `publish/v1.2.6-rc6` each passed all 15 Quality jobs in runs `35101731382`, `35102214094`, and `35102571654`. Publisher run `35102898151` verified reproducibility, Release and asset attestations, and published-asset redownload. Annotated tag object `2446301` targets `c327c715`; Release `389958951` is a non-draft pre-release with `immutable: true`. An independent redownload verified installable ZIP SHA-256 `612a0ef051ea46e9a05b3f352c867b6e4704bc56bdc380c04df29ebdd7886d43`. Later read-only account acceptance found typography, spacing, focus, validation, request-locking, and safe-area defects, and the opt-in native-login gate lacked a technical activation arm. Preserve RC6 as deployed history and publish a new reviewed immutable RC before any new installation; neither RC6 nor its publication authorizes gate activation.
- `v1.2.6-rc7` / `539f49c`: corrected native-immutable 1.2.6 candidate retained unchanged. Exact-head push and PR Quality runs `35196513209` and `35196516611`, merged `main` run `35196879813`, and `publish/v1.2.6-rc7` run `35197407509` each passed all 16 jobs. Publisher run `35197680055` verified two-build reproducibility, Release and asset attestations, and its own published-asset redownload. Annotated tag object `b43fb25` targets `539f49c`; Release `390529306` is a non-draft pre-release with `immutable: true`. Two independent redownloads were byte-identical and verified installable ZIP SHA-256 `564e13f41d1e071be1975011633546aceb54e417a6adedea0e66c9803250dba3`. Later UI acceptance found logo-centering, duplicate-copy, mobile-positioning, password-direction, and alternate-link presentation defects; preserve RC7 as history and use RC8 for subsequent acceptance.
- `v1.2.6-rc8` / `9d7f70b`: newest published native-immutable 1.2.6 candidate retained unchanged. PR #23 exact-head `pull_request` Quality run `35206496567`, merged `main` `push` run `35206965454`, and `publish/v1.2.6-rc8` `push` run `35207765834` each passed all 16 jobs. `workflow_run` publisher run `35208028725` verified two-build reproducibility, Release and asset attestations, and its own published-asset redownload. Annotated tag object `1c77883` targets `9d7f70b`; Release `390600592` is a non-draft pre-release with `immutable: true`. Two independent redownloads were byte-identical and verified installable ZIP SHA-256 `343d92e7e6919a045ba530cbe73910271bcc0dd7596a7d6a05f7ef6bf94c698d`. According to the operator report, RC8 is now the active production baseline; it does not contain the later checkout-modal correction, and neither its installation nor publication authorizes native-login gate activation.
- CI enforces synchronized release history between `CHANGELOG.md` and the WordPress.org `readme.txt` Changelog section.
- 1.3 development branch: `security/v1.2.3-v1.3.0`.

Never move or overwrite a published tag. Native immutability is proven only when the Release API reports `immutable: true`; older workflow-level no-overwrite behavior is not equivalent. Re-verify `main`, the publication workflow, refs, Release assets, and attestations before relying on this snapshot; a published RC is not authorization to install it on production.
