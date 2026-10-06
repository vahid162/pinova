# RC9 release and operational evidence — 2026-10-06

This dated record covers the immutable prerelease and authorized staged installation. Private logs, customer identifiers, credentials, database values and server backup paths are excluded. The prerelease was not promoted to stable/latest.

## Reviewed source and CI

- OTP worker: [PR64](https://github.com/vahid162/pinova/pull/64), reviewed exact head c2336b0a293f41ea00d44ff3e05408e3892be7de and successful [PR Quality 37419232270](https://github.com/vahid162/pinova/actions/runs/37419232270) / [branch Quality 37419227393](https://github.com/vahid162/pinova/actions/runs/37419227393); merge a46d1bc5e4a050b1c47a9b37ccfb1f6b7487afbf; independent [merged-main Quality 37420615480](https://github.com/vahid162/pinova/actions/runs/37420615480) succeeded.
- Native Dokan HPOS report correction and countdown-test repair: [PR65](https://github.com/vahid162/pinova/pull/65), final head 5e8664e9858ff7a2178705fa59d8a0b6ef8983a5; [PR Quality 37423916605](https://github.com/vahid162/pinova/actions/runs/37423916605) and [branch Quality 37423911419](https://github.com/vahid162/pinova/actions/runs/37423911419) succeeded.
- Final merged source fae0e0a6e214b50b58df5fbab6aa2765f1b9e4c1; independent [merged-main Quality 37425262166](https://github.com/vahid162/pinova/actions/runs/37425262166) succeeded. Applicable matrices/gates passed; event-inapplicable dependency review was skipped.
- Earlier [publish-branch Quality 37421346749](https://github.com/vahid162/pinova/actions/runs/37421346749) failed before publication on a preexisting 180-versus-179-second countdown assertion. The repaired test bounds signed expiry/countdown by the measured request interval and preserves uniform-response comparisons. No published history was altered.
- Final publish/v1.3.0-rc9 is the exact merged source. [Release-branch Quality 37426206239](https://github.com/vahid162/pinova/actions/runs/37426206239) and [exact-ref publisher 37426838794](https://github.com/vahid162/pinova/actions/runs/37426838794) succeeded.

## Immutable package

[Release v1.3.0-rc9](https://github.com/vahid162/pinova/releases/tag/v1.3.0-rc9): ID 404411640, native immutable=true, draft=false, prerelease=true. Annotated tag object 5616bee0f758bd4c88361a20699cf97714ab92af targets fae0e0a6e214b50b58df5fbab6aa2765f1b9e4c1. Header stays 1.3.0; build-info.json identifies RC9 and the exact source.

| Asset | SHA-256 |
|---|---|
| pinova-1.3.0.spdx.json | 00a75053308a50e26af7ea3077d2bcd7f032f914ecea00f265bd85bf6c584077 |
| pinova-1.3.0.spdx.json.sha256 | fcf21e213e240fd5fb7b3a0b8914dd8723905545f10ebf08291469ab0dc17158 |
| pinova-1.3.0.zip | 1c9a0b00d6811de72477df3fad5a96305da8bb13dbce3bff3e5e638fcf938455 |
| pinova-1.3.0.zip.sha256 | 9352af36a16c5eb4577f264cf2a42d82c4e87e29b286e8a1e61d089439c7a024 |

Publisher evidence: two builds under different timezone/umask conditions produced identical ZIPs; the SPDX SBOM matched all 44 production Composer packages. Every asset was downloaded again and compared. GitHub release/all four asset attestations and the signed SBOM attestation passed verification requiring the exact release source/ref.

Independent host evidence: fresh downloads of all four assets passed API digests, companion checksums, ZIP integrity, build identity, single plugin root, runtime allowlist and 44-package SBOM inventory. An additional local signature attempt could not retrieve the official Sigstore trust root (HTTP 403); that local check was neither claimed successful nor bypassed. Publisher cryptographic verification and host integrity verification are separate evidence.

RC8-to-RC9 comparison: 6 changed packaged files, 0 additions, 0 removals; all production vendor bytes unchanged. Changed paths are build-info.json, readme.txt, src/Integrations/Dokan/Load.php, src/Pinova.php, src/Services/OTPService.php and src/Services/RateLimitService.php. Development dependency overrides, native compatibility preload and tests are excluded from the package. The current official Dokan fixture archive changed only readme metadata; all 1,222 runtime files matched its predecessor.

## Defects and correction boundaries

The five-minute general cron could outlast three-minute signed OTP validity. Native wp pinova otp run selects only due one-shot Pinova delivery events with valid opaque flow arguments, requires successful unscheduling before dispatch, processes at most three and starts new jobs only within 20 seconds. Existing callback/provider/claim protections remain authoritative. Expired unclaimed ciphertext produces sampled queue_expired diagnosis without decrypting identity or sending an obsolete code. CLI counts mean processed jobs, not provider acceptance or phone receipt.

Dokan's native Order/Admin report callback appends posts.post_parent = 0 after WooCommerce builds its HPOS report from wc_orders AS orders. Pinova maps only that unquoted WHERE token to orders.parent_order_id for supported Dokan 5.1.3/5.2.1 and the exact HPOS FROM clause. Operators, values, other clauses, SQL literals and legacy CPT behavior remain intact, independently of routing switches. Native tests reproduced the original SQL failure and verified the repaired parent-order exclusion. No Dokan/wpForo/WooCommerce files were edited.

## Installed staging and main acceptance

The dedicated test site received the independently verified release, matching all 3,616 files with no extras. Native fixtures passed eligible/expired dispatch, no expired send, foreign-hook preservation and zero repeated jobs; actual Dokan HPOS reports; purpose-bound mobile proof and same-account normal/manual forum/vendor conversion; and actual Elementor RecentTopics rendering through query/pretty REST with routing off/on. Fixture processes blocked outbound HTTP/mail. Original settings were restored; all owned users/options/products/orders were verified absent.

No development tests ran on the main site. After staging passed, main received the same package; all 3,616 files matched, maintenance was cleared, schema stayed 3, forum/vendor routing stayed enabled, native administrator access remained available and native-login blocking stayed false. Public home/login/forum/cart/checkout, same-site forum/vendor login redirects, and both existing home-page REST render routes passed. UI/browser and real-plugin CPT/HPOS matrices ran in GitHub Actions rather than on the shared server.

The explicitly approved minute OTP runner uses www/PHP8.3, low CPU/I/O priority, its own overlap lock and the existing site cron lock. A 20-second delay yields first access to the email worker; limits are 512 MiB, 55-second timeout plus ten-second kill grace, at least 1 GiB available RAM and load below three. Output is discarded; the root-only status log rotates daily with seven archives and a 1 MiB threshold at normal rotation checks.

Natural execution window, Europe/Berlin: 2026-10-06T09:05:21+02:00 to 2026-10-06T09:10:26+02:00. Result-zero passes 6; site-lock deferrals 0; headroom deferrals 0; runtime range {'minimum': 4.0, 'maximum': 5.0}. Pending OTP cron was empty at validation. These passes establish scheduler execution, not end-to-end SMS receipt; busy-lock/resource deferrals can still exceed OTP validity.

Original general/email workers, guard, schedules, root crontab and 37 protected existing configurations retained their hashes/owners/modes. No shared-service reload/restart, global cache flush or other-site change occurred. Fresh main error streams in the final window showed no Pinova fatal, database error or legacy checkout warning.

## Conditional child-theme exception

The user preferred Pinova changes and conditionally allowed child functions.php if necessary. Repeated deprecations belonged to an existing child callback. Only its legacy get_product()/virtual accesses changed to native wc_get_product()/is_virtual(), with an exact single-file private backup and guarded rollback. Owner/mode were preserved; no other theme edits occurred.

Seven native WooCommerce staging field cases passed. An owned virtual-product fixture additionally passed native guest checkout order creation and manual BACS processing to on-hold, retaining phone/email and cleaning the order/product/seller. No funds transferred, online gateway or full shopper HTTP payment flow was claimed. Main cart/checkout checks passed and the legacy warning did not recur in the observation window.

## Log interpretation, recovery and limits

Review since October 4 found 14 queued flow groups, six recorded SMS provider-accepted outcomes, zero recorded provider failures and eight without an outcome. Three encrypted queue payloads had expired. Deliberate decoy/policy flows can also omit outcomes; all eight were not counted as defects. Provider acceptance does not prove phone receipt. No fresh real-recipient OTP test was sent in this task; the pending recipient authorization and physical receipt check remain open.

Direct existing-core/theme probes and direct-WAF duplicate-constant warnings were separated from normal Pinova requests. Truncated server error records do not establish a full call stack; the report defect was confirmed through native source and staging reproduction rather than attributed to checkout. A prior CLI mailer error belonged to separate email operations.

Private backups were integrity-verified before installation. Verified predecessor/current packages, installed verifier and guarded package/runner/child rollback helpers are retained privately. Main recovery retires only the new OTP schedule before restoring Pinova RC8 under maintenance, preserving current database/settings/orders. Whole-database restoration is not the default. Helpers were syntax-checked and retained, not executed. Canonical operations documentation records private recovery paths and final state separately.

## گزارش فارسی

نسخهٔ تغییرناپذیر RC9 پس از موفقیت بررسی کد، آزمون‌های GitHub، بررسی بسته و آزمون افزونه‌های واقعی روی سایت آزمایشی، روی سایت اصلی نصب شد. پینوا اکنون فرمان محدود اجرای OTP و ثبت نمونه‌برداری‌شدهٔ انقضای صف دارد. ناسازگاری ستون والد در گزارش HPOS دکان داخل پینوا اصلاح شد؛ فایل‌های دکان، wpForo و ووکامرس تغییر نکردند.

اجراکنندهٔ یک‌دقیقه‌ایِ ازقبل‌مجاز فقط کارهای OTP پینوا را با قفل مشترک سایت و محدودیت زمان و منابع اجرا می‌کند. اجراکننده‌های قبلی cron و ایمیل، ۳۷ فایل پیکربندی محافظت‌شده و سرویس‌های مشترک حفظ شدند. استثنای مشروط قالب فرزند فقط برای دو فراخوانی منسوخ ووکامرس استفاده شد و آزمون‌های فیلد و ایجاد سفارش با پرداخت دستی روی سایت آزمایشی موفق بودند.

پذیرش شش پیامک در لاگ سرویس‌دهنده ثبت شده بود؛ دریافت روی گوشی اثبات نشده است. پیامک آزمایشی تازه‌ای ارسال نشد و بررسی دریافت واقعی به پاسخ مجوز گیرنده وابسته است. موفقیت اجراکننده و تناوب یک‌دقیقه‌ای، تضمین دریافت پیش از انقضا نیست. پشتیبان‌ها و روش‌های بازگشت مستقل حفظ شده‌اند و بازگردانی کامل دیتابیس، روش پیش‌فرض بازگشت این نسخه نیست.
