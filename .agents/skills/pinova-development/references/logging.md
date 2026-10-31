# Structured logging contract

Read this reference before adding, changing, reviewing, testing, or operating Pinova logs.

## Purpose and scope

Pinova uses permanent logging infrastructure for temporary operational records. It follows PSR-3 levels and interfaces, OWASP privacy guidance, and WordPress/WooCommerce operational conventions. Logs support diagnosis and correlation; they are not a source of truth for identity, billing, security decisions, or migration state.

The default handler writes to `{$wpdb->prefix}pinova_logs`. If that table is unavailable or an insert fails, it sends the already-sanitized record to WooCommerce's logger with source `pinova`, and independently attempts PHP `error_log`. WooCommerce's void return cannot prove persistence; PHP acceptance also does not establish durable storage. A logging failure must not interrupt the request that caused it.

## Record schema

Every record uses schema `pinova.event.v1` and contains:

- UTC `created_at` assigned by the database handler;
- a valid PSR-3 `level`;
- a stable, machine-readable `event` code up to 100 characters;
- one request/process `correlation_id`;
- optional indexed `flow_id` in its own nullable column;
- optional numeric `user_id` in its own column;
- JSON `context` containing `SafeContext`-sanitized caller fields and trusted package metadata.

Packaged builds add a validated, deterministic `build-info.json` at packaging time. Its commit, package identity, and optional release tag are appended to record context **after** caller context passes `SafeContext`; callers cannot supply or override them. A Git source checkout reports `package_identity=source` and does not invent a commit or tag. Release packaging verifies the manifest in the ZIP. No build identity is stored in `AGENTS.md` or `README.md`.

Pinova REST responses expose the same value in `X-Pinova-Correlation-ID`. A scoped `rest_post_dispatch` filter also covers WordPress validation, permission, authentication, and missing-route failures under `/pinova/`, preserves an existing response header, and leaves unrelated namespaces untouched. The shared JavaScript client displays only a bounded, safe reference received in that header on failures, including unreadable response bodies. A network failure without a server response must never fabricate a reference. This is request-level correlation, not a multi-request flow identity. Never put identifiers or secrets in response headers.

An OTP flow ID is 16 cryptographically random bytes represented as 32 lowercase hexadecimal characters. It is stored on the OTP record and carried between requests only in Pinova-signed JWT state. A signed flow ID must match the loaded OTP row before any verification mutation; unsigned `flow_id` request parameters are rejected. Legacy OTP rows and logs keep a null flow ID and remain valid. Record-backed issuance, reuse, delivery failure, verification, registration, session creation, and password-recovery logs retain the same ID where applicable. The flow ID is a pseudonymous linkage value subject to log retention, not an authentication secret or a replacement for the request correlation ID; never put it in a response header.

## Levels and noise control

- Default minimum: `warning`.
- Configurable persistent minimum: `info`, `notice`, `warning`, or `error`.
- `debug` is available only through a diagnostic window that expires after 15 minutes, one hour, or 24 hours.
- Never enable permanent debug logging through a hidden option or filter.
- Events controlled by an attacker must be bounded. Log rate-limit transition only when `hits === limit + 1`; do not log every rejected request. Do not add per-request firewall-denial logs without persistent deduplication.

Retention defaults to 14 days, is clamped to 1–90 days, and is deleted in batches of at most 5,000 by daily `pinova_logging_cleanup`. If expired rows remain, schedule one `pinova_logging_cleanup_continue` five minutes later; database failure stops the chain for the next daily retry. Health reports distinguish pending backlog, failed scheduling, and a missing or overdue continuation. Read-only health checks never delete or reschedule. General WordPress cron is required; the delivery-only `pinova otp run` command does not perform retention maintenance.

OTP records have separate fixed retention: daily `pinova_otp_cleanup` deletes rows whose UTC expiry was more than seven days ago, including verified and legacy rows. Preserve null expiry, live codes, and recent channel-selection history. Its 5,000-row batches continue through `pinova_otp_cleanup_continue` at five-minute intervals while expired rows remain. Uninstall/deactivation must clear both maintenance chains.

