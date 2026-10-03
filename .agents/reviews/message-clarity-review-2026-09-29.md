# Pinova message clarity review — 2026-09-29

## Boundary and source

Local source candidate on `fix/auth-error-clarity`, based on verified remote main `c9e19660770c3efbb4fa4efa64563fa369b18acf`. The candidate is not committed, published, packaged, or installed. Production inspection used read-only queries and public page requests; no repair, settings change, migration, cache flush, service restart, or test SMS was performed.

The review covered public authentication and recovery responses, input validation, mobile proof, the common browser REST client, standalone/checkout error presentation, SMS test feedback, email OTP copy, installation notices, block-management feedback, and the corresponding notification callers. Existing account-privacy, purpose, session, rate-limit and provider-delivery policies remain intact.

## Findings and local corrections

- OTP rejection copy did not tell users what to do. Login, recovery and account-mobile verification now share neutral instructions to check the newest code and request another after the countdown. The message does not guess the internal reason or disclose account existence.
- Standalone and checkout banners combined a long server reference with their explanation. They now render the sanitized explanation and selectable LTR support reference separately, clear stale references, and retain existing combined messages for other callers.
- Mobile-proof JSON parsing could discard a received support reference when the response body was malformed. It now reads and validates the header first, retains the reference on that failure, and separates it with an LTR-isolated line. Network-only failures do not fabricate a reference.
- Password, recovery, input-validation and temporary-service failures now give concrete next steps. Server status codes and rejection decisions are unchanged.
- The administrator SMS test overstated provider acceptance as successful delivery. Success now describes provider acceptance and asks the operator to check the recipient device. Failure describes an unconfirmed outcome rather than assuming every failure is internal or that no message could have been sent.
- Email copy promised a full three-minute interval starting around sending/receipt despite queue delays consuming the signed lifetime. It now explains that validity starts at the request. The existing purpose-specific email integration test uses a short remaining lifetime and checks the corrected copy.
- Failed activation claimed no changes had occurred despite additive operations that can partially complete. The notice now acknowledges possible partial changes and directs the administrator to database access and server diagnostics.
- Authentication success, reset-success-after-session, block save/remove, purpose-specific email subjects and privacy-preserving queued-initiation semantics were inspected; no changes to their decisions were needed. Shared-client versus caller notification ownership remains unchanged.

Affected browser assets receive new cache revisions. No dependency or plugin release version was changed. Public documentation and the client-response contract were updated; released changelogs were preserved.

## Read-only operational evidence

A rolling three-day aggregate of the installed site's Pinova log table showed eight successful OTP verifications and sessions, nine OTP creations, two invalid-code events, one already-verified event, and four password authentication rejections. No warning/error-level Pinova events appeared in that result. Logging is bounded and these are observations, not complete request counters or proof that every request was healthy.

The reported rejection occurred after the verifier had resolved its record and passed flow, purpose, expiry and IP checks; the recorded reason was `invalid_code`. This establishes the failing boundary but not why the submitted value differed. Current standalone/account HTML and backend settings both use five digits. No OTP, hash, identifier, token, credential or raw provider payload was exposed.

The current installed WooCommerce runtime classifies Pinova as HPOS-compatible, with no incompatible or uncertain enabled features. Pinova schema and WooCommerce database versions were current. Bounded recent server logs and the relevant WooCommerce log files did not recover the earlier reported database error. The original mismatch cause and earlier database error remain unconfirmed; this source change does not claim to fix them.

## Validation and remaining gates

Passed locally: all 14 dependency-free JavaScript test files; JavaScript syntax lint for 32 first-party files; PHP syntax for changed PHP files; `git diff --check`; AI governance; changelog synchronization; plugin/readme metadata; instruction-contract synchronization.

New behavior coverage exercises separate references, reset/transport cleanup, exact validated server references, malformed mobile-proof responses, unsafe headers and network-only failures. Existing SMS integration expectations were updated. PHP/WordPress integration and real browser suites were not run on the shared production host.

Preflight found an existing failure on unchanged runtime main: run `36374275910`, job `108776792435` failed checkout focus restoration after closing during a pending request. Main differs from the installed RC6 runtime only in repository guidance. That failure needs separate reproduction before an installable candidate can be approved; this review does not relax or bypass the gate. Open dependency PRs and historical release-evidence PRs were classified as separate work and left untouched.

Before deployment: review/publish the source candidate, obtain passing exact-head integration/browser/quality checks, resolve the inherited focus gate, then follow the existing immutable release, staging and separately authorized production deployment process. No installable artifact is claimed by this report.
