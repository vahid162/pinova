import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const template = await readFile(
    new URL('../../templates/login-form.php', import.meta.url),
    'utf8',
);
const accountCss = await readFile(
    new URL('../../assets/css/account.css', import.meta.url),
    'utf8',
);

function formMarkup(id) {
    const match = template.match(new RegExp(`<form id="${id}"[\\s\\S]*?<\\/form>`));
    assert.ok(match, `form ${id} should exist`);
    return match[0];
}

test('standalone account document declares Persian RTL semantics', () => {
    assert.match(template, /<html[^>]*lang="fa"[^>]*dir="rtl"/i);
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
    assert.match(accountCss, /@media\s*\(max-width:\s*420px\)/i);
    assert.match(accountCss, /@media\s*\(forced-colors:\s*active\)/i);
    assert.match(accountCss, /outline-color:\s*Highlight/i);
    assert.doesNotMatch(accountCss, /overflow-x:\s*auto/i);
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