The additive `expiry_cleanup (expires_at, id)` index supports the cleanup range and ordering; do not substitute an identifier-leading index or order by ID alone. Advance schema metadata only after verifying this index exists, and preserve all OTP rows if an upgrade is interrupted. Integration coverage checks idempotent schema retry and the real database's expiry-range query plan.

Manual log deletion requires `manage_options` plus the `pinova_clear_logs` nonce and writes a fresh `logging.cleared` audit event only after a successful DELETE, including a successful zero-row DELETE. A missing table or database error must return a generic failure rather than a success notice or success audit. That bounded administrator action uses `Logger::audit()` so it is not suppressed when the configured minimum is `error`; do not use this threshold bypass for request-driven events.

## Privacy and context allowlist

Queue timing permits nonnegative integer `queue_delay_seconds` and `remaining_seconds`. The former includes provider processing until record creation is logged, so it is not a precise cron-only latency measurement. For public initiation, `otp.queued` is emitted only when the encrypted reservation is durably acknowledged after successful scheduling and signed response preparation; retries retaining that payload do not duplicate it. Legacy internal callers retain their earlier stored-payload observation for compatibility. It uses fixed-slot EventThrottle sampling keyed by source and opaque flow, with one event per slot and 100 site-wide per 15 minutes, so public decoy requests cannot amplify logs without bound. Sampling or logger failure never blocks issuance; missing queue events are not proof that scheduling failed. A valid signed flow with no matching record is retained in the failure event, but invalid/expired tokens never supply log metadata. `token_expired` distinguishes authenticated expiry from malformed/signature-invalid tokens without parsing exception messages. Public failure responses remain uniform.

An expired unclaimed encrypted queue payload observed by a delivery worker produces sampled `auth.request_failed` with `operation=queued_otp`, `reason=queue_expired`, and its opaque flow ID, without decrypting or resolving the identifier. Finished, cancelled and claimed payloads are excluded. Diagnostic read/emission failures remain silent and restore the database error policy; expiry is never a reason to send a stale OTP. The existing source/site throttle applies, so this is not an exact expiry counter.

Context is deny-by-default. `SafeContext` accepts only documented numeric IDs/counts, short code-like values, bounded code lists, approved keyed fingerprints, and exception class/code. It discards unknown keys. Historical event and correlation columns are also untrusted: the administrator viewer, incident export, and WordPress privacy export show only catalogued event codes and canonical WordPress-generated UUIDv4 request correlation IDs. Unknown events become `logging.unknown_event`; malformed or legacy-shaped correlation values become empty. This preserves ordinary support correlation while refusing syntax-valid secrets in historical columns. A deliberately UUIDv4-shaped historical secret cannot be distinguished from a generated ID by shape alone and remains a residual risk until rows have verifiable provenance.

Exception-class metadata uses one pure ASCII identifier/namespace validator for both Throwable objects and explicit strings, with an optional leading namespace separator and a 160-byte maximum. Anonymous class names, NULs, paths, newlines, malformed segments and overlong names are rejected rather than stripped or truncated into another symbol. Valid names retain their case and exception codes retain their existing behavior. Validation must not resolve/autoload classes or perform reflection, WordPress, configuration or database calls.

