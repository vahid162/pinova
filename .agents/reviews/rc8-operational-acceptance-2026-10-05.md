# RC8 staging acceptance and production integration activation — 2026-10-05

This supplements the immutable historical [October 4 release evidence](rc8-release-evidence-2026-10-04.md). The user explicitly approved the two staging WordPress memory constants after the previous report identified that separate configuration boundary. The existing integration implementation, private backup payloads and production activation remained authorized. No new release or source change was needed.

## Package and repository reconciliation

The [immutable RC8 prerelease](https://github.com/vahid162/pinova/releases/tag/v1.3.0-rc8) still targets `b7ac04f423ccda0b9e0b556ac493805b44adfb3f`. Its ZIP digest remains `bd218a81223f71023fb4613b24bef9acf1ef0f81c7f3af13774a9fe374a5f3b7`; all four public asset digests agree with the prior independent verification. This operation did not alter its tag, assets or prerelease status. Publication provenance, attestations, isolated compatibility/browser coverage and the two successful synthetic staging identity cases remain recorded in the previous report.

Remote `main` was reconciled at `ee1177084c39b73462c1ebcb1294cfd5aca0b5b5`, with successful merged-main Quality run [37194919205](https://github.com/vahid162/pinova/actions/runs/37194919205). Dependency PRs #62, #56 and #42 require independent scope, lockfile/security and regression review; they do not change this installed immutable package. Older evidence PRs #55 and #51 remain historical backlog, not a reason to overwrite merged evidence. This supplement is isolated on `docs/rc8-operational-acceptance-20261005`.

## Staging memory and actual REST acceptance

Only microbeauty.ir received `WP_MEMORY_LIMIT = '512M'` and `WP_MAX_MEMORY_LIMIT = '512M'` through native WP-CLI configuration commands. Both were rechecked as absent before addition; configuration ownership and permissions were preserved. A private nonsensitive rollback record documents removal of only these two additions. No configuration file or credentials were copied, and no shared PHP-FPM/Nginx setting was changed or service restarted.

The unchanged actual Elementor RecentTopics fixture assertions then passed:

| Integration routing | Query REST | Pretty REST |
| --- | --- | --- |
| Disabled | HTTP 200, valid JSON, actual widget output; 6.33 s | HTTP 200, valid JSON, actual widget output; 7.60 s |
| Enabled | HTTP 200, valid JSON, actual widget output; 11.39 s | HTTP 200, valid JSON, actual widget output; 10.67 s |

The owned fixture page was deleted and original integration settings restored. Read-only cleanup confirmed zero owned synthetic accounts, no fixture option and no maintenance marker. Installed identity remains RC8, WordPress 7.1.2, WooCommerce 11.1.2, wpForo 3.2.2, Dokan Lite 5.2.1, Elementor 4.3.3, schema 3 and HPOS enabled. Elementor Pro remains 4.3.0 on staging versus 4.3.1 on production; identical addon coverage is not claimed.

A separate 12-second homepage probe timed out. One subsequent bounded probe with a 30-second per-request ceiling preserved all response, fatal-error and routing assertions: home/account/forum returned HTTP 200, the Pinova login card was present, and forum/vendor login paths returned same-site Pinova HTTP 302 redirects. Measured page times were 3.72–6.28 seconds; redirects were 13.30 and 17.78 seconds. Functional acceptance passed, but these measurements do not establish a performance improvement or resolve intermittent latency.

## Production state and activation

A fresh production database plus installed-Pinova backup was completed inside the already approved private destination, preserving the previous backup. Access restrictions, SQL completion, archive readability, sizes and SHA-256 manifest were verified. No `wp-config.php` was copied. Backup material stays outside Git and public artifacts.

Production preflight found RC8 already installed, with both integration switches off. A 35-second plugin-loaded CLI read timed out; one bounded 60-second read completed and returned the expected versions and administrator capability. No bootstrap defect was established by that timeout. All 3,616 installed Pinova files matched the independently verified release ZIP, with zero mismatches and zero unexpected files. Therefore no redundant production installation or maintenance activation occurred.

Using an existing administrator actor and native WordPress option persistence, only `wpforo_enabled` and `dokan_enabled` changed from `0` to `1`. Both report `active`. Schema 3, HPOS, native-login settings, administrator capability, logging at info with 30-day retention, disabled automatic cron and scheduled cleanup were preserved. Native-login blocking remains disabled; no clean-session administrator/2FA test is claimed.

Read-only production smoke checks used existing public pages, without synthetic accounts/pages or provider calls:

| Check | Result |
| --- | --- |
| Home | HTTP 200; 0.36 s |
| Pinova account | HTTP 200; login card present; 8.14 s |
| Forum | HTTP 200; 14.08 s |
| Forum login / vendor dashboard | HTTP 302 to same-site Pinova account; 6.40 / 4.68 s |
| Existing public homepage, query REST | HTTP 200; valid JSON; forum widget output present; 5.08 s |
| Existing public homepage, pretty REST | HTTP 200; valid JSON; forum widget output present; 12.52 s |

The production REST smoke checks demonstrate existing page rendering; they do not substitute for the isolated real-widget regression and staging fixture assertions. No automated development suite ran against production.

## Observation and review

The post-activation observation exceeded five minutes. The final bounded log reads completed after 11:21 CEST (Europe/Berlin). Since the pre-activation baseline, Pinova's database log contained one `settings.updated` notice and no warning/error events; the site's server error stream had no new fatal/uncaught, Pinova or RecentTopics matches.

WooCommerce captured one exception from a standalone WP-CLI `eval` command at 11:13:47 CEST: the PHPMailer class was unavailable. Its trace contains `Eval_Command->__invoke`, no Pinova/Dokan/wpForo frame and none of this operation's `eval-file` acceptance helpers. It is a CLI-only diagnostic failure; no attribution to the integration or its public rendering is established. An initial signature counter matched both `Uncaught` and the error type in that single record; the local observation helper was corrected to count records once. No global mail configuration or plugin code was changed to mask that unrelated command failure.

The supplement was manually reviewed against captured package, site-state, REST and redacted log summaries. Lightweight AI governance, changelog synchronization, plugin metadata, skill synchronization and whitespace checks passed. No runtime/instruction contract changed, so no ceremonial skill or changelog edit was made. Full compatibility/browser/build evidence remains tied to the immutable release rather than rerunning development suites on the shared live host.

## Rollback and remaining boundaries

Configuration rollback removes only the two new staging constants. Production activation rollback restores the two prior integration values, both `0`; verified private plugin backups are available if a separate binary rollback becomes necessary. A full production database restore is not the default rollback and would require separate coordination of current orders/account writes.

No code in Dokan/wpForo/Elementor was patched. Other sites, shared services, cron scheduling and global caches were unchanged. No SMS was sent. Physical-device acceptance, real SMS receipt and the previously documented five-minute external production cron interval remain separate operational boundaries. RC8 remains a prerelease.

## خلاصهٔ فارسی

با اجازهٔ صریح کاربر، فقط دو ثابت حافظهٔ وردپرس سایت آزمایشی روی 512M تنظیم شد. هر چهار آزمون واقعی REST ابزارک RecentTopics در حالت خاموش و روشن بودن مسیریابی موفق بود و داده‌های موقت پاک شدند. بعضی درخواست‌ها همچنان کند بودند؛ موفقیت عملکردی به معنای رفع مشکل کارایی نیست.

در سایت اصلی، RC8 از قبل نصب بود و تمام ۳٬۶۱۶ فایل آن با بستهٔ انتشار مطابق بودند؛ بنابراین نصب دوباره انجام نشد. پس از پشتیبان خصوصی تازه، فقط دو گزینهٔ هماهنگی پینوا با انجمن و دکان فعال شدند. صفحات موجود، انتقال‌های ورود و هر دو قالب REST صفحهٔ اصلی موفق بودند. مسدودسازی ورود بومی، تنظیمات لاگ و زمان‌بندی cron حفظ شدند. کد افزونه‌های دیگر، سایت‌های دیگر و سرویس‌های مشترک تغییر نکردند و پیامکی ارسال نشد.

پس از بیش از پنج دقیقه بررسی، لاگ پینوا فقط تغییر تنظیمات را بدون هشدار یا خطا ثبت کرده بود. یک خطای جداگانهٔ فرمان `eval` در WP-CLI به نبود کلاس PHPMailer مربوط بود؛ مسیر اجرای آن شامل افزونه‌های هماهنگی یا ابزارهای آزمون این عملیات نبود. شمارش اولیهٔ دو امضا برای همان یک رکورد نیز در ابزار محلی اصلاح شد.
