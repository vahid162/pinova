import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';

const repositoryRoot = fileURLToPath(new URL('../..', import.meta.url));
const authenticateRoute = '**/pinova/user/authenticate*';
const otpRoute = '**/pinova/user/login/otp*';

function prepareCheckoutFixture() {
    const fixtureScript = String.raw`
update_option('woocommerce_enable_guest_checkout', 'yes');
update_option('woocommerce_enable_checkout_login_reminder', 'yes');
\Pinova\Pinova::set_option('general.woocommerce_checkout_registration_required', 'no');

$product_id = wc_get_product_id_by_sku('pinova-browser-modal-product');
if (!$product_id) {
    $product = new \WC_Product_Simple();
    $product->set_name('Pinova browser modal product');
    $product->set_slug('pinova-browser-modal-product');
    $product->set_sku('pinova-browser-modal-product');
    $product->set_regular_price('10000');
    $product->set_status('publish');
    $product_id = $product->save();
}

$checkout = get_page_by_path('pinova-browser-checkout', OBJECT, 'page');
$checkout_data = [
    'ID' => $checkout ? $checkout->ID : 0,
    'post_type' => 'page',
    'post_status' => 'publish',
    'post_name' => 'pinova-browser-checkout',
    'post_title' => 'Pinova browser checkout',
    'post_content' => '[woocommerce_checkout]',
];
$checkout_id = $checkout ? wp_update_post($checkout_data) : wp_insert_post($checkout_data);
update_option('woocommerce_checkout_page_id', $checkout_id);

$fixture = get_page_by_path('pinova-browser-fixture', OBJECT, 'page');
$fixture_data = [
    'ID' => $fixture ? $fixture->ID : 0,
    'post_type' => 'page',
    'post_status' => 'publish',
    'post_name' => 'pinova-browser-fixture',
    'post_title' => 'Pinova browser fixture',
    'post_content' => sprintf(
        '<a id="pinova-test-add-to-cart" href="%s">Add fixture product</a>',
        esc_url(home_url('/?add-to-cart=' . $product_id))
    ),
];
$fixture ? wp_update_post($fixture_data) : wp_insert_post($fixture_data);
`;

    execFileSync(
        'npx',
        ['wp-env', 'run', 'cli', 'wp', 'eval', fixtureScript],
        {
            cwd: repositoryRoot,
            encoding: 'utf8',
            env: process.env,
            stdio: ['ignore', 'pipe', 'pipe'],
        },
    );
}

function setCodeLength(length) {
    expect([4, 5, 6]).toContain(length);
    execFileSync(
        'npx',
        [
            'wp-env',
            'run',
            'cli',
            'wp',
            'eval',
            `\\Pinova\\Pinova::set_option('advanced.code_length', ${length});`,
        ],
        {
            cwd: repositoryRoot,
            encoding: 'utf8',
            env: process.env,
            stdio: ['ignore', 'pipe', 'pipe'],
        },
    );
}

function responseBody({ success, message, data = {} }) {
    return JSON.stringify({ success, message, data });
}

async function openLogin(page) {
    const response = await page.goto('/login', { waitUntil: 'domcontentloaded' });
    expect(response?.ok()).toBe(true);
    await expect(page.locator('#authenticate')).toBeVisible();
}

async function openCheckout(page) {
    const fixtureResponse = await page.goto('/pinova-browser-fixture/', {
        waitUntil: 'domcontentloaded',
    });
    expect(fixtureResponse?.ok()).toBe(true);

    const addToCartUrl = await page.locator('#pinova-test-add-to-cart').getAttribute('href');
    expect(addToCartUrl).toContain('add-to-cart=');

    await page.goto(addToCartUrl, {
        waitUntil: 'domcontentloaded',
    });
    const checkoutResponse = await page.goto('/pinova-browser-checkout/', {
        waitUntil: 'domcontentloaded',
    });
    expect(checkoutResponse?.ok()).toBe(true);

    await expect(page.locator('#billing_phone')).toBeVisible();
    await expect(page.locator('#pinovaLoginModal')).toBeAttached();
    await expect(page.locator('form.checkout #pinovaLoginModal')).toHaveCount(0);
    await page.waitForFunction(() => Boolean(
        document.querySelector('#pinovaLoginModal')?._x_dataStack?.[0],
    ));
}

