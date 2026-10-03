# Production log audit and correction candidate, 2026-10-03

This is dated evidence, not installation authority. The user authorized production log inspection, repository fixes and publication. Production gpante.com and all other sites/services remained read-only. No Dokan, wpForo, WooCommerce, theme or WordPress source was changed.

## Observation window

The installed package reports `v1.3.0-rc6`, commit `9e07121ef6e90596474a9423089cea0e8b791bb9`. Pinova schema is 3; Pinova and WooCommerce database versions agree with their installed versions. At the 2026-10-03 10:04 UTC inspection, 405 Pinova rows were retained, spanning 2026-09-13 through 2026-10-03. Logging minimum is Info and retention is 30 days; temporary diagnostic mode is inactive. This supports review after days but does not preserve provider-level Debug detail indefinitely.

Since the previous inspection at 2026-09-29 14:17:21 UTC, observed events included 13 OTP creations, 6 OTP verifications, 3 registrations and their sessions, 1 password success, 2 missing-record OTP failures, 2 invalid-token OTP failures, 1 reused-code failure, 1 rate-limit warning and 2 native-only password-policy denials. These are observations, not complete request counters; failure events are sampled. Successful verifications and expected rejections coexist. No duplicate OTP flow groups were found.

Missing-record and invalid-token rows have no usable flow metadata in the installed version. Absence of a flow in a failure row does not prove a record was deleted. The two invalid-token events cannot retrospectively be separated into signed expiry versus malformed/bad-signature input. No new Pinova database incompatibility was established.

## Confirmed operational mismatch

Automatic WordPress cron is disabled and the existing site runner executes every five minutes. The signed OTP deadline is 180 seconds from request. Pinova intentionally respects disabled automatic cron, queues encrypted work after the REST response, and refuses expired delivery. Consequently the configured runner interval is longer than an entire valid flow. OTP creation clusters near runner boundaries support delayed dispatch, but existing records cannot attribute each failure causally. The runner needs to execute at least once per minute; changing the production schedule is a separate operation, not performed here.

## Separate server observations

The bounded tail of the site's Nginx error log contained 8 fatal entries during September 30 through October 3, with no Pinova stack mentions: core/theme entry-point failures included missing `get_locale`/`get_header`, and one additional core bootstrap failure remained unattributed. This was a bounded tail inspection, not an assertion that the entire server is error-free.

A separate WooCommerce fatal on September 30 at 17:53:13 UTC came from `wpforo\\widgets\\RecentTopics->get_widget`, with `get_topics()` called on null while Elementor rendered the WordPress widget. The observed stack includes wpForo and Elementor, not Pinova. An independent widget/lifecycle investigation is needed; this release does not claim to repair third-party source failures.

## Repository corrections

- Actionable generic Persian OTP/authentication/recovery messages preserve account privacy; actual server support references render separately with correct direction and clear on subsequent state changes.
- Malformed mobile-proof responses retain a received, validated correlation header; network failures do not invent one.
- Administrative SMS test copy states provider acceptance rather than receipt. Email copy states that validity starts at request. Partial installation failure copy no longer falsely promises that nothing changed.
- Sampled `otp.queued` records preserve only opaque flow and remaining time. Creation records include elapsed request-to-creation and remaining lifetime. A signed missing flow is correlated; invalid/expired token payloads are never logged. Authenticated expiry has the fixed `token_expired` reason.
- Queue observations use fixed-slot throttling, with 100 site-wide events per 15 minutes, so decoy requests cannot cause unbounded logging. Logging failure remains observational.
- The Logs page explains the external cron requirement when automatic cron is disabled; no server runner is overridden.

## Verification and build-tool correction

Initial candidate PR #58 at `27ec7e81fee9186d2cd8f81883cdc0bbeaa9c884` passed the PHP and WordPress integration matrices, standalone/checkout browser acceptance and modal fault-sensitivity gate. Real-plugin browser assertions still expected the old error wording; they were updated to assert rejection, new useful copy and the actual server correlation reference. The former main checkout failure did not recur in these exact-head gates; no speculative focus implementation change was made.

Ten specialist review passes found no actionable defect in the initial correction diff. Focused correctness, security and adversarial reviews also found no actionable defect in the dependency and logging supplement. The cross-model route did not run because no different-provider CLI was installed; local adversarial review ran. All 15 dependency-free JS test files passed with the required Node PATH, together with PHP syntax and governance checks. Targeted integration regressions cover the 100-event queue budget and successful API issuance despite false/throwing logging backend queries. New source changes require final exact-head CI before merge/publication.

Supply Chain exposed GHSA-ch52-4w7c-c8xp in development-only `http-cache-semantics` 4.2.0, reviewed upstream on October 2. No patched npm release was available. The private vendored snapshot copies proposed upstream commit `14a8c2ad51740dc39bf3e8f1a11c845a5003f217` exactly with its BSD license; `4.2.1-pinova.1` is explicitly an unofficial private version. Runtime ZIPs exclude tools and all npm dependencies.

The original registry source reproduced restricted shared-cookie reuse with max-stale; the pinned fix rejected it. Regression coverage also preserves ordinary stale reuse and public/immutable cookie opt-ins. The isolated lock-maintenance workflow `37117317212` passed `npm ci`, installed-byte verification and `npm audit --audit-level=low` with zero vulnerabilities. Its artifact changed only root dependency metadata and the cache package lock entry. Normal Supply Chain now verifies actual installed bytes and remediation before the unchanged audit gate. The temporary lock-preparation workflow is absent from the final candidate.

Publication and immutable-release evidence will be recorded separately after final review, exact-head/main/publish CI, reproducible builds, attestations and independent asset downloads. No production installation or cron configuration change is implied.