`changed_keys` accepts at most 20 names from the first-party settings field allowlist; it never contains values. Only strict 32-character lowercase hexadecimal `flow_id` values pass `SafeContext`, and `Logger` moves them into the dedicated column before JSON persistence. Packaged `build_commit` (40 lowercase hexadecimal characters), `package_identity` (`pinova-release-zip`), and an optional validated `release_tag` are trusted package metadata, not arbitrary `SafeContext` input. Source checkouts carry only `package_identity=source`.

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
| `auth.request_failed` | error | Unexpected authentication request or queued-delivery worker failure; worker observations are throttled |
| `auth.password_failed` | notice | Native pipeline rejected password or role policy |
| `auth.password_succeeded` | info | Password login completed |
| `auth.logout_rejected` | notice | A logout nonce was invalid; session preserved and observation throttled |
| `auth.password_reset_failed` | notice | Password-reset token, account/policy, confirmation, key generation, key validation, or reset operation failed; observation throttled |
| `auth.password_reset_succeeded` | info | Password reset completed; observation throttled |
| `auth.redirect_failed` | warning | A validated authentication/logout redirect was rejected or headers were already committed before a Location response could be emitted |
| `auth.session_created` | info | Pinova created an authenticated session |
| `auth.session_failed` | notice/error | Final OTP session policy rejected the request, or a session callback raised an exception |
| `auth.session_destroyed` | info | Pinova logout completed |
| `identity.resolved` | debug | Identifier resolution result during a diagnostic window |
| `identity.mobile_conflict` | warning | One mobile matched multiple legacy User IDs; resolution failed closed |
| `integration.wpforo_status_changed` | notice/warning | A bounded wpForo capability observation changed; partial/unavailable observations are warnings |
| `integration.dokan_status_changed` | notice/warning | A bounded Dokan capability observation changed; partial/unavailable observations are warnings |
| `integration.operation_failed` | error | A guarded membership, rendering, seller or classic checkout operation failed; fixed adapter/reason codes and existing observation throttle |
| `logging.cleared` | warning | An administrator manually cleared existing records |
| `logging.incident_exported` | notice | An authorized administrator generated a bounded incident export; logs actor ID, count, scope, and result, never export content |
| `settings.updated` | notice | An authorized persisted Pinova settings change; logs actor ID, first-party changed key names, section, and success only |
| `otp.queued` | info | A public encrypted delivery reservation was acknowledged (legacy internal callers retain their stored-payload event); contains only the flow and remaining deadline, never account classification or delivery claims |
| `otp.queue_recovered` | info | Reconciliation was attempted for a live acknowledged reservation after its scheduled job disappeared; emitted before claim, not successful dispatch, provider acceptance or receipt |
| `otp.queue_deferred` | warning | A live reservation could not be claimed on this pass; fixed `claim_unavailable` reason, nonterminal and not proof of a scheduler or database defect |
| `otp.created` | info | OTP record was created and at least one channel succeeded; queued delivery includes total elapsed time since the signed request and remaining lifetime |
| `otp.diagnostic` | debug | An explicitly scoped, budgeted OTP stage observation; missing stages and accepted provider results never establish physical receipt |
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

Event names are API-like identifiers, not translated prose. Renaming an event is a compatibility change for monitoring consumers. Add a new event rather than inserting dynamic data into the event name. Add each new stable event to `LogRepository`'s export allowlist at the same time; otherwise its event code is replaced by `logging.unknown_event` in administrator and privacy exports.

The logout-rejection, password-reset, and OTP-verification-failure events use `EventThrottle`, not the administrator-only threshold bypass. The existing rate-limit table admits at most one observation per HMAC-derived source slot and 100 observations site-wide per event in a 15-minute window. OTP failures separate source slots by fixed failure reason so distinct operational causes remain visible. Each event has 1,024 fixed source slots plus one site bucket, so missing cleanup cannot grow throttle storage without bound. Slot collisions conservatively suppress observations; they never affect authentication. Conditional database writes determine admission atomically. A missing/failing throttle backend suppresses these observations without blocking the user operation. These sampled events are not complete request counters. Their contexts contain only fixed reasons/statuses, optional trusted User ID, and keyed IP fingerprint, never a nonce, reset key, password, token, or return URL.

Disabled event levels skip throttle database writes entirely. Failure to read the logging configuration suppresses the observation without affecting authentication.

Integration status observations run only in authorized non-report administrator requests and existing daily cleanup. Two non-autoloaded options retain finite state/reason tuples; atomic claims avoid repeated no-change writes and duplicate transition observations. Snapshot/report/CLI reads never persist these options or events. Current capability status remains available even when retained history is empty, truncated or unreadable. Versions are bounded current metadata, not transition identities or runtime gates. Status observations use the ordinary configured logging level and can be absent or incomplete; the state claim precedes best-effort logging, so a suppressed or failed event is not retried or backfilled while the observed state stays unchanged. They are coalesced observations, not exhaustive change history or proof of recovery. Historical compatibility observations are distinct from confirmed bugs and manual issue decisions remain unverified.