test.beforeAll(() => {
    prepareCheckoutFixture();
});

async function mockOtpStart(page) {
    await page.route(authenticateRoute, async route => {
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: responseBody({
                success: true,
                message: 'کد آزمایشی ارسال شد.',
                data: {
                    login_method: 'otp',
                    jwt: 'browser-acceptance-jwt',
                    ttl: 120,
                },
            }),
        });
    });
}

async function mockPasswordStart(page) {
    await page.route(authenticateRoute, async route => {
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: responseBody({
                success: true,
                message: 'ورود با رمز عبور',
                data: {
                    login_method: 'password',
                    ttl: 120,
                },
            }),
        });
    });
}

async function enterOtpStep(page) {
    await openLogin(page);
    await page.locator('#pinova-identifier').fill('09121234567');
    await page.locator('#authenticate button[type="submit"]').click();
    await expect(page.locator('#loginByOtp')).toBeVisible();
    await page.waitForTimeout(150);
    await expect(page.locator('#loginByOtp [data-pinova-step-heading]')).toBeFocused();
    await expect(page.locator('#pinova-login-otp')).not.toBeFocused();
}

function rgb(value) {
    const channels = value.match(/[\d.]+/g)?.map(Number) || [];
    if (channels.length < 3) {
        throw new Error(`Unsupported computed color: ${value}`);
    }
    return channels.slice(0, 3);
}

function relativeLuminance([red, green, blue]) {
    const channels = [red, green, blue].map(channel => {
        const normalized = channel / 255;
        return normalized <= 0.04045
            ? normalized / 12.92
            : ((normalized + 0.055) / 1.055) ** 2.4;
    });
    return (0.2126 * channels[0]) + (0.7152 * channels[1]) + (0.0722 * channels[2]);
}

function contrastRatio(first, second) {
    const light = Math.max(relativeLuminance(rgb(first)), relativeLuminance(rgb(second)));
    const dark = Math.min(relativeLuminance(rgb(first)), relativeLuminance(rgb(second)));
    return (light + 0.05) / (dark + 0.05);
}

test('real login page loads Yekan and preserves spacing, contrast, and one focus ring', async ({ page }) => {
    setCodeLength(4);
    const fontResponses = [];
    page.on('response', response => {
        if (/\.woff2(?:\?|$)/i.test(response.url())) {
            fontResponses.push({ status: response.status(), url: response.url() });
        }
    });

    await openLogin(page);
    await page.waitForTimeout(150);

    const initialFocus = await page.evaluate(() => document.activeElement?.tagName || '');
    expect(['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON']).not.toContain(initialFocus);

    const presentation = await page.evaluate(async () => {
        const loadedFaces = await document.fonts.load('16px "Yekan Bakh FaNum"');
        await document.fonts.ready;
        const bodyStyle = getComputedStyle(document.body);
        const inputStyle = getComputedStyle(document.querySelector('#pinova-identifier'));
        const leadFieldStyle = getComputedStyle(document.querySelector('.pinova-auth-field--lead'));
        const shellStyle = getComputedStyle(document.querySelector('.pinova-auth-shell'));
        return {
            loadedFaceCount: loadedFaces.length,
            bodyFont: bodyStyle.fontFamily,
            inputFont: inputStyle.fontFamily,
            borderColor: inputStyle.borderTopColor,
            backgroundColor: inputStyle.backgroundColor,
            leadFieldMarginTop: parseFloat(leadFieldStyle.marginTop),
            safeAreaSupported: CSS.supports('padding-top', 'env(safe-area-inset-top, 0px)'),
            shellPadding: [
                shellStyle.paddingTop,
                shellStyle.paddingRight,
                shellStyle.paddingBottom,
                shellStyle.paddingLeft,
            ].map(parseFloat),
            viewport: document.querySelector('meta[name="viewport"]')?.content || '',
        };
    });

    expect(presentation.loadedFaceCount).toBeGreaterThan(0);
    expect(presentation.bodyFont).toContain('Yekan Bakh FaNum');
    expect(presentation.inputFont).toContain('Yekan Bakh FaNum');
    expect(presentation.leadFieldMarginTop).toBeGreaterThan(0);
    expect(presentation.safeAreaSupported).toBe(true);
    expect(presentation.shellPadding.every(value => value > 0)).toBe(true);
    expect(presentation.viewport).toContain('viewport-fit=cover');
    expect(contrastRatio(presentation.borderColor, presentation.backgroundColor)).toBeGreaterThanOrEqual(3);
    expect(fontResponses.some(item => item.status === 200 && item.url.includes('YekanBakhFaNum-Regular.woff2'))).toBe(true);

    const identifier = page.locator('#pinova-identifier');
    await identifier.focus();
    await page.keyboard.press('Tab');
    await page.keyboard.press('Shift+Tab');
    await expect(identifier).toBeFocused();
    const focusStyle = await identifier.evaluate(element => {
        const style = getComputedStyle(element);
        return {
            outlineStyle: style.outlineStyle,
            outlineWidth: parseFloat(style.outlineWidth),
            boxShadow: style.boxShadow,
        };
    });
    expect(focusStyle.outlineStyle).not.toBe('none');
    expect(focusStyle.outlineWidth).toBeGreaterThanOrEqual(2);
    expect(focusStyle.boxShadow).toBe('none');
});

