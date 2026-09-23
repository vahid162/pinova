# پینوا (Pinova)

پینوا افزونه‌ای رایگان و متن‌باز برای ورود و عضویت کاربران وردپرس با رمز یک‌بارمصرف، ایمیل، شمارهٔ موبایل، نام کاربری و رمز عبور است. افزونه با ووکامرس و چند سرویس پیام‌رسان و پیامکی یکپارچه می‌شود.

- صفحهٔ رسمی: [wordpress.org/plugins/pinova](https://wordpress.org/plugins/pinova/)
- مخزن توسعه: [github.com/vahid162/pinova](https://github.com/vahid162/pinova)
- تاریخچهٔ انتشار: [CHANGELOG.md](CHANGELOG.md)
- راهنمای مشارکت: [CONTRIBUTING.md](CONTRIBUTING.md)
- سیاست امنیتی: [SECURITY.md](SECURITY.md)
- مجوز: GPL-3.0-or-later

وضعیت جاری شاخه‌ها، نسخه‌های آزمایشی، CI و انتشارها باید مستقیماً از Git و [GitHub Releases](https://github.com/vahid162/pinova/releases) بررسی شود. این سند عمداً Snapshot عملیاتی، شناسهٔ اجرا، Checksum یا برنامهٔ نسخهٔ بعدی نگهداری نمی‌کند.

## امکانات

- ورود و ثبت‌نام با OTP از طریق کانال‌های پیکربندی‌شده
- ورود با رمز عبور از Pipeline استاندارد وردپرس و سازگار با Hookهای امنیتی و 2FA
- تشخیص حساب با نام کاربری، ایمیل یا موبایل Legacy بدون بازنویسی `user_login`
- جداسازی هدف OTP میان ورود، ثبت‌نام و بازیابی رمز عبور
- یکپارچگی حساب و Checkout ووکامرس و اعلام سازگاری HPOS
- Rate limit اتمیک و فهرست مسدودی نوع‌دار برای موبایل، ایمیل، نام کاربری و IP
- خروجی Excel و VCF با Capability، Nonce، Escaping و محدودیت ردیف
- رابط مستقل و پنجرهٔ ورود Checkout با پشتیبانی صفحه‌کلید، RTL و طول قابل‌تنظیم OTP
- مسیر خصوصی مدیر که Pipeline بومی وردپرس و افزونه‌های امنیتی را حفظ می‌کند
- گزارش‌گیری ساخت‌یافته، محدود، قابل‌هم‌بستگی و Privacy-safe

## مدل هویت و امنیت

WordPress User ID لنگر اصلی حساب است. پینوا می‌تواند شناسه‌های قدیمی یک حساب را به همان User ID متصل کند، اما ورود OTP یا ویرایش موبایل نباید `wp_users.user_login` را تغییر دهد. موبایل صریح ذخیره‌شده در `pinova_mobile` بر Aliasهای قدیمی اولویت دارد و تطبیق مبهم چند حساب Fail-closed می‌شود.

ورود با رمز از Pipeline استاندارد وردپرس عبور می‌کند تا Wordfence، 2FA، Passkey و Hookهای احراز هویت دور زده نشوند. پاسخ‌های عمومی نیز وجود حساب، نوع نقش یا داشتن رمز را افشا نمی‌کنند.

مسدودسازی مسیر اصلی ورود وردپرس Opt-in است. پیش از فعال‌سازی باید مسیر خصوصی معتبر ذخیره، ورود واقعی مدیر از همان مسیر آزمایش، دو روش بازیابی دسترسی آماده و ثابت اضطراری `PINOVA_BLOCK_NATIVE_LOGIN=false` مستند شده باشد.

## گزارش‌گیری و حریم خصوصی

پینوا رخدادهای عملیاتی را با Event code ثابت، سطح PSR-3، Correlation ID، User ID اختیاری و Context محدود ثبت می‌کند. Context بر اساس Allowlist ساخته می‌شود؛ OTP، رمز، Token، Cookie، Reset key، شناسهٔ خام، متن Exception و Stack trace ذخیره نمی‌شوند.

پاسخ‌هایی که از لایهٔ استاندارد REST پینوا ساخته می‌شوند Correlation ID را برای تطبیق خطای کاربر با رخداد سرور ارائه می‌کنند. در صورت خرابی جدول اختصاصی، Logger فقط ساختار ازپیش‌پاک‌سازی‌شده را به مسیرهای Fallback می‌فرستد و خرابی گزارش‌گیری نباید درخواست کاربر را متوقف کند.

پینوا با ابزارهای حریم خصوصی وردپرس یکپارچه است: خروجی کاربر فقط موبایل متعلق به پروفایل پینوا و واقعیت‌های ممیزی مجاز را شامل می‌شود و هرگز OTP، Credential، Token، Fingerprint یا Context گزارش را صادر نمی‌کند. پاک‌سازی تأییدشده، متای موبایل و رکوردهای موقت OTP را حذف و پیوند شخصی گزارش‌های عملیاتی را ناشناس می‌کند، در حالی که واقعیت غیرهویتی رخداد برای بررسی امنیتی باقی می‌ماند.

جزئیات داده‌های ذخیره‌شده، Retention و سرویس‌های خارجی در `readme.txt` و متن سیاست حریم خصوصی افزونه نگهداری می‌شود.

## نیازمندی‌ها و نصب

نسخه‌های حداقل WordPress و PHP و مقدار Stable tag در `readme.txt` و هدر اصلی افزونه، منابع Canonical سازگاری هستند.

برای نصب نسخهٔ پایدار، از مخزن رسمی WordPress استفاده کنید. برای آزمون یک پیش‌انتشار، فقط ZIP نصب‌شدنی همان GitHub Release را دانلود کنید؛ Source archive خودکار GitHub Artifact نصب‌شدنی پروژه نیست. ZIP معتبر یک پوشهٔ اصلی `pinova/` دارد.

Checkout سورس Git عمداً پوشهٔ `vendor/` را نگهداری نمی‌کند و پیش از اجرا یا آزمون به `composer install` از روی `composer.lock` نیاز دارد. وابستگی‌های JavaScript فقط با `npm ci --ignore-scripts` و `package-lock.json` بازسازی می‌شوند. ZIP رسمی انتشار، وابستگی‌های Production را در خود دارد و تنها Artifact قابل‌نصب GitHub است.

پیش از جایگزینی افزونه، از دیتابیس و بستهٔ نصب‌شده Backup تهیه کنید و نسخهٔ جدید را ابتدا در Staging یا Canary کنترل‌شده بررسی کنید. انتشار در GitHub به‌تنهایی مجوز نصب یا Migration روی Production نیست.

فعال‌سازی و ارتقای پینوا به نوشتن در پوشهٔ افزونه وابسته نیست و شِمای پایگاه‌داده به‌صورت افزایشی و قابل‌تکرار مدیریت می‌شود. غیرفعال‌سازی، زمان‌بندی‌های پینوا را پاک می‌کند. حذف افزونه به‌طور پیش‌فرض داده‌ها را نگه می‌دارد؛ پاک‌سازی جدول‌ها، تنظیمات و داده‌های موقت فقط با گزینهٔ صریح مدیر انجام می‌شود و متای هویتی `pinova_mobile` را حذف نمی‌کند.

## ساختار مخزن

```text
pinova.php                         Bootstrap و Metadata افزونه
src/API/                          Endpointهای REST
src/Services/                     احراز هویت، OTP، امنیت و کانال‌ها
src/Logging/                      Logger، Context امن و Retention
src/Integrations/Wordpress/       WordPress، پروفایل، Export و Privacy
src/Integrations/Woocommerce/     حساب، Checkout و مشتری
templates/ و assets/              رابط کاربری
tests/                            آزمون‌های Unit، Integration و Browser
tools/                            کنترل‌های مخزن، محیط تست و Build
.agents/reviews/                  شواهد تاریخ‌دار و غیرعملیاتی
.agents/skills/                   قراردادهای تخصصی پایدار
AGENTS.md                         نخستین سند الزامی برای عامل‌های AI
```

## توسعه و کنترل کیفیت

همهٔ مشارکت‌کنندگان انسانی و AI باید ابتدا [AGENTS.md](AGENTS.md) و سپس [CONTRIBUTING.md](CONTRIBUTING.md) را بخوانند. دستورالعمل تخصصی Pinova فقط بعد از آن و طبق مسیریابی `AGENTS.md` استفاده می‌شود.

این مخزن ممکن است روی میزبانی باشد که منابع آن با Production مشترک است. پیش از نصب Dependency یا اجرای PHPStan، Composer build، wp-env یا Browser suite باید مرز CPU، RAM، Swap، I/O و Docker مشخص شود. روی میزبان مشترک فقط کنترل‌های سبک اجرا و Matrix کامل به GitHub Actions یا میزبان توسعهٔ ایزوله واگذار شود.

کنترل‌های سبک و مستقل از محیط:

```bash
php tools/check-ai-governance.php
php tools/check-changelog-sync.php
php tools/check-plugin-metadata.php
bash .agents/skills/pinova-development/scripts/check-skill-sync.sh --working-tree
npm run lint:js
npm run test:js
```

فرمان‌های کامل، Matrix سازگاری، ساخت تکرارپذیر ZIP و قواعد انتشار در راهنمای مشارکت و Skill مخزن مستند هستند.

## مشارکت و امنیت

Pull Request باید دامنه تغییر، آزمون‌ها، اثر Schema، حریم خصوصی، Logging، Packaging و دستورالعمل AI را مشخص کند. تغییرهای مکانیکی، مستنداتی، Runtime، Schema و Release تا حد امکان در Commit و PRهای مستقل نگهداری شوند.

آسیب‌پذیری‌ها را در Issue عمومی منتشر نکنید. مسیر گزارش خصوصی و اطلاعات موردنیاز در [SECURITY.md](SECURITY.md) آمده است.

## مجوز

پینوا تحت [GNU General Public License v3 or later](LICENSE) منتشر می‌شود.
