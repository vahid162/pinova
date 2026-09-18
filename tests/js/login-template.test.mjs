import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const template = await readFile(
    new URL('../../templates/login-form.php', import.meta.url),
    'utf8',
);
const themePartial = await readFile(
    new URL('../../templates/login-partial.php', import.meta.url),
    'utf8',
);
const accountCss = await readFile(
    new URL('../../assets/css/account.css', import.meta.url),
    'utf8',
);
const sharedCss = await readFile(
    new URL('../../assets/css/style.css', import.meta.url),
    'utf8',
);
const sharedCssSource = await readFile(
    new URL('../../assets/css/index.scss', import.meta.url),
    'utf8',
);
const sharedCssIntermediate = await readFile(
    new URL('../../assets/css/index.css', import.meta.url),
    'utf8',
);

function formMarkup(id) {
    const match = template.match(new RegExp(`<form id="${id}"[\\s\\S]*?<\\/form>`));
    assert.ok(match, `form ${id} should exist`);
    return match[0];
}

function cssHexVariable(name) {
    const match = accountCss.match(new RegExp(`--${name}:\\s*(#[0-9a-f]{6})`, 'i'));
    assert.ok(match, `CSS variable --${name} should exist`);
    return match[1];
}

function relativeLuminance(hex) {
    const channels = hex.slice(1).match(/.{2}/g).map(value => Number.parseInt(value, 16) / 255);
    const linear = channels.map(value => (
        value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4
    ));
    return (0.2126 * linear[0]) + (0.7152 * linear[1]) + (0.0722 * linear[2]);
}

function contrastRatio(first, second) {
    const firstLuminance = relativeLuminance(first);
    const secondLuminance = relativeLuminance(second);
    const lighter = Math.max(firstLuminance, secondLuminance);
    const darker = Math.min(firstLuminance, secondLuminance);
    return (lighter + 0.05) / (darker + 0.05);
}

test('standalone account document declares Persian RTL semantics', () => {
    assert.match(template, /<html[^>]*lang="fa"[^>]*dir="rtl"/i);
    assert.match(template, /<meta[^>]*name="viewport"[^>]*viewport-fit=cover/i);
    assert.match(template, /<main\b/i);
    assert.match(template, /<h1\b/i);
});

test('every interactive step uses a real submit form and associated labels', () => {
    assert.equal((template.match(/<form\b/gi) || []).length, 6);
    for (const id of [
        'authenticate',
        'signIn',
        'loginByPassword',
        'loginByOtp',
        'forgotPassword',
        'changePassword',
    ]) {
        formMarkup(id);
    }
    for (const id of [
        'pinova-identifier',
        'pinova-signin-code',
        'pinova-password',
        'pinova-login-otp',
        'pinova-forgot-otp',
        'pinova-password-new',
        'pinova-password-confirm',
    ]) {
        assert.match(template, new RegExp(`id="${id}"`));
        assert.match(template, new RegExp(`for="${id}"`));
        assert.match(template, new RegExp(`<input[^>]*id="${id}"[^>]*\\brequired(?:\\s|>)`, 'i'));
    }
});

test('keyboard controls, image alternatives, and live feedback are explicit', () => {
    assert.doesNotMatch(template, /<div[^>]*pinova-on:click=/i);
    assert.equal(
        (template.match(/<img\b/gi) || []).length,
        (template.match(/<img[^\n]*\balt=/gi) || []).length,
    );
    assert.match(template, /aria-live="polite"/i);
    assert.match(template, /role="status"/i);
    assert.equal((template.match(/<h2[^>]*data-pinova-step-heading[^>]*tabindex="-1"/gi) || []).length, 6);
    assert.equal((template.match(/class="pinova-auth-error"[^>]*role="alert"[^>]*aria-atomic="true"/gi) || []).length, 7);
    assert.match(template, /class="pinova-auth-content"[^>]*pinova-bind:inert="pageLoaderIsActive"/i);
    assert.match(template, /id="pinova-identifier"[^>]*dir="auto"/i);
});

