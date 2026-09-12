# Structured logging contract

Read this reference before adding, changing, reviewing, testing, or operating Pinova logs.

## Purpose and scope

Pinova 1.2.4 adds permanent logging infrastructure for temporary operational records. It follows PSR-3 levels and interfaces, OWASP privacy guidance, and WordPress/WooCommerce operational conventions. Logs support diagnosis and correlation; they are not a source of truth for identity, billing, security decisions, or migration state.

The default handler writes to `{$wpdb->prefix}pinova_logs`. If that table is unavailable or an insert fails, it sends the already-sanitized record to WooCommerce's logger with source `pinova`, then to PHP `error_log` as a last resort. A logging failure must not interrupt the request that caused it.

## Record schema

Every record uses schema `pinova.event.v1` and contains:

- UTC `created_at` assigned by the database handler;
- a valid PSR-3 `level`;
- a stable, machine-readable `event` code up to 100 characters;
- one request/process `correlation_id`;
- optional numeric `user_id` in its own column;
- JSON `context` produced exclusively by `SafeContext`.

REST responses created through `RestAPI::response()` expose the same value in `X-Pinova-Correlation-ID`. Never put identifiers or secrets in response headers.

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

When correlation across events is required, call `Logger::fingerprint( $normalized_value, $purpose )`. It uses a keyed HMAC derived from the WordPress auth salt and stores only 32 hexadecimal characters. Use a purpose such as `email`, `mobile`, `ip`, or `rate_limit_subject` so identical raw values are not linkable across unrelated domains. A fingerprint is still operational data and follows the same retention policy.

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
| `auth.session_created` | info | Pinova created an authenticated session |
| `auth.session_destroyed` | info | Pinova logout completed |
| `identity.resolved` | debug | Identifier resolution result during a diagnostic window |
| `identity.mobile_conflict` | warning | One mobile matched multiple legacy User IDs; resolution failed closed |
| `logging.cleared` | warning | An administrator manually cleared existing records |
| `otp.created` | info | OTP record was created and at least one channel succeeded |
| `otp.delivery_failed` | error | No usable OTP delivery completed |
| `otp.channel_send_failed` | warning | One configured channel threw or failed |
| `otp.verify_failed` | notice/warning | Verification failed; IP mismatch is warning |
| `otp.verified` | info | OTP was verified |
| `security.rate_limited` | warning | A subject entered the limited state for one window |
| `security.block_added` | notice | An administrator added or updated a block |
| `security.block_removed` | notice | An administrator removed a non-system block |
| `user.export_generated` | notice | Excel or VCF content was generated for download |
| `user.export_failed` | error | Export generation failed |
| `user.registered` | notice | Pinova created a WordPress user |

Event names are API-like identifiers, not translated prose. Renaming an event is a compatibility change for monitoring consumers. Add a new event rather than inserting dynamic data into the event name.

## Operational workflow

1. Ask the reporter for the approximate time, operation, and `X-Pinova-Correlation-ID`; do not ask them to send OTPs, passwords, tokens, or full cookies.
2. Review **Pinova → Logs** with the smallest necessary level filter. Access requires `manage_options`.
3. Enable a short diagnostic window only when default warning/notice information is insufficient. Reproduce once, then turn it off; automatic expiry is a safety backstop.
4. Correlate by event, correlation ID, User ID, and keyed fingerprint. Treat an unmatched fingerprint as inconclusive because normalization/purpose may differ.
5. Clear logs only when needed for privacy or a clean test boundary. Retention normally handles deletion.
6. If the dedicated table is unavailable, inspect WooCommerce logs for source `pinova`, then the server PHP error log. Those fallbacks contain the same sanitized structure.

Do not query or alter the production table during development. Production inspection or deletion needs explicit authority for that environment.

## Required tests for logging changes

- unit: valid PSR-3 levels, minimum threshold, debug expiry, event sanitization, fingerprint stability/purpose separation, and deny-by-default context;
- integration: table creation/upgrade, persistence without raw PII/secrets, exception-message removal, retention boundary, correlation header, identity-conflict redaction, and rate-limit amplification protection;
- UI/authorization: log viewer capability, output escaping, level filtering/pagination, clear capability and nonce;
- compatibility: WordPress 6.8/latest and WooCommerce/HPOS pairs in the repository matrix;
- packaging: production ZIP includes `psr/log` and runtime logger classes but excludes tests and agent documentation.
