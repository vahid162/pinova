# Pinova 1.2.6 WooCommerce Checkout Lifecycle — RC3 Review

Date: 2026-09-15 (Asia/Tehran)

## Confirmed defect

- A read-only review of the site running Pinova 1.2.5 found 97 repeated WooCommerce `wc_get_product` lifecycle warnings after installation, all associated with checkout AJAX requests and the Pinova checkout-phone validator. The observed checkout responses remained HTTP 200 and no Pinova REST 5xx was attributed to this path, so the issue was nonfatal but real diagnostic noise and premature filter execution.
- Source review confirmed that Pinova called `WC()->checkout()->get_posted_data()` from `woocommerce_checkout_process`. WooCommerce fires that action immediately before its own call to `get_posted_data()`, so Pinova reparsed every checkout field and re-entered extension filters too early.
- The same source bytes were present in immutable `v1.2.6-rc2`; that release remains untouched and is superseded for a new installation rather than retagged or overwritten.

## Fix and regression

- Checkout phone validation now runs on `woocommerce_after_checkout_validation`, consumes WooCommerce's already-sanitized `$posted_data`, and reports stable errors through the supplied `WP_Error` object.
- The integration regression replaces the WooCommerce checkout singleton with a counting test double and proves the early checkout-process action no longer asks for the complete payload. It also covers invalid, normalized Persian-digit, and non-string phone inputs on the validated-data hook.
- The focused test failed against the original source (`1` premature parse instead of `0`) and passed after the fix with 4 tests and 4 assertions against WordPress 7.1, WooCommerce 11.1.0, PHP 8.1, and HPOS off.
- The complete isolated integration suite passed on the final source with HPOS off and on; each mode ran 48 tests and 176 assertions against WordPress 7.1, WooCommerce 11.1.0, and PHP 8.1. The uniquely named disposable project used verified free ports and did not stop, update, or alter any preserved wp-env project. Its containers were stopped after testing; its four task-specific volumes were retained.

## Logging decision

- The existing Pinova structured table, schema, privacy allowlist, retention, correlation IDs, and fallback chain were healthy in the production read-only review; no runtime logger or schema upgrade is justified by this defect.
- Capturing every global `doing_it_wrong_run` event in `pinova_logs` would have unreliable ownership and could amplify attacker- or extension-controlled request noise. Operational guidance now requires checking WordPress, WooCommerce, PHP, and web-server diagnostics alongside structured Pinova events. The owning call site and integration regression are the durable controls for this issue.

## Release gates

- Changelog synchronization and Skill synchronization passed. Full PHP syntax lint passed. PHPUnit passed 17 tests and 41 assertions. The five JavaScript VM regression files passed.
- The configured PHPCS suite and an additional direct check of both changed PHP files passed. The ordinary parallel PHPStan command was blocked by the host sandbox's local-socket restriction; the complete single-process debug analysis finished without errors, and a separate focused analysis of `Checkout.php` returned `[OK] No errors`.
- Online Composer audit of the exact lockfile reported no security vulnerability advisories.
- Two local Composer 2.10.3 builds under different umasks were byte-identical with SHA-256 `7fbdb046ced0ce09ea875eee39e81d363e78852fef81dea04175956c0f30850d`. ZIP integrity, version 1.2.6, 3,684 entries, and the single top-level `pinova/` constraint passed. The host PHP CLI lacks `fileinfo`, so only that platform requirement was explicitly ignored for local packaging; GitHub CI must build with the required extension before merge and publication.
- GitHub implementation PR and required Quality jobs: pending.
- Merge commit and green `main`: pending.
- Immutable `v1.2.6-rc3`, assets, attestations, checksum, and independent redownload: pending.
- Production installation is outside this change and remains blocked on a fresh stability check, backup, rollback plan, and explicit execution-time authorization.
