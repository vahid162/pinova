import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';

const repositoryRoot = fileURLToPath(new URL('../..', import.meta.url));
const reference = '123e4567-e89b-42d3-a456-426614174000';
let templates;

function renderAdapters() {
    const run = process.env.GITHUB_RUN_ID;
    const attempt = process.env.GITHUB_RUN_ATTEMPT;
    const base = new URL(process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8890');
    if (process.env.CI !== 'true' || process.env.GITHUB_ACTIONS !== 'true'
        || !['http://localhost:8890', 'http://127.0.0.1:8890'].includes(base.origin)
        || base.pathname !== '/' || base.username || base.password || base.search || base.hash || !/^\d+$/.test(run || '') || !/^\d+$/.test(attempt || '')
        || process.env.COMPOSE_PROJECT_NAME !== `pinova-browser-${run}-${attempt}`
        || process.env.WP_ENV_HOME !== `/tmp/pinova-browser-${run}-${attempt}`) {
        throw new Error('Theme browser tests require their run-owned disposable CI site.');
    }
    const output = execFileSync('npx', ['--no-install', 'wp-env', 'run', 'cli', 'env', 'PINOVA_BROWSER_FIXTURE=pinova-theme-browser',
        'wp', 'eval-file', 'wp-content/plugins/pinova/tests/fixtures/browser/theme-login.php', '--use-include'], {
        cwd: repositoryRoot, env: process.env, encoding: 'utf8', timeout: 90_000, maxBuffer: 1024 * 1024,
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    const match = output.match(/^PINOVA_THEME_FIXTURE:([A-Za-z0-9+/=]+)\s*$/m);
    if (!match) throw new Error('The actual theme adapter render receipt is missing.');
    const rendered = JSON.parse(Buffer.from(match[1], 'base64').toString('utf8'));
    for (const kind of ['woodmart', 'flatsome']) {
        if (typeof rendered[kind] !== 'string' || rendered[kind].length > 131_072
            || !rendered[kind].includes('pinovaLoginForm()')) {
            throw new Error('The actual theme adapter render receipt is invalid.');
        }
    }
    return rendered;
}

test.beforeAll(() => { templates = renderAdapters(); });

async function openTheme(page, kind, respond, mode = 'click') {
    // Commercial theme scripts are not installed here. This finite MFP target
    // models only the source-verified event/content API, using a non-document target.
    if (kind === 'flatsome') {
        await page.addInitScript(() => {
            const handlers = new Set();
            const target = {
                0: {},
                on(event, handler) { if (event === 'mfpBeforeClose.pinovaAuth') handlers.add(handler); },
                off(event, handler) { handlers.delete(handler); },
                beforeClose() { for (const handler of handlers) handler(); },
            };
            window.jQuery = {magnificPopup: {instance: {isOpen: true, content: [], ev: target}}};
        });
    }
    const markup = kind === 'woodmart'
        ? `<div class="${mode === 'hover' ? 'wd-event-hover' : 'wd-event-click wd-opened'}"><div class="wd-dropdown wd-dropdown-register">${templates[kind]}</div></div>`
        : `<div id="login-form-popup" class="lightbox-content">${templates[kind]}</div>`;
    // All Pinova REST routes are intercepted: these UI tests never request a real code.
    await page.route('**/wp-json/pinova/**', respond);
    await page.route(`**/pinova-theme-fixture/${kind}`, route => route.fulfill({
        contentType: 'text/html',
        body: `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>[pinova-cloak],.mfp-hide{display:none!important}.wd-dropdown{visibility:hidden;transition:visibility .4s cubic-bezier(.19,1,.22,1)}.wd-event-hover:hover>.wd-dropdown,.wd-event-click.wd-opened>.wd-dropdown,.wd-dropdown.wd-opened,.wd-dropdown-register[class*=wd-active]{visibility:visible}.screen-reader-text{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(1px,1px,1px,1px)}</style></head><body>${markup}</body></html>`,
    }));
    await page.goto(`/pinova-theme-fixture/${kind}`);
    if (kind === 'flatsome') {
        await page.evaluate(() => { window.jQuery.magnificPopup.instance.content = [document.getElementById('login-form-popup')]; });
    }
    if (mode === 'hover') await page.locator('.wd-event-hover').hover({position: {x: 5, y: 5}});
    const root = page.locator('[pinova-data="pinovaLoginForm()"]');
    await expect(root.locator('input[name="username"]')).toBeVisible();
    return root;
}

function json(route, data, status = 200, headers = {}) {
    return route.fulfill({ status, contentType: 'application/json', headers, body: JSON.stringify(data) });
}

for (const kind of ['woodmart', 'flatsome']) {
    test(`${kind}: wrong password shows plain feedback and the received reference`, async ({ page }) => {
        const root = await openTheme(page, kind, route => {
            if (route.request().url().includes('/user/authenticate')) {
                return json(route, { success: true, data: { login_method: 'password' } });
            }
            return json(route, { success: false, message: 'رمز عبور واردشده درست نیست.', data: {} }, 401,
                { 'X-Pinova-Correlation-ID': reference });
        });
        const identifier = root.getByRole('textbox', { name: /شماره موبایل/ });
        await identifier.fill('browser@example.test');
        await root.getByRole('button', { name: 'تایید', exact: true }).click();
        const heading = root.locator('[data-pinova-step="loginByPassword"] [data-pinova-step-heading]');
        await expect(heading).toBeFocused();
        const password = root.getByLabel('رمز عبور را وارد کنید', { exact: true });
        await expect(password).toHaveAttribute('required', '');
        await password.fill('synthetic-wrong-password');
        await root.getByRole('button', { name: 'ورود', exact: true }).click();
        await expect(root.getByRole('status')).toContainText('رمز عبور واردشده درست نیست.');
        await expect(root.locator('[pinova-text="status.reference"]')).toHaveText(reference);
        await expect(root.locator('[pinova-text="status.reference"]')).toHaveAttribute('dir', 'ltr');
        await expect(root.getByRole('status')).toHaveAttribute('aria-atomic', 'true');
    });

    test(`${kind}: incorrect OTP, throttling and invalid response preserve visible safe feedback`, async ({ page }) => {
        let phase = 'init';
        const root = await openTheme(page, kind, route => {
            if (phase === 'init') return json(route, { success: true, message: 'درخواست پذیرفته شد.', data: { login_method: 'otp', jwt: 'synthetic-browser-flow', ttl: 180 } });
            if (phase === 'wrong') return json(route, { success: false, message: 'کد واردشده درست نیست.', data: {} }, 401, { 'X-Pinova-Correlation-ID': reference });
            if (phase === 'limited') return json(route, { success: false, message: 'کمی بعد دوباره تلاش کنید.', data: {} }, 429, { 'Retry-After': '30', 'X-Pinova-Correlation-ID': reference });
            return route.fulfill({ status: 502, contentType: 'text/html', headers: { 'X-Pinova-Correlation-ID': reference }, body: '<p>synthetic invalid response</p>' });
        });
        await root.getByRole('textbox', { name: /شماره موبایل/ }).fill('browser@example.test');
        await root.getByRole('button', { name: 'تایید', exact: true }).click();
        await expect(root.locator('[data-pinova-step="loginByOtp"] [data-pinova-step-heading]')).toBeFocused();
        const code = root.getByLabel('کد تأیید', { exact: true });
        const length = await page.evaluate(() => Number(pinova.code_length));
        for (const next of ['wrong', 'limited', 'invalid']) {
            phase = next;
            await code.fill('123456'.slice(0, length));
            const feedback = root.getByRole('status');
            if (next === 'wrong') await expect(feedback).toContainText('کد واردشده درست نیست.');
            if (next === 'limited') await expect(feedback).toContainText('30');
            if (next === 'invalid') await expect(feedback).toContainText('پاسخ معتبری از سرور دریافت نشد.');
            await expect(root.locator('[pinova-text="status.reference"]')).toHaveText(reference);
            // An invalid response is an exception, so the user edits the same code before trying again.
            if (next !== 'invalid') await expect(code).toHaveValue('');
        }
    });

    test(`${kind}: local validation focuses its labelled field; network failure has no invented reference`, async ({ page }) => {
        const root = await openTheme(page, kind, route => route.abort('failed'));
        const identifier = root.getByRole('textbox', { name: /شماره موبایل/ });
        await root.getByRole('button', { name: 'تایید', exact: true }).click();
        await expect(identifier).toBeFocused();
        await expect(identifier).toHaveAttribute('aria-invalid', 'true');
        const errorId = await identifier.getAttribute('aria-describedby');
        await expect(page.locator(`[id="${errorId}"]`)).toBeVisible();
        await identifier.fill('browser@example.test');
        await root.getByRole('button', { name: 'تایید', exact: true }).click();
        await expect(root.getByRole('status')).toContainText('ارتباط با سرور برقرار نشد.');
        await expect(root.locator('[pinova-text="status.reference"]')).toBeHidden();
    });

    test(`${kind}: replacing an accepted flow makes its late WebOTP inert`, async ({ page }) => {
        let flow = 0;
        let verificationCalls = 0;
        await page.addInitScript(() => {
            window.OTPCredential = class {};
            window.pinovaCredentialWaits = [];
            Object.defineProperty(navigator, 'credentials', { configurable: true, value: {
                get(options) { return new Promise(resolve => window.pinovaCredentialWaits.push({ options, resolve })); },
            } });
        });
        const root = await openTheme(page, kind, route => {
            if (route.request().url().includes('/user/authenticate')) {
                return json(route, { success: true, message: 'درخواست پذیرفته شد.', data: { login_method: 'otp', jwt: `synthetic-flow-${++flow}`, ttl: 180 } });
            }
            ++verificationCalls;
            return json(route, { success: false, message: 'synthetic rejected', data: {} }, 401);
        });
        await root.getByRole('textbox', { name: /شماره موبایل/ }).fill('browser@example.test');
        await root.getByRole('button', { name: 'تایید', exact: true }).click();
        await expect.poll(() => page.evaluate(() => window.pinovaCredentialWaits.length)).toBe(1);
        await root.getByRole('button', { name: 'بازگشت به مرحله قبل' }).click();
        await root.getByRole('textbox', { name: /شماره موبایل/ }).fill('other@example.test');
        await root.getByRole('button', { name: 'تایید', exact: true }).click();
        await expect.poll(() => page.evaluate(() => window.pinovaCredentialWaits.length)).toBe(2);
        await page.evaluate(() => { window.pinovaCredentialWaits[0].resolve({ code: '123456'.slice(0, Number(pinova.code_length)) }); });
        await expect(root.getByLabel('کد تأیید', { exact: true })).toHaveValue('');
        expect(verificationCalls).toBe(0);
        expect(await page.evaluate(() => window.pinovaCredentialWaits[0].options.signal.aborted)).toBe(true);
    });
}

for (const kind of ['woodmart', 'flatsome']) {
    test(`${kind}: current WebOTP submits through the real Alpine component exactly once`, async ({ page }) => {
        let verificationCalls = 0;
        await page.addInitScript(() => {
            window.OTPCredential = class {};
            window.pinovaCredentialWaits = [];
            Object.defineProperty(navigator, 'credentials', {configurable: true, value: {
                get(options) { return new Promise(resolve => window.pinovaCredentialWaits.push({options, resolve})); },
            }});
        });
        const root = await openTheme(page, kind, route => {
            if (route.request().url().includes('/user/authenticate')) {
                return json(route, {success: true, message: 'درخواست پذیرفته شد.', data: {login_method: 'otp', jwt: 'synthetic-current-flow', ttl: 180}});
            }
            ++verificationCalls;
            return json(route, {success: false, message: 'کد واردشده درست نیست.', data: {}}, 401);
        });
        await root.getByRole('textbox', {name: /شماره موبایل/}).fill('browser@example.test');
        await root.getByRole('button', {name: 'تایید', exact: true}).click();
        await expect.poll(() => page.evaluate(() => window.pinovaCredentialWaits.length)).toBe(1);
        await page.evaluate(() => window.pinovaCredentialWaits[0].resolve({code: '123456'.slice(0, Number(pinova.code_length))}));
        await expect(root.getByRole('status')).toContainText('کد واردشده درست نیست.');
        expect(verificationCalls).toBe(1);
        expect(await page.evaluate(() => window.pinovaCredentialWaits.length)).toBe(1);
    });
}


for (const kind of ['woodmart', 'flatsome']) {
    test(`${kind}: closing and reopening its persistent theme container invalidates the old wait`, async ({page}) => {
        let verificationCalls = 0;
        await page.addInitScript(() => {
            window.OTPCredential = class {};
            window.pinovaCredentialWaits = [];
            Object.defineProperty(navigator, 'credentials', {configurable: true, value: {
                get(options) { return new Promise(resolve => window.pinovaCredentialWaits.push({options, resolve})); },
            }});
        });
        const root = await openTheme(page, kind, route => {
            if (route.request().url().includes('/user/authenticate')) {
                return json(route, {success: true, message: 'درخواست پذیرفته شد.', data: {login_method: 'otp', jwt: 'synthetic-container-flow', ttl: 180}});
            }
            ++verificationCalls;
            return json(route, {success: false, message: 'synthetic rejected', data: {}}, 401);
        });
        await root.getByRole('textbox', {name: /شماره موبایل/}).fill('browser@example.test');
        await root.getByRole('button', {name: 'تایید', exact: true}).click();
        await expect.poll(() => page.evaluate(() => window.pinovaCredentialWaits.length)).toBe(1);
        await page.evaluate(theme => {
            // Resolve first, then close/reopen before microtasks: a final visible
            // snapshot alone would let this queued old callback fill the new UI.
            window.pinovaCredentialWaits[0].resolve({code: '123456'.slice(0, Number(pinova.code_length))});
            if (theme === 'woodmart') {
                const owner = document.querySelector('.wd-event-click');
                owner.classList.remove('wd-opened');
                owner.classList.add('wd-opened');
            } else {
                const instance = window.jQuery.magnificPopup.instance;
                instance.ev.beforeClose();
                instance.isOpen = false;
                document.getElementById('login-form-popup').classList.add('mfp-hide');
                document.getElementById('login-form-popup').classList.remove('mfp-hide');
                instance.isOpen = true;
            }
        }, kind);
        await expect(root.getByLabel('کد تأیید', {exact: true})).toHaveValue('');
        expect(verificationCalls).toBe(0);
        expect(await page.evaluate(() => window.pinovaCredentialWaits[0].options.signal.aborted)).toBe(true);
    });
}


test('Woodmart hover close/reopen invalidates the wait before the visibility transition ends', async ({page}) => {
    let verificationCalls = 0;
    await page.addInitScript(() => {
        window.OTPCredential = class {};
        window.pinovaCredentialWaits = [];
        Object.defineProperty(navigator, 'credentials', {configurable: true, value: {
            get(options) { return new Promise(resolve => window.pinovaCredentialWaits.push({options, resolve})); },
        }});
    });
    const root = await openTheme(page, 'woodmart', route => {
        if (route.request().url().includes('/user/authenticate')) {
            return json(route, {success: true, message: 'درخواست پذیرفته شد.', data: {login_method: 'otp', jwt: 'synthetic-hover-flow', ttl: 180}});
        }
        ++verificationCalls;
        return json(route, {success: false, message: 'synthetic rejected', data: {}}, 401);
    }, 'hover');
    await root.getByRole('textbox', {name: /شماره موبایل/}).fill('browser@example.test');
    await root.getByRole('button', {name: 'تایید', exact: true}).click();
    await expect.poll(() => page.evaluate(() => window.pinovaCredentialWaits.length)).toBe(1);
    const box = await page.locator('.wd-event-hover').boundingBox();
    expect(box).not.toBeNull();
    await page.evaluate(() => document.querySelector('.wd-event-hover').addEventListener('mouseleave', () => {
        window.pinovaHoverCloseVisibility = getComputedStyle(document.querySelector('[data-pinova-theme-partial]')).visibility;
    }, {once: true}));
    await page.mouse.move(0, 0);
    expect(await page.evaluate(() => window.pinovaCredentialWaits[0].options.signal.aborted)).toBe(true);
    expect(await page.evaluate(() => window.pinovaHoverCloseVisibility)).toBe('visible');
    await page.mouse.move(box.x + 5, box.y + 5);
    await page.evaluate(() => window.pinovaCredentialWaits[0].resolve({code: '123456'.slice(0, Number(pinova.code_length))}));
    await expect(root.getByLabel('کد تأیید', {exact: true})).toHaveValue('');
    expect(verificationCalls).toBe(0);
});