test('changed standalone assets use an account-specific cache revision', () => {
    assert.match(template, /\$account_asset_version\s*=\s*PINOVA_VERSION\s*\.\s*'\.5'/);

    for (const asset of [
        'assets/css/style.css',
        'assets/css/account.css',
        'assets/js/pages/login-form.js',
    ]) {
        assert.match(
            template,
            new RegExp(`${asset.replaceAll('.', '\\.')}\\?ver=' \\. \\$account_asset_version`),
        );
    }

    assert.match(template, /assets\/js\/global\.js\?ver=' \. PINOVA_VERSION/);
});

test('dead links and public native-login references are absent', () => {
    assert.doesNotMatch(template, /href=["']#["']/i);
    assert.match(template, /<noscript>/i);
    assert.match(template, /برای ورود به حساب کاربری، JavaScript مرورگر را فعال کنید/);
    assert.doesNotMatch(template, /wp_login_url/i);
    assert.doesNotMatch(template, /wp-login\.php/i);
    assert.doesNotMatch(template, /ورود از مسیر اصلی وردپرس/i);
});

test('the first step has no duplicate header exit control', () => {
    assert.equal(
        (template.match(/class="pinova-auth-icon-button"/g) || []).length,
        1,
    );
    assert.doesNotMatch(
        template,
        /pinova-show="stepName === 'authenticate'"[\s\S]{0,300}aria-label="بازگشت به فروشگاه"/,
    );
    assert.match(
        template,
        /pinova-show="stepName === 'loginByPassword' \|\| stepName === 'changePassword'"/,
    );
    assert.doesNotMatch(template, /pinova-show="stepName !== 'authenticate'"/);
});

test('identifier and password instructions are concise bold field labels', () => {
    const authenticate = formMarkup('authenticate');
    const password = formMarkup('loginByPassword');

    assert.match(
        authenticate,
        /<label for="pinova-identifier">شماره موبایل، نام کاربری یا ایمیل خود را وارد کنید<\/label>/,
    );
    assert.doesNotMatch(authenticate, /<p[^>]*>شماره موبایل، نام کاربری یا ایمیل خود را وارد کنید\.<\/p>/);
    assert.match(
        password,
        /<label for="pinova-password">رمز عبور خود را وارد کنید<\/label>/,
    );
    assert.doesNotMatch(password, /رمز عبور حساب خود را وارد کنید\./);
    assert.match(accountCss, /\.pinova-auth-field label\s*{[^}]*font-weight:\s*800/is);
});

test('password controls are LTR with the visibility toggle on the right', () => {
    for (const id of [
        'pinova-password',
        'pinova-password-new',
        'pinova-password-confirm',
    ]) {
        assert.match(template, new RegExp(`id="${id}"[^>]*dir="ltr"[^>]*required`, 'i'));
    }

    assert.match(accountCss, /\.pinova-password-field\s*{[^}]*direction:\s*ltr/is);
    assert.match(
        accountCss,
        /button\.pinova-password-toggle\s*{[^}]*right:\s*3px[^}]*left:\s*auto/is,
    );
    assert.match(
        accountCss,
        /\.pinova-password-field input\s*{[^}]*padding-right:\s*54px[^}]*text-align:\s*left/is,
    );
});

test('password alternatives are equal-width normal-weight link actions with a pipe', () => {
    const password = formMarkup('loginByPassword');

    assert.match(password, /class="pinova-auth-actions pinova-auth-actions--split"/);
    assert.match(password, />ورود با رمز یک‌بارمصرف<\/button>/);
    assert.match(password, />رمز عبور را فراموش کرده‌ام<\/button>/);
    assert.match(
        accountCss,
        /\.pinova-auth-actions--split\s*{[^}]*display:\s*grid[^}]*grid-template-columns:\s*repeat\(2,\s*50%\)/is,
    );
    assert.match(accountCss, /\.pinova-auth-actions--split::after\s*{[^}]*content:\s*"\|"/is);
    assert.match(
        accountCss,
        /\.pinova-auth-actions--split button\s*{[^}]*width:\s*100%[^}]*font-weight:\s*400[^}]*text-decoration:\s*underline/is,
    );
});

test('OTP steps keep the safe server copy and an explicit edit action', () => {
    for (const id of ['signIn', 'loginByOtp', 'forgotPassword']) {
        const form = formMarkup(id);
        assert.match(form, /class="pinova-auth-description pinova-auth-otp-copy"/);
        assert.match(form, new RegExp(`pinova-text="forms\\.${id}\\.msg"`));
        assert.match(form, /class="pinova-auth-edit"/);
        assert.match(form, /pinova-on:click="editIdentifier\(\)"/);
        assert.match(form, /pinova-text="identifierEditLabel\(\)"/);
    }
});

