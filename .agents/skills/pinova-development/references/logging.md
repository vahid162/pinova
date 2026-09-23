# Structured logging contract

Read this reference before adding, changing, reviewing, testing, or operating Pinova logs.

## Purpose and scope

Pinova uses permanent logging infrastructure for temporary operational records. It follows PSR-3 levels and interfaces, OWASP privacy guidance, and WordPress/WooCommerce operational conventions. Logs support diagnosis and correlation; they are not a source of truth for identity, billing, security decisions, or migration state.

The default handler writes to `{$wpdb->prefix}pinova_logs`. If that table is unavailable or an insert fails, it sends the already-sanitized record to WooCommerce's logger with source `pinova`, then to PHP `error_log` as a last resort. A logging failure must not interrupt the request that caused it.

## Record schema

Every record uses schema `pinova.event.v1` and contains:

- UTC `created_at` assigned by the database handler;
- a valid PSR-3 `level`;
- a stable, machine-readable `event` code up to 100 characters;
- one request/process `correlation_id`;
- optional numeric `user_id` in its own column;
- JSON `context` produced exclusively by `SafeContext`.

Pinova REST responses expose the same value in `X-Pinova-Correlation-ID`. A scoped `rest_post_dispatch` filter also covers WordPress validation, permission, authentication, and missing-route failures under `/pinova/`, preserves an existing response header, and leaves unrelated namespaces untouched. The shared JavaScript client displays only a bounded, safe reference received in that header on failures, including unreadable response bodies. A network failure without a server response must never fabricate a reference. This is request-level correlation, not a multi-request flow identity. Never put identifiers or secrets in response headers.

## Levels and noise control

- Default minimum: `warning`.
- Configurable persistent minimum: `info`, `notice`, `warning`, or `error`.
- `debug` is available only through a diagnostic window that expires after 15 minutes, one hour, or 24 hours.
- Never enable permanent debug logging through a hidden option or filter.
- Events controlled by an attacker must be bounded. Log rate-limit transition only when `hits === limit + 1`; do not log every rejected request. Do not add per-request firewall-denial logs without persistent deduplication.

Retention defaults to 14 days, is clamped to 1–90 days, and is deleted in bounded batches by `pinova_logging_cleanup`. Manual deletion requires `manage_options` plus the `pinova_clear_logs` nonce and writes a fresh `logging.cleared` audit event after deletion. That bounded administrator action uses `Logger::audit()` so it is not suppressed when the configured minimum is `error`; do not use this threshold bypass for request-driven events.

## Privacy and context allowlist

Context is deny-by-default. `SafeContext` accepts only documented numeric IDs/counts, short code-like values, bounded code lists, approved keyed fingerprints, and exception class/code. It discards unknown keys.

Never persist any of the following, even at debug level:

- raw email, mobile, username, identifier, IP address, or proxy-header chain;
- OTP/code, password, JWT, access token, API key, reset key, nonce, cookie, or session value;
- request/response body, message content, billing/shipping profile, or arbitrary user metadata;
- exception message, file path, stack trace, database error text, or provider response.

When correlation across events is required, call `Logger::fingerprint( $normalized_value, $purpose )`. It uses a keyed HMAC derived from the WordPress auth salt and stores only 32 hexadecimal characters. Email values and the purpose are trimmed and lowercased centrally before HMAC calculation so case variants have one erasable fingerprint; callers must still normalize mobile, IP, username, and other purpose-specific values before calling it. Use a purpose such as `email`, `mobile`, `ip`, or `rate_limit_subject` so identical raw values are not linkable across unrelated domains. A fingerprint is still operational data and follows the same retention policy. Privacy erasure may reproduce the old case-sensitive HMAC for known historical variants. Because arbitrary old email casing and HMACs created before an auth-salt rotation are not recoverable, an approved erasure also removes fingerprints from unowned legacy rows explicitly classified with the erased identifier type, while preserving their event facts and every row owned by another User ID. New logging code must never call the legacy helper.

