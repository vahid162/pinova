# Pinova v1.3.0-rc7 release evidence — 2026-10-03

## Outcome

[Implementation PR #58](https://github.com/vahid162/pinova/pull/58) merged the authentication-message and OTP-diagnostics corrections. [Release v1.3.0-rc7](https://github.com/vahid162/pinova/releases/tag/v1.3.0-rc7) is published as a prerelease, with native `immutable: true`. Publication is complete; production installation and server configuration changes were not performed.

## Production audit

The [October 3 read-only audit](production-log-audit-2026-10-03.md) inspected the installed RC6 package and 405 retained Pinova log rows. Logging was Info with 30-day retention. Since September 29 14:17:21 UTC, observed events included 13 OTP creations, 6 verifications, 3 registrations and sessions, 1 password success, 2 missing-record failures, 2 invalid-token failures, 1 reused-code rejection, 1 rate-limit warning and 2 native-only policy denials. Failure events are sampled, so these are not complete request counters.

The external WordPress cron runner executes every five minutes with automatic cron disabled, while signed OTP validity is 180 seconds from request. That interval can exceed a whole valid flow. Existing logs do not establish the cause of each failure. Recommend a separately authorized change to execute the site runner at least once per minute; this release surfaces the requirement without overriding the server or cron policy.

No new Pinova/WooCommerce database incompatibility was established. The bounded site error-log inspection found no Pinova stack mentions. A separate September 30 wpForo RecentTopics/Elementor fatal called `get_topics()` on null; it needs independent widget/lifecycle investigation and is not claimed fixed here.

## Corrections and verification

- Clear Persian authentication, recovery and mobile-proof messages; genuine support references are separate, correctly directed, and cleared on subsequent state changes.
- SMS test outcomes distinguish provider acceptance from receipt; email validity starts at request; partial installation failure copy acknowledges possible partial completion.
- Bounded queue observations, request-to-creation timing, remaining validity, authenticated token-expiry reason and valid signed-flow correlation. No raw OTPs, tokens, identifiers, exception traces or provider credentials are added to logs.
- Queue observations retain the fixed-slot/site budget of 100 per 15 minutes. Integration regressions prove issuance and scheduling continue when log-backend queries return false or throw.
- Development-only GHSA-ch52-4w7c-c8xp remediation uses an explicitly unofficial, exact upstream snapshot and its BSD license. Installed bytes, restricted-cache behavior and the unchanged audit threshold are verified. Npm/tooling dependencies remain outside the installable ZIP.

Ten initial specialist reviews, five supplement reviews and a focused fixture correctness review found no retained actionable source defect. A different-provider cross-model review did not run because no such CLI was installed. All 15 dependency-free JS test files and lightweight governance, syntax, metadata and instruction checks passed.

A duplicate browser run exposed an intermittent focus assertion. Passive diagnostic run [37119307184](https://github.com/vahid162/pinova/actions/runs/37119307184) proved Pinova restored focus to the live checkout link before WooCommerce's delayed initial added-to-cart notice took it. The fixture now awaits observable native notice focus before modal interactions. Product focus code and assertions were preserved. Corrected diagnostic [37119707953](https://github.com/vahid162/pinova/actions/runs/37119707953) passed all 12 repetitions; complete acceptance and modal fault-sensitivity passed afterwards.

## Provenance chain

| Record | Identity |
| --- | --- |
| Implementation branch | `fix/auth-error-clarity` |
| Reviewed PR head | `1781f58fad2964d9a9fec9b8eede5a88b5b4365a` |
| Exact-head PR Quality | [37119655741](https://github.com/vahid162/pinova/actions/runs/37119655741), success |
| Exact-head push Quality | [37119653347](https://github.com/vahid162/pinova/actions/runs/37119653347), success |
| Merged main / release commit | `8be95cc07ffe9603110d1407cac8f5d50aec523f` |
| Exact merged-main Quality | [37120123080](https://github.com/vahid162/pinova/actions/runs/37120123080), success |
| Publish branch | `publish/v1.3.0-rc7` at the same release commit |
| Exact publish-branch Quality | [37120616109](https://github.com/vahid162/pinova/actions/runs/37120616109), success |
| Publisher dispatcher | [37120994422](https://github.com/vahid162/pinova/actions/runs/37120994422), success |
| Publisher on release ref | [37120999092](https://github.com/vahid162/pinova/actions/runs/37120999092), success |
| Annotated tag object | `152fc5b3b39949588538c7512162939c44f54ace` |
| Annotated tag target | `8be95cc07ffe9603110d1407cac8f5d50aec523f` |
| Release | ID `402489983`; draft false, prerelease true, immutable true |

Quality includes PHP 8.1–8.5, eight WordPress/WooCommerce combinations, actual Dokan/wpForo browser flows with HPOS on/off, WordPress Plugin Check, dependency security, browser acceptance and modal fault-sensitivity. Push runs skip only the PR-specific Dependency Review; the PR run passed it.

## Assets and independent verification

| Asset | Bytes | SHA-256 |
| --- | ---: | --- |
| `pinova-1.3.0.spdx.json` | 97938 | `7740afd7fbcc813364a258416a41c1ddb495ad7198273fdba76fd3781affc025` |
| `pinova-1.3.0.spdx.json.sha256` | 89 | `4eac44508756611470665330ebd0acf7bfb2f726febd892b8cd2a7cc37109469` |
| `pinova-1.3.0.zip` | 5012563 | `4ab14e31f3de099839a92170e6e9d714f6b0083b717a9ff6a39039c50ea6acaf` |
| `pinova-1.3.0.zip.sha256` | 83 | `031465e511934a7b651f3cc19ac1da0840a4fa1066c2a214161960ef2c426e88` |

The publisher built the ZIP twice and compared both results. It verified release and all four release-asset attestations, and verified the ZIP's SPDX SBOM attestation against the exact source digest `8be95cc07ffe9603110d1407cac8f5d50aec523f`, source ref `refs/heads/publish/v1.3.0-rc7` and predicate `https://spdx.dev/Document/v2.3`. It redownloaded every published asset and compared all four against its build outputs.

A separate download on the development account verified all four API asset digests and sizes, both checksum files, ZIP CRCs, the single `pinova/` root, package-content exclusions, release build identity and the SBOM's exact inventory of 44 installed Composer packages. The installable artifact is [pinova-1.3.0.zip](https://github.com/vahid162/pinova/releases/download/v1.3.0-rc7/pinova-1.3.0.zip).

## Operational boundary

The release is ready for staging/canary evaluation, not promoted to stable/latest. Production was inspected at RC6; no deployment, database change, production test, SMS send, server runner change, service restart or third-party source modification was performed in this operation. Physical Android/iOS acceptance and a production canary remain separate gates. Other sites and server services were not changed.

## خلاصهٔ فارسی

نسخهٔ پیش‌انتشار `v1.3.0-rc7` پس از ادغام PR شمارهٔ ۵۸ منتشر و تغییرناپذیری آن تأیید شد. پیام‌های ورود و بازیابی روشن‌تر شده‌اند، کد پیگیری واقعی جداگانه نمایش داده می‌شود و ثبت محدودِ زمان صف، اعتبار باقی‌مانده و دلیل انقضای توکن برای عیب‌یابی بعدی اضافه شده است. همهٔ بررسی‌های الزامی، ساخت تکرارپذیر، گواهی‌های انتشار و تطبیق مستقل فایل‌های دانلودشده موفق بودند.

در بررسی فقط‌خواندنی سایت اصلی، ۴۰۵ ردیف گزارش با نگهداری ۳۰روزه وجود داشت و ناسازگاری تازه‌ای میان دیتابیس پینوا و ووکامرس اثبات نشد. اجرای پنج‌دقیقه‌ای cron از اعتبار سه‌دقیقه‌ای OTP طولانی‌تر است؛ تغییر آن به اجرای حداقل هر دقیقه، عملیات جداگانه‌ای است که انجام نشده است. خطای مشاهده‌شده در ابزارک RecentTopicsِ wpForo هنگام رندر Elementor نیز به بررسی مستقل نیاز دارد و رفع آن به این نسخه نسبت داده نمی‌شود.

بسته برای ارزیابی روی سایت آزمایشی و canary آماده است و هنوز stable/latest نیست. سایت اصلی، پایگاه داده، تنظیمات cron، افزونه‌های دیگر و سرویس‌های سرور در این عملیات تغییر نکردند.
