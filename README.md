# پینوا (Pinova)

پینوا افزونه‌ای رایگان و متن‌باز برای ورود و عضویت کاربران وردپرس با رمز یک‌بارمصرف (OTP)، ایمیل، شمارهٔ موبایل، نام کاربری و رمز عبور است. افزونه با ووکامرس و چند سرویس پیامکی و ابزار رایج وردپرس یکپارچه می‌شود.

- صفحهٔ رسمی: [wordpress.org/plugins/pinova](https://wordpress.org/plugins/pinova/)
- مخزن توسعه: [github.com/vahid162/pinova](https://github.com/vahid162/pinova)
- مجوز: GPLv3

> **وضعیت انتشار:** شاخهٔ `main` شامل خط اصلاحی ۱.۲.۳ است. نسخه‌های GitHub با پسوند RC آزمایشی و به‌صورت Pre-release هستند. نصب روی production باید پس از کنترل artifact، بکاپ و تست برنامه‌ریزی‌شده انجام شود.

## امکانات نسخهٔ ۱.۲.۳

- ورود یا ثبت‌نام با OTP از طریق کانال‌های پیکربندی‌شده
- ورود با رمز از طریق pipeline استاندارد وردپرس و سازگار با hookهای امنیتی و 2FA
- تشخیص کاربر با نام کاربری، ایمیل یا موبایل legacy
- سیاست نقش‌محور؛ ورود مدیر از مسیر بومی `wp-login.php` در دسترس می‌ماند
- ذخیرهٔ موبایل جدید در `pinova_mobile` بدون بازنویسی `wp_users.user_login`
- توقف امن تطبیق موبایل وقتی یک مقدار به چند User ID برسد
- یکپارچگی حساب و checkout ووکامرس و اعلام سازگاری HPOS
- rate limit اتمیک، block موقت/دائمی و trusted proxy صریح
- خروجی Excel و VCF با capability، nonce، escaping و محدودیت ردیف
- REST response استاندارد با envelope سازگار `{success,message,data}`

نسخهٔ ۱.۲.۳ برای جلوگیری از تخریب بیشتر Identity طراحی شده است، اما چند حساب موجود را خودکار merge نمی‌کند. Identity Resolver، audit، migration، merge و rollback به خط توسعهٔ جداگانهٔ ۱.۳ تعلق دارند و هنوز ویژگی منتشرشدهٔ ۱.۲.۳ نیستند.

## نیازمندی‌ها

- WordPress: حداقل ۶.۸
- PHP: حداقل ۸.۱
- WooCommerce: حداقل ۷.۶ برای قابلیت‌های ووکامرس
- Docker: فقط برای محیط توسعه و تست integration

نسخه‌های دقیق بررسی‌شده در CI را از `.github/workflows/quality.yml` ببینید. هر ترکیب WordPress و WooCommerce باید با محدودیت‌های رسمی خود آن نسخه‌ها سازگار باشد.

## نصب

### WordPress.org

در پیشخوان وردپرس وارد **افزونه‌ها ← افزودن افزونه تازه** شوید، «Pinova» را جست‌وجو و نسخهٔ پایدار منتشرشده را نصب کنید.

### ZIP منتشرشده در GitHub

از بخش [GitHub Releases](https://github.com/vahid162/pinova/releases) فایل `pinova-<version>.zip` را دانلود کنید. سپس در **افزونه‌ها ← افزودن افزونه تازه ← بارگذاری افزونه** همان فایل را انتخاب کنید. ZIP صحیح یک پوشهٔ اصلی با نام `pinova/` دارد و ZIP خودکار Source code جایگزین artifact نصب‌شدنی نیست.

اگر پینوا از قبل نصب است، پیش از جایگزینی فایل‌ها از دیتابیس و افزونه بکاپ بگیرید. Pre-release را ابتدا روی staging یا با برنامهٔ دقیق canary بررسی کنید. انتشار در GitHub به‌تنهایی مجوز نصب یا migration روی production نیست.

## شناسه‌های یک کاربر در ۱.۲.۳

وردپرس، User ID را هویت اصلی حساب در نظر می‌گیرد. پینوا در ۱.۲.۳ می‌تواند نام کاربری، ایمیل و موبایل legacy را به همان ID resolve کند، مشروط بر اینکه داده‌ها واقعاً روی همان حساب باشند و تعارضی وجود نداشته باشد.

مثلاً این سه مقدار فقط وقتی یک حساب محسوب می‌شوند که همگی به یک User ID واحد متصل باشند:

```text
mahdavi162
gpante.ir@gmail.com
09370159434
```

قواعد ایمنی:

- `user_login` موجود با ورود OTP یا ویرایش موبایل تغییر نمی‌کند.
- موبایل معتبرِ ذخیره‌شده در meta فیزیکی `pinova_mobile` بر موبایل legacy اولویت دارد.
- بعد از ذخیرهٔ یک موبایل جدید، موبایل قدیمیِ موجود در username یا متاهای legacy به‌عنوان alias موبایلی پینوا استفاده نمی‌شود.
- اگر یک موبایل به چند User ID برسد، ورود با آن موبایل fail-closed می‌شود؛ «اولین ID» انتخاب نمی‌شود.
- اتصال و merge چند حساب جدا نیازمند workflow کنترل‌شدهٔ ۱.۳، انتخاب دستی حساب اصلی و انتقال امن مالکیت‌هاست.

## ساختار مخزن

```text
pinova.php                         bootstrap و metadata افزونه
src/API/                          endpointهای REST
src/Services/                     کاربر، OTP، امنیت، rate limit و کانال‌ها
src/Objects/                      Identifier و نرمال‌سازی موبایل
src/Integrations/Wordpress/       پروفایل، فهرست کاربران و export
src/Integrations/Woocommerce/     حساب، checkout و ساخت محدود مشتری
templates/                        قالب‌های ورود
assets/                           CSS، JavaScript، فونت و تصویر
tests/                            تست‌های unit و integration
tools/                            ابزارهای wp-env و ساخت ZIP
.agents/skills/pinova-development راهنمای تخصصی کار روی مخزن
AGENTS.md                         نقطهٔ شروع عامل‌های کدنویسی
```

## محیط توسعهٔ ایزوله

```bash
git clone https://github.com/vahid162/pinova.git
cd pinova
composer install --no-interaction --prefer-dist
npm install --ignore-scripts
```

تست‌های integration با `@wordpress/env` در کانتینرهای جدا اجرا می‌شوند و نباید به فایل یا دیتابیس production متصل شوند.

## کنترل کیفیت

```bash
composer lint
composer test
composer phpstan
composer phpcs
composer audit
```

برای integration و HPOS:

```bash
npm run env:configure
npm run env:start -- --update
npx wp-env run tests-cli sudo env PHP_INI_DIR=/usr/local/etc/php docker-php-ext-install pdo_mysql
PINOVA_TEST_HPOS=no npm run test:integration
PINOVA_TEST_HPOS=yes npm run test:integration
npm run env:stop
```

با `WP_VERSION` و `WC_VERSION` می‌توان زوج سازگاری را پیش از configure انتخاب کرد. نصب `pdo_mysql` فقط داخل کانتینر disposable انجام می‌شود و چیزی را روی PHP سیستم یا production تغییر نمی‌دهد. تست‌ها فقط در محیط disposable اجرا می‌شوند.

## ساخت ZIP قابل نصب

```bash
composer build
```

خروجی در `.build/pinova-<version>.zip` ساخته می‌شود. build فایل‌های توسعه مانند `.git`، `.github`، `.agents`، `AGENTS.md`، `node_modules`، تست‌ها و ابزارها را حذف و dependencyهای production را بسته‌بندی می‌کند.

```bash
unzip -tq .build/pinova-<version>.zip
unzip -Z1 .build/pinova-<version>.zip
sha256sum .build/pinova-<version>.zip
```

برای release، ZIP از exact tag دو بار ساخته می‌شود و SHA-256 هر دو build باید یکسان باشد. سپس asset منتشرشده دوباره از GitHub دانلود و کنترل می‌شود.

پیش‌انتشار GitHub فقط از branch کنترل‌شده‌ای مانند `publish/v1.2.3-rc2` انجام می‌شود. این branch باید از commit دقیق، بازبینی‌شده و سبزِ `main` ساخته شود. پس از سبزشدن workflow اصلی، workflow انتشار نسخه را تطبیق می‌دهد، tag حاشیه‌نویسی‌شده و تغییرناپذیر می‌سازد، build را دوبار مقایسه می‌کند، ZIP و checksum را به Release پیوست می‌کند و asset منتشرشده را دوباره دانلود و راستی‌آزمایی می‌کند.

## فرایند اصلاح باگ

1. نسخه و ref دقیق مشکل مشخص می‌شود.
2. مشکل در worktree و محیط ایزوله بازتولید می‌شود؛ production محل توسعه نیست.
3. در صورت امکان regression test نوشته می‌شود.
4. کوچک‌ترین اصلاح سازگار با قواعد امنیت و Identity انجام می‌شود.
5. تست هدفمند و سپس suite متناسب اجرا می‌شود.
6. Skill، AGENTS، README و changelog مرتبط در همان change set به‌روز می‌شوند.
7. Pull Request ساخته و GitHub Actions بررسی می‌شود.
8. پس از merge، tag جدید و ZIP تکرارپذیر ساخته می‌شود؛ tag منتشرشده جابه‌جا نمی‌شود.
9. نصب production فقط با مجوز جدا، بکاپ، rollback و آزمون پس از نصب انجام می‌شود.

در هر تغییر مؤثر بر افزونه باید Skill مخزن هم بازبینی شود:

```bash
bash .agents/skills/pinova-development/scripts/check-skill-sync.sh --working-tree
```

## نقشهٔ راه Identity 1.3

خط توسعهٔ ۱.۳ برای اتصال چند identifier به یک User ID و رسیدگی کنترل‌شده به چند User ID طراحی شده است. اهداف آن شامل جدول identity یکتا، audit و dry-run، ثبت conflict، merge دستی با journal، rollback محدود به تغییرات همان run، پشتیبانی WordPress Core/WooCommerce و توقف apply روی Multisite است.

هیچ conflict چندحسابی نباید خودکار merge شود. رمز، نقش، capability، session، application password و دادهٔ افزونه‌های امنیتی حساب مبدأ نباید به حساب مقصد merge شوند. این قابلیت‌ها پیش از انتشار عمومی به staging، آزمون migration/rollback و canary جدا نیاز دارند.

## امنیت و production

- OTP، password، token، reset key و شناسهٔ کامل کاربر را log نکنید.
- redirect خارجی باید با `wp_validate_redirect()` و host مجاز محدود شود.
- فقط `REMOTE_ADDR` پیش‌فرض معتبر است؛ proxy header به trusted CIDR نیاز دارد.
- migration قدیمی نباید ستون، index یا دادهٔ جدول‌های core را تغییر دهد.
- توسعه و تست خودکار نباید در `/www/wwwroot/gpante.com/wp-content/plugins/pinova` انجام شود.
- RC باید قبل از stable/latest شدن، staging و canary مورد توافق را بگذراند.

## راهنمای AI و مشارکت‌کنندگان

کار را از [AGENTS.md](AGENTS.md) و [.agents/skills/pinova-development/SKILL.md](.agents/skills/pinova-development/SKILL.md) شروع کنید. Skill باید با هر تغییر runtime، schema، dependency، تست، tooling، CI، command، integration یا release به‌صورت معنادار به‌روز شود.

برای مشارکت انسانی نیز جریان توصیه‌شده شامل شرح بازتولید، branch محدود، regression test، اصلاح، کنترل کیفیت، مستندسازی و Pull Request است.

## مجوز

پینوا تحت [GNU General Public License v3](https://www.gnu.org/licenses/gpl-3.0.html) منتشر می‌شود.
