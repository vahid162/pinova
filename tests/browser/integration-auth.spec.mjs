import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';

const repositoryRoot = fileURLToPath(new URL('../..', import.meta.url));
const username = 'pinova_mobile_browser';
const password = 'Pinova-browser-proof-2026!';
const mobile = '09125557788';
const code = '482163';
let forumLogin;
let forumRegister;
let forumHome;
let vendorMigration;
let vendorDashboard;
let vendorRegistration;

function cli(script) {
    const expected = `pinova-browser-${process.env.GITHUB_RUN_ID}-${process.env.GITHUB_RUN_ATTEMPT}`;
    if (process.env.CI !== 'true' || process.env.GITHUB_ACTIONS !== 'true' ||
        process.env.PINOVA_TEST_PROFILE !== 'third-party' || process.env.COMPOSE_PROJECT_NAME !== expected ||
        process.env.WP_ENV_HOME !== `/tmp/${expected}`) {
        throw new Error('Integration browser fixtures require the exact disposable CI environment.');
    }
    const authorized = `$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']); if (!$admins) { throw new Exception('Missing fixture administrator'); } wp_set_current_user((int)$admins[0]); ${script}`;
    return execFileSync('npx', ['--no-install', 'wp-env', 'run', 'cli', 'wp', 'eval', authorized], {
        cwd: repositoryRoot, encoding: 'utf8', timeout: 30000,
        env: process.env, stdio: ['ignore', 'pipe', 'pipe'],
    });
}

