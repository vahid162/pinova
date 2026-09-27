# U1 integration baseline preparation

This records a local development increment, not completion of U1 or approval for installation.

- Branch: `feat/integration-baseline`.
- Base: `ff731a561253d2f8867e7a1e0c5ccf908c05a5c0`, verified remote `main` when work started.
- Runtime source remains unchanged. Changes are confined to Pinova's test tooling, CI, and development guidance.
- Product constraint: mobile verification remains the identity foundation. A mobile flow must not falsely establish email ownership. All eventual adapters belong in Pinova.

## Prepared behavior matrix

These expected observations come from source inspection. Their real-plugin runtime assertions are prepared but have not yet run on isolated CI.

| Scenario | Baseline expectation | Prepared coverage |
| --- | --- | --- |
| Inactive wpForo member, manual approval off | Pinova's cookie hook runs before `wp_login`; wpForo activates the member without confirming email | Separate synthetic WP-CLI scenario |
| Inactive wpForo member, manual approval on | Cookie hook has already run when wpForo redirects to its denial page and exits | Separate process; requires redirect, inactive status, cleanup, and completion marker |
| WordPress password reset, member has no email | wpForo sets `is_email_confirmed` to 1 and activates the member when manual approval is off | Real `reset_password()` and registered callback |
| Convert subscriber/contributor to Dokan vendor | Existing roles are replaced by seller; WordPress login remains unchanged | Real Dokan conversion function |
| Seller account required fields | Current Pinova filter removes email from WooCommerce's required fields | Real registered filter |

The cookie assertions observe WordPress hooks. They do not prove HTTP cookie transmission, browser session behavior, a complete OTP flow, or a registration/recovery policy fix.

## Reproducibility and boundaries

`tests/fixtures/third-party-baseline.json` pins WordPress 7.1.2, WooCommerce 11.1.2, wpForo 3.2.1, and Dokan Lite 5.1.3. The two third-party archive SHA-256 values were calculated from the exact official downloads on this date. Pinning detects later byte changes; it is not publisher attestation or proof that an installed directory has identical bytes.

The installed `wpforo.php` and `dokan.php` entrypoints separately matched their respective archive entrypoints by SHA-256. This checks those two files only, not the complete installed trees.

The opt-in fixture preparer verifies both archives before extracting either. The new CI job activates the real plugins on its disposable wp-env installation. Synthetic account scenarios suppress WordPress mail and HTTP requests; no SMS credentials or production data are supplied. The runner refuses any environment outside its exact GitHub run, and PHP independently checks the local loopback environment marker. Cleanup reuses the existing run-scoped procedure.

## Local verification

- Nine fixture/configuration tests and two runner-boundary tests passed with the available Node 20.20.2 binary; the repository's required Node 24 CI validation is still pending. Failure coverage includes a corrupted second archive before any extraction, extraction errors, missing entrypoints, and refusal to reuse incomplete preparation.
- JavaScript syntax lint passed for all 28 first-party files.
- PHP 8.3 syntax check and shell syntax check passed for the new scenarios/runner.
- AI governance, changelog synchronization, instruction-contract synchronization, and whitespace checks passed.
- No dependency install, Docker environment, full PHP analysis, browser suite, release build, commit, push, or deployment was performed for this snapshot.

Code review completed with no actionable source findings (run `20260927-152345-0328f9fa`). Its verdict is **not ready for merge** because the required real-plugin CI has not run. It reviewed the ten implementation/guidance files, including the added failure-path test; this progress note was outside its code-review scope. The simplification pass consolidated PHP version checks onto the fixture manifest. A proposed extra exception wrapper around the receipt read was declined because it would add complexity without changing the fail-closed result.

## Outstanding U1 exit gates

1. Execute and diagnose the real-plugin CI job; record the exact tested commit and results.
2. Add real HTTP/browser characterization of direct login/register submissions, recovery, vendor conversion, and account-save behavior. Observe cookies and responses, not only hooks.
3. Identify the exact installed Pinova package/source identity, then test its upgrade with synthetic data. A version header alone is insufficient.
4. Reproduce delayed queue dispatch versus flow expiry and disabled/publicly blocked cron on isolated staging.
5. Complete the behavior matrix and confirm the mobile-policy constraints before U2–U7 change runtime behavior.

The planned U2 interface is an Integrations section inside Pinova settings, with independent wpForo/Dokan controls initially off and dependency/compatibility status. This increment does not add that interface.

## خلاصهٔ فارسی

این سند پیشرفت اولیهٔ توسعهٔ محلی را ثبت می‌کند؛ U1 هنوز کامل نشده و مجوز نصب یا انتشار صادر نشده است. ابزار آماده‌سازی بسته‌های دقیق، بررسی چک‌سام، آزمون‌های محلی و وظیفهٔ CI برای بررسی رفتار واقعی افزونه‌ها آماده شده‌اند. یازده آزمون محلی و کنترل‌های نگارشی و قواعد مخزن موفق‌اند؛ اجرای سناریوهای واقعی در CI هنوز انجام نشده است.

بررسی کد، اثر بازنشانی رمز بر تأیید ایمیل wpForo، ترتیب ایجاد نشست و کنترل ورود، جایگزینی نقش‌ها در Dokan و اختیاری‌شدن ایمیل حساب را مشخص کرده است. آزمون‌های آماده‌شده باید این مشاهدات را در محیط ایزوله تأیید کنند. این آزمون‌ها به‌تنهایی اثبات صحت جریان کامل OTP یا نشست مرورگر نیستند.

برای تکمیل U1، اجرای CI، آزمون درخواست‌های واقعی و مرورگر، شناسایی بستهٔ دقیق پینوای نصب‌شده و آزمون ارتقا، و بازتولید تأخیر کرون باقی مانده‌اند. کد اجرایی پینوا، Dokan و wpForo و سایت اصلی تغییری نکرده‌اند. بخش «یکپارچه‌سازی‌ها» داخل تنظیمات پینوا در U2 ساخته خواهد شد؛ کنترل هر افزونه ابتدا خاموش است و وضعیت وابستگی و سازگاری نمایش داده می‌شود.