Adding a context key requires all of:

1. a concrete diagnostic need that cannot be met by an existing key;
2. classification as a bounded integer, code, list, or keyed fingerprint;
3. an explicit addition to `SafeContext`;
4. a unit test proving secrets and arbitrary adjacent values are removed;
5. an update to this reference and public privacy documentation when user-visible.

## Event catalogue

| Event | Normal level | Meaning |
| --- | --- | --- |
| `admin.sms_test_succeeded` | notice | Authorized SMS test completed |
| `admin.sms_test_failed` | error | Authorized SMS test failed |
| `auth.request_failed` | error | Unexpected authentication request failure |
| `auth.password_failed` | notice | Native pipeline rejected password or role policy |
| `auth.password_succeeded` | info | Password login completed |
| `auth.logout_rejected` | notice | A logout nonce was invalid; session preserved and observation throttled |
| `auth.password_reset_failed` | notice | Password-reset token, account/policy, confirmation, key generation, key validation, or reset operation failed; observation throttled |
| `auth.password_reset_succeeded` | info | Password reset completed; observation throttled |
| `auth.redirect_failed` | warning | A validated authentication/logout redirect was rejected or headers were already committed before a Location response could be emitted |
| `auth.session_created` | info | Pinova created an authenticated session |
| `auth.session_destroyed` | info | Pinova logout completed |
| `identity.resolved` | debug | Identifier resolution result during a diagnostic window |
| `identity.mobile_conflict` | warning | One mobile matched multiple legacy User IDs; resolution failed closed |
| `logging.cleared` | warning | An administrator manually cleared existing records |
| `otp.created` | info | OTP record was created and at least one channel succeeded |
| `otp.delivery_failed` | error | No usable OTP delivery completed |
| `otp.channel_send_failed` | warning | One configured channel threw or failed |
| `otp.verify_failed` | notice/warning | Verification failed because the token, record, code, purpose, state, block, or IP was invalid; IP mismatch is warning |
| `otp.verified` | info | OTP was verified |
| `security.rate_limited` | warning | A subject entered the limited state for one window |
| `security.block_added` | notice | An administrator added or updated a block |
| `security.block_removed` | notice | An administrator removed a non-system block |
| `security.native_login_armed` | notice | A successful private-route administrator login created a short-lived activation arm |
| `security.native_login_gate_enabled` | warning | An authorized settings save consumed an arm and activated canonical-login blocking |
| `security.native_login_gate_disabled` | warning | An authorized settings save, slug change, or invalidation disabled canonical-login blocking |
| `user.export_generated` | notice | Excel or VCF content was generated for download |
| `user.export_failed` | error | Export generation failed |
| `user.registered` | notice | Pinova created a WordPress user |

Event names are API-like identifiers, not translated prose. Renaming an event is a compatibility change for monitoring consumers. Add a new event rather than inserting dynamic data into the event name.

The logout-rejection and password-reset events use `EventThrottle`, not the administrator-only threshold bypass. The existing rate-limit table admits at most one observation per HMAC-derived source slot and 100 observations site-wide per event in a 15-minute window. Each event has 1,024 fixed source slots plus one site bucket, so missing cleanup cannot grow throttle storage without bound. Slot collisions conservatively suppress observations; they never affect authentication. Conditional database writes determine admission atomically. A missing/failing throttle backend suppresses these observations without blocking the user operation. These sampled events are not complete request counters. Their contexts contain only fixed reasons/statuses, optional trusted User ID, and keyed IP fingerprint, never a nonce, reset key, password, token, or return URL.

Disabled event levels skip throttle database writes entirely. Failure to read the logging configuration suppresses the observation without affecting authentication.

