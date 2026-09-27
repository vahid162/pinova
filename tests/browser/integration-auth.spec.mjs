import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';

const repositoryRoot = fileURLToPath(new URL('../..', import.meta.url));
const username = 'pinova_mobile_browser';
const password = 'Pinova-browser-proof-2026!';
const mobile = '09125557788';
const code = '482163';

function cli(script) {
    const expected = `pinova-browser-${process.env.GITHUB_RUN_ID}-${process.env.GITHUB_RUN_ATTEMPT}`;
    if (process.env.CI !== 'true' || process.env.GITHUB_ACTIONS !== 'true' ||
        process.env.PINOVA_TEST_PROFILE !== 'third-party' || process.env.COMPOSE_PROJECT_NAME !== expected ||
        process.env.WP_ENV_HOME !== `/tmp/${expected}`) {
        throw new Error('Integration browser fixtures require the exact disposable CI environment.');
    }
    return execFileSync('npx', ['--no-install', 'wp-env', 'run', 'cli', 'wp', 'eval', script], {
        cwd: repositoryRoot, encoding: 'utf8', timeout: 30000,
        env: process.env, stdio: ['ignore', 'pipe', 'pipe'],
    });
}

function fixture(script) {
    return cli(`$id = username_exists('${username}'); if (!$id) { throw new Exception('Missing browser fixture'); } ${script}`);
}

async function login(page) {
    await page.goto('/login');
    await page.locator('#pinova-identifier').fill(username);
    await page.locator('#authenticate button[type="submit"]').click();
    await expect(page.locator('#loginByPassword')).toBeVisible();
    await page.locator('#pinova-password').fill(password);
    await page.locator('#loginByPassword button[type="submit"]').click();
    await expect.poll(() => new URL(page.url()).pathname).not.toMatch(/^\/login\/?$/);
}

test.beforeAll(() => {
    cli(String.raw`
if (!defined('PINOVA_THIRD_PARTY_BASELINE') || !PINOVA_THIRD_PARTY_BASELINE || !defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON || !function_exists('WPF') || !function_exists('dokan')) { throw new Exception('Missing required real-plugin fixture'); }
add_filter('pre_wp_mail', '__return_true');
add_filter('pre_http_request', static fn() => new WP_Error('fixture_no_network'));
\Pinova\Install::create_tables();
$id = username_exists('${username}');
if (!$id) {
    $id = wp_insert_user(['user_login' => '${username}', 'user_pass' => '${password}', 'user_email' => 'pinova-mobile-browser@example.test', 'role' => 'subscriber']);
}
if (is_wp_error($id)) { throw new Exception('Could not create browser fixture'); }
wp_set_password('${password}', $id);
\Pinova\Pinova::set_option('advanced.code_length', 6);
update_option('woocommerce_coming_soon', 'no');
update_option('woocommerce_store_pages_only', 'no');
WPF()->member->synchronize_user($id);
`);
});

test.afterAll(() => {
    cli(String.raw`
$id = username_exists('${username}');
if ($id) {
    add_filter('pre_wp_mail', '__return_true');
    add_filter('pre_http_request', static fn() => new WP_Error('fixture_no_network'));
    \Pinova\Services\MobileVerificationService::erase_pending($id);
    \Pinova\Models\OTP::query()->where('user_id', $id)->delete();
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($id);
}
wp_unschedule_hook('pinova_otp_delivery');
`);
});

for (const width of [390, 1280]) {
    test(`real proof-only REST verifies the same account at ${width}px`, async ({ page }) => {
        fixture(String.raw`
\Pinova\Services\MobileVerificationService::erase_pending($id);
\Pinova\Models\OTP::query()->where('user_id', $id)->delete();
\Pinova\Services\MobileVerificationService::revoke($id);
`);
        await page.setViewportSize({ width, height: 850 });
        await login(page);
        const cookiesBefore = (await page.context().cookies()).filter(cookie => cookie.name.startsWith('wordpress_logged_in_'));
        expect(cookiesBefore.length).toBeGreaterThan(0);
        const returnTarget = new URL('/my-account/?next=%2Fcheckout%3Fa%3D1#details', page.url()).href;
        await page.goto('/?pinova_verify_mobile=1&back_url=' + encodeURIComponent(returnTarget));
        const form = page.locator('.pinova-mobile-proof');
        await expect(form).toBeVisible();
        await expect(form.locator('a')).toHaveAttribute('href', returnTarget);
        await expect(form.locator('form form')).toHaveCount(0);
        await expect(form.locator('input[name="code"]')).toHaveAttribute('maxlength', '6');
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        const nonce = await form.getAttribute('data-nonce');
        const denied = await page.request.post('/?rest_route=/pinova/mobile/request', { data: { mobile } });
        expect(denied.status()).toBe(403);
        await form.locator('input[name="mobile"]').fill(mobile);
        const pending = page.waitForResponse(response => response.url().includes('/pinova/mobile/request') && response.request().method() === 'POST');
        await form.locator('[type="submit"]').click();
        const requested = await pending;
        expect(requested.status()).toBe(200);
        const state = await requested.json();
        expect(state.success).toBe(true);
        const payload = JSON.parse(Buffer.from(state.data.jwt.split('.')[1], 'base64url').toString());
        expect(payload.purpose).toBe('verify_mobile');
        expect(payload.flow_id).toMatch(/^[a-f0-9]{32}$/);
        // Seed a synthetic delivered credential, then exercise the real browser/API verifier.
        // Queue/provider delivery is covered separately by the runner and boundary suites.
        fixture(String.raw`
\Pinova\Models\OTP::query()->create(['user_id' => $id, 'identifier' => '${mobile}', 'type' => 'verify_mobile', 'code' => '${code}', 'flow_id' => '${payload.flow_id}', 'channels' => ['sms' => true]]);
`);
        let submissions = 0;
        page.on('request', request => { if (request.url().includes('/pinova/mobile/verify')) submissions += 1; });
        const rejected = page.waitForResponse(response => response.url().includes('/pinova/mobile/verify') && response.request().method() === 'POST');
        await form.locator('input[name="code"]').fill('000000');
        expect((await rejected).status()).toBe(401);
        await expect(form.locator('.pinova-mobile-status')).toContainText('معتبر نمی');
        await expect(form.locator('h2')).toBeFocused();
        const verified = page.waitForResponse(response => response.url().includes('/pinova/mobile/verify') && response.request().method() === 'POST');
        await form.locator('input[name="code"]').fill('۴۸۲۱۶۳');
        expect((await verified).status()).toBe(200);
        await expect(form.locator('.pinova-mobile-status')).toContainText('تأیید شد');
        await expect(form.locator('.pinova-mobile-status')).toBeFocused();
        expect(submissions).toBe(2);
        expect((await page.context().cookies()).filter(cookie => cookie.name.startsWith('wordpress_logged_in_'))).toEqual(cookiesBefore);
        expect(fixture(String.raw`
if (!\Pinova\Services\MobileVerificationService::is_verified($id) || get_userdata($id)->user_login !== '${username}') { throw new Exception('Proof did not preserve account'); }
echo 'PINOVA_BROWSER_PROOF_OK';
`)).toContain('PINOVA_BROWSER_PROOF_OK');
        const replay = await page.request.post('/?rest_route=/pinova/mobile/verify', {
            headers: { 'X-WP-Nonce': nonce }, data: { jwt: state.data.jwt, code },
        });
        expect(replay.status()).toBe(401);
    });
}