test('logo stays centered and the mobile content group uses the remaining viewport', async ({ page }) => {
    setCodeLength(4);
    await page.setViewportSize({ width: 1440, height: 900 });
    await openLogin(page);

    const desktopLogo = await page.evaluate(() => {
        const header = document.querySelector('.pinova-auth-header').getBoundingClientRect();
        const logo = document.querySelector('.pinova-auth-logo').getBoundingClientRect();
        return {
            headerCenter: header.left + (header.width / 2),
            logoCenter: logo.left + (logo.width / 2),
            width: logo.width,
            height: logo.height,
        };
    });
    expect(Math.abs(desktopLogo.headerCenter - desktopLogo.logoCenter)).toBeLessThanOrEqual(1);
    expect(desktopLogo.width).toBeCloseTo(240, 0);
    expect(desktopLogo.height).toBeCloseTo(80, 0);

    await page.setViewportSize({ width: 390, height: 844 });
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    const mobileLayout = await page.evaluate(() => {
        const header = document.querySelector('.pinova-auth-header').getBoundingClientRect();
        const logo = document.querySelector('.pinova-auth-logo').getBoundingClientRect();
        const main = document.querySelector('.pinova-auth-main');
        const mainRect = main.getBoundingClientRect();
        const visibleChildren = [...main.children]
            .filter(element => element.getBoundingClientRect().height > 0)
            .map(element => element.getBoundingClientRect());
        const groupTop = Math.min(...visibleChildren.map(rect => rect.top));
        const groupBottom = Math.max(...visibleChildren.map(rect => rect.bottom));
        return {
            headerBottom: header.bottom,
            headerCenter: header.left + (header.width / 2),
            logoCenter: logo.left + (logo.width / 2),
            logoWidth: logo.width,
            logoHeight: logo.height,
            mainTop: mainRect.top,
            spaceBefore: groupTop - mainRect.top,
            spaceAfter: mainRect.bottom - groupBottom,
            justifyContent: getComputedStyle(main).justifyContent,
        };
    });

    expect(Math.abs(mobileLayout.headerCenter - mobileLayout.logoCenter)).toBeLessThanOrEqual(1);
    expect(mobileLayout.logoWidth).toBeCloseTo(210, 0);
    expect(mobileLayout.logoHeight).toBeCloseTo(70, 0);
    expect(mobileLayout.mainTop).toBeGreaterThanOrEqual(mobileLayout.headerBottom);
    expect(mobileLayout.justifyContent).toBe('center');
    expect(Math.abs(mobileLayout.spaceBefore - mobileLayout.spaceAfter)).toBeLessThanOrEqual(20);
});

