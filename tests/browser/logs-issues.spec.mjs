import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { createHash, randomBytes } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';

const execFileAsync = promisify(execFile);
const repositoryRoot = fileURLToPath(new URL('../..', import.meta.url));
const suffix = randomBytes(8).toString('hex');
const username = `pinova_logs_${suffix}`;
const password = `Pinova-logs-${suffix}!`;
const fixtureOption = `pinova_logs_browser_${suffix}`;
const failureCode = 'otp.channel_send_failed:provider_returned_false';
const secret = `pinova-private-${suffix}`;
const fingerprint = randomBytes(16).toString('hex');
const attachmentFixtureName = `pinova-logs-attachment-${suffix}.php`;
const attachmentFixtureSource = String.raw`<?php
// Run-owned Linux WPE presentation fixture; never part of Pinova runtime.
if (!defined('ABSPATH') || PHP_SAPI === 'cli' || 'local' !== wp_get_environment_type()
    || !in_array(untrailingslashit(home_url()), ['http://localhost:8890', 'http://127.0.0.1:8890'], true)
    || 'POST' !== ($_SERVER['REQUEST_METHOD'] ?? '') || 'pinova_export_issues' !== ($_POST['action'] ?? '')) {
    return;
}
$pinova_attachment = get_option('${fixtureOption}');
if (!is_array($pinova_attachment) || '${suffix}' !== ($pinova_attachment['attachment_owner'] ?? '')
    || '${process.env.GITHUB_RUN_ID}:${process.env.GITHUB_RUN_ATTEMPT}' !== ($pinova_attachment['attachment_run'] ?? '')
    || hash_file('sha256', __FILE__) !== ($pinova_attachment['attachment_sha256'] ?? '')) {
    return;
}
header_register_callback(static function (): void {
    $saved = get_option('${fixtureOption}');
    if (!is_array($saved) || '${suffix}' !== ($saved['attachment_owner'] ?? '')) { return; }
    $observations = $saved['attachment_witnesses'] ?? [];
    if (!is_array($observations) || count($observations) >= 2) { return; }
    $headers = ['content-type' => [], 'content-disposition' => [], 'x-content-type-options' => []];
    foreach (headers_list() as $line) {
        $parts = explode(':', $line, 2);
        $name = strtolower(trim($parts[0]));
        if (isset($headers[$name]) && isset($parts[1])) { $headers[$name][] = trim($parts[1]); }
    }
    $witness = [
        'status' => (int)http_response_code(),
        'json' => count($headers['content-type']) === 1 && preg_match('/^application\/json(?:;|$)/i', $headers['content-type'][0]) === 1,
        'attachment' => $headers['content-disposition'] === ['attachment; filename="pinova-issues.json"'],
        'nosniff' => $headers['x-content-type-options'] === ['nosniff'],
        'actor' => current_user_can('manage_options') && (int)$saved['user_id'] === get_current_user_id()
            && '${suffix}' === get_user_meta(get_current_user_id(), '_pinova_logs_browser_owner', true),
        'nonce' => is_string($_POST['_wpnonce'] ?? null) && false !== wp_verify_nonce($_POST['_wpnonce'], 'pinova_export_issues'),
        'adapted' => false,
    ];
    if ($witness['status'] === 200 && $witness['json'] && $witness['attachment'] && $witness['nosniff'] && $witness['actor'] && $witness['nonce']) {
        header('Content-Type: application/octet-stream');
        $witness['adapted'] = true;
    }
    $saved['attachment_witnesses'][] = $witness;
    update_option('${fixtureOption}', $saved, false);
});
unset($pinova_attachment);
`;
const attachmentFixtureHash = createHash('sha256').update(attachmentFixtureSource).digest('hex');
let fixture;