After `OTPService::verify()` loads a trusted OTP record, verification success and known-record failure events include `otp_type`, `identifier_type`, and the same purpose-separated identifier HMAC used at creation. Invalid-token and missing-record events must not fabricate those fields. Never fingerprint the OTP code itself. Adding metadata must not move purpose checks after verification, attempt mutation, or user/session creation.

The `security.block_added` and `security.block_removed` contexts may contain the selected identifier type, keyed identifier fingerprint, block status, authorized administrator User ID, and block resource ID. They must never contain the raw mobile, email, username, or IP. Do not emit a new persistent event for every attacker-controlled blocked login attempt; the administrator mutation events provide the bounded audit trail without log amplification.

The two `admin.sms_test_*` events are bounded, capability-protected audit actions. They bypass the configured minimum level so a deliberate test always leaves a privacy-safe result, including when the site stores only `error` events. This exception must not be generalized to request-driven authentication, OTP, or channel events.

Native-login arming and gate-state events are bounded control-plane records. Their context may contain the authorized administrator User ID plus short `status`, `reason`, or `operation` codes. Never include the private slug, route URL, slug HMAC, transient arm contents, authentication material, or a reversible derivative of the route. Do not emit an event for canonical-login probes; only a successful eligible private login and an actual activation/invalidation transition are catalogued.

Settings audit observes successful WordPress option updates for first-party Pinova settings, including the integration switches `wpforo_enabled` and `dokan_enabled` and defined MaxSMS, MeliPayamak, and PanelChi provider fields, while a `manage_options` administrator is active. It compares persisted old and new values only to determine which allowed field names changed; those values are never handed to the logger. It skips no-ops and unauthorized/system-only writes, prevents recursion, and caps observations per request. This records a successful persistence result, not attempted or rejected settings submissions.

`auth.redirect_failed` is emitted when WordPress rejects a validated safe redirect or when the header state is already committed before/while dispatch reports success. Its context is limited to the bounded operation, reason and header-state codes plus the intended HTTP fallback status. Never include the destination/return URL, query string, nonce, cookie, identifier, token, source file/line, exception message, or trace.

## Scoped OTP stage detail

`DiagnosticContext` owns the finite stage catalogue for `otp.diagnostic`. Its constructor accepts a server-trusted flow, the original absolute signed deadline and one operation: `queued_otp`, `verify_otp` or `verify_mobile`. Format and deadline checks are not proof of ownership: the caller must first establish the persisted queue flow/deadline or the matching token, OTP record and purpose. A freshly generated candidate flow must not replace a reused persisted flow. Invalid tokens and missing records retain their existing observations without creating a trusted diagnostic context.

Construction and `observe()` perform only bounded in-memory work and pure clock checks. They must not read options, call WordPress, acquire a database connection or invoke a logger. Capture facts during delivery, then call `flush()` only after both the queue claim and any immediate-delivery lease have been released. Apply this boundary to cron/CLI as well as FPM; a cron claim is not protected by the immediate-only final HTTP guard. No diagnostic database operation belongs between a final ownership check and provider HTTP. One context buffers at most twelve observations, drains once and cannot reopen after flushing.

| Stage | Component | Symbolic source reference |
|---|---|---|
| `queue_accepted` | `otp_queue` | `otp_queue.acknowledge_queued_otp` |
| `immediate_admission` | `otp_immediate` | `otp_immediate.dispatch` |
| `worker_started` | `otp_delivery` | `otp_service.deliver_queued` |
| `provider_started`, `provider_completed` | `http_transport` | `curl.post` |
| `channel_completed` | `otp_delivery` | `channel_service.send` |
| `delivery_completed` | `otp_delivery` | `otp_service.deliver_queued` |
| `verification_completed` | `otp_verification` | `otp_service.verify` |