test('password step uses an LTR field, right-side toggle, and split link actions', async ({ page }) => {
    setCodeLength(4);
    await mockPasswordStart(page);
    await openLogin(page);
    await page.locator('#pinova-identifier').fill('administrator');
    await page.locator('#authenticate button[type="submit"]').click();
    await expect(page.locator('#loginByPassword')).toBeVisible();

    const presentation = await page.evaluate(() => {
        const form = document.querySelector('#loginByPassword');
        const field = form.querySelector('.pinova-password-field').getBoundingClientRect();
        const input = form.querySelector('#pinova-password');
        const toggle = form.querySelector('.pinova-password-toggle').getBoundingClientRect();
        const actions = form.querySelector('.pinova-auth-actions--split');
        const actionButtons = [...actions.querySelectorAll('button')];
        const buttonRects = actionButtons.map(button => button.getBoundingClientRect());
        const buttonStyles = actionButtons.map(button => getComputedStyle(button));
        return {
            descriptionPresent: Boolean(form.querySelector('.pinova-auth-description')),
            label: form.querySelector('label[for="pinova-password"]').textContent.trim(),
            inputDirection: getComputedStyle(input).direction,
            inputTextAlign: getComputedStyle(input).textAlign,
            toggleRightGap: Math.abs(field.right - toggle.right - 3),
            widths: buttonRects.map(rect => rect.width),
            fontWeights: buttonStyles.map(style => style.fontWeight),
            decorations: buttonStyles.map(style => style.textDecorationLine),
            separator: getComputedStyle(actions, '::after').content,
        };
    });

    expect(presentation.descriptionPresent).toBe(false);
    expect(presentation.label).toBe('رمز عبور خود را وارد کنید');
    expect(presentation.inputDirection).toBe('ltr');
    expect(presentation.inputTextAlign).toBe('left');
    expect(presentation.toggleRightGap).toBeLessThanOrEqual(1);
    expect(Math.abs(presentation.widths[0] - presentation.widths[1])).toBeLessThanOrEqual(1);
    expect(presentation.fontWeights).toEqual(['400', '400']);
    expect(presentation.decorations.every(value => value.includes('underline'))).toBe(true);
    expect(presentation.separator).toBe('"|"');
});

test('blank validation announces the error and focuses the first invalid field', async ({ page }) => {
    setCodeLength(4);
    await openLogin(page);

    const identifier = page.locator('#pinova-identifier');
    await expect(identifier).toHaveAttribute('required', '');
    await page.locator('#authenticate button[type="submit"]').click();

    const error = page.locator('#pinova-identifier-error');
    await expect(error).toBeVisible();
    await expect(error).toHaveAttribute('role', 'alert');
    await expect(error).toHaveAttribute('aria-atomic', 'true');
    await expect(error).not.toHaveText('');
    await expect(identifier).toHaveAttribute('aria-invalid', 'true');
    await expect(identifier).toBeFocused();
    expect(await error.evaluate(element => parseFloat(getComputedStyle(element).marginTop))).toBeGreaterThan(0);
});

