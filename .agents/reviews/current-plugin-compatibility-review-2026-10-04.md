# Current Dokan/wpForo compatibility review — 2026-10-04

This dated evidence records the implementation review for [PR #60](https://github.com/vahid162/pinova/pull/60). It does not grant installation authority or represent unrestricted compatibility with future plugin versions.

## Diagnosis

A September 30 retained WooCommerce fatal record and the matching HTTP 500 access-log entry identified Elementor rendering wpForo RecentTopics during a query-based WordPress REST request. wpForo's base member service existed, but its topic service was null. Its URL detection schedules full initialization for pretty REST URLs and otherwise waits for the frontend `wp` hook. WordPress dispatches query-based REST during `parse_request`, before that hook. Elementor's widget `should_render` filter runs after the content rendering that fails, so hiding the widget there does not repair the lifecycle.

The main site's manually installed RC7 had wpForo 3.2.2, Dokan Lite 5.2.1, Elementor 4.3.3 and WooCommerce 11.1.2. RC7's exact integration allowlists rejected those forum/vendor versions. Both production integration switches were off, and the bounded preflight found no seller accounts or integration-owned membership/onboarding markers. No new Pinova database incompatibility was established.

## Review and resulting behavior

The primary agent performed a focused manual review of the final runtime diff, real-plugin fixtures, browser assertions, dependency selection and packaging boundaries. Ponytail's native-first guidance informed the small runtime change; no new dependency or abstraction was introduced. GitHub's automatic Codex review completed on the initial implementation commit with no inline findings; the follow-up changed only formatting, the development analysis stub and isolated fixture memory configuration. No separate human or different-provider review is claimed.

- Pinova runs supported wpForo's native initialization only during a successful REST authentication path with a missing topic service. Upstream `WP_Error` results and already initialized services remain intact; forum login routing cannot redirect REST requests. This repair is independent of the integration switches.
- The shared exact allowlist supports wpForo 3.2.1/3.2.2 and Dokan Lite 5.1.3/5.2.1. Dokan eligibility also fails closed when a loaded forum version is unsupported, preserving the characterized seller-role callback boundary. Dokan Pro remains outside this support profile.
- Existing purpose-bound mobile proof, immutable account ID/username, manual forum holds, truthful email status, native seller conversion and selling/product approvals remain in force. Only the identified forum role-sync callback is scoped during the authorized native seller transition and then restored.
- The existing Pinova integration settings tab remains the management surface. No new menu or third-party source patch is required.

## Validation

Reviewed final head: `b9ec5b0e57d381b70d743dc8f1eb3918b09d8b8f`. Exact-head [PR Quality 37190873746](https://github.com/vahid162/pinova/actions/runs/37190873746) and [push Quality 37190871806](https://github.com/vahid162/pinova/actions/runs/37190871806) both passed. PR #60 merged as `b7ac04f423ccda0b9e0b556ac493805b44adfb3f`; its separate [main Quality 37191500101](https://github.com/vahid162/pinova/actions/runs/37191500101) passed.

The isolated suite covered all four old/current wpForo/Dokan pairings with HPOS on/off, actual Elementor RecentTopics output through both REST URL forms with routing off/on, purpose-bound mobile proof and native vendor/forum approval/group preservation. It also passed PHP 8.1–8.5, eight WordPress/WooCommerce configurations, WordPress Plugin Check, package/SBOM checks, browser acceptance and modal fault sensitivity, dependency security and project guidance. Push runs skipped only PR-specific Dependency Review; the PR run passed it.

The first implementation run exposed a development stub that typed wpForo as `stdClass` and a 128 MB CLI fixture limit after Elementor was added. The corrected stub models native `init()`; only the disposable third-party fixture configuration receives bounded 256/512 MB WordPress memory limits. No host PHP configuration changed and no assertions were weakened. Lightweight syntax, all 15 source JS test files, metadata, changelog, governance and semantic guidance checks passed locally; heavy testing/builds ran on GitHub Actions.

## Boundaries

Open dependency-update PRs require their own refreshed lockfile/security/regression review and are unrelated to this release. Historical open documentation PRs were inspected but not merged blindly. Native-login blocking is outside this activation operation. Physical SMS delivery, mobile-device acceptance and the previously documented external cron interval are distinct from these synthetic integration checks. Release, staging and deployment evidence is recorded separately after those gates complete.

Instruction snapshots at the reviewed merged commit:

| Source | SHA-256 |
| --- | --- |
| `AGENTS.md` | `85d0aaba67aa1902c0d176d1b9f380fe74805333e53aa42eeb2d2d2539d4742b` |
| Pinova skill entrypoint | `304dd0ac3097a4fe31595dcf8c184d496fa88c4dff4101578838cf9d9b9e8196` |
| Project map | `172493bbae2ee6220bcec5229b6825571c0ded45ee2c4eb187457b1ddd342c65` |
| Quality/release reference | `7e825c451883e6da4573d0e09382c917f23d4a1a4c93b984c4986ddf7f86a0ad` |

## خلاصهٔ فارسی

علت خطای RecentTopics، راه‌اندازی‌نشدن سرویس موضوع‌های wpForo پیش از رندر Elementor در درخواست REST با پارامتر `rest_route` بود. اصلاح داخل پینوا، چرخهٔ بومی wpForo را در زمان مناسب اجرا می‌کند و خطاهای دسترسی و سرویس‌های موجود را حفظ می‌کند. پشتیبانی دقیق از چهار ترکیب نسخه‌های قدیم و فعلی wpForo و دکان لایت، با HPOS روشن و خاموش، تأیید شد؛ تأیید موبایل، محدودیت مدیر، وضعیت واقعی ایمیل، گروه‌های انجمن و قواعد تأیید فروشنده حفظ می‌شوند.

PR شمارهٔ ۶۰ و اجرای مستقل CI روی main موفق بودند. کد افزونه‌های دیگر و تنظیمات سرویس‌های سرور تغییر نکردند. مدیریت از تب هماهنگی موجود انجام می‌شود. بررسی دریافت واقعی پیامک و فاصلهٔ اجرای cron همچنان مرز عملیاتی جداگانه دارند؛ شواهد انتشار و نصب در گزارش مستقل ثبت می‌شوند.