Component and source reference are derived from the stage, never copied from caller prose or file paths. `error_code`, `result`, `channel` and `execution_mode` use the finite catalogue in `DiagnosticContext`; syntax-valid but unknown codes, exceptions and arbitrary provider fields are omitted. Execution modes are `immediate`, `cron`, `cli` and `request`. `SafeContext` applies this stricter catalogue only to the diagnostic event, and `EventEvidence` uses the same catalogue for every historical output. Ordinary events retain their existing context and threshold contracts.

`observed_at` is the code-captured UTC occurrence time at whole-second precision; the database `created_at` is the later flush/storage time. `flow_deadline` remains the original absolute deadline. Both are bounded UTC epochs in 2020–2099. Optional integer fields are `request_to_worker_seconds` and `remaining_seconds` (0–180), `provider_duration_ms` and `channel_duration_ms` (0–600,000), and `http_status` (100–599). Numeric strings, negative values and arbitrary adjacent fields are rejected. Producers must use the signed origin and actual precision for request age, and a same-process monotonic clock for elapsed provider/channel measurements. An absent acknowledgement witness remains unknown; `updated_at` and a delayed flush timestamp are not acknowledgement time. The legacy `queue_delay_seconds` continues to mean total elapsed time through its existing result observation.

Only `Logger::diagnostic()` may emit this event. It requires an active `diagnostic_until` and exact `diagnostic_scope` object: `type=operation` with a finite matching operation, or `type=flow` with the matching validated flow. A legacy unscoped debug window does not admit new stage detail. The new path neither calls `audit()` nor relies on global DEBUG promotion. Scope/expiry are checked again after budget admission and immediately before handing the record to the existing handler; a consumed slot is never refunded or retried. True means a budgeted handler invocation completed, not durable storage. Configuration, metadata, encoding, admission and handler failures are contained. Ordinary logger calls perform no additional diagnostic-scope reads.

The final encoded diagnostic context, including trusted build metadata, is limited to 2 KiB before budget work. No caller may supply build provenance. The existing viewer/export SQL caps new diagnostic contexts at 2 KiB before fetching them, while retaining the old 8 KiB limit for other events; oversized contexts are empty and explicitly marked truncated. Historical direct redaction also enforces these bounds. Calling the PSR-3 or administrative audit methods with the diagnostic event cannot bypass admission.

### Shared budget and lifecycle

`EventThrottle` retains the legacy event-specific budgets and separately owns one fixed `log_diagnostics` row in the existing rate-limit table. Site admissions are capped at 1,000 per 900-second window. Its versioned payload contains at most 1,024 purpose-separated HMAC slot indexes, each storing a maximum of twelve admissions and an original deadline. It contains no raw flow, identity or provider material. Active collisions share the twelve slots conservatively and retain the maximum successfully admitted deadline. Site-window rollover preserves all still-live flow counters; a flow's lifetime allowance cannot reopen at a site reset.

One compare-and-swap update checks the previous site count, reset, update time and binary payload, then jointly changes both caps. Only exactly one affected row admits. A stale result, zero affected rows, reconnect/replay or unknown outcome suppresses the observation without retry or readback permission. The selected reset and original deadline must still be future at the final SQL boundary. Database UTC time is derived without session-timezone conversion; inconsistent clocks, future update times, corrupt data and unknown counters fail closed for detail alone.

Payload projection is capped at 64 KiB in SQL before fetching/decoding, with strict version, type, depth, slot and deadline checks. Exhaustion persists at most one coalesced coverage bit per window; subsequent drops do not write a drop counter. Contention or a failing backend may also lose that marker, so absence of the bit cannot prove full coverage.

Only an authorized first control-plane activation may call `initialize_diagnostics()`. The settings owner must preserve its server-owned `diagnostic_budget_initialized` flag; later missing or corrupt state remains unavailable rather than being repaired during emission or report reads. Initialization preserves existing counters. General rate-limit cleanup exempts exactly `EventThrottle::diagnostic_bucket_key()`, keeping this single bounded aggregate row across inactive days; other expired rows, including another key with the same scope text, retain normal cleanup behavior. It is not an identity record or an additional event store.