test('busy state makes content inert and locks submit, resend, and alternate actions', async ({ page }) => {
    setCodeLength(4);
    let authenticateRequests = 0;
    let releaseRequest;
    const requestGate = new Promise(resolve => {
        releaseRequest = resolve;
    });

    await page.route(authenticateRoute, async route => {
        authenticateRequests += 1;
        await requestGate;
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: responseBody({ success: false, message: 'پاسخ آزمایشی' }),
        });
    });

    await openLogin(page);
    await page.locator('#pinova-identifier').fill('buyer@example.test');
    await page.locator('#authenticate').evaluate(form => {
        form.requestSubmit();
        form.requestSubmit();
        const state = form.closest('[pinova-data]')?._x_dataStack?.[0];
        state?.authenticate({ force_otp: '1' });
        state?.authenticate({ forget: '1' }, 'forgotPassword');
        state?.loginByPassword();
    });

    const layout = page.locator('.pinova-auth-layout');
    const content = page.locator('.pinova-auth-content');
    try {
        await expect(layout).toHaveAttribute('aria-busy', 'true');
        await expect.poll(() => content.evaluate(element => element.inert && element.hasAttribute('inert'))).toBe(true);
        await expect.poll(() => authenticateRequests).toBe(1);
        await page.locator('#authenticate').evaluate(form => form.requestSubmit());
        await page.waitForTimeout(100);
        expect(authenticateRequests).toBe(1);
    } finally {
        releaseRequest();
    }

    await expect.poll(() => content.evaluate(element => element.inert)).toBe(false);
});

test('checkout errors stay inline and the explicit login action opens an accessible modal', async ({ page }) => {
    setCodeLength(4);
    await mockPasswordStart(page);
    await page.setViewportSize({ width: 1440, height: 900 });
    await openCheckout(page);

    const modalViewport = page.locator('.pinova-auth-modal__viewport');
    const phone = page.locator('#billing_phone');
    await page.evaluate(() => {
        const billingPhone = document.querySelector('#billing_phone');
        billingPhone.closest('.form-row').classList.add('woocommerce-invalid-required-field');
        window.jQuery(document.body).trigger('checkout_error');
    });

    await expect(modalViewport).toBeHidden();
    await expect(phone).toBeFocused();

    const opener = page.locator('.showlogin').first();
    await opener.click();
    await expect(modalViewport).toBeVisible();
    await expect(page.locator('#pinova-modal-authenticate [data-pinova-step-heading]')).toBeFocused();

    const openState = await page.evaluate(async () => {
        await document.fonts.load('16px "Yekan Bakh FaNum"');
        await document.fonts.ready;
        const modal = document.querySelector('#pinovaLoginModal');
        const dialog = modal.querySelector('[role="dialog"]');
        const logo = modal.querySelector('.pinova-auth-logo').getBoundingClientRect();
        const header = modal.querySelector('.pinova-auth-header').getBoundingClientRect();
        return {
            labelledBy: dialog.getAttribute('aria-labelledby'),
            modal: dialog.getAttribute('aria-modal'),
            bodyOverflow: document.body.style.overflow,
            inertBackgroundCount: [...document.querySelectorAll('[inert]')]
                .filter(element => !modal.contains(element)).length,
            font: getComputedStyle(modal.querySelector('#pinova-modal-identifier')).fontFamily,
            logoOffset: Math.abs(
                (logo.left + (logo.width / 2)) - (header.left + (header.width / 2)),
            ),
        };
    });

    expect(openState.labelledBy).toBe('pinova-checkout-dialog-title');
    expect(openState.modal).toBe('true');
    expect(openState.bodyOverflow).toBe('hidden');
    expect(openState.inertBackgroundCount).toBeGreaterThan(0);
    expect(openState.font).toContain('Yekan Bakh FaNum');
    expect(openState.logoOffset).toBeLessThanOrEqual(1);

    await page.locator('#pinova-modal-identifier').fill('buyer@example.test');
    await page.locator('#pinova-modal-authenticate button[type="submit"]').click();
    await expect(page.locator('#pinova-modal-loginByPassword')).toBeVisible();
    await expect(page.locator('#pinova-modal-loginByPassword [data-pinova-step-heading]')).toBeFocused();

    const passwordPresentation = await page.evaluate(() => {
        const form = document.querySelector('#pinova-modal-loginByPassword');
        const field = form.querySelector('.pinova-password-field').getBoundingClientRect();
        const input = form.querySelector('#pinova-modal-password');
        const toggle = form.querySelector('.pinova-password-toggle').getBoundingClientRect();
        const actions = form.querySelector('.pinova-auth-actions--split');
        const buttons = [...actions.querySelectorAll('button')];
        return {
            direction: getComputedStyle(input).direction,
            textAlign: getComputedStyle(input).textAlign,
            toggleRightGap: Math.abs(field.right - toggle.right - 3),
            widths: buttons.map(button => button.getBoundingClientRect().width),
            weights: buttons.map(button => getComputedStyle(button).fontWeight),
            separator: getComputedStyle(actions, '::after').content,
        };
    });

    expect(passwordPresentation.direction).toBe('ltr');
    expect(passwordPresentation.textAlign).toBe('left');
    expect(passwordPresentation.toggleRightGap).toBeLessThanOrEqual(1);
    expect(Math.abs(passwordPresentation.widths[0] - passwordPresentation.widths[1])).toBeLessThanOrEqual(1);
    expect(passwordPresentation.weights).toEqual(['400', '400']);
    expect(passwordPresentation.separator).toBe('"|"');

    await page.keyboard.press('Escape');
    await expect(modalViewport).toBeHidden();
    await expect(opener).toBeFocused();

    const closedState = await page.evaluate(() => {
        const modal = document.querySelector('#pinovaLoginModal');
        const state = modal._x_dataStack[0];
        return {
            bodyOverflow: document.body.style.overflow,
            inertBackgroundCount: [...document.querySelectorAll('[inert]')]
                .filter(element => !modal.contains(element)).length,
            step: state.stepName,
            identifier: state.forms.authenticate.inputs.identifier.value,
            password: state.forms.loginByPassword.inputs.password.value,
        };
    });

    expect(closedState.bodyOverflow).toBe('');
    expect(closedState.inertBackgroundCount).toBe(0);
    expect(closedState.step).toBe('authenticate');
    expect(closedState.identifier).toBe('');
    expect(closedState.password).toBe('');
});

