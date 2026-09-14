# Account and Blocked List UI — Phase 4 Account Experience

Date: 2026-09-14 (Asia/Tehran)

## Scope and lineage

- Development branch: `fix/1.2.5-account-block-ui`.
- Development base: `main` commit `c54f442dff0b3edc3476a6587c883f16e59fc486`.
- Affected runtime release: immutable `v1.2.5-rc1`, commit `9b86398cfbae46072c4b26aba1588a3b40454efc`.
- The work is a local follow-up candidate whose prospective changelog section is 1.2.6; the existing release/tag was not rewritten.
- Production, the active 48-hour canary, GitHub branches/tags/releases, WordPress options, production data, and the separate 1.3 identity/migration line were not changed.

## Account-page implementation

- Replaced the presentation shell with a document-level Persian RTL layout, semantic `main`, headings, real forms, associated labels, submit buttons, and named status regions.
- Added a mobile-first account stylesheet with 320 px support, desktop two-panel layout, visible focus, high-contrast feedback, at least 44 px control targets, reduced-motion handling, and no horizontal overflow.
- Added a clear return-to-store action, an always-available native WordPress login route, configured privacy link, and a useful `noscript` fallback. Dead `href="#"` controls were removed.
- Decorative and meaningful images now have explicit alternative-text treatment. Loading feedback is localized and announced without replacing the whole page with an unexplained overlay.
- Existing WordPress passwords are no longer subjected to the new-password eight-character client rule.

## State, error, and privacy behavior

- Persian, Arabic, and ASCII digits are normalized before numeric validation and WebOTP assignment.
- Each OTP issue restarts one deterministic timer; leaving an OTP/reset route clears the timer, code, JWT, reset key, and password values.
- Browser Back follows the logical authentication history. Step changes reject unknown names and return focus to the relevant input.
- REST transport has a 30-second default timeout. Account requests suppress the helper's duplicate generic toast and instead announce one safe inline message.
- Valid safe 403 Blocked List responses remain visible in the account form. They do not expose account existence or native-only role membership, and the Phase 3 server-side block checks remain unchanged.

## Automated verification

- PHP syntax lint: passed.
- Composer lint: passed.
- Unit PHPUnit: 17 tests, 41 assertions, passed.
- PHPStan single-process debug mode: passed with no errors. The normal parallel launcher could not bind its local worker socket in the filesystem sandbox, so the equivalent non-parallel run was used.
- PHPCS: 10/10 configured files passed.
- JavaScript: all four files passed. Focused totals include 12 REST-helper scenarios, 6 account state-machine scenarios, and 5 template/CSS contract scenarios, in addition to the Phase 3 Blocked List suite.
- Full integration, HPOS off: 44 tests, 170 assertions, passed.
- Full integration, HPOS on: 44 tests, 170 assertions, passed.
- `git diff --check`: passed.
- Installable ZIP: `.build/pinova-1.2.5.zip`; two pinned Composer 2.10.3 offline builds produced the identical SHA-256 `e6f240ca7e620befdf235bbf4e7d022ef25f99a85f3a18e0422295300e003d73`. Archive integrity and inclusion of `assets/css/account.css`, `assets/js/pages/login-form.js`, and `templates/login-form.php` were verified.

## Real-browser smoke verification

A temporary Chromium installation inside the uniquely named Phase 4 wp-env loaded the real WordPress query login route and its plugin assets. The retained test environment uses WordPress 7.1, WooCommerce 11.1, PHP 8.1, and ports 8892/8893.

| View | Viewport | Document/body width | Result |
| --- | ---: | ---: | --- |
| Mobile | 320 × 1100 | 320 / 320 | No horizontal overflow; touch targets and initial focus passed |
| Desktop | 1366 × 900 | 1366 / 1366 | Two-panel layout, assets, labels, and keyboard path passed |
| 200% zoom equivalent | 683 × 450 | 668 / 668 | No horizontal overflow; content remained operable |

Across these runs, `dir="rtl"` was present, fonts completed loading, the identifier field received initial focus, only the active step was visible, all images had alt treatment, Tab reached the primary submit button, and no undersized interactive target or overflowing element was detected. Chromium with JavaScript disabled exposed the native-login fallback.

## Environment-specific observations and risk disposition

- The disposable Apache container did not have a generated `.htaccess`, so its pretty `/my-account/` route returned 404. The equivalent Pinova query route `/?pinova_login=1` returned 200 with the real template, no-cache/private headers, RTL markup, styles, labels, and fallback. This is an isolated wp-env routing limitation, not evidence of a plugin regression.
- The first integration attempt stopped before assertions because the fresh wp-env lacked its uploads directory. Creating that directory inside only the disposable Phase 4 containers allowed both complete matrices to pass.
- Composer reported no known advisories from its available cache, but the live Packagist registry check timed out; this is not represented as a fully fresh online audit.
- No package dependency changed in Phase 4. A new live npm audit lock could not be obtained because the registry stalled. The Phase 3 result therefore remains the applicable result: production dependencies have no reported vulnerability, while development-only `@wordpress/env` 10.35.0 carries two high-severity `extract-zip` advisories for which npm proposes a forced breaking change. The development tool is excluded from the plugin ZIP.

## Release disposition

- No commit, push, pull request, tag, release, deployment, production login, OTP delivery, Blocked List mutation, cache operation, service restart, or production database write was performed.
- The Phase 4 wp-env remains running and isolated for any explicitly authorized next phase.
