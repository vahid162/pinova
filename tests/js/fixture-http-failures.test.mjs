import assert from 'node:assert/strict';
import test from 'node:test';
import { EventEmitter } from 'node:events';
import { observeHTTPFailures } from '../Integration/fixture-http-failures.mjs';

function harness() {
    const outgoing = new EventEmitter(), incoming = new EventEmitter(), rows = [], rejected = [];
    outgoing.destroy = error => outgoing.emit('error', error);
    const observer = observeHTTPFailures(outgoing, { phase: 'functional_uncertain_quiescence', category: 'fpm_status',
        record: row => rows.push(row), reject: error => rejected.push(error) });
    observer.watchIncoming(incoming);
    return { outgoing, incoming, rows, rejected, observer };
}

test('actual timeout event destroys once with a finite timeout tag', () => {
    const h = harness(); h.outgoing.emit('timeout');
    assert.deepEqual(h.rows, [{ phase: 'functional_uncertain_quiescence', category: 'fpm_status', status: null, failure: 'timeout' }]);
    assert.equal(h.rejected[0].code, 'PINOVA_FIXTURE_TIMEOUT');
});

test('actual body-bound synthetic destroy remains distinct from timeout', () => {
    const h = harness(); h.observer.bound('body_bound');
    assert.equal(h.rows[0].failure, 'body_bound'); assert.equal(h.rejected.length, 1);
});

test('response abortion records one finite result despite subsequent error events', () => {
    const h = harness(); h.incoming.emit('aborted'); h.incoming.emit('error', new Error('SECRET BODY'));
    h.outgoing.emit('error', Object.assign(new Error('SECRET URL'), { code: 'ECONNRESET' }));
    assert.equal(h.rows[0].failure, 'response_aborted'); assert.equal(h.rows.length, 1); assert.equal(h.rejected.length, 1);
    assert.equal(JSON.stringify(h.rows).includes('SECRET'), false);
});

test('request errors retain known codes and exclude arbitrary error details', () => {
    for (const [code, expected] of [['ECONNREFUSED','connection_refused'], ['ECONNRESET','connection_reset'],
        ['ETIMEDOUT','timeout'], ['SECRET-CODE','transport_failure'], ['constructor','transport_failure']]) {
        const h = harness(); h.outgoing.emit('error', Object.assign(new Error('SECRET PAYLOAD'), { code }));
        assert.equal(h.rows[0].failure, expected); assert.equal(JSON.stringify(h.rows).includes('SECRET'), false);
    }
});

test('unclassified response-stream error has its own finite tag', () => {
    const h = harness(); h.incoming.emit('error', new Error('SECRET HEADER'));
    assert.equal(h.rows[0].failure, 'response_error'); assert.equal(h.rejected.length, 1);
    assert.equal(JSON.stringify(h.rows).includes('SECRET'), false);
});


test('actual request and response handlers retain finite pipe, stream and header parser failures', () => {
    for (const [code, expected] of [['EPIPE','broken_pipe'], ['ERR_STREAM_PREMATURE_CLOSE','premature_close'],
        ['HPE_HEADER_OVERFLOW','header_limit'], ['HPE_INVALID_HEADER_TOKEN','invalid_header']]) {
        for (const stage of ['outgoing', 'incoming']) {
            const h = harness();
            h[stage].emit('error', Object.assign(new Error('SECRET RAW HEADER'), { code }));
            assert.deepEqual(h.rows, [{ phase: 'functional_uncertain_quiescence', category: 'fpm_status', status: null, failure: expected }]);
            assert.equal(h.rejected.length, 1);
            assert.equal(JSON.stringify(h.rows).includes('SECRET'), false);
        }
    }
});


test('known timeout and body bound win when destroy emits response abort before request error', () => {
    for (const reason of ['timeout', 'body_bound']) {
        const h = harness();
        h.outgoing.destroy = error => {
            assert.equal(h.rows.length, 1); assert.equal(h.rejected.length, 1);
            assert.equal(h.rows[0].failure, reason);
            h.incoming.emit('aborted');
            h.incoming.emit('error', Object.assign(new Error('SECRET'), { code: 'ECONNRESET' }));
            h.outgoing.emit('error', error);
        };
        if (reason === 'timeout') h.outgoing.emit('timeout');
        else h.observer.bound('body_bound');
        assert.equal(h.rows.length, 1); assert.equal(h.rejected.length, 1);
        assert.equal(h.rows[0].failure, reason);
        assert.equal(h.rejected[0].code, reason === 'timeout' ? 'PINOVA_FIXTURE_TIMEOUT' : 'PINOVA_FIXTURE_BODY_BOUND');
    }
});
