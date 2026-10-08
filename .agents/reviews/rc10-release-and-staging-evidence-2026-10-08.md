# RC10 release and test-site evidence — 2026-10-08

This dated record covers Logs and Issues, the reviewed immutable prerelease, and installation on the authorized dedicated test site only. Production received no package or settings changes. Private logs, credentials, account identifiers, infrastructure coordinates and backup paths are excluded. The prerelease was not promoted to stable/latest.

## Reviewed source and verification

- Implementation: [PR67](https://github.com/vahid162/pinova/pull/67), final reviewed head `8747c3b6f8113cac0387a9d4569cb2c5328875c8`.
- [PR Quality 37786031983](https://github.com/vahid162/pinova/actions/runs/37786031983): all 29 jobs succeeded. [Branch Quality 37786023795](https://github.com/vahid162/pinova/actions/runs/37786023795): all 28 applicable jobs succeeded; event-inapplicable Dependency Review skipped.
- Merge/source `4a8754a8c6622b7f2763ae635aadff4940d97d96`; independent [merged-main Quality 37787670135](https://github.com/vahid162/pinova/actions/runs/37787670135) succeeded.
- Release branch `publish/v1.3.0-rc10` points to that exact merged source. Its successful publication evidence is recorded below.

The matrices cover PHP 8.1–8.5, WordPress/WooCommerce combinations and eight baseline/current/mixed Dokan/wpForo combinations with HPOS enabled and disabled. Real-plugin CLI and browser paths passed. The browser suite also passed its deliberate-fault sensitivity audit with whole-test retries disabled.

Earlier failed runs were preserved. Diagnostic-mode tests originally supplied an absolute deadline where settings expect a duration; corrected coverage asserts the saved future deadline and effective debug threshold. Browser fixtures now use asynchronous CLI calls. The actual navigation defect persisted with both Chromium renderers and was fixed by dequeuing the core cross-document view-transition style only on the Pinova Logs submenu, after core enqueues it and before styles print. Ordinary click/navigation assertions remain enabled.

Review found that a rejected review-action redirect could yield an empty response; the action now uses Pinova's controlled redirect helper, preserves the selected date range, and emits only finite diagnostic context. The suggested event-arrival race does not close new evidence: review watermarks cover only the observed IDs and any later event reopens the issue. Independent validation confirmed that boundary.

## Behavior and defect corrections

The existing Logs page becomes Logs and Issues. Its page, JSON download and read-only `wp pinova logs report` command share the bounded `pinova.issues.v1` report. Categories distinguish confirmed operational failures, expected rejections and insufficient evidence. An operational failure is not automatically a confirmed code defect. Missing queue outcomes remain inconclusive; provider acceptance is separate from physical SMS receipt.

Evidence uses finite event/reason catalogs, safe flow/correlation IDs and timing/build metadata. Reports expose date coverage, truncation, sampling, retention, effective logging level and uninspected external logs. Acknowledged and manually resolved states retain their evidence watermark; manual resolution is unverified, and recurrence reopens the issue. Capability, nonce, stale-report and stale-review protections cover review actions. Reports do not delete source events or autonomously change code, settings or deployments.

Logging corrections report cleanup database failures truthfully, retain bounded best-effort logger-health state, and record per-channel false/throw outcomes without provider text or secrets. OTP worker outcomes are sampled within a fixed budget. Native-login inclusion now imports WordPress's global username variable, preventing a fresh private-login GET from displaying an undefined-variable warning in the username field.

The observed TeraWallet 1.7.1 HPOS report defect is corrected inside Pinova. A narrow adapter maps only the native unquoted numeric `posts.ID NOT IN (...)` exclusion when WooCommerce builds the exact HPOS report query. SQL strings/comments, CPT behavior and unsupported versions remain untouched. The checksum-pinned real upstream callback reproduced the original database error and passed repaired recharge exclusion, routing opt-out and coexistence with Dokan report constraints. This is native report-callback coverage, not a full active-wallet payment test.

## Immutable package and independent download

[Release v1.3.0-rc10](https://github.com/vahid162/pinova/releases/tag/v1.3.0-rc10): ID 406900433, native immutable=true, draft=false, prerelease=true. Annotated tag object `8e10992044f3aa7555de31fe195214068bfaa039` targets `4a8754a8c6622b7f2763ae635aadff4940d97d96`. Header version stays 1.3.0; build-info.json identifies RC10 and the exact source.

[Release-branch Quality 37788805857](https://github.com/vahid162/pinova/actions/runs/37788805857) and [exact-ref publisher 37789755173](https://github.com/vahid162/pinova/actions/runs/37789755173) succeeded. Two builds under different timezone/umask conditions produced identical ZIPs. The publisher verified GitHub release/all four asset attestations and the signed SBOM with the exact release source/ref.

| Asset | SHA-256 |
|---|---|
| pinova-1.3.0.spdx.json | a7ee7a3eeafce1a2d7bd55920c08fee792a568f91d5d1276ed827e0e2ff4f7c1 |
| pinova-1.3.0.spdx.json.sha256 | 8683ebd49ec47de755615db27b09071958f1a0d37dfb035914af434f0dfa997e |
| pinova-1.3.0.zip | 6dcc7250fd03c21f5e8b4114224230c576bb77aa1faae7c82c4219d92c2ef969 |
| pinova-1.3.0.zip.sha256 | cd55883111e04c4671e11772164fa2bdee9959dcfb906cc5bb570ba0439a2777 |

Independent host downloads of all four assets passed API digests, companion checksums, ZIP integrity, exact build identity, runtime allowlist and the 44-package Composer SBOM comparison. Host verification establishes downloaded-byte integrity; cryptographic provenance verification ran in the publisher. No separate successful local signature verification is claimed.

RC9-to-RC10: 3,622 packaged files; 14 changed, six added, zero removed. The only changed vendor files are generated Composer classmap/static autoload files. The production Composer dependency inventory is unchanged; tests, development helpers and operational documentation are excluded from the ZIP.

## Test-only installation, rollback and acceptance

The dedicated test site received the independently verified RC10 ZIP: all 3,622 files matched, with no extras. A real package cycle RC9 → RC10 → RC9 → RC10 passed. Both upgrade and rollback preserved settings, original account identities, order count, original logs and schema 3. The final installation is RC10; the native-login gate remained off and maintenance was cleared. Private database/plugin backups and self-contained package rollback artifacts were verified and retained.

Installed acceptance used WordPress 7.1.3, WooCommerce 11.1.2, wpForo 3.2.2, Dokan 5.2.1 and Elementor 4.3.3. Checks passed for:

- Actual administrator HTTP page, acknowledgement, unverified manual resolution, recurrence reopening, nonce/capability rejection, redacted JSON download and parity with the report. An initial harness assertion read a stale negative-option cache after another HTTP request had saved the correct state. A fresh process confirmed database persistence; the harness now refreshes that cache, and the unchanged assertions passed. Its exactly identified leftover synthetic review option was removed. This was a test-process defect, not a product-state failure.
- Purpose-bound mobile proof, same-account native vendor conversion, preserved forum groups and native vendor/product approvals in normal/manual forum modes. A profile flag alone did not establish mobile proof, and mobile proof did not fabricate email confirmation.
- The native wallet report callback, original HPOS failure reproduction, corrected recharge exclusion, opt-out and coexistence with Dokan constraints. The expected deliberate failure occurred before the clean observation baseline.
- Actual Elementor RecentTopics output through both query and pretty REST URLs with integration routing off/on. The owned fixture page was removed and original routing settings restored.
- The registered OTP command with an empty schedule, unchanged cron contents, and fresh private-login GET with an empty username and no PHP warning.
- Public home, Pinova login and forum HTTP 200 responses; forum/vendor login routes returned same-site Pinova redirects. This does not represent a new real shopper payment or physical-SMS test.

All owned fixture accounts, pages and review/REST options were verified absent. Original account identity/settings hashes and the zero-order baseline matched. All 29 original log records retained identical bytes. Two legitimate settings-audit events remain as evidence of the temporary acceptance switches. The installed CLI report returned complete, untruncated coverage with database logging readable, cleanup successful and not overdue, no fallback attempt and no issues. Its explicit test-site coverage was warning-level/14-day retention, unchanged from site settings.

Byte comparisons also preserved every backed-up wpForo (682), Dokan (1,223) and Elementor (3,429) file. Read-only production comparison still matched all 3,616 RC9 files, with zero mismatches or extras and maintenance absent. No production package, settings, theme or scheduler change occurred.

Post-fixture observation, Europe/Berlin: 2026-10-08T16:17:48+02:00 to 2026-10-08T16:33:09+02:00 (15 minutes 21 seconds). The installed issue report remained complete and untruncated with no classified issues. No new Pinova, database, RecentTopics or fatal error was recorded. The WooCommerce fatal stream had zero new bytes; the server error stream had 1,182 new bytes containing the four pre-existing Elementor warnings described below. Neither stream rotated nor exceeded the scan bound. This is a measured acceptance window, not a guarantee that all future requests will succeed.

## Observed production evidence and remaining boundaries

Production was inspected read-only. In the fixed 24-hour window ending 2026-10-08 15:01:12 Europe/Berlin, 24 retained Pinova events included five queued flows with five recorded provider-accepted outcomes. No Pinova warning/error was recorded. Queue-to-recorded-outcome delays were 7–60 seconds, with 120–173 seconds of validity remaining. This does not establish phone receipt or absence of unlogged faults. One older flow since RC9 lacked an outcome and remains insufficient evidence, not a proven delivery defect.

Three observed native wallet report errors motivated the adapter above. Three separate production Wordfence duplicate-constant warnings belong outside this Pinova-only procedure and were not modified. No recent Pinova fatal or RecentTopics failure was found in the inspected production window.

The broader test-site observation found four Elementor PHP warnings across the public home/forum probes: `core/base/document.php:356` reads property `ID` from null. Historical read-only comparison found the same warning before installation and as early as September 27; it is not a new RC10 regression. Both probed pages still returned HTTP 200. Elementor document/kit state needs a separate investigation; no Elementor code or document configuration was changed to suppress the warning. This external warning means the whole site is not described as error-free even though the Pinova acceptance gates passed.

Original schedulers, theme, other plugins and shared services were not changed by this task. No real SMS/email test was sent. Physical mobile-device acceptance and actual SMS receipt are not newly established by the CI/staging checks.

## گزارش فارسی

نسخهٔ RC10 پس از موفقیت بازبینی، آزمون‌های نسخهٔ نهایی و شاخهٔ اصلی، ساخت تکرارپذیر و بررسی گواهی‌های انتشار در GitHub منتشر شد. دانلود مستقل هر چهار فایل، چک‌سام‌ها، محتوای بسته و فهرست ۴۴ وابستگی را تأیید کرد. این نسخه فقط روی سایت آزمایشی نصب شد؛ سایت اصلی همچنان RC9 است و انتشار به stable/latest ارتقا نیافت.

صفحهٔ «لاگ‌ها و مشکلات» شواهد را بین صفحهٔ مدیریت، JSON و فرمان فقط‌خواندنی WP-CLI مشترک می‌کند. خرابی مشاهده‌شده، رد درخواست مطابق کنترل‌ها و شواهد ناکافی از هم جدا هستند. اعلام رفع دستی به معنای اثبات رفع نیست و رخداد تازه مشکل را دوباره باز می‌کند. وضعیت سلامت ثبت لاگ، پاک‌سازی و محدودیت پوشش نیز گزارش می‌شود. این قابلیت کد یا تنظیمات را خودکار اصلاح نمی‌کند.

ناسازگاری گزارش HPOS کیف پول، تشخیص شکست پاک‌سازی و ارسال کانال، هشدار صفحهٔ ورود خصوصی و مشکلات هدایت/انتقال تصویری صفحهٔ مدیریت در خود پینوا اصلاح شدند. آزمون واقعی نصب و بازگشت RC9 → RC10 → RC9 → RC10، بررسی HTTP صفحهٔ مشکلات، هویت موبایلی و تبدیل حساب دکان/wpForo، گزارش کیف پول و ابزارک واقعی RecentTopics موفق بودند. خطای اولیهٔ ابزار آزمون به کش قدیمی همان فرایند مربوط بود؛ ذخیرهٔ افزونه درست بود و پس از اصلاح ابزار، همان بررسی‌ها موفق شدند.

حساب اصلی سایت آزمایشی، تنظیمات، سفارش‌ها و ۲۹ لاگ قبلی حفظ شدند؛ داده‌های موقت آزمون پاک‌سازی و دو رخداد معتبر ممیزی تنظیمات نگه‌داری شدند. فایل‌های دکان، wpForo و المنتور تغییر نکردند. هشدار جداگانهٔ تعریف تکراری ثابت در Wordfence سایت اصلی و چهار هشدار خواندن شناسهٔ سند از مقدار null در المنتور سایت آزمایشی، خارج از این تغییرات باقی ماندند. سابقهٔ همان هشدار المنتور از ۲۷ سپتامبر وجود دارد و حاصل RC10 نیست؛ با وجود موفقیت آزمون‌های پینوا، کل سایت بی‌خطا توصیف نمی‌شود. پیامک یا ایمیل واقعی ارسال نشد؛ دریافت روی گوشی، آزمون دستگاه فیزیکی و پرداخت کامل کیف پول با این بررسی‌ها اثبات نمی‌شوند.
