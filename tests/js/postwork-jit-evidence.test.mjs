import assert from 'node:assert/strict';
import test from 'node:test';
import { createHash } from 'node:crypto';
import { expectedNativeFpmProfile, parseJitMode, modeRequest, fastCgiModeBody, preserveOutcomeAndObserve,
    validatePostworkProfile, MODE_OBSERVATION_TIMEOUT_MS } from '../Integration/postwork-jit-evidence.mjs';

const example = (minor = '8.4') => ({ schema: 'pinova.postwork-jit-mode.v1', state: 'observed', sapi: 'fpm-fcgi',
    php_version: minor === '8.3' ? '8.3.36' : '8.4.27', pid: 777, opcache_enabled: true,
    ini: { mode: minor === '8.3' ? 'tracing' : 'disable', buffer_bytes: minor === '8.3' ? 0 : 67108864,
        enable_cli: false, optimization_level: 2147401727, hot_loop: 64, hot_func: 127, hot_return: 8, hot_side_exit: 8 },
    jit: { enabled: false, on: false, kind: minor === '8.3' ? 5 : 3, capacity_bytes: 0, free_bytes: 0 } });
const startup = (minor = '8.4') => ({ php_minor: minor, master_pid: 700, master_start_ticks: 12345,
    binary_sha256: 'a'.repeat(64), mode_endpoint_sha256: 'b'.repeat(64), proc_cmdline_sha256: 'c'.repeat(64),
    concrete_launch_argv_sha256: 'd'.repeat(64), owned_configuration_in_cmdline: true,
    flags_recoverable_from_proc_title: false, binding: 'pinned_launcher_source_and_binary_not_recovered_flags',
    declared_mode: minor === '8.3' ? 'tracing' : 'disable', configured_buffer_bytes: minor === '8.3' ? 0 : 67108864,
    declared_argv: ['FPM', '-d', minor === '8.3' ? 'opcache.jit=tracing' : 'opcache.jit=disable', '-d',
        minor === '8.3' ? 'opcache.jit_buffer_size=0' : 'opcache.jit_buffer_size=64M', '--allow-to-run-as-root', '-F', '-y', 'RUN/fpm.conf'] });
const witnessed = (minor = '8.4') => ({ ...example(minor), master_pid: 700, master_start_ticks: 12345,
    binary_sha256: 'a'.repeat(64), source_sha256: 'b'.repeat(64), responder_start_ticks: 12346,
    responder_owned_main_pool: true, primary_receipt_sha256: 'e'.repeat(64), primary_outcome_frozen: true,
    collection_boundary: 'after_primary_receipt_before_fpm_teardown', attests_signaled_worker: false });

test('selected native-default profiles distinguish configured capacity from inactive allocation and drop raw fields', () => {
    for (const minor of ['8.3', '8.4']) {
        const profile = expectedNativeFpmProfile(minor);
        assert.deepEqual(profile, minor === '8.3' ? { php_minor: '8.3', mode: 'tracing', buffer_ini: '0', buffer_bytes: 0 } :
            { php_minor: '8.4', mode: 'disable', buffer_ini: '64M', buffer_bytes: 67108864 });
        assert.equal(Object.isFrozen(profile), true);
        const value = example(minor); value.private = 'SECRET-PAYLOAD';
        const result = parseJitMode(JSON.stringify(value), minor);
        assert.equal(result.ini.buffer_bytes, profile.buffer_bytes);
        assert.deepEqual(result.jit, { enabled: false, on: false, kind: value.jit.kind, capacity_bytes: 0, free_bytes: 0 });
        assert.equal(JSON.stringify(result).includes('SECRET'), false);
    }
    assert.throws(() => expectedNativeFpmProfile('8.5'));
});

test('wrong runtime, mode, active state, allocation or missing/unbounded data cannot attest a profile', () => {
    for (const minor of ['8.3', '8.4']) {
        assert.throws(() => parseJitMode(JSON.stringify(example(minor === '8.3' ? '8.4' : '8.3')), minor));
        for (const mutate of [value => { value.ini.mode = '1235'; }, value => { value.ini.buffer_bytes = 268435456; },
            value => { value.jit.enabled = true; }, value => { value.jit.on = true; }, value => { value.jit.capacity_bytes = 1; },
            value => { value.jit.free_bytes = 1; }, value => { value.opcache_enabled = false; }]) {
            const value = example(minor); mutate(value);
            assert.throws(() => parseJitMode(JSON.stringify(value), minor));
        }
        assert.throws(() => parseJitMode('{"state":"unknown"}', minor));
        assert.throws(() => parseJitMode('x'.repeat(4097), minor));
    }
});

function frame(type, body) {
    const h = Buffer.alloc(8); h[0] = 1; h[1] = type; h.writeUInt16BE(1, 2); h.writeUInt16BE(body.length, 4);
    return Buffer.concat([h, body]);
}
test('actual framing parser is bounded and accepts only a complete native response', () => {
    const body = JSON.stringify(example());
    const response = Buffer.concat([frame(6, Buffer.from('Content-Type: text/html\r\n\r\n' + body)), frame(3, Buffer.alloc(8))]);
    assert.equal(fastCgiModeBody(response), body);
    assert.equal(fastCgiModeBody(response.subarray(0, response.length - 1)), null);
    assert.throws(() => fastCgiModeBody(Buffer.alloc(32769)));
    const wrong = Buffer.from(response); wrong.writeUInt16BE(2, 2);
    assert.throws(() => fastCgiModeBody(wrong));
    const request = modeRequest('/tmp/pinova-fpm-full-123-1/startup-jit-mode.php');
    assert.equal(request.includes(Buffer.from('wp-load')), false);
    assert.equal(request.includes(Buffer.from('PHP_VALUE')), false);
    assert.throws(() => modeRequest('/production/wp-load.php'));
});

