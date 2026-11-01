import assert from 'node:assert/strict';
import test from 'node:test';
import { cleanupFailureEvidence, cleanupMarker } from '../browser/cli-failure-evidence.mjs';

test('child exit failure exposes only finite metadata and the last owned step', () => {
    const secret = 'SECRET-command-password-cookie-error-trace';
    const evidence = cleanupFailureEvidence({ status: 1, signal: null, code: 1,
        stdout: secret + '\nPINOVA_BROWSER_CLEANUP_STEP primary_user_lookup\nPINOVA_BROWSER_CLEANUP_STEP primary_otp_delete\n',
        stderr: secret, message: secret, stack: secret, command: secret, cause: new Error(secret) });
    assert.equal(evidence.classification, 'nonzero_exit');
    assert.equal(evidence.exit_status, 1);
    assert.equal(evidence.signal, null);
    assert.equal(evidence.error_code, 'unknown');
    assert.equal(evidence.last_step, 'primary_otp_delete');
    assert.equal(evidence.stderr_bytes, Buffer.byteLength(secret));
    assert.equal(JSON.stringify(evidence).includes(secret), false);
    assert.deepEqual(Object.keys(evidence), ['classification', 'exit_status', 'signal', 'error_code',
        'stdout_bytes', 'stderr_bytes', 'stdout_tail_truncated', 'last_step']);
});

test('timeouts, output bounds, spawn errors and signals have distinct fixed classes', () => {
    assert.equal(cleanupFailureEvidence({ code: 'ETIMEDOUT', signal: 'SIGTERM' }).classification, 'timeout');
    for (const code of ['ENOBUFS', 'ERR_CHILD_PROCESS_STDIO_MAXBUFFER']) {
        assert.equal(cleanupFailureEvidence({ code }).classification, 'output_limit');
    }
    for (const code of ['ENOENT', 'EACCES']) assert.equal(cleanupFailureEvidence({ code }).classification, 'spawn_failure');
    const signaled = cleanupFailureEvidence({ signal: 'SIGSEGV' });
    assert.equal(signaled.classification, 'signal_exit');
    assert.equal(signaled.signal, 'SIGSEGV');
    assert.equal(signaled.exit_status, null);
});

test('invalid status and arbitrary labels never become public evidence', () => {
    for (const status of [-1, 256, NaN, Infinity, '1', true]) {
        const evidence = cleanupFailureEvidence({ status, signal: 'SECRET-SIGNAL', code: 'SECRET-CODE' });
        assert.equal(evidence.exit_status, null);
        assert.equal(evidence.signal, 'unknown');
        assert.equal(evidence.error_code, 'unknown');
        assert.equal(evidence.classification, 'unknown');
        assert.equal(JSON.stringify(evidence).includes('SECRET'), false);
    }
    assert.equal(cleanupFailureEvidence({ status: 0 }).classification, 'unknown');
    assert.equal(cleanupFailureEvidence(null).last_step, 'unknown');
});

test('only exact stdout step lines are accepted and later invalid labels remain unknown', () => {
    const valid = 'PINOVA_BROWSER_CLEANUP_STEP signup_queue_delete';
    for (const stdout of ['prefix ' + valid + '\n', valid + ' SECRET\n', valid.toLowerCase() + '\n']) {
        assert.equal(cleanupFailureEvidence({ stdout }).last_step, 'unknown');
    }
    assert.equal(cleanupFailureEvidence({ stderr: valid + '\n' }).last_step, 'unknown');
    assert.equal(cleanupFailureEvidence({ stdout: valid + '\r\n' }).last_step, 'signup_queue_delete');
    assert.equal(cleanupFailureEvidence({ stdout: valid + '\nPINOVA_BROWSER_CLEANUP_STEP SECRET\n' }).last_step, 'unknown');
});

test('captured Buffer output and its tail are bounded without exporting raw text', () => {
    const marker = 'PINOVA_BROWSER_CLEANUP_STEP cleanup_complete\n';
    const stdout = Buffer.from('SECRET'.repeat(12000) + '\n' + marker);
    const evidence = cleanupFailureEvidence({ stdout, stderr: Buffer.from('SECRET') });
    assert.equal(evidence.stdout_tail_truncated, true);
    assert.equal(evidence.stdout_bytes, stdout.length);
    assert.equal(evidence.last_step, 'cleanup_complete');
    assert.equal(JSON.stringify(evidence).includes('SECRET'), false);
    // A marker-looking suffix of a cut first line cannot masquerade as a complete line.
    const cut = 'x' + marker + 'x'.repeat(65536 - marker.length);
    assert.equal(cleanupFailureEvidence({ stdout: cut }).last_step, 'unknown');
});

test('cleanup marker generation is fixed, flushes its own stream, and rejects injection', () => {
    assert.equal(cleanupMarker('primary_otp_delete'),
        'fwrite(STDOUT, "\\nPINOVA_BROWSER_CLEANUP_STEP primary_otp_delete\\n"); fflush(STDOUT);');
    assert.throws(() => cleanupMarker("primary_otp_delete'); SECRET"), /Unknown integration cleanup step/);
    assert.throws(() => cleanupMarker('SECRET'), /Unknown integration cleanup step/);
});
