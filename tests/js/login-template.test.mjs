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
    assert.ok((template.match(/<form\b/gi) || []).length >= 6);
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
});

test('OTP steps expose the submitted destination and an explicit edit action', () => {
    for (const id of ['signIn', 'loginByOtp', 'forgotPassword']) {
        const form = formMarkup(id);
        assert.match(form, /class="pinova-auth-destination"/);
        assert.match(form, /forms\.authenticate\.inputs\.identifier\.value/);
        assert.match(form, /pinova-on:click="editIdentifier\(\)"/);
        assert.match(form, /pinova-text="identifierEditLabel\(\)"/);
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

    const loginByOtp = formMarkup('loginByOtp');
    assert.ok(
        loginByOtp.indexOf('ورود با رمز عبور') > loginByOtp.indexOf('class="pinova-auth-resend"'),
        'the alternate password method should come after resend',
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
    assert.match(accountCss, /\.pinova-auth-card\s*{[^}]*grid-row:\s*1/is);
    assert.match(accountCss, /\.pinova-auth-intro\s*{[^}]*grid-row:\s*2/is);
    assert.match(accountCss, /\.pinova-auth-layout\s*{[^}]*direction:\s*ltr/is);
    assert.match(accountCss, /\.pinova-auth-card\s*{[^}]*direction:\s*rtl/is);
    assert.match(accountCss, /\.pinova-auth-intro\s*{[^}]*direction:\s*rtl/is);
    assert.match(accountCss, /@media\s*\(max-width:\s*420px\)/i);
    assert.doesNotMatch(accountCss, /overflow-x:\s*auto/i);
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
        /\.pinova-auth-store-link\s*{[^}]*background:\s*rgba\(255,\s*255,\s*255,\s*0\.28\)[^}]*box-shadow:\s*none/is,
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
    assert.match(accountCss, /unicode-bidi:\s*plaintext/i);
});