async function cli(script) {
    const expected = `pinova-browser-${process.env.GITHUB_RUN_ID}-${process.env.GITHUB_RUN_ATTEMPT}`;
    const base = new URL(process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8890');
    if (process.env.CI !== 'true' || process.env.GITHUB_ACTIONS !== 'true' ||
        !/^\d+$/.test(process.env.GITHUB_RUN_ID || '') || !/^\d+$/.test(process.env.GITHUB_RUN_ATTEMPT || '') ||
        process.env.COMPOSE_PROJECT_NAME !== expected || process.env.WP_ENV_HOME !== `/tmp/${expected}` ||
        !['http://localhost:8890', 'http://127.0.0.1:8890'].includes(base.origin) || base.pathname !== '/' ||
        base.username || base.password || base.search || base.hash) {
        throw new Error('Logs browser fixtures require the exact disposable loopback GitHub wp-env.');
    }
    const guarded = String.raw`
if (!defined('WP_CLI') || !WP_CLI || 'local' !== wp_get_environment_type()
    || !in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['localhost', '127.0.0.1'], true)
    || 8890 !== wp_parse_url(home_url(), PHP_URL_PORT)) {
    throw new RuntimeException('Logs browser fixture requires its disposable local site.');
}
add_filter('pre_wp_mail', '__return_true');
add_filter('pre_http_request', static fn() => new WP_Error('pinova_fixture_no_network'));
global $wpdb;
${script}
`;
    const { stdout } = await execFileAsync('npx', ['--no-install', 'wp-env', 'run', 'cli', 'wp', 'eval', guarded], {
        cwd: repositoryRoot, encoding: 'utf8', timeout: 30_000,
        env: process.env,
    });
    return stdout;
}

async function readData(script) {
    const output = await cli(script);
    const line = output.split('\n').find(value => value.startsWith('PINOVA_LOGS_BROWSER_DATA '));
    if (!line) throw new Error('Missing logs browser fixture receipt.');
    return JSON.parse(line.slice('PINOVA_LOGS_BROWSER_DATA '.length));
}

function report() {
    return readData(String.raw`
$saved = get_option('${fixtureOption}');
echo 'PINOVA_LOGS_BROWSER_DATA ' . wp_json_encode(\Pinova\Logging\IssueMonitor::report($saved['dates']));
`);
}

function eventSnapshot() {
    return readData(String.raw`
$saved = get_option('${fixtureOption}');
$rows = [];
foreach ($saved['ids'] as $id) {
    $rows[] = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND user_id = %d', $wpdb->prefix . 'pinova_logs', $id, $saved['user_id']), ARRAY_A);
}
echo 'PINOVA_LOGS_BROWSER_DATA ' . wp_json_encode(['count' => count(array_filter($rows)), 'digest' => hash('sha256', wp_json_encode($rows))]);
`);
}

test.beforeAll(async () => {
    fixture = await readData(String.raw`
if (username_exists('${username}') || false !== get_option('${fixtureOption}', false)) {
    throw new RuntimeException('Logs fixture ownership collision');
}
$review_name = 'pinova_issue_review_' . hash('sha256', '${failureCode}');
$saved = [
    'user_id' => 0, 'ids' => [], 'review_name' => $review_name,
    'review_before' => get_option($review_name, false),
    'arm_before' => get_option('pinova_native_login_arm', false),
    'dates' => ['created_from' => gmdate('Y-m-d', time() - DAY_IN_SECONDS), 'created_to' => gmdate('Y-m-d')],
];
if (!add_option('${fixtureOption}', $saved, '', false)) { throw new RuntimeException('Could not own logs fixture'); }
$id = wp_insert_user([
    'user_login' => '${username}', 'user_pass' => '${password}',
    'user_email' => '${username}@example.test', 'role' => 'administrator',
    'meta_input' => ['_pinova_logs_browser_owner' => '${suffix}'],
]);
if (is_wp_error($id)) { throw new RuntimeException('Could not create logs fixture administrator'); }
$saved['user_id'] = $id;
update_option('${fixtureOption}', $saved, false);
$events = [
    ['event' => 'otp.channel_send_failed', 'level' => 'warning', 'context' => ['reason' => 'provider_returned_false', 'channel' => 'sms', 'duration_ms' => 53]],
    ['event' => 'otp.verify_failed', 'level' => 'notice', 'context' => ['reason' => 'token_expired']],
    ['event' => 'otp.queued', 'level' => 'info', 'context' => ['remaining_seconds' => 180]],
    ['event' => '${secret}', 'level' => 'warning', 'context' => ['reason' => '${secret}']],
];
foreach ($events as $event) {
    $context = $event['context'] + [
        'password' => '${secret}', 'token' => '${secret}', 'identifier' => '${secret}@example.test',
        'identifier_fingerprint' => '${fingerprint}', 'message' => '<script>window.pinovaLogsSecret=1</script>${secret}',
        'path' => '/synthetic/${secret}', 'trace' => '${secret}',
    ];
    if (1 !== $wpdb->insert($wpdb->prefix . 'pinova_logs', [
        'created_at' => gmdate('Y-m-d H:i:s', 'otp.queued' === $event['event'] ? time() - 600 : time()),
        'level' => $event['level'], 'event' => $event['event'], 'user_id' => $id,
        'correlation_id' => '${secret}', 'flow_id' => bin2hex(random_bytes(16)), 'context' => wp_json_encode($context),
    ])) { throw new RuntimeException('Could not seed historical logs fixture'); }
    $saved['ids'][] = (int)$wpdb->insert_id;
    update_option('${fixtureOption}', $saved, false);
}
echo 'PINOVA_LOGS_BROWSER_DATA ' . wp_json_encode([
    'login' => \Pinova\Integrations\Wordpress\NativeLoginGate::url(), 'ids' => $saved['ids'],
    'dates' => $saved['dates'], 'user_id' => $id,
]);
`);
});

test.afterAll(async () => {
    await cli(String.raw`
$attachment_path = WPMU_PLUGIN_DIR . '/${attachmentFixtureName}';
if (file_exists($attachment_path) || is_link($attachment_path)) {
    if (is_link($attachment_path) || !is_file($attachment_path) || basename($attachment_path) !== '${attachmentFixtureName}'
        || hash_file('sha256', $attachment_path) !== '${attachmentFixtureHash}') {
        throw new RuntimeException('Refusing cleanup of an unowned attachment fixture');
    }
    if (!unlink($attachment_path)) { throw new RuntimeException('Could not remove owned attachment fixture'); }
}
$saved = get_option('${fixtureOption}');
if (false === $saved) { return; }
$id = (int)$saved['user_id'];
if ($id && '${suffix}' !== get_user_meta($id, '_pinova_logs_browser_owner', true)) {
    throw new RuntimeException('Refusing cleanup of an unowned logs browser account');
}
$review = get_option($saved['review_name']);
if (is_array($review) && in_array($review['through_id'] ?? 0, $saved['ids'], true)) {
    false === $saved['review_before'] ? delete_option($saved['review_name']) : update_option($saved['review_name'], $saved['review_before']);
}
$arm = get_option('pinova_native_login_arm');
if ($id && is_array($arm) && $id === (int)($arm['user_id'] ?? 0)) {
    false === $saved['arm_before'] ? delete_option('pinova_native_login_arm') : update_option('pinova_native_login_arm', $saved['arm_before']);
}
if ($id) {
    // Includes only synthetic evidence and audit events attributed to this owned administrator.
    if (false === $wpdb->delete($wpdb->prefix . 'pinova_logs', ['user_id' => $id])) {
        throw new RuntimeException('Could not remove owned logs browser events');
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    if (!wp_delete_user($id)) { throw new RuntimeException('Could not remove owned logs browser account'); }
}
delete_option('${fixtureOption}');
`);
});

test('Logs and Issues explains evidence, preserves manual reviews, reopens recurrence and downloads redacted JSON', async ({ browserName, page }, testInfo) => {
    test.setTimeout(120_000);
    await page.goto(fixture.login, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#user_login')).toHaveValue('');
    await expect(page.locator('body')).not.toContainText('Undefined variable');
    // Wait for WordPress's delayed autofocus before it can interrupt password entry.
    await expect(page.locator('#user_login')).toBeFocused();
    await page.locator('#user_login').fill(username);
    await page.locator('#user_pass').fill(password);
    await expect(page.locator('#user_login')).toHaveValue(username);
    await expect(page.locator('#user_pass')).toHaveValue(password);
    await page.locator('#wp-submit').click();
    await expect.poll(async () => (await page.context().cookies()).some(cookie => cookie.name.startsWith('wordpress_logged_in_'))).toBe(true);

    const query = new URLSearchParams({ page: 'pinova-logs', ...fixture.dates });
    const response = await page.goto(`/wp-admin/admin.php?${query}`, { waitUntil: 'domcontentloaded' });
    expect(response.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'لاگ‌ها و مشکلات پینوا' })).toBeVisible();
    const issues = page.locator('section[aria-labelledby="pinova-issues-heading"]');
    await expect(issues).toContainText('خرابی مشاهده‌شده لزوماً باگ پینوا نیست');
    await expect(issues).toContainText('تأیید سرویس‌دهنده نیز به معنی دریافت پیامک روی گوشی نیست');
    await expect(issues).toContainText('ناپدیدشدن یک رخداد از بازه یا پایان نگهداری، به معنی رفع آن نیست');
    const rowFor = code => issues.locator('tbody tr').filter({ has: page.getByText(code, { exact: true }) });
    const before = await report();
    expect(before.coverage).toMatchObject({ complete: true, truncated: false, observed_counts_only: true, physical_delivery: 'not_verified' });
    const categories = {
        [failureCode]: 'خرابی مشاهده‌شده؛ علت هنوز تأیید نشده',
        'otp.verify_failed:token_expired': 'رد درخواست مطابق کنترل‌های سیستم',
        'otp.outcome_unknown': 'شواهد ناکافی برای تشخیص',
        'logging.unknown_event': 'شواهد ناکافی برای تشخیص',
    };
    for (const [code, label] of Object.entries(categories)) {
        const issue = before.issues.find(value => value.code === code);
        expect(issue).toBeTruthy();
        expect(issue.investigation_hint).toBeTruthy();
        await expect(rowFor(code)).toContainText(label);
        await expect(rowFor(code)).toContainText(issue.investigation_hint);
    }
    const failure = before.issues.find(value => value.code === failureCode);
    const originalEvents = await eventSnapshot();
    expect(originalEvents.count).toBe(4);
    await expect(rowFor(failureCode)).toContainText('نیازمند بررسی');
    const evidence = rowFor(failureCode).getByRole('link', { name: `#${fixture.ids[0]}`, exact: true });
    const evidenceQuery = new URL(await evidence.getAttribute('href')).searchParams;
    expect(evidenceQuery.get('event')).toBe('otp.channel_send_failed');
    expect(evidenceQuery.get('flow_id')).toMatch(/^[a-f0-9]{32}$/);

    await rowFor(failureCode).getByRole('button', { name: 'بررسی شد', exact: true }).click();
    await expect(rowFor(failureCode)).toContainText('بررسی‌شده؛ رفع تأیید نشده');
    let reviewed = (await report()).issues.find(value => value.code === failureCode);
    expect(reviewed.state).toBe('acknowledged');
    expect(reviewed.review.through_id).toBe(failure.last_event_id);
    expect(reviewed.evidence).toEqual(failure.evidence);
    expect(await eventSnapshot()).toEqual(originalEvents);

    await rowFor(failureCode).getByRole('button', { name: 'اعلام رفع توسط مدیر', exact: true }).click();
    await expect(rowFor(failureCode)).toContainText('مدیر اعلام رفع کرده؛ آزمون رفع ثبت نشده');
    reviewed = (await report()).issues.find(value => value.code === failureCode);
    expect(reviewed.state).toBe('resolved_unverified');
    expect(reviewed.observed_count).toBe(failure.observed_count);
    expect(await eventSnapshot()).toEqual(originalEvents);

    const recurrence = await readData(String.raw`
$saved = get_option('${fixtureOption}');
$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND user_id = %d', $wpdb->prefix . 'pinova_logs', $saved['ids'][0], $saved['user_id']), ARRAY_A);
if (!$row) { throw new RuntimeException('Missing owned recurrence fixture'); }
unset($row['id']);
$row['created_at'] = gmdate('Y-m-d H:i:s');
if (1 !== $wpdb->insert($wpdb->prefix . 'pinova_logs', $row)) { throw new RuntimeException('Could not insert recurrence fixture'); }
$id = (int)$wpdb->insert_id;
$saved['ids'][] = $id;
update_option('${fixtureOption}', $saved, false);
echo 'PINOVA_LOGS_BROWSER_DATA ' . wp_json_encode($id);
`);
    await page.goto(`/wp-admin/admin.php?${query}`, { waitUntil: 'domcontentloaded' });
    await expect(rowFor(failureCode)).toContainText('نیازمند بررسی');
    const expected = await report();
    const reopened = expected.issues.find(value => value.code === failureCode);
    expect(reopened.state).toBe('open');
    expect(reopened.review).toEqual(reviewed.review);
    expect(reopened.last_event_id).toBe(recurrence);
    expect(reopened.observed_count).toBe(failure.observed_count + 1);
    expect((await eventSnapshot()).count).toBe(5);

    const adaptAttachment = browserName === 'webkit' && process.platform === 'linux';
    if (adaptAttachment) {
        // WPE displays JSON attachments, and its intercepted responses cannot
        // reliably become downloads. A run-owned MU fixture verifies the real
        // precommit headers, then changes MIME on the native HTTP response only.
        // It never reads or transforms the export body. Safari remains separate.
        testInfo.annotations.push({ type: 'platform-limitation', description:
            'Linux WPE native-HTTP MIME fixture; genuine JSON headers are witnessed and export parity is asserted. '
            + 'https://github.com/microsoft/playwright/blob/v1.64.1/browser_patches/webkit/patches/bootstrap.diff' });
        await cli(String.raw`
$saved = get_option('${fixtureOption}');
if (!is_array($saved) || '${suffix}' !== get_user_meta((int)$saved['user_id'], '_pinova_logs_browser_owner', true)) {
    throw new RuntimeException('Attachment fixture requires its owned administrator');
}
// The Cron boundary uses this same wp-env CLI/MU-directory installation path.
if (!wp_mkdir_p(WPMU_PLUGIN_DIR) || is_link(WPMU_PLUGIN_DIR) || !is_writable(WPMU_PLUGIN_DIR)
    || realpath(WPMU_PLUGIN_DIR) !== realpath(ABSPATH . 'wp-content/mu-plugins')) {
    throw new RuntimeException('Disposable MU fixture directory is unavailable');
}
$path = WPMU_PLUGIN_DIR . '/${attachmentFixtureName}';
if (file_exists($path) || is_link($path)) { throw new RuntimeException('Attachment fixture ownership collision'); }
$source = base64_decode('${Buffer.from(attachmentFixtureSource).toString('base64')}', true);
if (!is_string($source) || hash('sha256', $source) !== '${attachmentFixtureHash}') { throw new RuntimeException('Invalid attachment fixture source'); }
$file = fopen($path, 'x');
if (false === $file) { throw new RuntimeException('Could not create owned attachment fixture'); }
try { $written = fwrite($file, $source); } finally { fclose($file); }
if ($written !== strlen($source) || hash_file('sha256', $path) !== '${attachmentFixtureHash}') {
    throw new RuntimeException('Incomplete attachment fixture installation');
}
$saved['attachment_owner'] = '${suffix}';
$saved['attachment_run'] = '${process.env.GITHUB_RUN_ID}:${process.env.GITHUB_RUN_ATTEMPT}';
$saved['attachment_sha256'] = '${attachmentFixtureHash}';
$saved['attachment_witnesses'] = [];
if (!update_option('${fixtureOption}', $saved, false)) { throw new RuntimeException('Could not record attachment fixture ownership'); }
`);
    }
    const expectAttachmentWitnesses = async count => {
        if (!adaptAttachment) return;
        const observations = await readData(String.raw`
$saved = get_option('${fixtureOption}');
echo 'PINOVA_LOGS_BROWSER_DATA ' . wp_json_encode($saved['attachment_witnesses'] ?? []);
`);
        expect(observations).toEqual(Array.from({ length: count }, () => ({
            status: 200, json: true, attachment: true, nosniff: true, actor: true, nonce: true, adapted: true,
        })));
    };

    const downloaded = page.waitForEvent('download');
    await issues.getByRole('button', { name: 'دریافت گزارش مشکلات برای بررسی', exact: true }).click();
    const download = await downloaded;
    expect(download.suggestedFilename()).toBe('pinova-issues.json');
    expect(await download.failure()).toBeNull();
    const json = await readFile(await download.path(), 'utf8');
    await expectAttachmentWitnesses(1);
    const exported = JSON.parse(json);
    expect(exported.schema).toBe('pinova.issues.v1');
    expect(exported.selected_range).toEqual(expected.selected_range);
    expect(exported.issues).toEqual(expected.issues);
    expect(exported.coverage).toEqual(expected.coverage);
    expect(Buffer.byteLength(json)).toBeLessThanOrEqual(2 * 1024 * 1024);
    const evidenceContext = exported.issues.find(value => value.code === failureCode).evidence[0].context;
    expect(evidenceContext).toMatchObject({ reason: 'provider_returned_false', channel: 'sms', duration_ms: 53 });
    for (const output of [json, await page.locator('#wpbody-content').innerHTML()]) {
        expect(output).not.toContain(secret);
        expect(output).not.toContain(fingerprint);
        expect(output).not.toContain('window.pinovaLogsSecret');
    }
    expect(await page.evaluate(() => window.pinovaLogsSecret)).toBeUndefined();

    const flow = evidenceQuery.get('flow_id');
    const timeline = await readData(String.raw`
$saved = get_option('${fixtureOption}');
echo 'PINOVA_LOGS_BROWSER_DATA ' . wp_json_encode(\Pinova\Logging\IssueMonitor::report($saved['dates'] + ['flow_id' => '${flow}']));
`);
    const timelineQuery = new URLSearchParams({ page: 'pinova-logs', ...fixture.dates, flow_id: flow });
    await page.goto(`/wp-admin/admin.php?${timelineQuery}`, { waitUntil: 'domcontentloaded' });
    await expect(issues.getByRole('heading', { name: 'خط زمانی رخدادهای مشاهده‌شده', exact: true })).toBeVisible();
    await expect(issues).toContainText('دریافت گوشی: تأیید نشده');
    await expect(issues.getByRole('button', { name: 'بررسی شد', exact: true })).toHaveCount(0);
    await expect(issues.locator('input[name="flow_id"]')).toHaveValue(flow);
    const timelineDownload = page.waitForEvent('download');
    await issues.getByRole('button', { name: 'دریافت گزارش مشکلات برای بررسی', exact: true }).click();
    const timelineFile = await timelineDownload;
    expect(await timelineFile.failure()).toBeNull();
    const timelineJson = await readFile(await timelineFile.path(), 'utf8');
    await expectAttachmentWitnesses(2);
    const timelineExport = JSON.parse(timelineJson);
    expect(timelineExport.mode).toBe('timeline');
    expect(timelineExport.timeline).toEqual(timeline.timeline);
    expect(timelineExport.selected_selector).toEqual(timeline.selected_selector);
    expect(timelineExport.issues).toEqual([]);
    expect(timelineExport.timeline.physical_delivery).toBe('not_verified');
    expect(timelineJson).not.toContain(secret);
    expect(Buffer.byteLength(timelineJson)).toBeLessThanOrEqual(2 * 1024 * 1024);

    const commandReport = await readData(String.raw`
$saved = get_option('${fixtureOption}');
$command = 'pinova logs report --from=' . $saved['dates']['created_from'] . ' --to=' . $saved['dates']['created_to'] . ' --flow=${flow}';
$json = WP_CLI::runcommand($command, ['return' => true, 'launch' => false]);
$report = json_decode($json, true);
if (!is_array($report)) { throw new RuntimeException('The real report command must return JSON.'); }
echo 'PINOVA_LOGS_BROWSER_DATA ' . wp_json_encode($report);
`);
    expect(commandReport.timeline).toEqual(timeline.timeline);
    expect(commandReport.selected_selector).toEqual(timeline.selected_selector);
    const { snapshot_event_id: commandSnapshot, ...commandCoverage } = commandReport.coverage;
    const { snapshot_event_id: timelineSnapshot, ...timelineCoverage } = timeline.coverage;
    expect(commandCoverage).toEqual(timelineCoverage);
    expect(commandSnapshot).toBeGreaterThanOrEqual(timelineSnapshot);
});
