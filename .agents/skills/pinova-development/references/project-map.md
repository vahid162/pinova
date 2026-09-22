# Pinova project map

Read this reference when locating code, reviewing authentication or identifiers, changing persistence, or assessing integration impact.

## Entry points and modules

- `pinova.php`: metadata, constants, bootstrap, integrations, and HPOS declaration.
- `src/API/`: REST controllers and response envelope.
- `src/Services/`: OTP, users, validation, rate limiting, firewall, SMS, channels, and API orchestration.
- `src/Objects/`: normalized request identifiers and mobile handling.
- `src/Integrations/Wordpress/`: profiles, users list, export authorization, Excel, VCF, and the staged native-login gate.
- `src/Integrations/Woocommerce/`: login/account/checkout and constrained customer creation.
- `src/Helpers/IP.php`: client-address and trusted-proxy handling.
- `src/Logging/`: PSR-3 logger, safe-context allowlist, database handler, retention repository, and fallback handling.
- `src/Admin/Logs.php`: capability-protected operational log viewer and manual clear action.
- `src/Install.php`, `src/Version.php`, and `utils/`: additive schema, upgrades, settings, and compatibility helpers.
- `templates/` and `assets/`: login interface.
- `tests/`: unit, plugin-load, and security integration coverage.
- `tests/browser/account-ui.spec.mjs`: real disposable WordPress browser acceptance, including modal control geometry and keyboard behavior.
- `tools/check-modal-test-sensitivity.mjs` and `tests/js/modal-test-sensitivity.test.mjs`: loopback-only response mutations and strict report classification; no runtime plugin edits.
- `tools/cleanup-browser-env.sh` and `tests/js/browser-cleanup.test.mjs`: verified cleanup restricted to the current GitHub browser job, with fake-Docker unit coverage.
- `.agents/reviews/`: immutable historical evidence; never treat a record there as a live release or deployment pointer.

Identity-table resolver, migration, merge, rollback, and WP-CLI code live only on the separate 1.3 development line until explicitly merged. Do not document those features as shipped by 1.2.3.

## Identifier behavior in 1.2.3

`UserService::match()` resolves email through WordPress, username through WordPress, and mobile through legacy `user_login` plus configured metadata aliases. Queries use prepared placeholders.

A valid physical `pinova_mobile` value is an explicit login-mobile override. It is checked before legacy values when displaying a user's mobile. If an account later stores a different explicit override, the old value remains unchanged in a mobile-shaped WordPress username or legacy metadata but no longer authenticates as that user's Pinova mobile alias.

Resolution returns a User ID only when the candidate is unambiguous. Multiple legacy accounts matching one mobile return no user and fire `pinova/identity_conflict_detected`. Resolving that conflict and merging ownership is deferred to the reviewed 1.3 workflow.

## Data tables in the 1.2.x line

- `pinova_otp`: OTP attempts, channels, expiry, and verification.
- `pinova_blocks`: temporary or permanent blocked identifiers.
- `pinova_rate_limits`: atomic hashed rate-limit buckets.
- `pinova_logs` (1.2.4+): temporary structured operational events, correlation IDs, optional User IDs, and allowlisted JSON context.

Schema changes must be additive and `dbDelta()` compatible. Never alter/drop columns or indexes in `wp_users` or other core tables.

Lifecycle setup is database-coordinated through `pinova_db_schema_version`; runtime code never writes activation sentinels into the plugin directory. Activation and normal startup call the same idempotent migration coordinator before services boot. Initial table creation uses WordPress `dbDelta()` so activation does not depend on PDO/Eloquent being available before the host finishes provisioning PHP extensions. A failed migration leaves the version unchanged for retry and keeps Pinova runtime integrations unloaded so native WordPress access remains available. Network-wide activation is unsupported and must fail with an administrator-facing explanation.

Deactivation clears every hook returned by `Install::scheduled_hooks()`. Uninstall preserves data unless `pinova_delete_data_on_uninstall` is explicitly enabled. Opt-in purge removes Pinova tables, options, transients, and schedules while preserving account identity metadata such as `pinova_mobile`.