function readData(script) {
    const output = cli(script);
    const line = output.split('\n').find(value => value.startsWith('PINOVA_BROWSER_DATA '));
    if (!line) throw new Error('Missing CLI fixture receipt');
    return JSON.parse(line.slice('PINOVA_BROWSER_DATA '.length));
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
add_option('pinova_browser_saved_options', ['integrations' => get_option('pinova_integrations', null), 'authorization' => WPF()->settings->authorization]);
$registration = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Pinova browser vendor registration', 'post_content' => '[dokan-vendor-registration]']);
if (!$registration || is_wp_error($registration)) { throw new Exception('Missing registration fixture page'); }
update_option('pinova_browser_vendor_page', $registration);
`);
    const routes = readData(`echo 'PINOVA_BROWSER_DATA ' . wp_json_encode(['login' => wpforo_url('', 'login'), 'register' => wpforo_url('', 'register'), 'home' => wpforo_home_url(), 'migration' => wc_get_account_endpoint_url('account-migration'), 'dashboard' => dokan_get_navigation_url(), 'vendor_registration' => get_permalink(get_option('pinova_browser_vendor_page'))]);`);
    forumLogin = routes.login; forumRegister = routes.register; forumHome = routes.home;
    vendorMigration = routes.migration; vendorDashboard = routes.dashboard;
    vendorRegistration = routes.vendor_registration;
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
foreach (['09127770001', '09127770002'] as $mobile) {
    $synthetic_id = \Pinova\Services\UserService::get_by_mobile($mobile);
    if ($synthetic_id) { wp_delete_user($synthetic_id); }
    \Pinova\Services\RateLimitService::delete_queued_for_identifiers([(new \Pinova\Objects\Identifier($mobile))->get_value()]);
}
$saved = get_option('pinova_browser_saved_options');
if (is_array($saved)) {
    null === $saved['integrations'] ? delete_option('pinova_integrations') : update_option('pinova_integrations', $saved['integrations']);
    wpforo_update_option('wpforo_authorization', $saved['authorization']);
    delete_option('pinova_browser_saved_options');
}
wp_unschedule_hook('pinova_otp_delivery');
wp_delete_post((int)get_option('pinova_browser_vendor_page'), true);
delete_option('pinova_browser_vendor_page');
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
$queued = \Pinova\Services\RateLimitService::claim_queued_otp('${payload.flow_id}');
if (!$queued || $queued['user_id'] !== (int)$id) { throw new Exception('Missing account-bound queued fixture'); }
try {
    \Pinova\Models\OTP::query()->create(['user_id' => $id, 'identifier' => $queued['identifier'], 'ip_address' => $queued['ip'], 'type' => 'verify_mobile', 'code' => '${code}', 'flow_id' => '${payload.flow_id}', 'channels' => ['sms' => true]]);
} finally { \Pinova\Services\RateLimitService::finish_queued_otp('${payload.flow_id}', $queued['claim_token']); }
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
        // A persisted history restoration must discard the stopped transport and stale nonce.
        const reloaded = page.waitForEvent('domcontentloaded');
        await page.evaluate(() => {
            window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
            window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
        });
        await reloaded;
        await expect(page.locator('.pinova-mobile-proof-content')).toBeVisible();
        await expect(page.locator('.pinova-mobile-proof input[name="mobile"]')).toBeEditable();
    });
}

test('proof UI clears edited secrets, accepts WebOTP once, and cancels pending navigation', async ({ page }) => {
    await page.addInitScript(() => {
        window.OTPCredential = class {};
        window.pinovaTestOtp = [];
        Object.defineProperty(navigator.credentials, 'get', { configurable: true, value: ({ signal }) => new Promise((resolve, reject) => {
            window.pinovaTestOtp.push({ resolve, signal });
            signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')), { once: true });
        }) });
    });
    await login(page);
    await page.goto('/?pinova_verify_mobile=1');
    const root = page.locator('.pinova-mobile-proof');
    let requests = 0;
    let verifications = 0;
    let pendingVerify;
    await page.route('**/pinova/mobile/*', async route => {
        if (route.request().url().endsWith('/request')) {
            requests += 1;
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, message: 'Fixture', data: { jwt: 'synthetic-ui-state', ttl: 1 } }) });
        } else {
            verifications += 1;
            pendingVerify = route;
        }
    });
    await root.locator('input[name="mobile"]').fill(mobile);
    await root.locator('[type="submit"]').click();
    await expect(root.locator('input[name="code"]')).toBeEditable();
    await root.locator('input[name="code"]').fill('12');
    await root.locator('.pinova-mobile-edit').click();
    await expect(root.locator('input[name="code"]')).toHaveValue('');
    await expect(root.locator('input[name="mobile"]')).toBeEditable();
    expect(await page.evaluate(() => window.pinovaTestOtp[0].signal.aborted)).toBe(true);
    await root.locator('[type="submit"]').click();
    await expect(root.locator('.pinova-mobile-resend')).toBeVisible();
    await root.locator('.pinova-mobile-resend').click();
    await expect.poll(() => requests).toBe(3);
    await expect(root.locator('form')).toHaveAttribute('aria-busy', 'false');
    await root.locator('input[name="code"]').fill('000000');
    await expect.poll(() => verifications).toBe(1);
    const rejectedVerify = pendingVerify;
    // Autofill may arrive while the manual verification is still pending.
    await page.evaluate(() => window.pinovaTestOtp.at(-1).resolve({ code: '۴۸۲۱۶۳' }));
    await rejectedVerify.fulfill({ status: 401, contentType: 'application/json', body: JSON.stringify({ success: false, message: 'Invalid fixture code' }) });
    await expect.poll(() => verifications).toBe(2);
    await expect(root.locator('.pinova-mobile-proof-content')).toHaveAttribute('inert', '');
    await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: false })));
    await expect(root.locator('input[name="code"]')).toHaveValue('');
    await pendingVerify.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, message: 'STALE_SUCCESS' }) }).catch(() => {});
    await expect(root.locator('.pinova-mobile-status')).not.toHaveText('STALE_SUCCESS');
    expect(verifications).toBe(2);
});

test('real forum GET and registration POST reach Pinova before native mutation', async ({ page }) => {
    cli(`update_option('pinova_integrations', ['wpforo_enabled' => '1', 'dokan_enabled' => '0']);`);
    const target = new URL('/my-account/?next=%2Fcheckout%3Fa%3D1#forum', forumHome).href;
    for (const route of [forumLogin, forumRegister]) {
        await page.goto(route + (route.includes('?') ? '&' : '?') + 'redirect_to=' + encodeURIComponent(target));
        await expect(page.locator('#authenticate')).toBeVisible();
        expect(new URL(page.url()).searchParams.get('back_url')).toBe(target);
    }
    const usersBefore = readData(`global $wpdb; echo 'PINOVA_BROWSER_DATA ' . wp_json_encode((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"));`);
    const result = await page.request.post(forumRegister, {
        form: { wpfaction: 'registration', redirect_to: target, user_login: 'pinova_browser_bypass', user_email: 'bypass@example.test' },
        maxRedirects: 0,
    });
    expect(result.status()).toBe(302);
    expect(new URL(result.headers().location).searchParams.get('back_url')).toBe(target);
    expect(result.headers()['set-cookie'] || '').not.toContain('wordpress_logged_in_');
    expect(readData(`global $wpdb; echo 'PINOVA_BROWSER_DATA ' . wp_json_encode((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"));`)).toBe(usersBefore);
});

for (const manual of [false, true]) {
    test(`real mobile signup keeps forum email truthful with manual approval ${manual}`, async ({ page }) => {
        const number = manual ? '09127770002' : '09127770001';
        cli(String.raw`
update_option('pinova_integrations', ['wpforo_enabled' => '1', 'dokan_enabled' => '0']);
\Pinova\Pinova::set_option('general.wordpress_users_can_register', '1');
$authorization = WPF()->settings->authorization;
$authorization['manually_approval'] = ${manual ? 'true' : 'false'};
$authorization['user_register'] = true;
$authorization['user_register_email_confirm'] = true;
wpforo_update_option('wpforo_authorization', $authorization);
`);
        await page.goto(forumRegister + (forumRegister.includes('?') ? '&' : '?') + 'redirect_to=' + encodeURIComponent(forumHome));
        await expect(page.locator('#authenticate')).toBeVisible();
        const pending = page.waitForResponse(response => response.url().includes('/pinova/user/authenticate') && response.request().method() === 'POST');
        await page.locator('#pinova-identifier').fill(number);
        await page.locator('#authenticate button[type="submit"]').click();
        const accepted = await pending;
        expect(accepted.status()).toBe(200);
        const state = await accepted.json();
        expect(state.success).toBe(true);
        const payload = JSON.parse(Buffer.from(state.data.jwt.split('.')[1], 'base64url').toString());
        expect(payload.flow_id).toMatch(/^[a-f0-9]{32}$/);
        cli(String.raw`
$queued = \Pinova\Services\RateLimitService::claim_queued_otp('${payload.flow_id}');
if (!$queued) { throw new Exception('Missing queued registration fixture'); }
try {
    \Pinova\Models\OTP::query()->create(['identifier' => $queued['identifier'], 'ip_address' => $queued['ip'], 'type' => 'register', 'code' => '${code}', 'flow_id' => '${payload.flow_id}', 'channels' => ['sms' => true]]);
} finally { \Pinova\Services\RateLimitService::finish_queued_otp('${payload.flow_id}', $queued['claim_token']); }
`);
        let verificationBody;
        // Capture the real upstream body before the application navigates away and Chromium discards it.
        await page.route('**/pinova/user/login/otp', async route => {
            const upstream = await route.fetch();
            verificationBody = await upstream.json();
            await route.fulfill({ response: upstream });
        });
        const verified = page.waitForResponse(response => response.url().includes('/pinova/user/login/otp') && response.request().method() === 'POST');
        await page.locator('#pinova-login-otp').fill(code);
        const response = await verified;
        expect(response.status()).toBe(200);
        expect(verificationBody.success).toBe(true);
        await expect.poll(() => new URL(page.url()).pathname).not.toMatch(/^\/login\/?$/);
        expect((await page.context().cookies()).some(cookie => cookie.name.startsWith('wordpress_logged_in_'))).toBe(true);
        const outcome = readData(String.raw`
$id = \Pinova\Services\UserService::get_by_mobile('${number}');
if (!$id) { throw new Exception('Mobile signup did not create its account'); }
echo 'PINOVA_BROWSER_DATA ' . wp_json_encode(['proof' => \Pinova\Services\MobileVerificationService::is_verified($id), 'status' => WPF()->member->get_status($id), 'email_confirmed' => (bool)WPF()->member->get_is_email_confirmed($id), 'email' => get_userdata($id)->user_email, 'seller' => in_array('seller', get_userdata($id)->roles, true)]);
`);
        expect(outcome).toEqual({ proof: true, status: manual ? 'inactive' : 'active', email_confirmed: false, email: '', seller: false });
    });
}

test('native vendor conversion keeps the authenticated account and mobile proof', async ({ page }) => {
    fixture(String.raw`
update_option('pinova_integrations', ['wpforo_enabled' => '1', 'dokan_enabled' => '1']);
update_user_meta($id, 'pinova_mobile', '${mobile}');
$otp = \Pinova\Models\OTP::query()->create(['user_id' => $id, 'identifier' => '${mobile}', 'type' => 'login', 'code' => '${code}', 'flow_id' => bin2hex(random_bytes(16)), 'channels' => ['sms' => true]]);
\Pinova\Services\OTPService::verify_with_flow(\Pinova\Services\OTPService::signed_state($otp), '${code}', ['login']);
WPF()->member->set_secondary_groupids($id, [5]);
`);
    await page.goto(vendorDashboard);
    await expect(page.locator('#authenticate')).toBeVisible();
    expect(new URL(page.url()).searchParams.get('back_url')).toBe(vendorDashboard);
    const target = new URL('/my-account/?from=vendor&next=%2Fcheckout%3Fa%3D1#details', vendorMigration).href;
    await page.goto(vendorRegistration + '?back_url=' + encodeURIComponent(target));
    await expect(page.locator('#authenticate')).toBeVisible();
    const onboarding = new URL(new URL(page.url()).searchParams.get('back_url'));
    expect(onboarding.pathname).toBe(new URL(vendorMigration).pathname);
    expect(onboarding.searchParams.get('back_url')).toBe(target);
    await login(page);
    await page.goto(vendorRegistration + '?back_url=' + encodeURIComponent(target));
    const form = page.locator('form.update-customer-to-vendor');
    await expect(form).toBeVisible();
    const nonce = await form.locator('[name="dokan_nonce"]').inputValue();
    const invalid = await page.request.post(vendorMigration, {
        form: { dokan_migration: '1', dokan_nonce: 'invalid', fname: 'Browser', shopname: 'Fixture', phone: '09350000001' },
        maxRedirects: 0,
    });
    expect(invalid.status()).toBe(200);
    expect(fixture(`if (dokan_is_user_seller($id)) { throw new Exception('Invalid nonce converted account'); } echo 'PINOVA_VENDOR_UNCHANGED';`)).toContain('PINOVA_VENDOR_UNCHANGED');
    const converted = await page.request.post(vendorMigration, {
        form: { dokan_migration: '1', dokan_nonce: nonce, fname: 'Browser', lname: 'Fixture', shopname: 'Browser fixture store', shopurl: 'pinova-browser-fixture', phone: '09350000001', back_url: target },
        maxRedirects: 0,
    });
    expect(converted.status()).toBe(302);
    expect(converted.headers().location).toBe(target);
    expect(fixture(String.raw`
$user = get_userdata($id);
if ($user->user_login !== '${username}' || !dokan_is_user_seller($id) || !\Pinova\Services\MobileVerificationService::is_verified($id) || get_user_meta($id, '_pinova_dokan_onboarding', true) !== 'completed' || !in_array(5, array_map('intval', WPF()->member->get_secondary_groupids($id)), true)) { throw new Exception('Native browser conversion did not preserve account/proof/forum groups'); }
echo 'PINOVA_VENDOR_BROWSER_OK';
`)).toContain('PINOVA_VENDOR_BROWSER_OK');
    await page.goto(target);
    await expect(page.locator('body')).not.toContainText('critical error');
});