`diagnostics_snapshot()` is read-only. Its finite output is `read_success`, `coverage` (`sampled`, `incomplete` or `unknown`), effective current-window `admitted`, `site_limit`, `flow_limit`, `window_seconds` and `active_slots`; unknown reads use null counts. These are budget observations, not persisted-event, authentication, delivery or failure counters. `DiagnosticContext::coverage()` describes its local buffer/admission only (`pending`, `sampled`, `incomplete`, `expired`, `invalid`), never complete flow history. Expired or discontinuous clocks suppress new detail without extending authentication validity or changing existing provider outcomes.

New budget regressions must use independent autocommit database connections and worker processes for 11/12 and 999/1,000 races. Shared-connection query-filter tests remain useful legacy coverage but do not prove process-level atomicity. Cover rollover at the final CAS, slot collisions, fixed storage under many flows, stale replay, malformed/oversized/missing counters, coalesced drops and the exact cleanup exception.

## Operational workflow

Unexpected queued-delivery worker failures use the existing finite `EventThrottle` budget for `auth.request_failed`; routine provider failures retain their existing `otp.delivery_failed` event. The public response carries neither result.

1. Ask the reporter for the approximate time, operation, and `X-Pinova-Correlation-ID`; do not ask them to send OTPs, passwords, tokens, or full cookies.
2. Review **Pinova → Logs and Issues** with the smallest necessary level, exact event, correlation ID, User ID, and UTC date filters. Access requires `manage_options`; page size and page number are capped, and filters use prepared exact-match predicates. The viewer redacts even historical context before display. It reports effective minimum level and read-only health: `unavailable` when the table/connection is absent, `degraded` when the table cannot be queried or cleanup is unscheduled, `database-backed` when readable with cleanup scheduled, and `fallback` when the current PHP request attempted fallback. The separate last-fallback observation is best effort and coalesced; neither a WooCommerce call nor PHP acceptance proves durable storage. A healthy read is not proof of future write success; no write probe is performed merely by opening the page. An empty table with minimum `error` may be expected because lower-severity events were never persisted; it is not by itself evidence of a rendering failure.
   Render pagination only when `paginate_links()` returns a string; a single result page returns no markup and must never be passed as `null` into WordPress escaping functions.
   An administrator may download a nonce-protected JSON incident export for at most seven UTC calendar days, 1,000 events, and 2 MiB. Rows stream in bounded batches; incomplete/truncated output is marked. The export includes environment versions, current package metadata, selected range, and redacted event facts, but never raw identifiers, credentials, arbitrary legacy context, or keyed fingerprints. `logging.incident_exported` records the administrator User ID and count without storing the bundle. Treat the downloaded file as sensitive operational data and share it only with authorized responders.
3. Enable a short diagnostic window only when default warning/notice information is insufficient. Reproduce once, then turn it off; automatic expiry is a safety backstop.
4. Correlate by event, correlation ID, User ID, and keyed fingerprint. Treat an unmatched fingerprint as inconclusive because normalization/purpose may differ.
5. Clear logs only when needed for privacy or a clean test boundary. Retention normally handles deletion.
6. If the dedicated table is unavailable, inspect WooCommerce logs for source `pinova`, then the server PHP error log. Those fallbacks contain the same sanitized structure.
7. Even when the Pinova table is healthy and contains no warnings or errors, inspect WordPress debug output, WooCommerce logs, PHP-FPM/PHP logs, and the web-server error stream for lifecycle, deprecation, and compatibility diagnostics near the same request. These global streams are complementary evidence, not Pinova logger fallbacks. Do not persist every `doing_it_wrong_run` event in `pinova_logs`: the hook is global, attribution to Pinova is not reliable, and repeated public requests could create log amplification. Fix the owning call site and add a focused regression instead.

Do not query or alter the production table during development. Production inspection or deletion needs explicit authority for that environment.

## Required tests for logging changes

The administrator viewer and bounded incident export accept an exact flow-ID filter. The export includes only a strictly validated flow ID; approved privacy erasure clears the flow ID from rows it anonymizes. Legacy rows with null flow IDs remain queryable by their original request correlation IDs.

