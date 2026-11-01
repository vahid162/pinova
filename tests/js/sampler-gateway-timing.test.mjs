import test from 'node:test';
import assert from 'node:assert/strict';
import { constants, readFileSync, mkdtempSync, writeFileSync, symlinkSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { createSamplerGatewayTiming, parseNginxTiming, proofSerialization, readNginxTiming } from '../Integration/sampler-gateway-timing.mjs';

function observed({ redirect = false, clockJump = false, downwardDrift = false } = {}) {
    const recorder = createSamplerGatewayTiming(), rows = [];
    for (let i = 0; i < 20; i++) {
        const mode = i < 10 ? 'off' : 'on', label = `${mode}-normal-${i % 10 + 1}-page`;
        const base = (i + 1) * 1000, epoch = base + 100000;
        const c = recorder.clientStart(label, 0, base, epoch);
        const sample = recorder.samplerStart(`${mode}_normal`, base + 30, epoch + 30);
        recorder.samplerEnd(sample, base + 90, epoch + 90);
        recorder.clientHeaders(c, base + 95, epoch + 95, redirect && i === 0 ? 301 : 200);
        recorder.clientEnd(c, base + 100, epoch + 100 + (i === 19 ? clockJump ? 20 : downwardDrift ? -4 : 0 : 0));
        rows.push({ case_id: `c${String(i + 1).padStart(2, '0')}`, msec: ((epoch + 60) / 1000).toFixed(3),
            request_time: '0.060', upstream_response_time: '0.050', status: redirect && i === 0 ? 301 : 200, bytes: 300, body_bytes: 100 });
        if (redirect && i === 0) {
            const next = recorder.clientStart(label, 1, base + 110, epoch + 110);
            recorder.clientHeaders(next, base + 145, epoch + 145, 200);
            recorder.clientEnd(next, base + 150, epoch + 150);
            rows.push({ case_id: 'c01', msec: ((epoch + 140) / 1000).toFixed(3), request_time: '0.030',
                upstream_response_time: '0.020', status: 200, bytes: 300, body_bytes: 100 });
        }
    }
    return { recorder, rows, serialize: list => JSON.parse(JSON.stringify(recorder.snapshot(list.map(row => JSON.stringify(row)).join('\n') + '\n'))) };
}

test('actual serializer joins twenty cases and reports overlap without claiming causation', () => {
    const { rows, serialize } = observed();
    const proof = serialize(rows);
    assert.equal(proof.state, 'observed'); assert.equal(proof.resource_acceptance, false);
    assert.equal(proof.complete_client_requests, 20); assert.equal(proof.matched_physical_hops, 20);
    assert.equal(proof.samples[0].client_ms, 100);
    assert.equal(proof.samples[0].sampler_overlap_client_ms, 60);
    assert.equal(proof.samples[0].estimated_nginx_log_to_callback_gap_ms, 37);
    assert.equal(proof.samples[0].estimated_sampler_overlap_after_nginx_log_ms, 27);
    assert.match(proof.attribution, /overlap is not causation/);
    assert.match(proof.attribution, /does not bound sending completion or packet arrival/);
    assert.equal(proof.cross_process_state, 'estimated_cached_timestamp_freshness_unknown');
    assert.equal(JSON.stringify(proof).includes('lower_bound'), false);
});

test('actual matching supports serial redirects but rejects ambiguous, missing, and mismatched hops', () => {
    const { rows, serialize } = observed({ redirect: true });
    assert.equal(serialize(rows).matched_physical_hops, 21);
    assert.equal(serialize(rows).state, 'observed');
    for (const broken of [rows.slice(1), [...rows, rows[0]], rows.map((row, i) => i === 0 ? { ...row, status: 200 } : row),
        rows.map((row, i) => i === 1 ? { ...row, msec: rows[0].msec } : row)]) {
        const proof = serialize(broken); assert.equal(proof.state, 'partial_or_unknown'); assert.deepEqual(proof.samples, []);
    }
});

test('missing log, invalid upstream lists, unexpected fields and clock jumps are unknown', () => {
    const { recorder, rows, serialize } = observed();
    assert.equal(recorder.snapshot(null).state, 'partial_or_unknown');
    for (const broken of [{ ...rows[0], upstream_response_time: '0.010, 0.020' },
        { ...rows[0], upstream_response_time: '-' }, { ...rows[0], uri: '/private' },
        { ...rows[0], case_id: 'on-normal-1-page' }, { ...rows[0], bytes: -1 }]) {
        const proof = serialize([broken, ...rows.slice(1)]);
        assert.equal(proof.state, 'partial_or_unknown'); assert.deepEqual(proof.samples, []);
        assert.equal(JSON.stringify(proof).includes('/private'), false);
    }
    const jumped = observed({ clockJump: true });
    assert.equal(jumped.serialize(jumped.rows).state, 'partial_or_unknown');
    assert.ok(jumped.serialize(jumped.rows).reasons.includes('cross_clock_discontinuity'));
});

test('caps, incomplete callbacks and unallowlisted labels never imply complete evidence', () => {
    const recorder = createSamplerGatewayTiming();
    assert.equal(recorder.clientStart('on-normal-11-page', 0, 1, 1), null);
    assert.equal(recorder.clientStart('/secret?cookie=value', 0, 1, 1), null);
    for (let i = 0; i < 81; i++) recorder.clientStart('on-normal-1-page', 0, i + 1, i + 1);
    const proof = recorder.snapshot('');
    assert.equal(proof.state, 'partial_or_unknown'); assert.equal(proof.client_hops.length, 80);
    assert.ok(proof.reasons.includes('client_hop_bound'));
    const callback = createSamplerGatewayTiming(); callback.samplerStart('off_normal', 1, 1);
    assert.ok(callback.snapshot('').reasons.includes('sampler_end_missing'));
    const capped = createSamplerGatewayTiming();
    for (let i = 0; i < 4097; i++) { const token = capped.samplerStart('off_normal', i * 2 + 1, i * 2 + 1); capped.samplerEnd(token, i * 2 + 2, i * 2 + 2); }
    assert.equal(capped.snapshot('').sampler_callbacks.length, 4096);
    assert.ok(capped.snapshot('').reasons.includes('sampler_callback_bound'));
    assert.deepEqual(parseNginxTiming('x'.repeat(65537)).reasons, ['nginx_read_or_byte_bound']);
});

test('actual Nginx map emits only fixed enums and serializer leaves evaluation thresholds immutable', () => {
    const shell = readFileSync(new URL('../Integration/otp-fpm-full-stack.sh', import.meta.url), 'utf8');
    const entries = [...shell.matchAll(/~\^(off|on)-normal-([0-9]+)-page\\\$ (c[0-9]{2});/g)];
    assert.equal(entries.length, 20); assert.equal(new Set(entries.map(entry => entry[3])).size, 20);
    assert.match(shell, /default "";/); assert.match(shell, /if=\\\$ordinary_case_id/);
    const format = shell.split('log_format ordinary_timing')[1].split('\n')[0];
    for (const forbidden of ['request_uri', 'remote_addr', 'cookie', 'http_x_', 'request_body']) assert.equal(format.includes(forbidden), false);
    const gates = Object.freeze({ normal: Object.freeze({ ordinary_p95_ms: 458.71323749999647 }) });
    const before = JSON.stringify(gates);
    const serialized = JSON.parse(JSON.stringify(proofSerialization('frozen-resource-gates.json', gates)));
    assert.equal(serialized.proof_metadata.resource_acceptance, false);
    assert.equal(JSON.stringify(gates), before); assert.equal(Object.hasOwn(gates, 'proof_metadata'), false);
    assert.equal(proofSerialization('full-stack-result.json', gates), gates);
});

test('accepted four-millisecond downward drift widens projection without claiming a finish bound', () => {
    const { rows, serialize } = observed({ downwardDrift: true });
    const proof = serialize(rows);
    assert.equal(proof.state, 'observed'); assert.equal(proof.clock_offset_span_ms, 4);
    assert.deepEqual(proof.clock_offset_envelope_ms, { min: 99996, max: 100000 });
    assert.equal(proof.samples[0].estimated_nginx_log_to_callback_gap_ms, 33);
    assert.equal(proof.samples[0].estimated_sampler_overlap_after_nginx_log_ms, 23);
    assert.equal(proof.samples[0].sampler_overlap_client_ms, 60);
    assert.equal(proof.nginx_timestamp_freshness, 'unknown_cached_ngx_timeofday');
    assert.equal(JSON.stringify(proof).includes('lower_bound'), false);
});

test('actual bounded reader rejects real symlinks/directories and closes regular-file descriptors', () => {
    const root = mkdtempSync(path.join(tmpdir(), 'sampler-gateway-reader-'));
    try {
        const target = path.join(root, 'timing.jsonl'), link = path.join(root, 'link');
        writeFileSync(target, 'finite\n'); symlinkSync(target, link);
        assert.equal(readNginxTiming(target), 'finite\n');
        assert.equal(readNginxTiming(link), null); assert.equal(readNginxTiming(root), null);
        writeFileSync(target, 'x'.repeat(65537)); assert.equal(readNginxTiming(target), null);
    } finally { rmSync(root, { recursive: true, force: true }); }
});

test('actual reader uses nofollow/nonblock and never reads FIFO-like or unstable targets', () => {
    let reads = 0, closes = 0, observedFlags;
    const stat = extra => ({ isFile: () => true, size: 1, dev: 1, ino: 2, mtimeMs: 3, ctimeMs: 4, ...extra });
    const io = { constants, openSync: (_file, flags) => { observedFlags = flags; return 7; },
        fstatSync: () => stat({ isFile: () => false }), readSync: () => { reads++; throw new Error('must not read'); },
        closeSync: () => { closes++; } };
    assert.equal(readNginxTiming('private', io), null); assert.equal(reads, 0); assert.equal(closes, 1);
    assert.ok((observedFlags & constants.O_NOFOLLOW) !== 0); assert.ok((observedFlags & constants.O_NONBLOCK) !== 0);
    let calls = 0;
    const unstable = { ...io, fstatSync: () => stat(calls++ ? { ino: 99 } : {}),
        readSync: (_fd, buffer) => { reads++; buffer.write('a'); return 1; } };
    assert.equal(readNginxTiming('private', unstable), null); assert.equal(reads, 1); assert.equal(closes, 2);
    assert.equal(readNginxTiming('private', { ...io, constants: { O_RDONLY: 0 } }), null);
    assert.equal(closes, 2);
});