test('OTP boxes are a dynamic visual layer over one accessible input', () => {
    for (const id of ['signIn', 'loginByOtp', 'forgotPassword']) {
        const form = formMarkup(id);
        assert.equal(
            (form.match(/autocomplete="one-time-code"/g) || []).length,
            1,
            `${id} should expose exactly one one-time-code input`,
        );
        assert.match(form, /class="pinova-auth-code-input"/);
        assert.match(form, /--pinova-code-length:\s*\$\{codeLength\}/);
        assert.match(form, /pinova-for="digitIndex in codeLength"/);
        assert.match(form, /class="pinova-auth-code-slots" aria-hidden="true"/);
        assert.match(form, /\.charAt\(digitIndex - 1\)/);
        assert.match(form, /type="text" inputmode="numeric" autocomplete="one-time-code"/);
        assert.match(form, new RegExp(`pinova-on:input="handleOtpInput\\('${id}'\\)"`));
        assert.match(form, /pinova-on:focus="\$el\.setSelectionRange\(\$el\.value\.length, \$el\.value\.length\)"/);
        assert.match(form, /pinova-on:click="\$el\.setSelectionRange\(\$el\.value\.length, \$el\.value\.length\)"/);
    }
});

test('OTP steps keep verification primary and resend secondary to the task', () => {
    for (const id of ['signIn', 'loginByOtp', 'forgotPassword']) {
        const form = formMarkup(id);
        const codeInput = form.indexOf('autocomplete="one-time-code"');
        const primary = form.indexOf('class="pinova-auth-primary"');
        const resend = form.indexOf('class="pinova-auth-resend"');

        assert.ok(codeInput >= 0, `${id} should retain OTP autocomplete`);
        assert.ok(primary > codeInput, `${id} primary action should follow the code field`);
        assert.ok(resend > primary, `${id} resend should follow the primary action`);
        assert.doesNotMatch(form, /pinova-bind:disabled="!time\.btnResendIsActive"/);
        assert.match(form, /ارسال دوبارهٔ کد تا/);
        assert.match(form, /pinova-show="time\.btnResendIsActive"/);
    }

    for (const id of ['loginByOtp', 'forgotPassword']) {
        const form = formMarkup(id);
        assert.ok(
            form.indexOf('ورود با رمز عبور') > form.indexOf('class="pinova-auth-resend"'),
            `${id} alternate password method should come after resend`,
        );
    }
});

test('password recovery resend remains in the recovery workflow', () => {
    assert.match(
        formMarkup('forgotPassword'),
        /authenticate\(\{forget: '1', force_otp: '1'\}, 'forgotPassword'\)/,
    );
    assert.match(
        themePartial,
        /authenticate\(\{forget\s*:\s*'1', force_otp\s*:\s*'1'\}, 'forgotPassword'\)/,
    );
});

test('OTP guidance follows the configured code length without hard-coding four digits', () => {
    for (const id of ['signIn', 'loginByOtp', 'forgotPassword']) {
        const form = formMarkup(id);
        assert.match(form, /pinova-text="`کد \$\{codeLength\} رقمی را وارد کنید\.`"/);
        assert.match(form, /aria-describedby="[^"]*-hint [^"]*-error"/);
        assert.doesNotMatch(form, /کد ۴ رقمی|کد 4 رقمی/);
    }
});

test('account stylesheet protects small screens, focus, and touch targets', () => {
    assert.match(template, /assets\/css\/account\.css/i);
    assert.match(accountCss, /min-width:\s*320px/i);
    assert.match(accountCss, /:focus-visible/i);
    assert.match(accountCss, /min-height:\s*44px/i);
    assert.match(accountCss, /\.pinova-auth-layout\s*{[^}]*max-width:\s*620px/is);
    assert.doesNotMatch(accountCss, /\.pinova-auth-layout\s*{[^}]*grid-template-columns/is);
    assert.match(accountCss, /\.pinova-auth-card\s*{[^}]*direction:\s*rtl/is);
    assert.match(
        accountCss,
        /\.pinova-auth-logo\s*{[^}]*left:\s*50%[^}]*width:\s*min\(240px,\s*calc\(100%\s*-\s*104px\)\)[^}]*transform:\s*translate\(-50%,\s*-50%\)/is,
    );
    assert.match(accountCss, /\.pinova-auth-logo img\s*{[^}]*object-fit:\s*contain/is);
    assert.match(accountCss, /@media\s*\(max-width:\s*420px\)/i);
    assert.match(
        accountCss,
        /@media\s*\(max-width:\s*420px\)[\s\S]*\.pinova-auth-main\s*{[^}]*flex:\s*1 0 auto[^}]*justify-content:\s*center/is,
    );
    assert.match(
        accountCss,
        /@media\s*\(max-width:\s*420px\)[\s\S]*\.pinova-auth-logo\s*{[^}]*width:\s*min\(210px,\s*calc\(100%\s*-\s*104px\)\)[^}]*height:\s*70px/is,
    );
    assert.match(accountCss, /@media\s*\(forced-colors:\s*active\)/i);
    assert.match(accountCss, /outline-color:\s*Highlight/i);
    assert.match(accountCss, /env\(safe-area-inset-top,\s*0px\)/i);
    assert.match(accountCss, /env\(safe-area-inset-right,\s*0px\)/i);
    assert.match(accountCss, /env\(safe-area-inset-bottom,\s*0px\)/i);
    assert.match(accountCss, /env\(safe-area-inset-left,\s*0px\)/i);
    assert.doesNotMatch(accountCss, /overflow-x:\s*auto/i);
});

