import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));
const selectedTest = 'checkout corner controls resist theme CSS after-account in every modal step';
export const modalMutations = [
    {
        name: 'heading-focus', asset: 'assets/js/pages/login-modal.js',
        before: 'heading.focus({ preventScroll: true });', after: 'void heading;',
        marker: '[modal:heading-focus]',
    },
    {
        name: 'reverse-trap', asset: 'assets/js/pages/login-modal.js',
        before: 'last.focus();', after: 'void last;',
        marker: '[modal:reverse-trap]',
    },
    {
        name: 'forward-trap', asset: 'assets/js/pages/login-modal.js',
        before: 'first.focus();', after: 'void first;',
        marker: '[modal:forward-trap]',
    },
    {
        name: 'close-position', asset: 'assets/css/account.css',
        before: '.pinova-auth-modal .pinova-auth-close-button {\n  position: absolute;',
        after: '.pinova-auth-modal .pinova-auth-close-button {\n  position: relative;',
        marker: '[modal:close-position]',
    },
    {
        name: 'back-position', asset: 'assets/css/account.css',
        before: '.pinova-auth-modal .pinova-auth-icon-button {\n  position: absolute;',
        after: '.pinova-auth-modal .pinova-auth-icon-button {\n  position: relative;',
        marker: '[modal:back-position]',
    },
];

export function requireLocalTestOrigin(value = process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8890') {
    const origin = new URL(value);
    assert.ok(['localhost', '127.0.0.1', '[::1]'].includes(origin.hostname), 'Sensitivity checks require a loopback test site');
    assert.ok(['http:', 'https:'].includes(origin.protocol));
    return origin.origin;
}

// A test-only response override; never writes a plugin file or changes a server.
export async function installModalTestMutation(page) {
    const name = process.env.PINOVA_MODAL_TEST_MUTATION || '';
    const state = { active: Boolean(name), applied: 0 };
    if (!name) return state;
    const allowedOrigin = requireLocalTestOrigin();
    const mutation = modalMutations.find(item => item.name === name);
    assert.ok(mutation, `Unknown modal test mutation: ${name}`);
    await page.route(`**/${mutation.asset}*`, async route => {
        assert.equal(new URL(route.request().url()).origin, allowedOrigin, 'Mutation target must be the local test site');
        const response = await route.fetch();
        assert.equal(response.status(), 200, 'Mutation requires a healthy asset response');
        const body = await response.text();
        assert.equal(body.split(mutation.before).length, 2, 'Mutation target must occur exactly once');
        await route.fulfill({ response, body: body.replace(mutation.before, mutation.after) });
        state.applied += 1;
    });
    return state;
}

export function checkSensitivityReport(report, exitCode, mutation, repeats = 1) {
    assert.deepEqual(report.errors, [], 'Runner/setup errors do not prove fault detection');
    const tests = [];
    function visit(suite) {
        for (const spec of suite.specs || []) {
            assert.equal(spec.title, selectedTest, 'An unexpected test was selected');
            tests.push(...spec.tests);
        }
        for (const child of suite.suites || []) visit(child);
    }
    for (const suite of report.suites || []) visit(suite);
    assert.equal(tests.length, repeats, 'No tests, missing repetitions, or an unexpected selection');
    assert.equal(report.stats.skipped, 0);
    assert.equal(report.stats.flaky, 0, 'A pass on retry is not a stable result');
    if (!mutation) {
        assert.equal(exitCode, 0, 'Healthy control failed');
        assert.equal(report.stats.expected, repeats);
        assert.equal(report.stats.unexpected, 0);
    } else {
        assert.equal(exitCode, 1, 'Mutated product escaped the test or the runner crashed');
        assert.equal(report.stats.unexpected, repeats);
        assert.equal(report.stats.expected, 0);
    }
    for (const test of tests) {
        assert.equal(test.expectedStatus, 'passed', 'Do not mark product faults as expected failures');
        assert.equal(test.results.length, 1, 'Retries are not allowed');
        const result = test.results[0];
        assert.equal(result.retry, 0);
        assert.equal(result.status, mutation ? 'failed' : 'passed');
        const errors = result.errors || [];
        if (mutation) {
            assert.equal(errors.length, 1, 'Only the intended assertion may fail');
            assert.ok(errors[0].message.includes(mutation.marker), 'Failure did not reach the intended contract');
            assert.match(errors[0].message, /expect\(/, 'A setup exception is not a product assertion');
        } else {
            assert.deepEqual(errors, []);
        }
    }
}

async function main() {
    requireLocalTestOrigin();
    const output = path.join(root, 'test-results/modal-sensitivity');
    await mkdir(output, { recursive: true });
    const cases = [
        { name: 'healthy-before', repeats: 3 },
        ...modalMutations.map(mutation => ({ name: mutation.name, repeats: 1, mutation })),
        { name: 'healthy-after', repeats: 1 },
    ];
    for (const item of cases) {
        const result = spawnSync(path.join(root, 'node_modules/.bin/playwright'), [
            'test', '--config', 'tests/browser/playwright.config.mjs',
            '--grep', `^.*${selectedTest}$`, '--workers=1', '--retries=0',
            `--repeat-each=${item.repeats}`, '--reporter=json',
            `--output=${path.join(output, item.name)}`,
        ], {
            cwd: root, encoding: 'utf8', timeout: 240_000, maxBuffer: 16 * 1024 * 1024,
            env: { ...process.env, PINOVA_MODAL_TEST_MUTATION: item.mutation?.name || '' },
        });
        await writeFile(path.join(output, `${item.name}.json`), result.stdout || '');
        await writeFile(path.join(output, `${item.name}.stderr.log`), result.stderr || '');
        assert.ifError(result.error);
        assert.equal(result.signal, null, 'Runner was interrupted');
        checkSensitivityReport(JSON.parse(result.stdout), result.status, item.mutation, item.repeats);
        console.log(`${item.name}: ${item.mutation ? 'intended product fault detected' : `${item.repeats} clean pass(es), no retries`}`);
    }
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    await main();
}
