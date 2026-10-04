# Pinova v1.3.0-rc8 release evidence — 2026-10-04

[Release v1.3.0-rc8](https://github.com/vahid162/pinova/releases/tag/v1.3.0-rc8) is an immutable prerelease. It repairs the native wpForo REST lifecycle during Elementor RecentTopics rendering and adds exact support for the currently installed Dokan/wpForo versions. See the [implementation review](current-plugin-compatibility-review-2026-10-04.md) for diagnosis and scope. Publication is verified; operational acceptance is recorded below as it completes. This is not a stable/latest promotion.

## Provenance chain

| Record | Identity |
| --- | --- |
| Implementation branch / PR | `fix/current-plugin-compatibility`; [PR #60](https://github.com/vahid162/pinova/pull/60) |
| Reviewed final head | `b9ec5b0e57d381b70d743dc8f1eb3918b09d8b8f` |
| Exact-head PR Quality | [37190873746](https://github.com/vahid162/pinova/actions/runs/37190873746), success |
| Exact-head push Quality | [37190871806](https://github.com/vahid162/pinova/actions/runs/37190871806), success |
| Merged main / release source | `b7ac04f423ccda0b9e0b556ac493805b44adfb3f` |
| Exact merged-main Quality | [37191500101](https://github.com/vahid162/pinova/actions/runs/37191500101), success |
| Publish branch | `publish/v1.3.0-rc8` at the same source commit |
| Exact publish-branch Quality | [37191920955](https://github.com/vahid162/pinova/actions/runs/37191920955), success |
| Publisher dispatcher | [37192299134](https://github.com/vahid162/pinova/actions/runs/37192299134), success |
| Publisher on release ref | [37192302708](https://github.com/vahid162/pinova/actions/runs/37192302708), success |
| Annotated tag object | `6a80c0b3dfc1ca4b8165ded3c8caa31b95338ee9` |
| Annotated tag target | `b7ac04f423ccda0b9e0b556ac493805b44adfb3f` |
| Release API | ID `402942695`; draft false, prerelease true, native immutable true |

The publisher built the ZIP twice under different environment inputs and compared bytes. It verified GitHub release and all four asset attestations, verified the SPDX SBOM attestation against this exact source digest and `refs/heads/publish/v1.3.0-rc8`, and redownloaded all four assets for comparison with the build outputs.

## Independent download verification

| Asset | Bytes | SHA-256 |
| --- | ---: | --- |
| `pinova-1.3.0.zip` | 5012968 | `bd218a81223f71023fb4613b24bef9acf1ef0f81c7f3af13774a9fe374a5f3b7` |
| `pinova-1.3.0.zip.sha256` | 83 | `fb45b174c073742ad45ee8554e066ce05d491303741b0ebe06334c89fb46384b` |
| `pinova-1.3.0.spdx.json` | 97938 | `07781588c03b855590b576caef4ee4b295761aa85e46d50b34b1dab289d23b35` |
| `pinova-1.3.0.spdx.json.sha256` | 89 | `eca40c2542cada340ebc0eac03515d6f51210d34921be4e3497872ad7d7cfbd8` |

A separate development-account download verified the API digests and sizes for all four files, both checksum files, ZIP CRCs and the single `pinova/` root, package allowlist/exclusions and exact build identity. The independently downloaded SBOM matched all 44 installed Composer package names and versions. The installable package is [pinova-1.3.0.zip](https://github.com/vahid162/pinova/releases/download/v1.3.0-rc8/pinova-1.3.0.zip).

## Staging acceptance

The user designated microbeauty.ir as a dedicated test site and authorized the integration implementation and deployment scenario. They separately approved private database and plugin backups for this site and the main site. The test site's export and installed Pinova/wpForo/Dokan/Elementor archive were verified before installation; no `wp-config.php` was copied. Rollback was defined, site-only maintenance was cleared, prior directory ownership was preserved, and every installed Pinova file was compared with the published ZIP.

The separately approved production database and installed Pinova backup also completed: access-restricted export/archive, verified dump completion, archive readability, sizes and SHA-256 manifest. The production backup is retained privately and is not included in Git or these public reports. Its creation did not install or configure the production plugin.

The test site was updated from RC6 to RC8, wpForo 3.2.1 to 3.2.2, Dokan Lite 5.1.3 to 5.2.1 and Elementor 4.3.2 to 4.3.3 using checksum-verified official packages. WordPress 7.1.2, WooCommerce 11.1.2 and HPOS enabled remained in place. Elementor Pro 4.3.0 was observed on staging, while production has 4.3.1; no third-party source patch was made.

Bounded acceptance with temporary synthetic accounts passed normal and manual-approval scenarios: profile flags do not establish possession; purpose-bound OTP verification does; invalid nonces never convert; native seller conversion retains the User ID/username, truthful empty-email status, forum primary/secondary groups and manual hold, and manual selling/product approval remains intact. Native forum role callbacks are restored. Mail and outbound HTTP were suppressed in those CLI processes, and owned accounts/OTP records were removed. This does not prove real SMS delivery or browser cookie receipt; isolated CI separately covers actual browser proof/conversion.

Two acceptance-tool issues were diagnosed rather than attributed to Pinova: root CLI selected FTP for Elementor's native filesystem upgrade, resolved by running as the site's actual filesystem owner; progress output committed PHP headers before later redirects, resolved by buffering output without weakening assertions.

Actual public REST widget acceptance is pending: the staging PHP 8.4 runtime exhausts its 256 MB memory budget during Elementor Pro menu-cart rendering and emits fatal HTML with HTTP 200/application-json headers. The independently green CI fixtures allow a bounded 512 MB maximum; production PHP 8.3 allows 512 MB. Neither WordPress memory constant is explicitly configured on staging. A concrete, test-site-only adjustment to those two constants was submitted for approval because the user's Pinova-only change constraint does not clearly authorize this separate configuration operation. No shared PHP configuration or service has been changed. Each failed REST probe removed its own fixture page and restored integration settings.

## Operational boundary

Production installation remains held until the actual staging REST widget assertions pass. RC8 publication does not override that gate. Native-login blocking, shared services and other sites remain unchanged. No SMS was sent in this operation. Physical device/SMS acceptance and the previously documented production five-minute external cron interval remain separate operational boundaries.

## خلاصهٔ فارسی

پیش‌نسخهٔ `v1.3.0-rc8` پس از موفقیت بررسی‌های PR، main و شاخهٔ انتشار منتشر شد و تغییرناپذیری، ساخت تکرارپذیر، گواهی‌ها، checksumها و دانلود مستقل آن تأیید شدند. اصلاح چرخهٔ RESTِ wpForo و پشتیبانی دقیق از نسخه‌های فعلی دکان و انجمن، داخل پینوا انجام شده است.

بسته روی سایت آزمایشی نصب و فایل‌های آن با ZIP منتشرشده تطبیق داده شدند. بررسی‌های هویت در حالت عادی و تأیید دستی موفق بودند و گروه‌ها، هویت حساب، وضعیت واقعی ایمیل و قواعد تأیید فروشنده حفظ شدند. رندر عمومی REST به محدودیت حافظهٔ ۲۵۶ مگابایتی سایت آزمایشی برخورد کرد؛ برای تنظیم دو ثابت حافظه فقط در همین سایت، اجازهٔ جداگانه درخواست شد. تا موفقیت این مرحله، نصب روی سایت اصلی انجام نمی‌شود. کد افزونه‌های دیگر، سرویس‌های مشترک و سایت‌های دیگر تغییر نکرده‌اند و پیامکی ارسال نشده است.
