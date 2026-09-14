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

test('dead links are absent and no-JavaScript/native-login fallbacks exist', () => {
    assert.doesNotMatch(template, /href=["']#["']/i);
    assert.match(template, /<noscript>/i);
    assert.match(template, /wp_login_url/i);
});

test('account stylesheet protects small screens, focus, and touch targets', () => {
    assert.match(template, /assets\/css\/account\.css/i);
    assert.match(accountCss, /min-width:\s*320px/i);
    assert.match(accountCss, /:focus-visible/i);
    assert.match(accountCss, /min-height:\s*44px/i);
    assert.match(accountCss, /grid-template-rows:\s*auto\s+1fr/i);
    assert.match(accountCss, /@media\s*\(max-width:\s*420px\)/i);
    assert.doesNotMatch(accountCss, /overflow-x:\s*auto/i);
});