After `OTPService::verify()` loads a trusted OTP record, verification success and known-record failure events include `otp_type`, `identifier_type`, and the same purpose-separated identifier HMAC used at creation. Invalid-token and missing-record events must not fabricate those fields. Never fingerprint the OTP code itself. Adding metadata must not move purpose checks after verification, attempt mutation, or user/session creation.

The `security.block_added` and `security.block_removed` contexts may contain the selected identifier type, keyed identifier fingerprint, block status, authorized administrator User ID, and block resource ID. They must never contain the raw mobile, email, username, or IP. Do not emit a new persistent event for every attacker-controlled blocked login attempt; the administrator mutation events provide the bounded audit trail without log amplification.

The two `admin.sms_test_*` events are bounded, capability-protected audit actions. They bypass the configured minimum level so a deliberate test always leaves a privacy-safe result, including when the site stores only `error` events. This exception must not be generalized to request-driven authentication, OTP, or channel events.

Native-login arming and gate-state events are bounded control-plane records. Their context may contain the authorized administrator User ID plus short `status`, `reason`, or `operation` codes. Never include the private slug, route URL, slug HMAC, transient arm contents, authentication material, or a reversible derivative of the route. Do not emit an event for canonical-login probes; only a successful eligible private login and an actual activation/invalidation transition are catalogued.

`auth.redirect_failed` is emitted when WordPress rejects a validated safe redirect or when the header state is already committed before/while dispatch reports success. Its context is limited to the bounded operation, reason and header-state codes plus the intended HTTP fallback status. Never include the destination/return URL, query string, nonce, cookie, identifier, token, source file/line, exception message, or trace.

## Operational workflow

1. Ask the reporter for the approximate time, operation, and `X-Pinova-Correlation-ID`; do not ask them to send OTPs, passwords, tokens, or full cookies.
2. Review **Pinova → Logs** with the smallest necessary level filter. Access requires `manage_options`. The viewer reports whether its table exists and shows the effective minimum level. An empty table with minimum `error` may be expected because lower-severity events were never persisted; it is not by itself evidence of a rendering failure.
   Render pagination only when `paginate_links()` returns a string; a single result page returns no markup and must never be passed as `null` into WordPress escaping functions.
3. Enable a short diagnostic window only when default warning/notice information is insufficient. Reproduce once, then turn it off; automatic expiry is a safety backstop.
4. Correlate by event, correlation ID, User ID, and keyed fingerprint. Treat an unmatched fingerprint as inconclusive because normalization/purpose may differ.
5. Clear logs only when needed for privacy or a clean test boundary. Retention normally handles deletion.
6. If the dedicated table is unavailable, inspect WooCommerce logs for source `pinova`, then the server PHP error log. Those fallbacks contain the same sanitized structure.
7. Even when the Pinova table is healthy and contains no warnings or errors, inspect WordPress debug output, WooCommerce logs, PHP-FPM/PHP logs, and the web-server error stream for lifecycle, deprecation, and compatibility diagnostics near the same request. These global streams are complementary evidence, not Pinova logger fallbacks. Do not persist every `doing_it_wrong_run` event in `pinova_logs`: the hook is global, attribution to Pinova is not reliable, and repeated public requests could create log amplification. Fix the owning call site and add a focused regression instead.

Do not query or alter the production table during development. Production inspection or deletion needs explicit authority for that environment.

## Required tests for logging changes

- unit: valid PSR-3 levels, minimum threshold, debug expiry, event sanitization, fingerprint stability/purpose separation, and deny-by-default context;
- integration: table creation/upgrade, persistence without raw PII/secrets, exception-message removal, retention boundary, correlation header, identity-conflict redaction, and rate-limit amplification protection;
- UI/authorization: log viewer capability, output escaping, level filtering/pagination, clear capability and nonce;
- compatibility: WordPress 6.8/latest and WooCommerce/HPOS pairs in the repository matrix;
- packaging: production ZIP includes `psr/log` and runtime logger classes but excludes tests and agent documentation.