Blocked List writes require an authoritative `blocked_type`: `mobile`, `email`, `username`, or `ip`. REST writes validate the selected type, persist the canonical identifier for that type, store `NULL` for a permanent block, and keep system-managed rows immutable through manual add/delete operations. The current table stores the canonical identifier rather than a separate type column, so response resources derive `identifier_type` from the stored value. List filters return a stable `{users:[{id,name}]}` shape and server pagination metadata. Authentication checks the normalized identifier and client IP before OTP issuance and checks them again during OTP verification; therefore adding a block after issuing a code must still prevent verification, session creation, and user creation.

The 1.2.x Eloquent models depend on the connection initializer in `utils/class-database.php`. Composer includes that file in `autoload.files`, not only in the classmap: a classmap makes the class discoverable but does not execute the bottom-of-file initializer on a fresh request. Because a CLI/test process may load Composer before WordPress exists, `pinova.php` also performs an idempotent resolver check and explicitly initializes the connection after WordPress loads. Integration bootstrap checks the resolver before any installer helper can conceal this failure.

## Security boundaries

- Exports require `list_users`, nonce validation, safe strings, VCF escaping, batching, and XLSX row limits.
- WooCommerce customer creation accepts only allowed billing/shipping input and forces role `customer`.
- Redirects require `wp_validate_redirect()` and the allowed-host policy; an omitted or empty return target is replaced explicitly with the safe role-aware/site fallback because WordPress otherwise preserves the empty location. Public login/logout routes derive from `home_url()`, and a nested return URL is encoded exactly once before `add_query_arg()` so a single request-query decode restores the exact destination. Pinova logout URLs use canonical `/logout/`; an invalid/stale nonce terminates with HTTP 403 before session destruction, while a rejected safe redirect after a completed logout terminates with an HTTP 503 page containing only a validated same-site continuation link instead of a blank 200. A core force-reauthentication URL retains `reauth=1`: public-role sessions are cleared before Pinova renders, while native-only users continue through the private core handler.
- Public password/forgot/OTP responses do not expose account existence or native-only role membership.
- OTP records are purpose-bound at reuse and verification: ordinary OTP login accepts only `login`/`register`, password recovery accepts only `forget`, and a mismatch fails before verification, account/session creation, or reset-key issuance. Concurrent purpose-specific codes remain independently valid only in their own flows. Email delivery carries a purpose-specific subject, explanation, instruction, and footer and is addressed only to the normalized user email; Pinova does not copy OTPs to an administrator.
- Native administrator password/2FA login remains available through the private Pinova administrator route, which internally executes `wp-login.php` and its core/security-plugin hooks. Canonical account actions can return 404 only when an explicitly persisted private slug, the blocking setting, and a matching durable activation record are all present. A successful private-route login by a `manage_options` native user creates a 30-minute single-use arm bound to the slug HMAC, User ID, and plugin version; enabling consumes it, while disabling or changing the slug invalidates both arm and activation. Exact core handlers remain available for password-protected posts, nonce-protected logout, keyed privacy confirmation, administrator-email confirmation, and nonce-protected Recovery Mode exit. The private route remains testable before enablement, is derived from the public home URL even when WordPress core is installed in a subdirectory, and is never rendered by the public account template, REST output, or structured logs.
- Rate limits hash identifiers and return HTTP 429 with `Retry-After`.
- Active mobile, email, username, and IP blocks deny the corresponding public authentication path without exposing account existence. Block messages may identify the blocked input type but never disclose whether a WordPress account exists.
- Logs retain stable codes, keyed identifier fingerprints, and bounded context—not raw identifiers, secrets, exception messages, or traces. REST responses include `X-Pinova-Correlation-ID` for support correlation.
- Proxy chains are considered only behind trusted CIDRs.

## Client REST response contract

`assets/js/global.js` is shared by the full-page login, login modal, Blocked List administration, and WooCommerce customer-creation modal. For a valid JSON response it returns the existing server envelope and adds an `http` object containing the status, optional `Retry-After` values, and optional Pinova correlation ID. A non-2xx JSON response is normalized to `success: false`; standard WordPress argument-validation errors prefer their field-specific `data.params` message over the generic `rest_invalid_param` summary. Callers remain responsible for rendering those server messages. The helper itself shows a generic notification and rejects only when transport fails or the response is malformed, preventing duplicate notifications while preserving actionable blocked, OTP, rate-limit, and delivery failures.

