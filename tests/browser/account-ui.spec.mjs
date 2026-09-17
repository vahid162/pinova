import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';

const repositoryRoot = fileURLToPath(new URL('../..', import.meta.url));
const authenticateRoute = '**/pinova/user/authenticate*';
const otpRoute = '**/pinova/user/login/otp*';

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
        const descriptionStyle = getComputedStyle(document.querySelector('.pinova-auth-description'));
        const shellStyle = getComputedStyle(document.querySelector('.pinova-auth-shell'));
        return {
            loadedFaceCount: loadedFaces.length,
            bodyFont: bodyStyle.fontFamily,
            inputFont: inputStyle.fontFamily,
            borderColor: inputStyle.borderTopColor,
            backgroundColor: inputStyle.backgroundColor,
            descriptionMarginTop: parseFloat(descriptionStyle.marginTop),
            descriptionMarginBottom: parseFloat(descriptionStyle.marginBottom),
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
    expect(presentation.descriptionMarginTop).toBe(0);
    expect(presentation.descriptionMarginBottom).toBeGreaterThan(0);
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