test('checkout modal remains dismissible while an authentication request is pending', async ({ page }) => {
    setCodeLength(4);
    let releaseRequest;
    const requestGate = new Promise(resolve => {
        releaseRequest = resolve;
    });

    await page.route(authenticateRoute, async route => {
        await requestGate;
        try {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: responseBody({
                    success: true,
                    message: null,
                    data: { login_method: 'password', ttl: 120 },
                }),
            });
        } catch {
            // Closing the modal aborts the browser request before this delayed response is released.
        }
    });

    await openCheckout(page);
    const opener = page.locator('.showlogin').first();
    const modalViewport = page.locator('.pinova-auth-modal__viewport');
    const modalMain = page.locator('#pinovaLoginModal .pinova-auth-main');
    const closeButton = page.locator('#pinovaLoginModal .pinova-auth-close-button');

    try {
        await opener.click();
        await page.locator('#pinova-modal-identifier').fill('buyer@example.test');
        await page.locator('#pinova-modal-authenticate button[type="submit"]').click();

        await expect(page.locator('#pinovaLoginModal .pinova-auth-layout')).toHaveAttribute('aria-busy', 'true');
        await expect.poll(() => modalMain.evaluate(element => element.inert)).toBe(true);
        await expect(closeButton).toBeVisible();
        await expect(closeButton).toBeEnabled();
        await closeButton.click();

        await expect(modalViewport).toBeHidden();
        await expect(opener).toBeFocused();

        const closedState = await page.evaluate(() => {
            const modal = document.querySelector('#pinovaLoginModal');
            const state = modal._x_dataStack[0];
            return {
                busy: state.pageLoaderIsActive,
                step: state.stepName,
                bodyOverflow: document.body.style.overflow,
                inertBackgroundCount: [...document.querySelectorAll('[inert]')]
                    .filter(element => !modal.contains(element)).length,
            };
        });

        expect(closedState.busy).toBe(false);
        expect(closedState.step).toBe('authenticate');
        expect(closedState.bodyOverflow).toBe('');
        expect(closedState.inertBackgroundCount).toBe(0);
    } finally {
        releaseRequest();
    }
});