test('account typography and paragraph spacing override the shared reset', () => {
    assert.match(
        accountCss,
        /body\.pinova-account-page\s*{[^}]*font-family:\s*"Yekan Bakh FaNum",\s*Tahoma,\s*sans-serif/is,
    );
    for (const stylesheet of [sharedCss, sharedCssSource, sharedCssIntermediate]) {
        assert.match(stylesheet, /YekanBakhFaNum-Thin\.woff2/);
        assert.doesNotMatch(stylesheet, /YekanBakhFaNum-thin\.woff2/);
    }

    for (const selector of [
        'pinova-auth-description',
        'pinova-auth-otp-copy',
        'pinova-auth-error',
        'pinova-auth-hint',
        'pinova-auth-resend-status',
    ]) {
        assert.match(
            accountCss,
            new RegExp(`\\.pinova-account-page\\s+\\.${selector}\\s*\\{`, 'i'),
        );
    }
});

test('control borders and the single focus outline meet non-text contrast', () => {
    assert.ok(contrastRatio(cssHexVariable('gp-control-border'), '#ffffff') >= 3);
    assert.ok(contrastRatio(cssHexVariable('gp-focus'), '#ffffff') >= 3);
    assert.match(
        accountCss,
        /input:focus-visible\s*{[^}]*outline:\s*2px solid var\(--gp-focus\)/is,
    );
    assert.match(
        accountCss,
        /\.pinova-auth-code-input:focus-within \.pinova-auth-code-slot\.is-active\s*{[^}]*box-shadow:\s*none[^}]*outline:\s*2px solid var\(--gp-focus\)/is,
    );
    assert.doesNotMatch(
        accountCss,
        /(?:input:focus|focus-within)[^{]*\{[^}]*border-color:\s*var\(--gp-focus\)/is,
    );
    assert.doesNotMatch(accountCss, /0 0 0 4px var\(--gp-orange-soft\)/i);
});

test('the account page is one centered card with one tertiary store exit', () => {
    assert.doesNotMatch(template, /<aside\b/i);
    assert.doesNotMatch(template, /pinova-auth-intro/);
    assert.equal((template.match(/class="pinova-auth-store-link"/g) || []).length, 1);

    const cardStart = template.indexOf('<section class="pinova-auth-card"');
    const storeLink = template.indexOf('class="pinova-auth-store-link"');
    const cardEnd = template.indexOf('</section>', cardStart);

    assert.ok(cardStart >= 0 && storeLink > cardStart && storeLink < cardEnd);
    assert.match(
        template,
        /class="pinova-auth-store-link" href="<\?php echo esc_url\( \$home_url \); \?>"/,
    );
});

test('account stylesheet uses the GPANTE palette and unambiguous actions', () => {
    assert.match(accountCss, /--gp-orange:\s*#f4a51c/i);
    assert.match(accountCss, /--gp-orange-hover:\s*#d98b08/i);
    assert.match(accountCss, /--gp-background:\s*#f8f3ea/i);
    assert.match(
        accountCss,
        /\.pinova-auth-store-link\s*{[^}]*width:\s*100%/is,
    );
    assert.match(
        accountCss,
        /\.pinova-auth-store-link\s*{[^}]*background:\s*transparent[^}]*box-shadow:\s*none/is,
    );
    assert.doesNotMatch(
        accountCss,
        /\.pinova-auth-store-link\s*{[^}]*background:\s*var\(--gp-dark\)/is,
    );
    assert.match(
        accountCss,
        /button\.pinova-auth-primary\s*{[^}]*background-color:\s*var\(--gp-orange\)/is,
    );
    assert.match(accountCss, /\.pinova-auth-resend-status\s*{/);
    assert.match(accountCss, /button\.pinova-auth-resend-button\s*{/);
    assert.match(accountCss, /\.pinova-auth-code-slots\s*{[^}]*repeat\(var\(--pinova-code-length\)/is);
    assert.match(accountCss, /unicode-bidi:\s*plaintext/i);
});
