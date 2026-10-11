import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { randomBytes } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';

test.use({ channel: 'chromium' });
const execFileAsync = promisify(execFile);
const repositoryRoot = fileURLToPath(new URL('../..', import.meta.url));
const suffix = randomBytes(8).toString('hex');
const owner = `pinova_virtual_browser_${suffix}`;
const username = `pinova_virtual_${suffix}`;
const password = `Pinova-virtual-${suffix}!`;
let fixture;

async function cli(script) {
    const expected = `pinova-browser-${process.env.GITHUB_RUN_ID}-${process.env.GITHUB_RUN_ATTEMPT}`;
    const base = new URL(process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8890');
    if (process.env.CI !== 'true' || process.env.GITHUB_ACTIONS !== 'true' ||
        !/^\d+$/.test(process.env.GITHUB_RUN_ID || '') || !/^\d+$/.test(process.env.GITHUB_RUN_ATTEMPT || '') ||
        process.env.COMPOSE_PROJECT_NAME !== expected || process.env.WP_ENV_HOME !== `/tmp/${expected}` ||
        base.origin !== 'http://localhost:8890' || base.pathname !== '/' || base.username || base.password || base.search || base.hash) {
        throw new Error('Virtual checkout fixtures require the exact disposable loopback GitHub wp-env.');
    }
    const guarded = String.raw`
if (!defined('WP_CLI') || !WP_CLI || 'local' !== wp_get_environment_type()
    || 'localhost' !== wp_parse_url(home_url(), PHP_URL_HOST) || 8890 !== wp_parse_url(home_url(), PHP_URL_PORT)) {
    throw new RuntimeException('Virtual checkout fixture requires its disposable local site.');
}
add_filter('pre_wp_mail', '__return_true');
add_filter('pre_http_request', static fn() => new WP_Error('pinova_fixture_no_network'));
${script}
`;
    const { stdout } = await execFileAsync('npx', ['--no-install', 'wp-env', 'run', 'cli', 'wp', 'eval', guarded], {
        cwd: repositoryRoot, encoding: 'utf8', timeout: 30_000, env: process.env,
    });
    return stdout;
}

async function data(script) {
    const output = await cli(script);
    const line = output.split('\n').find(value => value.startsWith('PINOVA_VIRTUAL_BROWSER '));
    if (!line) throw new Error('Missing virtual checkout fixture receipt.');
    return JSON.parse(line.slice('PINOVA_VIRTUAL_BROWSER '.length));
}

async function policy(enabled, taxes = false) {
    await cli(String.raw`
\Pinova\Pinova::set_option('general.woocommerce_virtual_checkout', ${enabled ? 'true' : 'false'});
update_option('woocommerce_calc_taxes', '${taxes ? 'yes' : 'no'}');
update_option('woocommerce_tax_based_on', 'billing');
update_option('woocommerce_checkout_page_id', ${fixture.classic});
`);
}

async function cart(page, ids) {
    for (const id of ids) await page.goto(`/?add-to-cart=${id}`);
    await page.goto(`/?page_id=${fixture.classic}`);
    await expect(page.locator('form.checkout')).toBeVisible();
    await expect(page.locator('#billing_phone')).toBeVisible();
    await expect(page.locator('#billing_email')).toBeVisible();
}

const removed = ['billing_company', 'billing_address_1', 'billing_address_2', 'billing_city', 'billing_postcode', 'billing_country', 'billing_state', 'order_comments'];
async function compactFields(page) {
    for (const key of removed) await expect(page.locator(`#${key}_field`)).toHaveCount(0);
    for (const key of ['billing_first_name', 'billing_last_name', 'billing_phone', 'billing_email']) {
        await expect(page.locator(`#${key}`)).toBeVisible();
    }
}

async function normalFields(page) {
    for (const key of ['billing_address_1', 'billing_city', 'billing_postcode', 'billing_country']) {
        await expect(page.locator(`#${key}_field`)).toBeVisible();
    }
}

test.beforeAll(async () => {
    fixture = await data(String.raw`
\Pinova\Install::create_tables();
if (false !== get_option('${owner}', false) || username_exists('${username}')) { throw new RuntimeException('Fixture ownership collision'); }
$saved = ['options' => [], 'products' => [], 'pages' => [], 'user' => 0];
foreach (['pinova_general', 'woocommerce_calc_taxes', 'woocommerce_tax_based_on', 'woocommerce_checkout_page_id',
    'woocommerce_bacs_settings', 'woocommerce_cheque_settings', 'woocommerce_cod_settings', 'woocommerce_enable_guest_checkout',
    'woocommerce_coming_soon', 'woocommerce_store_pages_only', 'pinova_native_login_arm'] as $key) {
    $sentinel = new stdClass();
    $value = get_option($key, $sentinel);
    $saved['options'][$key] = ['exists' => $value !== $sentinel, 'value' => $value !== $sentinel ? $value : null];
}
if (!add_option('${owner}', $saved, '', false)) { throw new RuntimeException('Could not own fixture'); }
update_option('woocommerce_enable_guest_checkout', 'yes');
update_option('woocommerce_coming_soon', 'no');
update_option('woocommerce_store_pages_only', 'no');
update_option('woocommerce_bacs_settings', ['enabled' => 'yes']);
update_option('woocommerce_cheque_settings', ['enabled' => 'no']);
update_option('woocommerce_cod_settings', ['enabled' => 'no']);
\Pinova\Pinova::set_option('general.woocommerce_checkout_registration_required', 'no');
\Pinova\Pinova::set_option('general.woocommerce_virtual_checkout', false);
$ids = [];
foreach (['virtual' => true, 'physical' => false] as $type => $virtual) {
    $product = new WC_Product_Simple();
    $product->set_name('Synthetic virtual checkout ' . $type);
    $product->set_virtual($virtual);
    $product->set_regular_price('10');
    $product->set_status('publish');
    $ids[$type] = $product->save();
    $saved['products'][] = $ids[$type];
    update_option('${owner}', $saved, false);
}
foreach (['classic' => '[woocommerce_checkout]', 'blocks' => '<!-- wp:woocommerce/checkout /-->'] as $type => $content) {
    $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Synthetic checkout ' . $type,
        'post_name' => '${username}-' . $type, 'post_content' => $content]);
    if (!$id || is_wp_error($id)) { throw new RuntimeException('Could not create checkout page'); }
    $ids[$type] = $id;
    $saved['pages'][] = $id;
    update_option('${owner}', $saved, false);
}
$id = wp_insert_user(['user_login' => '${username}', 'user_pass' => '${password}', 'user_email' => '${username}@example.test', 'role' => 'administrator']);
if (is_wp_error($id)) { throw new RuntimeException('Could not create fixture administrator'); }
$saved['user'] = $id;
update_option('${owner}', $saved, false);
$ids['login'] = \Pinova\Integrations\Wordpress\NativeLoginGate::url();
echo 'PINOVA_VIRTUAL_BROWSER ' . wp_json_encode($ids);
`);
});

test.afterAll(async () => {
    await cli(String.raw`
$saved = get_option('${owner}');
if (!is_array($saved)) { return; }
foreach ($saved['products'] as $id) { $product = wc_get_product($id); if ($product) { $product->delete(true); } }
foreach ($saved['pages'] as $id) { wp_delete_post($id, true); }
if ($saved['user']) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($saved['user']); }
foreach ($saved['options'] as $key => $before) { $before['exists'] ? update_option($key, $before['value']) : delete_option($key); }
delete_option('${owner}');
`);
});

for (const width of [1280, 390]) {
    test(`classic virtual policy preserves native fields and tax requirements at ${width}px`, async ({ page }) => {
        test.setTimeout(90_000);
        await page.setViewportSize({ width, height: 900 });
        await policy(false);
        await cart(page, [fixture.virtual]);
        await normalFields(page);
        await policy(true);
        await page.reload();
        await compactFields(page);
        await page.locator('#billing_first_name').fill('Synthetic');
        await page.locator('#billing_last_name').fill('Shopper');
        await page.locator('#billing_email').fill(`${username}@example.test`);
        await page.locator('#billing_phone').fill('invalid');
        const [response] = await Promise.all([
            page.waitForResponse(candidate => new URL(candidate.url()).searchParams.get('wc-ajax') === 'checkout' &&
                candidate.request().method() === 'POST'),
            page.locator('#place_order').click(),
        ]);
        expect(response.ok()).toBe(true);
        const rejection = await response.json();
        expect(rejection.result).toBe('failure');
        expect(rejection.messages).toContain('شماره موبایل');
        const notice = page.locator('.woocommerce-NoticeGroup-checkout [role="alert"]');
        await expect(notice).toBeVisible();
        await expect(notice).toContainText('شماره موبایل');
        await expect(page.locator('.pinova-auth-modal')).not.toBeVisible();
        await policy(true, true);
        await page.reload();
        await normalFields(page);
        await policy(false);
        await page.reload();
        await normalFields(page);
    });
    for (const type of ['physical', 'mixed']) {
        test(`classic ${type} cart keeps addresses at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 900 });
            await policy(true);
            await cart(page, type === 'mixed' ? [fixture.virtual, fixture.physical] : [fixture.physical]);
            await normalFields(page);
        });
    }
}

test('existing settings UI saves the optional policy and reports Checkout Blocks', async ({ page }) => {
    test.setTimeout(90_000);
    await policy(false);
    await page.goto(fixture.login);
    const login = page.locator('#user_login');
    await expect(login).toBeFocused();
    await login.fill(username);
    await page.locator('#user_pass').fill(password);
    await expect(login).toHaveValue(username);
    await expect(page.locator('#user_pass')).toHaveValue(password);
    await page.locator('#wp-submit').click();
    await page.goto('/wp-admin/admin.php?page=pinova');
    const toggle = page.locator('input[type="checkbox"][name="pinova_general[woocommerce_virtual_checkout]"]');
    await expect(toggle).not.toBeChecked();
    await toggle.check();
    await toggle.locator('xpath=ancestor::form').locator('input[type="submit"]').click();
    await expect(toggle).toBeChecked();
    const stored = await data(String.raw`echo 'PINOVA_VIRTUAL_BROWSER ' . wp_json_encode(\Pinova\Pinova::get_option('general.woocommerce_virtual_checkout', false));`);
    expect(stored).toBeTruthy();
    await cli(`update_option('woocommerce_checkout_page_id', ${fixture.blocks});`);
    await page.reload();
    await expect(page.locator('body')).toContainText('Checkout Blocks');
    await policy(false);
});
