import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const template = await readFile(
    new URL('../../templates/login-modal.php', import.meta.url),
    'utf8',
);
const accountCss = await readFile(
    new URL('../../assets/css/account.css', import.meta.url),
    'utf8',
);
const wooLoader = await readFile(
    new URL('../../src/Integrations/Woocommerce/Load.php', import.meta.url),
    'utf8',
);

function formMarkup(step) {
    const match = template.match(
        new RegExp(`<form id="pinova-modal-${step}"[\\s\\S]*?<\\/form>`),
    );
    assert.ok(match, `modal form ${step} should exist`);
    return match[0];
}

test('checkout authentication is a labelled modal with explicit close behavior', () => {
    assert.match(template, /class="pinova-container pinova-auth-modal pinova-account-page"/);
    assert.match(template, /role="dialog"/);
    assert.match(template, /aria-modal="true"/);
    assert.match(template, /aria-labelledby="pinova-checkout-dialog-title"/);
    assert.match(template, /pinova-on:keydown="handleDialogKeydown\(\$event\)"/);
    assert.match(template, /aria-label="بستن پنجرهٔ ورود"/);
    assert.match(template, /aria-label="بازگشت به مرحله قبل"/);
    assert.match(template, /class="pinova-auth-logo" href="<\?php echo esc_url\( \$home_url \); \?>"/);
    assert.doesNotMatch(template, /<div[^>]*pinova-on:click=/i);
});

test('modal busy state is announced, keeps task content inert, and leaves close available', () => {
    assert.match(template, /pinova-bind:aria-busy="pageLoaderIsActive"/);
    assert.match(template, /class="pinova-auth-loader"[\s\S]*role="status"/);
    assert.match(template, /aria-live="polite"/);
    assert.match(
        template,
        /class="pinova-auth-main"[^>]*pinova-bind:inert="pageLoaderIsActive"/,
    );
    assert.match(template, /class="pinova-auth-logo"[^\n]*pinova-bind:inert="pageLoaderIsActive"/);

    const closeButton = template.match(
        /<button(?=[^>]*class="pinova-auth-close-button")[^>]*>/,
    );
    assert.ok(closeButton);
    assert.doesNotMatch(closeButton[0], /pinova-bind:disabled=/);
});

test('every modal step is a semantic form with native required fields and live errors', () => {
    assert.equal((template.match(/<form\b/gi) || []).length, 6);

    for (const step of [
        'authenticate',
        'signIn',
        'loginByPassword',
        'loginByOtp',
        'forgotPassword',
        'changePassword',
    ]) {
        const form = formMarkup(step);
        assert.match(form, /pinova-on:submit\.prevent="submit\(\)"/);
        assert.match(form, /data-pinova-step-heading[^>]*tabindex="-1"/);
    }

    assert.equal((template.match(/\brequired(?:\s|>)/gi) || []).length, 7);
    assert.equal((template.match(/role="alert"/gi) || []).length, 7);
    assert.doesNotMatch(template, /pinova-html=/i);
});

test('modal copy and password controls match the corrected standalone interface', () => {
    const authenticate = formMarkup('authenticate');
    const password = formMarkup('loginByPassword');

    assert.match(
        authenticate,
        /<label for="pinova-modal-identifier">شماره موبایل، نام کاربری یا ایمیل خود را وارد کنید<\/label>/,
    );
    assert.doesNotMatch(authenticate, /سلام!/);
    assert.match(authenticate, /id="pinova-modal-identifier"[^>]*dir="auto"[^>]*required/);

    assert.match(
        password,
        /<label for="pinova-modal-password">رمز عبور خود را وارد کنید<\/label>/,
    );
    assert.match(password, /id="pinova-modal-password"[^>]*dir="ltr"[^>]*required/);
    assert.match(password, /class="pinova-password-toggle"/);
    assert.match(password, /class="pinova-auth-actions pinova-auth-actions--split"/);
    assert.match(password, />ورود با رمز یک‌بارمصرف<\/button>/);
    assert.match(password, />رمز عبور را فراموش کرده‌ام<\/button>/);
});

test('modal OTP presentation keeps one real configured-length input', () => {
    for (const step of ['signIn', 'loginByOtp', 'forgotPassword']) {
        const form = formMarkup(step);
        assert.equal((form.match(/autocomplete="one-time-code"/g) || []).length, 1);
        assert.match(form, /pinova-for="digitIndex in codeLength"/);
        assert.match(form, /class="pinova-auth-code-slots" aria-hidden="true"/);
        assert.match(form, /type="text" inputmode="numeric" autocomplete="one-time-code"/);
        assert.match(form, new RegExp(`handleOtpInput\\('${step}'\\)`));
    }

    assert.match(
        formMarkup('forgotPassword'),
        /authenticate\(\{forget: '1', force_otp: '1'\}, 'forgotPassword'\)/,
    );
});

test('modal uses the shared branded account stylesheet with a cache revision', () => {
    assert.match(accountCss, /\.pinova-auth-modal\b/);
    assert.match(
        accountCss,
        /\.pinova-auth-modal button,[\s\S]*\.pinova-auth-modal input \{[\s\S]*font-family: "Yekan Bakh FaNum"[^;]*!important;/,
    );
    assert.match(
        accountCss,
        /@media \(max-width: 420px\)[\s\S]*\.pinova-auth-modal \.pinova-auth-card \{[\s\S]*min-height: 100dvh;/,
    );
    assert.match(
        wooLoader,
        /wp_enqueue_style\(\s*'pinova-account'[\s\S]*assets\/css\/account\.css/,
    );
    assert.match(
        wooLoader,
        /wp_enqueue_script\(\s*'pinova-login-modal'[\s\S]*\[\s*'pinova-global'\s*\]/,
    );
    assert.match(wooLoader, /PINOVA_VERSION\s*\.\s*'\.4'/);
});

test('modal is rendered outside the checkout form and replaceable fragments', () => {
    assert.match(
        wooLoader,
        /add_action\(\s*'wp_footer',\s*\[\s*\$this,\s*'checkout_login_modal'\s*\]\s*\)/,
    );
    assert.doesNotMatch(wooLoader, /woocommerce_review_order_after_submit/);
    assert.match(
        wooLoader,
        /function checkout_login_modal\(\)[\s\S]*! is_checkout\(\)[\s\S]*is_order_received_page\(\)/,
    );
});

test('placeholder policy links are absent from the modal', () => {
    assert.doesNotMatch(template, /href=["']#["']/i);
    assert.doesNotMatch(template, />\s*شرایط\s*</);
    assert.doesNotMatch(template, />\s*قوانین\s*</);
});