test('checkout modal fills the supported mobile viewport without horizontal overflow', async ({ page }) => {
    setCodeLength(6);
    await page.setViewportSize({ width: 390, height: 844 });
    await openCheckout(page);
    await page.locator('.showlogin').first().click();
    await expect(page.locator('.pinova-auth-modal__viewport')).toBeVisible();

    const geometry = await page.evaluate(() => {
        const modal = document.querySelector('#pinovaLoginModal');
        const viewport = modal.querySelector('.pinova-auth-modal__viewport');
        const dialog = modal.querySelector('.pinova-auth-modal__dialog').getBoundingClientRect();
        const card = modal.querySelector('.pinova-auth-card').getBoundingClientRect();
        const header = modal.querySelector('.pinova-auth-header').getBoundingClientRect();
        const logo = modal.querySelector('.pinova-auth-logo').getBoundingClientRect();
        const main = modal.querySelector('.pinova-auth-main').getBoundingClientRect();
        return {
            viewport: { width: window.innerWidth, height: window.innerHeight },
            modalScrollWidth: viewport.scrollWidth,
            dialog: { width: dialog.width, height: dialog.height },
            cardHeight: card.height,
            logoOffset: Math.abs(
                (logo.left + (logo.width / 2)) - (header.left + (header.width / 2)),
            ),
            mainStartsAfterHeader: main.top >= header.bottom,
        };
    });

    expect(geometry.modalScrollWidth).toBeLessThanOrEqual(geometry.viewport.width + 1);
    expect(geometry.dialog.width).toBeCloseTo(geometry.viewport.width, 0);
    expect(geometry.dialog.height).toBeGreaterThanOrEqual(geometry.viewport.height - 1);
    expect(geometry.cardHeight).toBeGreaterThanOrEqual(geometry.viewport.height - 1);
    expect(geometry.logoOffset).toBeLessThanOrEqual(1);
    expect(geometry.mainStartsAfterHeader).toBe(true);
});

for (const codeLength of [4, 5, 6]) {
    test(`one ${codeLength}-digit normalized entry produces exactly one OTP request`, async ({ context, page }) => {
        setCodeLength(codeLength);
        await mockOtpStart(page);
        let otpRequests = 0;
        let requestPayload = '';
        let releaseRequest;
        const requestGate = new Promise(resolve => {
            releaseRequest = resolve;
        });

        await page.route(otpRoute, async route => {
            otpRequests += 1;
            requestPayload = route.request().postData() || '';
            await requestGate;
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: responseBody({ success: false, message: 'کد آزمایشی پذیرفته نشد.' }),
            });
        });

        await enterOtpStep(page);
        const codeInput = page.locator('#pinova-login-otp');
        const localized = ['۱', '٢', '۳', '٤', '۵', '٦'].slice(0, codeLength).join('');
        const normalized = '123456'.slice(0, codeLength);
        await expect(codeInput).toHaveAttribute('maxlength', String(codeLength));
        await expect(codeInput).toHaveAttribute('autocomplete', 'one-time-code');
        await expect(codeInput).toHaveAttribute('inputmode', 'numeric');

        await codeInput.fill(localized.slice(0, -1));
        await page.waitForTimeout(100);
        expect(otpRequests).toBe(0);
        try {
            if (codeLength === 6) {
                await context.grantPermissions(['clipboard-read', 'clipboard-write']);
                await page.evaluate(value => navigator.clipboard.writeText(value), localized);
                await codeInput.focus();
                await codeInput.selectText();
                await page.keyboard.press('Control+V');
            } else {
                await codeInput.fill(localized);
            }
            await expect(codeInput).toHaveValue(normalized);
            await expect.poll(() => otpRequests).toBe(1);
            expect(new URLSearchParams(requestPayload).get('code')).toBe(normalized);
            await page.locator('#loginByOtp').evaluate(form => {
                form.requestSubmit();
                form.requestSubmit();
            });
            await codeInput.evaluate(element => element.dispatchEvent(new Event('input', { bubbles: true })));
            await page.waitForTimeout(100);
            expect(otpRequests).toBe(1);
        } finally {
            releaseRequest();
        }
    });
}

