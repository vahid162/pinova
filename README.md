# پینوا (Pinova)

پینوا افزونه‌ای رایگان و متن‌باز برای ورود و عضویت کاربران وردپرس با رمز یک‌بارمصرف (OTP)، ایمیل، شمارهٔ موبایل، نام کاربری و رمز عبور است. این افزونه با ووکامرس و چند قالب و افزونهٔ رایج وردپرس یکپارچه می‌شود و از نسخهٔ ۱.۳ چند شناسهٔ متعلق به یک کاربر را به یک WordPress User ID ثابت متصل می‌کند.

- صفحهٔ افزونه در WordPress.org: [wordpress.org/plugins/pinova](https://wordpress.org/plugins/pinova/)
- مخزن توسعه: [github.com/vahid162/pinova](https://github.com/vahid162/pinova)
- مجوز: GPLv3

> **وضعیت انتشار:** نسخه‌های `1.2.3 RC1` و `1.3.0 RC1` در GitHub به‌صورت Pre-release منتشر شده‌اند و هنوز stable/latest نیستند. پیش از استفاده روی production باید مراحل staging و canary این سند انجام شود.

## پینوا چه کاری انجام می‌دهد؟

کاربر می‌تواند با شمارهٔ موبایل یا ایمیل خود کد تأیید دریافت کند و وارد سایت شود. ورود با رمز عبور نیز از مسیر استاندارد وردپرس انجام می‌شود تا hookهای امنیتی، Wordfence و راهکارهای 2FA همچنان کار کنند. اگر حسابی وجود نداشته باشد، پینوا می‌تواند طبق تنظیمات سایت یک حساب مشتری ایجاد کند.

قابلیت‌های اصلی:

- ورود و ثبت‌نام با OTP از طریق پیامک، ایمیل، تماس صوتی و پیام‌رسان بله
- ورود با نام کاربری، ایمیل یا موبایل و رمز عبور مجاز
- سیاست ورود نقش‌محور و حفظ ورود بومی مدیران از `wp-login.php`
- یکپارچگی با فرم حساب و تسویه‌حساب ووکامرس
- پشتیبانی از WooCommerce HPOS
- مسدودسازی شناسه و IP و rate limiting اتمیک
- تشخیص امن IP در شبکه‌های دارای trusted proxy
- خروجی Excel و VCF کاربران با کنترل دسترسی، nonce و escaping
- Identity Resolver برای اتصال username، email و mobile به یک User ID
- audit، migration، conflict review، merge و rollback از طریق WP-CLI
- پنل مدیریت Identity با capability اختصاصی `manage_pinova_identities`

## نیازمندی‌ها و سازگاری

- WordPress: حداقل `6.8`
- PHP: حداقل `8.1`
- WooCommerce: حداقل `7.6.0` برای قابلیت‌های ووکامرس
- نسخهٔ بررسی‌شدهٔ WooCommerce در metadata فعلی: `10.9.4`

یکپارچگی‌های فعال در bootstrap فعلی:

- WooCommerce
- Gravity Forms
- Paid Memberships Pro
- WP Rocket و Perfmatters
- Woodmart و Flatsome
- درگاه‌های ملی پیامک، MaxSMS، PanelChi و Persian WooCommerce SMS

برای Autoptimize و LiteSpeed Cache کلاس‌های سازگاری در source وجود دارد، اما در snapshot فعلی `1.3.0 RC1` از bootstrap اصلی فراخوانی نمی‌شوند. بنابراین تا زمان افزودن تست integration و اصلاح بارگذاری، نباید سازگاری فعال این دو مورد را قطعی در نظر گرفت.

پشتیبانی migration/merge نسخهٔ ۱.۳ در مرحلهٔ اول فقط مالکیت‌های WordPress Core و WooCommerce را پوشش می‌دهد. در Multisite اجرای `--apply` مسدود است. وجود wpForo یا یک سامانهٔ عضویت پشتیبانی‌نشده می‌تواند preflight ادغام را متوقف کند تا adapter مناسب اضافه شود.

## نصب برای استفادهٔ عادی

### نسخهٔ پایدار WordPress.org

از مدیریت وردپرس وارد **افزونه‌ها ← افزودن افزونه تازه** شوید، «پینوا» را جست‌وجو، نصب و فعال کنید.

### نسخه‌های آزمایشی GitHub

فایل ZIP موردنظر را از صفحهٔ Releases دریافت کنید:

- [Pinova 1.2.3 RC1 — Security Hotfix](https://github.com/vahid162/pinova/releases/tag/v1.2.3-rc1)
- [Pinova 1.3.0 RC1 — Identity & Migration](https://github.com/vahid162/pinova/releases/tag/v1.3.0-rc1)

سپس از **افزونه‌ها ← افزودن افزونه تازه ← بارگذاری افزونه** فایل ZIP را انتخاب کنید. فایل منتشرشده پوشهٔ اصلی `pinova/` را دارد و مستقیماً قابل نصب است.

اگر نسخهٔ قبلی نصب است، پیش از جایگزینی از فایل‌ها و دیتابیس بکاپ بگیرید. ابتدا نسخهٔ جدید را روی staging آزمایش کنید؛ نصب GitHub Release به معنی مجازبودن migration یا ادغام حساب‌ها روی production نیست.

## مدل Identity در نسخهٔ ۱.۳

هدف Identity Resolver این است که نام کاربری، ایمیل و موبایل مرتبط، همگی به یک User ID برسند. برای نمونه اگر سه مقدار زیر واقعاً متعلق به یک شخص و به‌درستی تأیید شده باشند، ورود با هرکدام باید همان حساب را باز کند:

```text
mahdavi162
gpante.ir@gmail.com
09370159434
```

اصول این مدل:

- `wp_users.ID` هویت ثابت حساب است.
- `user_login` موجود در جریان OTP یا migration بازنویسی نمی‌شود.
- موبایل به E.164، ایمیل به فرم lowercase canonical و نام کاربری طبق قواعد وردپرس نرمال می‌شود.
- مقادیر خالی Identity نیستند.
- موبایل یا ایمیل فقط پس از اثبات موفق، verified محسوب می‌شود.
- دادهٔ legacy بدون مدرک OTP به‌صورت unverified وارد می‌شود.
- اگر یک شناسه به چند User ID برسد، عملیات متوقف و conflict ثبت می‌شود؛ اولین حساب هرگز خودکار انتخاب نمی‌شود.
- merge چند حساب فقط با انتخاب دستی canonical User ID انجام می‌شود.

حساب مبدأ پس از merge حذف نمی‌شود. با `pinova_merged_into` غیرفعال و ورود آن مسدود می‌شود. sessionهای فعال مبدأ باطل می‌شوند و بازگرداندن sessionها ممکن نیست.

جزئیات عملیاتی در [راهنمای migration هویت](docs/identity-migration.md) آمده است.

## فرمان‌های WP-CLI

بررسی وضعیت بدون تغییر داده:

```bash
wp pinova identity audit
wp pinova identity migrate --dry-run
wp pinova identity conflicts
```

اجرای migration پس از staging، تأیید conflictها و بکاپ کامل:

```bash
wp pinova identity migrate --apply --yes
```

پیش‌نمایش و اجرای merge دستی:

```bash
wp pinova identity merge <source-user-id> --into=<target-user-id> --dry-run
wp pinova identity merge <source-user-id> --into=<target-user-id> --apply --yes
```

پیش‌نمایش و اجرای rollback یک run:

```bash
wp pinova identity rollback <run-id> --dry-run
wp pinova identity rollback <run-id> --apply --yes
```

rollback فقط objectهایی را برمی‌گرداند که همان run تغییر داده و هنوز در وضعیت ثبت‌شده قرار دارند. فعالیت جدید پس از merge دست‌نخورده باقی می‌ماند.

## ساختار پروژه

```text
pinova.php                         فایل اصلی و bootstrap افزونه
src/API/                          endpointهای REST
src/Services/                     OTP، کاربر، امنیت، rate limit و کانال‌ها
src/Identity/                     Resolver، repository، audit، migration و merge
src/CLI/IdentityCommand.php       فرمان‌های WP-CLI هویت
src/Integrations/Wordpress/       پروفایل، فهرست کاربران و export
src/Integrations/Woocommerce/     حساب، checkout، مشتری و انتقال مالکیت
templates/                        قالب‌های رابط ورود
assets/                           CSS، JavaScript، فونت و تصویر
tests/                            تست‌های unit و integration
tools/                            ابزارهای wp-env و ساخت ZIP
docs/identity-migration.md        runbook مهاجرت و ادغام
.agents/skills/pinova-development Skill تخصصی Codex برای این مخزن
AGENTS.md                         نقطهٔ شروع و workflow عامل‌های کدنویسی
```

## راه‌اندازی محیط توسعه

مخزن را clone و dependencyهای قفل‌شده را نصب کنید:

```bash
git clone https://github.com/vahid162/pinova.git
cd pinova
composer install --no-interaction --prefer-dist
npm install --ignore-scripts
```

برای تست‌های integration به Docker و یک daemon فعال نیاز است. محیط `@wordpress/env` یک WordPress و دیتابیس جدا ایجاد می‌کند و نباید به دیتابیس production متصل شود.

## تست و کنترل کیفیت

کنترل‌های PHP:

```bash
composer lint
composer test
composer phpstan
composer phpcs
composer audit
```

تست integration با HPOS خاموش و روشن:

```bash
npm run env:configure
npm run env:start -- --update
PINOVA_TEST_HPOS=no npm run test:integration
PINOVA_TEST_HPOS=yes npm run test:integration
npm run env:stop
```

برای انتخاب نسخه‌ها می‌توان پیش از configure مقدارهای `WP_VERSION` و `WC_VERSION` را تنظیم کرد. هر ترکیب باید واقعاً با هم سازگار باشد؛ برای مثال در snapshot فعلی بعضی jobهای WordPress 6.8 پیش از اجرای تست متوقف می‌شوند، زیرا بستهٔ انتخاب‌شدهٔ WooCommerce حداقل WordPress 6.9 می‌خواهد. ماتریس CI باید پیش از stable release اصلاح و کاملاً سبز شود.

workflow اصلی در [.github/workflows/quality.yml](.github/workflows/quality.yml) ماتریس PHP، WordPress، WooCommerce و HPOS را اجرا می‌کند.

## ساخت ZIP قابل نصب

```bash
composer build
```

خروجی در مسیر زیر ساخته می‌شود:

```text
.build/pinova-<version>.zip
```

اسکریپت build فایل‌های توسعه مانند `.git`، `.github`، `.agents`، `AGENTS.md`، `node_modules`، تست‌ها و ابزارها را از بسته حذف می‌کند، dependencyهای production را نصب و timestamp و ترتیب فایل‌ها را ثابت می‌کند.

کنترل artifact:

```bash
unzip -tq .build/pinova-<version>.zip
unzip -Z1 .build/pinova-<version>.zip
sha256sum .build/pinova-<version>.zip
```

برای انتشار، build باید دو مرتبه از همان tag اجرا شود و SHA-256 هر دو خروجی یکسان باشد.

## فرایند اصلاح باگ

1. وضعیت Git و نسخه/tag دقیق مشکل بررسی می‌شود.
2. مشکل در محیط ایزوله بازتولید می‌شود؛ production محل توسعه یا تست خودکار نیست.
3. در صورت امکان یک regression test نوشته می‌شود که قبل از اصلاح شکست بخورد.
4. کوچک‌ترین اصلاح سازگار با Identity و مرزهای امنیتی انجام می‌شود.
5. تست مرتبط و سپس مجموعهٔ متناسب unit، integration، HPOS، PHPStan و PHPCS اجرا می‌شود.
6. `SKILL.md` و مستندات مرتبط در همان change set به‌روز می‌شوند.
7. CI باید برای commit مورد انتشار سبز شود.
8. ZIP از tag ساخته، دوباره از GitHub دانلود و checksum آن کنترل می‌شود.
9. نسخه ابتدا روی staging و سپس با مجوز صریح و برنامهٔ rollback روی production نصب می‌شود.

برای اطمینان از همگام‌ماندن Skill با پروژه اجرا کنید:

```bash
bash .agents/skills/pinova-development/scripts/check-skill-sync.sh --working-tree
```

GitHub CI نیز تغییر فایل‌های مؤثر بر افزونه را بدون بازبینی هم‌زمان Skill رد می‌کند.

## فرایند انتشار

1. نسخه، changelog، dependency lockها و مستندات همگام شوند.
2. تمام تست‌های لازم و GitHub Actions برای commit نهایی موفق باشند.
3. tag غیرقابل‌تغییر RC ساخته شود.
4. ZIP تکرارپذیر از یک worktree جدا در همان tag ساخته شود.
5. branch و tag بدون force-push به GitHub ارسال شوند.
6. GitHub Pre-release با ZIP و SHA-256 ساخته شود.
7. asset از GitHub دوباره دانلود و بررسی شود.
8. staging و canary انجام شوند.
9. فقط با تأیید صریح، نسخه به stable/latest تبدیل شود.

انتشار کد در GitHub به‌تنهایی مجوز نصب روی سایت production یا اجرای migration نیست.

## ملاحظات امنیتی و production

- رمز، OTP، token، reset key و شناسهٔ کامل کاربران را log نکنید.
- برای log عملیاتی از User ID، نوع رویداد، شناسهٔ ماسک‌شده و correlation ID استفاده کنید.
- فقط `REMOTE_ADDR` به‌صورت پیش‌فرض قابل اعتماد است؛ proxy header به trusted CIDR صریح نیاز دارد.
- ورود رمز عبور باید از pipeline استاندارد WordPress عبور کند.
- redirect خارجی باید با allowlist و `wp_validate_redirect()` کنترل شود.
- migration و merge تولیدی به staging، dry-run، تأیید دستی conflict و بکاپ فوری نیاز دارد.
- هیچ branch، tag یا GitHub Release به معنی مجوز تغییر دیتابیس production نیست.

## راهنمای AI و مشارکت‌کنندگان

عامل‌های کدنویسی باید کار را از [AGENTS.md](AGENTS.md) و Skill مخزن در [.agents/skills/pinova-development/SKILL.md](.agents/skills/pinova-development/SKILL.md) آغاز کنند. هر تغییر مؤثر بر رفتار، schema، dependency، تست، build، CI، فرمان‌ها یا انتشار باید همراه با بازبینی و به‌روزرسانی معنادار Skill باشد.

برای مشارکت انسانی نیز همین ترتیب توصیه می‌شود: issue یا شرح بازتولید، branch محدود، regression test، اصلاح، کنترل کیفیت، به‌روزرسانی مستندات و سپس Pull Request.

## مجوز

پینوا تحت مجوز [GNU GPL version 3](https://www.gnu.org/licenses/gpl-3.0.html) منتشر می‌شود.