WooCommerce bootstrap runs early enough that constructing `WC()->checkout()` can trigger its text domain before WordPress permits just-in-time translation loading. The registration-required default mirrors WooCommerce's filtered `woocommerce_enable_guest_checkout` option without constructing checkout. Checkout phone validation runs on `woocommerce_after_checkout_validation`, consumes WooCommerce's already-sanitized data and `WP_Error`, and never reparses the complete payload from `woocommerce_checkout_process`.

## Standalone account UI contract

The full-page interface in `templates/login-form.php` is one centered authentication card; do not add a second promotional pane. Its OTP flows share one hierarchy: safe server delivery copy and edit action, code field with configured-length guidance, primary verification, resend availability, then any alternate login method. Delivery copy is text-bound rather than interpreted as HTML and mixed-direction content uses plaintext/bidirectional isolation. Editing the identifier returns to `authenticate` through the component state transition so short-lived OTP and reset secrets are cleared.

The resend countdown communicates availability only. While it runs, render plain status text without per-second live-region announcements; when it ends, replace that text with a touch-sized text action. Keep the OTP as one real `type="text"` field with numeric input mode and `autocomplete="one-time-code"`; segmented boxes are an `aria-hidden` visual layer whose count comes from `codeLength`, never separate inputs or a hard-coded digit count. Preserve paste, WebOTP, and exactly one exact-length autosubmit. Native required semantics and live field errors accompany custom validation; validation focuses the first invalid control, while initial render and normal workflow transitions never focus an input or open the mobile keyboard. Intentional transitions focus a non-input heading. One request lock covers submit, resend, and alternate methods, and all non-loader content is inert while a request is active. Explicit Yekan Bakh typography, exact-case font assets, one accessible focus treatment, 3:1 control-boundary contrast, `dir="auto"` on the mixed identifier, and safe-area padding are part of this interface contract. Center the wordmark independently of the back control, make it slightly larger only on desktop, and on mobile keep the logo header at the top while the remaining account group is centered in the available viewport. Identifier and password instructions live once in bold field labels; password values render LTR with right-side visibility toggles. The two password-step alternate actions split the row equally, use normal-weight underlined link styling, and have a visible pipe separator. The orange verification control remains the sole primary action. The store exit stays inside the card, full width but visually tertiary, and the first identifier step does not repeat that exit as an unlabeled header navigation choice.

## WooCommerce checkout authentication modal contract

The checkout modal reuses the standalone account design and authentication endpoints, but remains a true labelled dialog. Render it from `wp_footer`, outside the WooCommerce checkout `<form>` and replaceable order-review/payment fragments, so its semantic forms are valid and checkout AJAX refreshes cannot destroy active modal state. It traps focus while open, closes with Escape or the explicit close control, restores focus to the opener, makes the checkout background inert, locks document scrolling, and clears passwords, OTP/reset tokens, countdowns, status, and workflow state before reopening. Workflow transitions focus a non-input heading; native validation focuses the first invalid control; the centralized request lock covers submit, resend, and alternate actions while the form task content is inert. The close control is deliberately excluded from that inert content and remains usable during a pending request; cancellation and late-response rejection preserve immediate dismissal.

`.pinova-auth-close-button` is a direct child of the relatively positioned `.pinova-auth-card`, outside the header and loader. Keep its 44×44 target at the top-left corner using safe-area-aware 12px insets, with no header-relative translation. Keep the logo, forms, handler, and icon unchanged. Scope all close-control rules and only the modal back-control position under `.pinova-auth-modal` so generic theme button positioning does not override them. Preserve shared standalone-page rules and keep shared account asset revisions synchronized when their source changes.

WooCommerce's generic `checkout_error` event is not an authentication signal. Missing or invalid `billing_phone` stays an inline checkout error and the invalid control receives focus. Pinova opens only when the customer explicitly selects checkout login or when the current WooCommerce error notice contains Pinova's scoped existing-account conflict link. The modal retains one real configured-length OTP input, localized-digit normalization, paste, autofill/WebOTP, and one exact-length autosubmit.

The browser geometry matrix selects presentation states and checks computed layout/focus; it does not send 120 authentication requests. Product-driven heading focus is a synchronization condition before keyboard traversal. The separate mutation audit must detect the exact targeted product failures, not runner failures. See [quality and release](quality-and-release.md) for commands, coverage limits, and cleanup boundaries; historical run evidence belongs under `.agents/reviews/`.