- unit: valid PSR-3 levels, minimum threshold, debug expiry, event sanitization, fingerprint stability/purpose separation, and deny-by-default context;
- integration: table creation/upgrade, persistence without raw PII/secrets, exception-message removal, retention boundary, correlation header, identity-conflict redaction, and rate-limit amplification protection;
- UI/authorization: log viewer capability, output escaping, level filtering/pagination, clear capability and nonce;
- incident tooling: exact filters, malformed-filter rejection, export capability/nonce, seven-day/row/byte caps, historical-secret redaction, settings-key-only audit, current-request fallback truthfulness, and deterministic package metadata;
- compatibility: WordPress 6.8/latest and WooCommerce/HPOS pairs in the repository matrix;
- packaging: production ZIP includes `psr/log` and runtime logger classes but excludes tests and agent documentation.

Pre-consumption OTP policy rejections use the existing `otp.verify_failed` event with bounded reason `policy_rejected`. They do not issue a password-reset key or claim successful verification. Public responses remain generic; the event never includes policy configuration, raw identifiers, or token contents.

When automatic WordPress cron is disabled, the Logs page explains that an external runner must execute at least once per minute for the 180-second OTP deadline. Pinova does not override `DISABLE_WP_CRON` or change server cron. Queue scheduling is not proof of dispatch or receipt; compare `otp.queued` and `otp.created` by their dedicated flow columns. Absence of creation alone does not identify a scheduler defect, because decoy/policy-denied requests are deliberately queued too.


## Issue monitoring and diagnostic parity

`EventEvidence` is the shared finite historical-context redactor for the viewer, incident export and issue report. Keep positive reason/timing tests alongside secret-removal tests. Never expose an arbitrary reason, operation, exception or provider merely because it has code-like syntax. `otp.delivery_skipped` records only sampled fixed reasons: `policy_rejected`, `already_completed`, `provider_outcome_unknown`, `invalid_payload`, `duplicate_records`, `claim_unavailable`, `expired`. Claim unavailability cannot distinguish cancellation, expiry or database failure. Channel failures retain `provider_returned_false` or `provider_exception` plus bounded elapsed milliseconds; accepted alternate channels remain valid.

`IssueMonitor` builds `pinova.issues.v1` from at most seven UTC calendar days and 1,000 observed rows, with three evidence samples per finite group. Keyset reads freeze the first high-water event ID. Diagnostic reads replace contexts above 8 KiB with an empty object and mark incomplete evidence. Report counts are observations, never request totals or failure rates. Current threshold is not historical coverage. Each issue includes the same finite `investigation_hint` shown in administration. External streams and physical delivery remain unverified. A missing queue outcome is inconclusive; operational failure is distinct from a confirmed code bug.

Administrators use the existing `pinova-logs` route, nonce-protected issue export and review actions. Reports ignore the raw-event table's identity/event/level filters and use only the displayed date range. Read-only `wp pinova logs report [--from=YYYY-MM-DD --to=YYYY-MM-DD]` emits the same JSON without logging or persisting a report. CLI access relies on the already authorized host operator; no public endpoint or remote AI service is introduced.

Review state uses one non-autoloaded option per finite issue code. Stale review tokens or event watermarks fail; later matching evidence reopens a group. An older date range cannot move the reviewed-through watermark backward. Failed/truncated reports cannot be marked reviewed. `resolved_unverified` means an administrator reported resolution, not that a test established it. Range expiry or retention never implies recovery. Clearing events does not clear review metadata. A bounded `logging.issue_reviewed` audit records the administrator and chosen state without free-text notes.

Cleanup returns `false` for failure and zero for successful empty deletion. Two bounded health options retain last cleanup and coalesced fallback observations with only fixed codes/timestamps/counts; writes are best effort, recursion-safe and never break authentication. Fallback observations are coalesced for five minutes, not exact counters. The daily cleanup health check has one hour of lateness tolerance. A status read never writes probes. Health checks include every required viewer column, including `flow_id`; page/export read errors must not be rendered as an empty successful result.

