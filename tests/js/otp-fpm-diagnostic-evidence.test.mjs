import assert from 'node:assert/strict';
import test from 'node:test';
import { DIAGNOSTIC_CASES, executionEvidence, sanitizeCheckpoint, parseFpmTerminationTail, finiteMarkers, readDiagnosticEvidence } from '../Integration/otp-fpm-diagnostic-evidence.mjs';
const stamp = '[31-Oct-2026 14:00:00] WARNING: [pool fixture] child 123';
test('native timeout and signal witnesses discard URI, script and credential text', () => {
    const secret = 'OTP-123456-cookie-password-token';
    const result = parseFpmTerminationTail(`${stamp}, script '/private/${secret}.php' (request: "POST /?${secret}"), execution timed out (20.123 sec), terminating\n${stamp} exited on signal 15 (SIGTERM) after 21.2 seconds from start\n`);
    assert.deepEqual(result.events, [{ pid: 123, pool: 'fixture', event: 'request_timeout', timeout_seconds: 20.123 },
        { pid: 123, pool: 'fixture', event: 'signal_exit', signal_number: 15, signal_name: 'SIGTERM', lifetime_seconds: 21.2 }]);
    assert.equal(JSON.stringify(result).includes(secret), false);
    assert.equal(JSON.stringify(result).includes('/private/'), false);
});
test('finite parser bounds and unknown labels cannot smuggle raw diagnostics', () => {
    const secret = 'SECRET-OTP';
    const lines = Array.from({ length: 20 }, () => `${stamp} exited on signal 9 (${secret}) after 2 seconds from start`).join('\n');
    const result = parseFpmTerminationTail(lines);
    assert.equal(result.events.length, 16); assert.equal(result.event_bound, true);
    assert.equal(result.events[0].signal_name, 'SIGKILL');
    assert.equal(JSON.stringify(result).includes(secret), false);
    const malformed = parseFpmTerminationTail(`${stamp} exited on signal 99 (SECRET) after 2 seconds from start\n${'x'.repeat(9000)}\nUncaught Error: ${secret}\n`);
    assert.equal(malformed.events.length, 0); assert.equal(malformed.invalid_records, 3);
    assert.equal(parseFpmTerminationTail('x'.repeat(70000)).tail_truncated, true);
});
test('execution status is finite even when no child receipt exists', () => {
    const result = executionEvidence({ status: null, signal: 'SIGTERM', error: { code: 'ETIMEDOUT', message: 'private URI OTP' },
        stdout: 'secret-output', stderr: 'private-script' }, 180012);
    assert.equal(result.error_code, 'ETIMEDOUT'); assert.equal(result.signal, 'SIGTERM');
    assert.equal(result.elapsed_ms, 180012); assert.equal(result.timeout_ms, 180000);
    assert.equal(JSON.stringify(result).includes('private'), false);
    assert.equal(executionEvidence({ signal: 'SIGSECRET', error: { code: 'SECRET' } }, Infinity).signal, 'unknown');
    assert.equal(executionEvidence({}, -1).elapsed_ms, null);
});
test('checkpoint normalization accepts only finite phase and lifecycle fields', () => {
    const result = sanitizeCheckpoint({ phase: 'warm_issue', arm: 'b1', flow_index: 5, at_ms: 1793459146000,
        elapsed_ms: 2000, pid: 123, uri: '/private/?otp=123456', resource_acceptance: true });
    assert.equal(result.phase, 'warm_issue'); assert.equal(result.flow_index, 5); assert.equal(result.resource_acceptance, false);
    assert.equal(JSON.stringify(result).includes('otp'), false);
    const invalid = sanitizeCheckpoint({ phase: 'private-secret', flow_index: 999, at_ms: NaN, pid: -1 });
    assert.equal(invalid.phase, 'unknown'); assert.equal(invalid.flow_index, null); assert.equal(invalid.at_ms, null);
});
test('last finite markers preserve chronology with no arbitrary context', () => {
    const rows = Array.from({ length: 70 }, (_, index) => JSON.stringify({ case: 'revision-warm-worker-5', event: 'cli_command',
        pid: index + 10, at: 1793459146 + index, jwt: 'SECRET-JWT', uri: '/private' }));
    const result = finiteMarkers(rows.join('\n'));
    assert.equal(result.events.length, 64); assert.equal(result.event_bound, true); assert.equal(result.events[0].pid, 16);
    assert.equal(JSON.stringify(result).includes('SECRET'), false);
    const unknown = finiteMarkers(JSON.stringify({ case: 'OTP-123456', event: 'provider_enter', pid: 123, at: 1793459146 }));
    assert.equal(unknown.events[0].case, 'unknown');
    assert.equal(finiteMarkers('{"event":"SECRET"}\n').events.length, 0);
    assert.equal(finiteMarkers('x'.repeat(70000)).tail_truncated, true);
});
test('normal status-pool exits and fixed probe identities remain distinguishable from resource acceptance', () => {
    const result = parseFpmTerminationTail('[31-Oct-2026 14:00:00] NOTICE: [pool fixture_status] child 222 exited with code 0 after 10.123 seconds from start\n');
    assert.deepEqual(result.events, [{ pid: 222, pool: 'fixture_status', event: 'normal_exit', exit_code: 0, lifetime_seconds: 10.123 }]);
    assert.equal(DIAGNOSTIC_CASES.length, 4); assert.equal(new Set(DIAGNOSTIC_CASES.map(item => item.id)).size, 4);
    assert.equal(readDiagnosticEvidence('/outside-the-authorized-fixture').checkpoint_read_success, false);
});
