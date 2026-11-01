import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { DIAGNOSTIC_CASES, executionEvidence, sanitizeCheckpoint, parseFpmTerminationTail, finiteMarkers, readDiagnosticEvidence, parseNginxErrorTail } from '../Integration/otp-fpm-diagnostic-evidence.mjs';
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
        stdout: 'secret-output', stderr: 'private-script' }, 420012);
    assert.equal(result.error_code, 'ETIMEDOUT'); assert.equal(result.signal, 'SIGTERM');
    assert.equal(result.elapsed_ms, 420012); assert.equal(result.timeout_ms, 420000);
    assert.equal(JSON.stringify(result).includes('private'), false);
    assert.equal(executionEvidence({ signal: 'SIGSECRET', error: { code: 'SECRET' } }, Infinity).signal, 'unknown');
    assert.equal(executionEvidence({}, -1).elapsed_ms, null);
    const completed = executionEvidence({ status: 0, signal: null, stdout: '', stderr: '' }, 305000);
    assert.equal(completed.exit_code, 0); assert.equal(completed.error_code, null);
    assert.equal(completed.elapsed_ms, 305000); assert.equal(completed.timeout_ms, 420000);
});
test('checkpoint normalization accepts only finite phase and lifecycle fields', () => {
    const result = sanitizeCheckpoint({ phase: 'warm_issue', arm: 'b1', flow_index: 5, at_ms: 1793459146000,
        elapsed_ms: 2000, pid: 123, uri: '/private/?otp=123456', resource_acceptance: true });
    assert.equal(result.phase, 'warm_issue'); assert.equal(result.flow_index, 5); assert.equal(result.resource_acceptance, false);
    assert.equal(JSON.stringify(result).includes('otp'), false);
    const invalid = sanitizeCheckpoint({ phase: 'private-secret', flow_index: 999, at_ms: NaN, pid: -1 });
    assert.equal(invalid.phase, 'unknown'); assert.equal(invalid.flow_index, null); assert.equal(invalid.at_ms, null);
    const measured = sanitizeCheckpoint({ phase: 'measurement_acceptance', flow_index: 10, pid: 123,
        uri: '/private/?otp=123456', resource_acceptance: true });
    assert.equal(measured.phase, 'measurement_acceptance'); assert.equal(measured.flow_index, 10);
    assert.equal(measured.resource_acceptance, false); assert.equal(JSON.stringify(measured).includes('otp'), false);
});
test('last finite markers preserve chronology with no arbitrary context', () => {
    const rows = Array.from({ length: 262 }, (_, index) => JSON.stringify({ case: 'revision-warm-worker-5', event: 'cli_command',
        pid: index + 10, at: 1793459146 + index, jwt: 'SECRET-JWT', uri: '/private' }));
    const result = finiteMarkers(rows.join('\n'));
    assert.equal(result.events.length, 256); assert.equal(result.event_bound, true); assert.equal(result.events[0].pid, 16);
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

const nginxPrefix = '2026/11/01 07:00:00 [error] 123#123: *456 ';
test('real Nginx response-header limit error becomes a count without request or cookie context', () => {
    const secret='CANARY-session-cookie-OTP';
    const line=nginxPrefix+'upstream sent too big header while reading response header from upstream, client: 127.0.0.1, request: "POST /?'+secret+' HTTP/1.1", upstream: "fastcgi://private", cookie: "'+secret+'"';
    const result=parseNginxErrorTail(line);
    assert.equal(result.read_success,true);assert.equal(result.class_counts.response_header_too_large,1);
    assert.equal(result.invalid_records,0);assert.equal(result.tail_truncated,false);
    assert.equal(JSON.stringify(result).includes(secret),false);assert.equal(JSON.stringify(result).includes('fastcgi://'),false);
    assert.deepEqual(Object.keys(result.class_counts),['response_header_too_large','upstream_premature_close','upstream_timeout','upstream_connection_failure','upstream_invalid_header','other_error']);
});
test('a classifier phrase inside a URL or arbitrary message cannot forge an upstream header error', () => {
    const phrase='upstream sent too big header while reading response header from upstream';
    const result=parseNginxErrorTail(nginxPrefix+'open() failed (2: missing), request: "GET /?'+phrase+' HTTP/1.1"\n'+phrase);
    assert.equal(result.class_counts.response_header_too_large,0);assert.equal(result.class_counts.other_error,1);assert.equal(result.invalid_records,1);
});
test('other ordinary upstream failures remain separate finite classes', () => {
    const lines=['upstream prematurely closed connection while reading response header from upstream',
        'upstream timed out (110: Connection timed out) while reading response header from upstream',
        'connect() failed (111: Connection refused) while connecting to upstream',
        'recv() failed (104: Connection reset by peer) while reading response header from upstream',
        'upstream sent invalid header: "private-header-value"'];
    const result=parseNginxErrorTail(lines.map(line=>nginxPrefix+line+', request: "POST /private"').join('\n'));
    assert.deepEqual(result.class_counts,{response_header_too_large:0,upstream_premature_close:1,upstream_timeout:1,upstream_connection_failure:2,upstream_invalid_header:1,other_error:0});
    assert.equal(JSON.stringify(result).includes('private'),false);
});
test('oversized and truncated error tails cannot become complete absence evidence', () => {
    const result=parseNginxErrorTail(nginxPrefix+'upstream sent too big header while reading response header from upstream, '+ 'SECRET'.repeat(1800));
    assert.equal(result.invalid_records,1);assert.equal(result.class_counts.response_header_too_large,0);
    assert.equal(parseNginxErrorTail('x'.repeat(70000)).tail_truncated,true);
    assert.equal(parseNginxErrorTail(nginxPrefix+'some error',{truncated:true}).tail_truncated,true);
    assert.equal(parseNginxErrorTail(null).read_success,false);
});
test('an unavailable or unowned Nginx log retains read failure instead of asserting no gateway errors', () => {
    const result=readDiagnosticEvidence('/outside-the-authorized-fixture').nginx_errors;
    assert.equal(result.read_success,false);assert.equal(result.classification_scope,'retained_64KiB_tail');
    assert.equal(Object.values(result.class_counts).every(value=>value===0),true);
});

// Native evidence is collected after workload completion; retain the signaled
// request even when later unrelated markers would push it out of the old64 cap.
test('256 retained markers preserve an earlier native signal PID without widening exported fields', () => {
    const rows = Array.from({ length: 300 }, (_, index) => JSON.stringify({
        case: 'revision-warm-7-verify', event: 'request_start', pid: index === 100 ? 777 : 1000 + index,
        at: 1793459146 + index / 1000, raw_context: 'SECRET-OTP-COOKIE',
    }));
    const markers = finiteMarkers(rows.join('\n'));
    const native = parseFpmTerminationTail('[31-Oct-2026 14:00:00] WARNING: [pool fixture] child 777 exited on signal 11 (SIGSEGV) after 2 seconds from start\n');
    const signal = native.events.find(event => event.event === 'signal_exit' && event.signal_number === 11 && event.pool === 'fixture');
    assert.equal(markers.events.length, 256);
    assert.equal(markers.event_bound, true);
    assert.equal(markers.events.filter(event => event.pid === signal.pid).length, 1);
    assert.equal(markers.events.find(event => event.pid === signal.pid).case, 'revision-warm-7-verify');
    assert.equal(markers.events.slice(-64).some(event => event.pid === signal.pid), false);
    assert.deepEqual(Object.keys(markers.events.find(event => event.pid === signal.pid)), ['case', 'event', 'pid', 'at']);
    assert.equal(JSON.stringify(markers).includes('SECRET'), false);
});

test('both startup variants keep fine checkpoints in memory and the ordinary sparse write pattern', () => {
    const source = readFileSync(new URL('../Integration/otp-fpm-full-stack.mjs', import.meta.url), 'utf8');
    const start = source.indexOf('function checkpoint(');
    const end = source.indexOf("\ncheckpoint('controller_start');", start);
    assert.ok(start >= 0 && end > start);
    for (const arm of ['a1', 'b1']) {
        const writes = [];
        const clock = { now: () => 20 };
        const controller = runInNewContext(`let checkpointStarted=0,checkpointFailed=false,lastCheckpoint=null,measuring=false;
            ${source.slice(start, end)}
            ({checkpoint,state:()=>({lastCheckpoint,checkpointFailed}),measure:value=>{measuring=value;}})`, {
            sanitizeCheckpoint, performance: clock, process: { env: { PINOVA_FPM_REVISION_ARM: arm } },
            probeCase: { id: 'startup-jit' }, startupProof: true, root: '/fixture',
            path: { join: (...parts) => parts.join('/') },
            writeFileSync: () => writes.push('write'), renameSync: () => writes.push('rename'),
        });
        controller.checkpoint('revision_warmup');
        assert.equal(writes.length, 2);
        controller.checkpoint('warm_verify');
        assert.equal(writes.length, 2);
        assert.equal(controller.state().lastCheckpoint.phase, 'warm_verify');
        controller.measure(true);
        controller.checkpoint('revision_measurement');
        assert.equal(writes.length, 2);
        assert.equal(controller.state().lastCheckpoint.phase, 'revision_measurement');
    }
});


test('native warmup classifier accepts only pre-teardown evidence from the warmup failure phase', () => {
    const source = readFileSync(new URL('../Integration/otp-fpm-revision-campaign.mjs', import.meta.url), 'utf8');
    const start = source.indexOf('const matchedSignal11 = row => {');
    const end = source.indexOf('        const first = campaign.arms[0];', start);
    assert.ok(start >= 0 && end > start && end - start < 2000);
    assert.equal(source.indexOf('const matchedSignal11 = row => {', start + 1), -1);
    const classify = runInNewContext(`(() => {${source.slice(start, end)}; return matchedSignal11;})()`);
    const row = { failure_phase: 'revision_warmup', diagnostic_evidence: {
        collection_boundary: 'controller_before_fpm_teardown',
        fpm_termination: { read_success: true, events: [{
            event: 'signal_exit', signal_number: 11, pool: 'fixture', pid: 777,
        }] },
        markers: { read_success: true, events: [{
            event: 'request_start', case: 'revision-warm-7-verify', pid: 777, at: 1793459146,
        }] },
    } };
    assert.equal(classify(row), true);
    assert.equal(classify({ ...row, diagnostic_evidence: { ...row.diagnostic_evidence,
        collection_boundary: 'after_fixture_exit_or_timeout', termination_causation_unverified: true,
    } }), false);
    assert.equal(classify({ ...row, diagnostic_evidence: { ...row.diagnostic_evidence,
        collection_boundary: undefined,
    } }), false);
    for (const failure_phase of ['fixture_execution', 'arm_cleanup', 'readiness', null]) {
        assert.equal(classify({ ...row, failure_phase }), false);
    }
});