test('six-slot OTP layout has no horizontal overflow across Stage 1 widths', async ({ page }) => {
    setCodeLength(6);
    await mockOtpStart(page);
    await enterOtpStep(page);
    await page.locator('#pinova-login-otp').fill('123');

    const viewports = [
        { name: '320', width: 320, height: 568 },
        { name: '360', width: 360, height: 800 },
        { name: '390', width: 390, height: 844 },
        { name: '412', width: 412, height: 915 },
        { name: '420', width: 420, height: 800 },
        { name: '421', width: 421, height: 800 },
        { name: 'landscape', width: 844, height: 390 },
        { name: 'reduced-height', width: 390, height: 480 },
        { name: 'desktop', width: 1440, height: 900 },
    ];

    for (const viewport of viewports) {
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        const geometry = await page.evaluate(() => {
            const container = document.querySelector('.pinova-auth-code-input').getBoundingClientRect();
            const input = document.querySelector('#pinova-login-otp').getBoundingClientRect();
            const slots = [...document.querySelectorAll('.pinova-auth-code-slot')]
                .map(element => element.getBoundingClientRect())
                .sort((first, second) => first.left - second.left);
            const layoutStyle = getComputedStyle(document.querySelector('.pinova-auth-layout'));
            return {
                viewportWidth: window.innerWidth,
                documentWidth: document.documentElement.scrollWidth,
                bodyWidth: document.body.scrollWidth,
                container: { left: container.left, right: container.right },
                input: { left: input.left, right: input.right },
                slots: slots.map(slot => ({ left: slot.left, right: slot.right, width: slot.width })),
                borderWidth: parseFloat(layoutStyle.borderTopWidth),
                borderRadius: parseFloat(layoutStyle.borderTopLeftRadius),
            };
        });

        expect(geometry.documentWidth, `${viewport.name}: document overflow`).toBeLessThanOrEqual(geometry.viewportWidth + 1);
        expect(geometry.bodyWidth, `${viewport.name}: body overflow`).toBeLessThanOrEqual(geometry.viewportWidth + 1);
        expect(geometry.slots, `${viewport.name}: slot count`).toHaveLength(6);
        expect(geometry.slots.every(slot => slot.width > 0), `${viewport.name}: positive slot widths`).toBe(true);
        expect(geometry.slots[0].left, `${viewport.name}: first slot`).toBeGreaterThanOrEqual(geometry.container.left - 1);
        expect(geometry.slots.at(-1).right, `${viewport.name}: last slot`).toBeLessThanOrEqual(geometry.container.right + 1);
        expect(Math.abs(geometry.input.left - geometry.container.left), `${viewport.name}: input left`).toBeLessThanOrEqual(1);
        expect(Math.abs(geometry.input.right - geometry.container.right), `${viewport.name}: input right`).toBeLessThanOrEqual(1);
        for (let index = 1; index < geometry.slots.length; index += 1) {
            expect(geometry.slots[index].left, `${viewport.name}: slot overlap`).toBeGreaterThanOrEqual(geometry.slots[index - 1].right - 1);
        }
        if (viewport.width === 420) {
            expect(geometry.borderWidth).toBe(0);
            expect(geometry.borderRadius).toBe(0);
        }
        if (viewport.width === 421) {
            expect(geometry.borderWidth).toBeGreaterThan(0);
            expect(geometry.borderRadius).toBeGreaterThan(0);
        }
    }

    const codeInput = page.locator('#pinova-login-otp');
    await codeInput.click();
    const focusTreatment = await page.locator('.pinova-auth-code-slot.is-active').evaluate(element => {
        const style = getComputedStyle(element);
        return {
            outlineStyle: style.outlineStyle,
            outlineWidth: parseFloat(style.outlineWidth),
            boxShadow: style.boxShadow,
        };
    });
    expect(focusTreatment.outlineStyle).not.toBe('none');
    expect(focusTreatment.outlineWidth).toBeGreaterThanOrEqual(2);
    expect(focusTreatment.boxShadow).toBe('none');
});
