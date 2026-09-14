# Account and Blocked List UI — Phase 1 Baseline

Date: 2026-09-13 (Asia/Tehran)

## Authority and boundaries

- This phase establishes the 1.2.5 baseline and acceptance criteria only.
- Development happens in the isolated `fix/1.2.5-account-block-ui` worktree.
- Production plugin files, WordPress options, database rows, caches, services, the active canary, GitHub releases, and published tags are out of scope.
- The separate 1.3 identity/migration line is out of scope.

## Exact baseline

- Development base: `main` commit `c54f442dff0b3edc3476a6587c883f16e59fc486`.
- Affected runtime tag: `v1.2.5-rc1`, commit `9b86398cfbae46072c4b26aba1588a3b40454efc`.
- `pinova.php` version: `1.2.5`.
- `readme.txt` stable tag: `1.2.5`.
- The changes between the affected tag and current `main` are documentation, changelog synchronization, CI, and packaging-quality gates. Login, account, Blocked List, REST, and other runtime files are unchanged, so the runtime baseline remains 1.2.5.
- The live `/my-account/` response was HTTP 200 on 2026-09-13 at 20:09:03 GMT, with `X-Robots-Tag: noindex, follow` and private/cache-bypass headers.
- Live desktop/mobile screenshots are pending because the Opera Browser Connector was not connected. The source/DOM baseline is complete, but visual screenshot evidence must be captured before visual implementation begins.

Baseline hashes in the development source:

| File | SHA-256 |
| --- | --- |
| `assets/js/global.js` | `d56ccdd313360c10de6d8b18fbb04422085e38be79ffb3a93927f886aa470b53` |
| `assets/js/pages/login-form.js` | `a4509e278e73774c58d1c6caf1141a5a6d00ed756bf52ded21014822a1df08a9` |
| `assets/js/pages/blocks.js` | `67551a08d9585cc64234bd36b96ae8e6b0d6537fd0f8e849673d4e08d4c73083` |
| `assets/css/style.css` | `3d92dbcaee7503c55ee01bce9c8adf0f30ab5a5bd22e5c09a16406934de92474` |
| `templates/login-form.php` | `f38c96d9fd5c1c0edb8ba3fd99007e5084144686c6606fc7b645b688dc98abf9` |
| `templates/admin/blocks.php` | `0564c04741cc76bb50c0f40afa810c4d5742fcf4ad66f351124693e06a40e03a` |
| `src/API/BlocksAPI.php` | `1e71f77b1c7536e3625f0de4fd657a01d63b5f1102820e9627be93b6404d6ce7` |
| `src/Services/ValidationService.php` | `48d78b146fb848889b2280046f51bef1aa9afabc0ac4009c175bd0d3f9990395` |

## Reproduced account-page defects

### P0 — HTTP errors lose their useful REST response

`pinovaApiRequest()` parses a non-2xx response, throws, replaces the server message with one generic notification, and prevents caller-side error handling. This hides blocked-identifier, invalid password/OTP, rate-limit, provider, and retry information.

### P1 — Semantics and keyboard accessibility

- The standalone document has `lang="fa"` but no document-level RTL direction.
- The audited initial DOM has no `main`, heading, or real `form` landmark.
- Seven step inputs lack programmatically associated labels.
- One button is unnamed; image elements lack explicit alternative-text treatment.
- Back controls and password-visibility controls use clickable `div` elements.
- Focus styling is weak or explicitly removed from some interactive elements.

### P1 — State and localization

- OTP cleaning accepts ASCII digits only and strips Persian/Arabic digits before the server can normalize them.
- The existing-password screen enforces an eight-character client minimum even though WordPress accepts valid legacy passwords that may be shorter.
- `startTime()` nulls the interval handle before trying to clear it.
- Back navigation does not consistently clear the timer, JWT, OTP, or sensitive password state.
- Browser history is not integrated with the multi-step flow.

### P1 — Navigation, fallback, and copy

- Terms/privacy links use `href="#"`.
- The current full-page flow does not normally transition to the legacy `signIn` step that contains that copy.
- Without working JavaScript, step content remains in templates and the page has no useful fallback.
- The initial copy and call to action do not clearly distinguish mobile, email, username, and the next action.
- The page is visually disconnected from the store and has no clear return/help/native-administrator path.

### P2 — Layout and feedback

- `.pinova-container body` does not match the body element that owns `pinova-container`, leaving the browser's default body margin in effect.
- Placeholder and password-strength colors do not all meet the intended contrast target.
- Loading uses an almost opaque full-page overlay without descriptive status or a bounded request timeout.
- Small icon controls do not consistently meet a comfortable touch-target size.

## Reproduced Blocked List defects

