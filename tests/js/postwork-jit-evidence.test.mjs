import assert from 'node:assert/strict';
import test from 'node:test';
import { createHash } from 'node:crypto';
import { parseJitMode, modeRequest, fastCgiModeBody, preserveOutcomeAndObserve, MODE_OBSERVATION_TIMEOUT_MS } from '../Integration/postwork-jit-evidence.mjs';

const example = (mode = '1235') => ({ schema: 'pinova.postwork-jit-mode.v1', state: 'observed', sapi: 'fpm-fcgi',
    php_version: '8.4.27', pid: 777, opcache_enabled: true,
    ini: { mode, buffer_bytes: 268435456, enable_cli: false, optimization_level: 2147401727,
        hot_loop: 64, hot_func: 127, hot_return: 8, hot_side_exit: 8 },
    jit: { enabled: mode === '1235', on: mode === '1235', kind: mode === '1235' ? 3 : 0,
        capacity_bytes: mode === '1235' ? 268435440 : 0, free_bytes: mode === '1235' ? 268400000 : 0 } });
test('selected runtime mode separates configured capacity from disabled allocation and drops raw fields', () => {
    for (const mode of ['1235', 'disable']) {
        const value = example(mode); value.private = 'SECRET-PAYLOAD';
        const result = parseJitMode(JSON.stringify(value), mode);
        assert.equal(result.ini.buffer_bytes, 268435456);
        assert.equal(result.jit.capacity_bytes === 0, mode === 'disable');
        assert.equal(JSON.stringify(result).includes('SECRET'), false);
    }
});
test('wrong mode, active mismatch, missing status and unbounded data stay unknown inputs', () => {
    assert.throws(() => parseJitMode(JSON.stringify(example('disable')), '1235'));
    const bad = example('disable'); bad.jit.on = true;
    assert.throws(() => parseJitMode(JSON.stringify(bad), 'disable'));
    assert.throws(() => parseJitMode('{"state":"unknown"}', 'disable'));
    assert.throws(() => parseJitMode('x'.repeat(4097), 'disable'));
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
});
test('successful post-outcome mode observation is still separate from completed workload', async () => {
    const primary = { state: 'passed', diagnostic_probe: { state: 'completed' } }; const writes = [];
    const result = await preserveOutcomeAndObserve(primary, name => writes.push(name), async () => parseJitMode(JSON.stringify(example('disable')), 'disable'));
    assert.equal(primary.state, 'passed'); assert.equal(result.state, 'observed');
    assert.deepEqual(writes, ['full-stack-result.json', 'postwork-jit-mode.json']);
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