## OTP completion failures

A checked code is not a completed login. Bounded `auth.request_failed` records preserve the trusted flow/account and `operation=verify_otp`: `mobile_evidence_unavailable` means the security generation could not be read before consumption; `otp_completion_failed` means account creation or proof completion failed after consumption. Neither is an invalid-code rejection. Public completion failures return a generic 503 with the normal correlation header, never exception text. `auth.session_failed:policy_rejected` remains an expected rejection, whereas `session_exception` is a confirmed operational failure; it does not establish whether a callback had already created cookies. These events do not label the underlying defect as confirmed without further investigation. Both request and session failures separate fixed source slots by reason, preserving the shared 100-events-per-window site cap. No automatic repair, deployment or new context key is introduced.


## Virtual checkout diagnostics

The optional classic checkout policy uses `integration.operation_failed` with fixed `operation=woocommerce` and `reason=virtual_checkout_contract_failed` only for unexpected inspection/contract failures. Use the existing finite throttle and ordinary severity threshold, retain the normal form, and never persist product/customer identifiers, field values, gateway payloads or exception text. Unknown requirements, physical/empty carts and unsupported Blocks are ordinary policy states rather than per-request failure events. Settings audit includes only the changed key name `woocommerce_virtual_checkout`, never its value.

## Durable queue observations

Scheduling or durable acknowledgement failure emits sampled `auth.request_failed` with `operation=queued_otp` and fixed `reason=queue_schedule_failed`. It is a confirmed initiation failure, not a confirmed root cause. Reserved, rejected reservations are never dispatched by recovery. `otp.queue_recovered` and `otp.queue_deferred` use the existing fixed-slot/site throttle and opaque flow; neither closes missing-outcome evidence or establishes message delivery.

The read-only `queue_health` report is a current snapshot, independent of historical date filters. It contains only `read_success`, `truncated` and bounded counts `pending`, `expired`, `reserved`, `processing`, `legacy`. No ciphertext is decrypted, and no identity, flow or bucket is exported. Expired means acknowledged unclaimed work whose deadline passed; legacy raw ciphertext has unknown historical acknowledgement. Processing does not prove a worker is currently active. Failed reads are unknown, never healthy zeros. Snapshot counts are not lifetime totals or manually reviewable historical incidents. The administrator page, JSON export and CLI share this snapshot; reading it never repairs the queue or writes events.


## Account-linking observations

`identity.account_linked` and `identity.account_link_rolled_back` are privileged warning-level audit events for an independently authorized identity-only reconciliation. They identify the canonical account in user_id, with fixed operation=account_link/result=success; the private identity journal records the operator and retired account IDs. Never log the preview fingerprint, proof, mobile, email, password, cookies or journal body. Privacy erasure removes the journal; retirement itself must not be undone by erasure.

`identity.account_link_failed` is a warning using the fixed EventThrottle source/site budget, separated by reason. Allowed reasons: not_authorized, unsupported_accounts, identity_conflict, stale_state, ownership_not_confirmed, active_delivery, write_failed, unsupported_storage, data_changed, source_has_content, source_has_wallet_activity, source_has_forum_activity, unsupported_ownership_adapter, operation_failed. write_failed/operation_failed are confirmed failures; the other known reasons are expected protective rejections, and a missing/unknown reason is insufficient evidence. Success audits do not imply fresh mobile proof or file delivery. `integration.operation_failed` additionally permits reason=order_owner_mismatch for operation=woocommerce; this confirms an aborted ownership mismatch, not historical exploitation.


`identity.legacy_contact_overlap` is a sampled warning after successful account creation when an earlier account contains a matching unverified billing/shipping contact. It is insufficient evidence, never a confirmed duplicate identity or ownership proof. Context retains only the new user_id, existing opaque flow and candidate_count capped at two, plus fixed reason=unverified_contact. Never emit candidate IDs or raw contacts. The fixed source/site EventThrottle budget applies, so absence is not proof of no overlap. Lookup/logging errors cannot fail registration. It never blocks a legitimate mobile owner or selects an old account.
