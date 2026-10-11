# Classic virtual checkout: release and test acceptance, 2026-10-11

This dated record covers the reviewed optional classic virtual-checkout implementation, its immutable RC16 package, and acceptance on the authorized test site. Production was read-only during this continuation and retained RC15. This record does not authorize production installation or a stable/latest release.

## Reviewed implementation

- Implementation: [PR #78](https://github.com/vahid162/pinova/pull/78).
- Final reviewed head: `09096e621f4158111a42352cc92b1caa03e139b7`.
- Exact-head push [Quality 38135358572](https://github.com/vahid162/pinova/actions/runs/38135358572) and pull-request [Quality 38135361459](https://github.com/vahid162/pinova/actions/runs/38135361459) passed.
- Merge source: `22be045b500add17d0dbe606fdb30efb728166c4`.
- Separate merged-main [Quality 38136378347](https://github.com/vahid162/pinova/actions/runs/38136378347) passed.

The existing WooCommerce settings section contains a default-disabled classic virtual-checkout option. Nonempty carts qualify only when their persisted native products and quantities establish that every item is virtual. Physical, mixed, empty or uncertain carts retain normal fields. Names, phone, email and extension fields remain available. Shipping-address requirements, customer-location taxation and unknown gateway contracts preserve the complete form. Native validation remains effective. AJAX, posted-data and order hooks preserve stored customer addresses.

When enabled, Pinova removes only the characterized legacy callback at priority 10 after theme registration. The same callback at other priorities and unrelated filters remain registered. No theme or dependency-plugin source is changed. Checkout Blocks are explicitly reported unsupported.

## CI evidence and review corrections

The suite includes 19 focused virtual-checkout integration tests and seven associated browser cases. The representative integration run reported 395 tests and 2,650 assertions. PHP 8.1–8.5, JavaScript, workflow/package/security checks, eight WordPress/WooCommerce/HPOS configurations, and ten real Dokan/wpForo profile/HPOS jobs passed. Chromium ran 23 functional cases plus healthy controls and five targeted modal fault controls.

Review repaired PHPCS layout and fixture assumptions around retained coupon success notices, the native accessible checkout-error wrapper and an optional screenshot waiting for fonts. Server-response, visible-error and modal-nonactivation assertions remain intact. Whole-test retries and acceptance thresholds were not relaxed. Coverage also verifies preservation of the legacy callback at another priority.

Heavy matrices, browser jobs and reproducible builds ran on GitHub Actions.

## Publication chain

- Publish branch: `publish/v1.3.0-rc16`, exact merge source above.
- Authorizing [Quality 38136872884](https://github.com/vahid162/pinova/actions/runs/38136872884): success.
- [Publisher 38137316165](https://github.com/vahid162/pinova/actions/runs/38137316165): success, including reproducible builds and post-publication verification.
- Annotated tag object: `3df7c083547ebcda6b41b6be5d2693a83014b063`.
- Tag target: `22be045b500add17d0dbe606fdb30efb728166c4`.
- [RC16 release](https://github.com/vahid162/pinova/releases/tag/v1.3.0-rc16), ID `409638295`: native `immutable=true`, `prerelease=true`, `draft=false`.

| Asset | Bytes | SHA-256 |
| --- | ---: | --- |
| pinova-1.3.0.zip | 5042017 | `4130b712cb6c8122b5bf3685eaf4a60a5c6a1415f4c6dcab0a0a767192a3bf8d` |
| pinova-1.3.0.zip.sha256 | 83 | `8f7b0babd13b49059cb8ae1dcf843f902f91c26ad8585e2e157807d5a9d3a0fa` |
| pinova-1.3.0.spdx.json | 97939 | `d1dbde345025178e2e396a646dc18d6977c53497ccb08f04140a32383a52584d` |
| pinova-1.3.0.spdx.json.sha256 | 89 | `5a60fef7cfbc043c2aad9d6fde4dba236d2c4c3e75f30d2303f26eee464ad259` |

Independent fresh downloads matched all four server-recorded digests and both checksum files. ZIP CRC, paths, allowlists, runtime identity and the SBOM inventory passed: 3,627 entries, 16,961,114 uncompressed bytes and 44 Composer packages.

Native release verification and verification of all four release assets passed against an authenticated trusted root. A changed synthetic checksum was rejected. Separate SPDX attestation verification pinned the source digest, publish ref, GitHub-hosted signer, OIDC issuer and exact publisher workflow identity; the entire signed SPDX predicate matched the downloaded SBOM. This is SPDX/source-identity evidence, not an additional SLSA provenance claim.

## Authorized installation and rollback

No new database export or wp-config copy was made, as the operator had declined that backup. This was a files-only, same-schema installation: runtime version 1.3.0 and schema 3, unchanged installation/migration code, and a default-disabled setting.

The verified public RC15 ZIP content matched all 3,626 installed prior files; filesystem ownership and modes were separately recorded and preserved. A guarded installer preserved unrelated files and configuration, used scoped maintenance, and independently verified each package. Its database-export-dependent path was not used.

The completed sequence was RC15 → RC16 → RC15 → RC16. Upgrade changed nine files and removed none. Rollback changed eight files and removed only the one integration file introduced by RC16, restoring the exact prior package. Reinstallation and final verification passed. Maintenance was cleared. Production's entire Pinova tree, child-theme source and configuration retained their pre-install fingerprints; test configuration and unrelated test-plugin/theme files were preserved.

## Native test-site acceptance

The configured checkout remained Blocks. A temporary process-only classic context exercised the published package without changing the checkout page or stored tax/gateway settings. Native BACS and temporary tax-option filters supplied the characterized acceptance context; this did not independently characterize the site's configured gateway. Mail and outbound WordPress HTTP were suppressed for the fixture; no payment, OTP issuance or SMS send was requested.

The first fixture run stopped before checkout assertions because its synthetic product author and anonymous CLI user were both zero. Dokan's native own-product restriction correctly made that fixture unpurchasable. Cleanup and original-setting restoration were independently checked. Assigning only the owned synthetic products to an existing administrator fixed the fixture without changing that account, impersonating it or bypassing native purchase rules.

The corrected run passed 23 assertions: Blocks detection/status, setting off/on, owned cart creation, normal/reduced fields, contact retention, virtual/mixed/physical/empty carts, customer-location taxation, native checkout validation, native order creation, contact/address snapshots, cleanup and original-setting restoration. Both temporary products and the single temporary order were removed. The option's originally absent key was restored, so the feature remains disabled.

Public home, checkout and account requests returned HTTP 200 with no critical-error markers. The inspected test web-error and PHP-FPM streams had no appended entries. The read-only Pinova issue report identified the RC16 build and reported a complete, untruncated range with zero observed rows/issues; its current threshold was warning and retention 14 days. These observations do not prove durable logging writes, request totals or absence of unobserved failures.

## Scoped maintenance review

The earlier authorized cleanup removed 24 exited containers, 20 dedicated volumes and four empty networks belonging to the five approved obsolete test projects, plus their one dedicated environment directory. The measured allocation before cleanup was 2,391,625,728 bytes; actual reclaimed filesystem space was not measured. Shared images, uncertain resources and worktrees were preserved. Subsequent inventory checks matched the preserved resource counts.

The test configuration permission correction changed mode 0666 to 0400 without changing contents. Its owner remained the native PHP/CLI service user and authorized reads still worked.

## Limits and next boundary

Native test-site acceptance and immediate post-install observations passed. Browser acceptance ran in isolated CI, not in the installed site's browser. Physical devices, payment processing, SMS receipt and a 24-hour canary were not exercised. Existing third-party Elementor PHP 8.4 deprecation and an unrelated previously unhealthy service remain outside this change; neither was modified.

RC16 remains a prerelease. Production installation is the owner's separate next action; classic field reduction remains opt-in, and an existing Blocks checkout should not be expected to reduce its fields.