test('actual collector writes primary outcome before observation and never replaces it on failure', async () => {
    const primary = { state: 'failed', diagnostic_evidence: { collection_boundary: 'controller_before_fpm_teardown' } };
    const before = JSON.stringify(primary); const writes = [];
    const result = await preserveOutcomeAndObserve(primary, (name, value) => writes.push({ name, value: structuredClone(value) }), async () => {
        assert.deepEqual(writes.map(row => row.name), ['full-stack-result.json']);
        throw new Error('SECRET-PAYLOAD');
    });
    assert.equal(JSON.stringify(primary), before);
    assert.equal(result.state, 'unknown'); assert.equal(JSON.stringify(result).includes('SECRET'), false);
    assert.equal(result.collection_boundary, 'after_primary_receipt_before_fpm_teardown');
    assert.equal(result.attests_signaled_worker, false);
    assert.equal(result.primary_receipt_sha256, createHash('sha256').update(JSON.stringify(primary, null, 2) + '\n').digest('hex'));
    assert.deepEqual(writes.map(row => row.name), ['full-stack-result.json', 'postwork-jit-mode.json']);
    assert.equal(Object.hasOwn(result, 'resource_acceptance'), false);
});

test('successful post-outcome observation remains separate from completed workload', async () => {
    for (const minor of ['8.3', '8.4']) {
        const primary = { state: 'passed' }; const writes = [];
        const result = await preserveOutcomeAndObserve(primary, name => writes.push(name), async () => parseJitMode(JSON.stringify(example(minor)), minor));
        assert.equal(primary.state, 'passed'); assert.equal(result.state, 'observed');
        assert.deepEqual(writes, ['full-stack-result.json', 'postwork-jit-mode.json']);
    }
});

test('unresponsive observation receives one fixed deadline and abort without losing primary outcome', async () => {
    assert.equal(MODE_OBSERVATION_TIMEOUT_MS, 3000);
    const primary = { state: 'failed' }; let aborted = false;
    const result = await preserveOutcomeAndObserve(primary, () => {}, signal => {
        signal.addEventListener('abort', () => { aborted = true; }, { once: true });
        return new Promise(() => {});
    });
    assert.equal(result.reason, 'mode_observation_timeout'); assert.equal(aborted, true);
    assert.equal(primary.state, 'failed');
});

test('separate evidence gate requires selected mode, primary binding, startup identity and owned responder', () => {
    for (const minor of ['8.3', '8.4']) {
        const options = { phpMinor: minor, primaryReceiptHash: 'e'.repeat(64), startupLaunch: startup(minor) };
        assert.equal(validatePostworkProfile(witnessed(minor), options), true);
        for (const mutate of [value => { value.primary_receipt_sha256 = 'f'.repeat(64); }, value => { value.primary_outcome_frozen = false; },
            value => { value.collection_boundary = 'before_primary'; }, value => { value.attests_signaled_worker = true; },
            value => { value.responder_owned_main_pool = false; }, value => { value.responder_start_ticks = 0; },
            value => { value.master_start_ticks++; }, value => { value.master_pid++; }, value => { value.binary_sha256 = 'f'.repeat(64); },
            value => { value.source_sha256 = 'f'.repeat(64); }]) {
            const value = witnessed(minor); mutate(value); assert.equal(validatePostworkProfile(value, options), false);
        }
        for (const mutate of [value => { value.php_minor = '8.5'; }, value => { value.declared_mode = '1235'; },
            value => { value.configured_buffer_bytes = 268435456; }, value => { value.declared_argv[2] = 'opcache.jit=1235'; },
            value => { value.owned_configuration_in_cmdline = false; }]) {
            const launch = startup(minor); mutate(launch);
            assert.equal(validatePostworkProfile(witnessed(minor), { ...options, startupLaunch: launch }), false);
        }
        assert.equal(validatePostworkProfile(null, options), false);
        assert.equal(validatePostworkProfile({ state: 'unknown' }, options), false);
        assert.equal(validatePostworkProfile(witnessed(minor), {}), false);
    }
});

test('secondary receipt write failure stays outside the preserved primary workload result', async () => {
    const primary = { state: 'passed' }, writes = [];
    const result = await preserveOutcomeAndObserve(primary, (name, value) => {
        if (name === 'postwork-jit-mode.json') throw new Error('SECRET-write-failure');
        writes.push({ name, value: structuredClone(value) });
    }, async () => parseJitMode(JSON.stringify(example()), '8.4'));
    assert.deepEqual(writes, [{ name: 'full-stack-result.json', value: { state: 'passed' } }]);
    assert.equal(primary.state, 'passed'); assert.equal(result.state, 'observed');
    assert.equal(JSON.stringify(result).includes('SECRET'), false);
});
