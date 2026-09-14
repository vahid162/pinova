# Account and Blocked List UI — Phase 2 REST Error Contract

Date: 2026-09-14 (Asia/Tehran)

## Scope

- Base: Pinova 1.2.5 runtime on `fix/1.2.5-account-block-ui`.
- Changed runtime file: `assets/js/global.js` only.
- Added a dependency-free JavaScript regression suite and CI job.
- Blocked List backend, account markup/styles/state machine, version metadata, package, release, production, and the active canary remain unchanged.

## Before evidence

The new nine-scenario test file was first executed against the unmodified 1.2.5 helper. Seven of the original eight scenarios failed: response metadata was absent; valid 400/403/429/503 JSON responses threw; the field-specific WordPress validation message was hidden; malformed-response rejection was not generic; and caller headers were mutated. The transport-failure scenario was the only initial pass. A ninth 401 OTP regression case was then added before final verification.

## Resulting contract

- Valid JSON success responses retain their existing fields and include `http.status`, optional `http.retryAfter`, calculated `http.retryAfterSeconds`, and optional `http.correlationId`.
- Valid non-2xx Pinova envelopes return to the existing caller-side `else` branch as `success: false` instead of throwing.
- Standard WordPress `rest_invalid_param` responses prefer the first safe field-specific message from `data.params`.
- A numeric or HTTP-date `Retry-After` value is normalized; 429 messages include an actionable seconds value when positive.
- Messages must be non-empty plain strings of at most 500 characters without angle-bracket markup; otherwise the generic safe message is used.
- Only transport or malformed-response failures reject and trigger the helper-level generic notification.
- Request headers are cloned. The internal `nonce: null` sentinel is removed and no longer reaches `fetch`; authenticated calls still receive `X-WP-Nonce`.

## Focused verification

- JavaScript regression scenarios: 9 passed, 0 failed under task-local Node.js 24.14.1.
- Scenarios: success/correlation metadata, plugin 403 blocked response, WordPress 400 field validation, Pinova 401 OTP error, 429 plus Retry-After, 503 delivery error, malformed non-JSON response, network failure, and nonce-header isolation.
- PHPUnit: 17 tests and 41 assertions passed under PHP 8.3.
- PHP syntax, PHPStan, PHPCS, changelog synchronization (13 releases), Skill synchronization, JavaScript syntax, JSON parsing, and `git diff --check` passed.
- Composer audit of the locked dependency graph found no security vulnerability advisories.
- No live authentication, OTP, SMS, Blocked List mutation, database write, cache operation, service change, deployment, tag, release, or production file change was performed.

## Deferred verification

- GitHub CI must repeat the JavaScript suite with Node.js 20 after a later, explicitly authorized push or pull request.
- Browser-level verification remains pending until an isolated wp-env/browser is available.
- Blocked List backend and account-page UI/state changes belong to later phases.