- The UI sends `blocked_type`, but the REST route does not declare it and `BlocksAPI::add()` does not use it; the type selector is therefore misleading.
- Reopening the add modal drops `blocked_until.always`, changing the intended permanent default into inconsistent state.
- Page navigation calls `getUsers()` instead of `getBlocks()`.
- Pagination uses `parseInt(total/per_page) + 1`, producing an extra page for zero or exact multiples.
- The blocked-by filter response shape does not match the JavaScript `.map()` consumer.
- The parsed REST `blocked_by` parameter is overwritten from raw `$_POST`, which is incompatible with the JSON request made by the UI.
- The only current integration assertion for this area proves that a directly inserted permanent row is enforced; it does not cover the administrator add/delete REST flow or its effect on authentication.
- The shared non-2xx handling hides specific add/delete/date/system-block errors from the administrator.

## Required behavior and acceptance criteria

### Account page

1. Preserve the current private/noindex/cache-bypass behavior and the security/non-enumeration policies of 1.2.5.
2. Use semantic `main`, headings, and submit-capable forms, with unique IDs and associated labels.
3. Set RTL at the document level and retain Persian copy.
4. Give every control a keyboard path, visible focus, accessible name, and an appropriate touch target.
5. Treat decorative images as `alt=""` and meaningful images/brand assets with useful text.
6. Remove all dead `href="#"` legal links; use configured real destinations or omit unavailable links.
7. Keep an always-visible, account-agnostic native administrator-login route without exposing account-specific role information.
8. Normalize Persian, Arabic, and ASCII digits for numeric input; accept OTP in all three forms.
9. Do not impose new-password rules on an existing WordPress password login.
10. Clear or replace timers deterministically; clear OTP/JWT/password state when the flow is abandoned; map browser Back to the previous logical step.
11. Return focus to the relevant heading/input after a transition and announce errors/status without duplicate notifications.
12. Use a bounded request timeout and local, descriptive loading state; prevent duplicate submissions.
13. Provide a usable no-JavaScript fallback.
14. Render without horizontal scrolling from 320 px through desktop widths and remain usable at 200% zoom.
15. Keep the standalone login shell lightweight while adding clear store-return, support, trust, and brand context.

### REST error contract

1. Normalize successful plugin envelopes and standard WordPress REST errors into a stable client result.
2. Preserve safe server messages for 400, 401, 403, 429, and 503 responses.
3. Expose HTTP status and `Retry-After` to the caller without breaking existing consumers.
4. Show the generic fallback only for network, timeout, malformed, or unsafe responses.
5. Smoke-test every use of the shared request helper, including the Pinova administration pages.

### Blocked List administration

1. Keep access restricted to `manage_options` and retain WordPress REST nonce enforcement.
2. Make the selected type authoritative and validate/normalize mobile, email, IP, and username independently.
3. Reject a type/value mismatch with a field-specific message and no database write.
4. Default consistently to a permanent block; require a future date only for a temporary block.
5. Update an existing identifier deterministically rather than creating an ambiguous duplicate.
6. Refresh the current paginated result after add/delete and calculate page counts with a ceiling operation.
7. Make blocked-by and date filters use the declared REST parameters and stable response shapes.
8. Keep system blocks non-removable through this UI and show the specific safe reason.
9. Preserve `blocked_until = NULL` as permanent and ensure expiry cleanup deletes only expired temporary rows.
10. Retain privacy-safe `security.block_added` and `security.block_removed` audit events without raw identifiers in logs.

### Block-to-login end-to-end contract

1. An active mobile, email, username, or IP block must stop the relevant public authentication request before OTP creation, channel delivery, user creation, or password authentication.
2. The account page must display the specific safe blocked-state message instead of the generic processing error.
3. A temporary block must stop applying after expiry; a permanent block must continue applying.
4. Adding/updating/removing a block must be covered through the real REST endpoints, not direct model insertion alone.
5. Tests must prove that the blocked path does not expose account existence or native-only role membership.

## Verification plan for later phases

- Add regression coverage before the behavioral fixes.
- Add focused JavaScript tests for response normalization, digit normalization, state transitions, timer cleanup, and Blocked List pagination/form state.
- Add WordPress integration tests for administrator permissions, all four block types, permanent/temporary/expired blocks, duplicate update, system-block removal, audit-log privacy, and no-OTP/no-user side effects.
- Run PHP lint, PHPUnit, PHPStan, PHPCS, Composer audit, changelog synchronization, Skill synchronization, HPOS off/on integration tests, and the configured WordPress/WooCommerce compatibility matrix.
- Perform keyboard, screen-reader semantics, 320 px/mobile/desktop, 200% zoom, no-JavaScript, and browser-history checks in a uniquely named disposable environment.
- Build twice with the pinned Composer toolchain and compare hashes only after implementation and documentation are complete.

## Phase 1 exit status

- Exact 1.2.5 runtime lineage: established.
- Isolated clean worktree: established.
- Runtime/UI/Blocked List defects: mapped to source behavior.
- Acceptance criteria: recorded.
- Live viewport screenshot evidence: pending browser connection; this is the only open Phase 1 evidence item.
- Production or release mutation: none.
