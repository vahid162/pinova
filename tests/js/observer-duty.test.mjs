import assert from 'node:assert/strict';
import test from 'node:test';
import { PHPProcessSampler } from '../Integration/php-process-sampler.mjs';
import { createObserverDuty, calibrationReceipt, observerSampleCadence } from '../Integration/observer-duty.mjs';

test('nested sample and identity timings count union occupancy once and retain fence calls', () => {
    let t = 0; const m = createObserverDuty({ enabled: true, clock: () => t }); m.beginWindow('off_normal');
    m.measure('cli_poll', () => { t += 2; m.measure('sample', () => { t += 3; m.measure('identity', () => { t += 4; }); }); t += 1; });
    t += 30; m.measure('sample', () => { t += 5; }); t = 100;
    const v = m.endWindow(true); assert.equal(v.occupied_ms, 15); assert.equal(v.duty_fraction, .15);
    assert.equal(v.kinds.sample.calls, 2); assert.equal(v.kinds.identity.calls, 1); assert.equal(v.state, 'observed');
});

test('callback throw is timed through finally and original error propagates', () => {
    let t = 0; const m = createObserverDuty({ enabled: true, clock: () => t }); m.beginWindow('off_idle');
    const e = new Error('controlled'); assert.throws(() => m.measure('sample', () => { t = 7; throw e; }), error => error === e);
    t = 20; assert.equal(m.endWindow(false).occupied_ms, 7);
});

test('asynchronous wait is excluded while callback work is counted', async () => {
    let t = 0; const m = createObserverDuty({ enabled: true, clock: () => t }); m.beginWindow('on_idle');
    let finish; const pending = m.measure('status_setup', () => { t = 2; return new Promise(resolve => { finish = resolve; }); });
    t = 92; m.measure('status_callback', () => { t = 95; finish(); }); await pending; t = 100;
    assert.equal(m.endWindow(true).occupied_ms, 5);
});

test('backward clock or call bound yields unknown without skipping operations', () => {
    for (const reversed of [false,true]) {
        let t = 0, calls = 0; const m = createObserverDuty({ enabled: true, clock: () => t, maxCalls: 1 }); m.beginWindow('on_normal');
        m.measure('sample', () => { calls++; t = 5; }); m.measure('sample', () => { calls++; t = reversed ? 4 : 8; }); t = 10;
        const v = m.endWindow(true); assert.equal(v.state, 'unknown'); assert.equal(v.target_met, false); assert.equal(v.duty_fraction, null); assert.equal(calls, 2);
    }
});

test('disabled meter preserves return, exception and zero observation calls', () => {
    const m = createObserverDuty({ clock: () => { throw new Error('must not sample'); } });
    assert.equal(m.measure('sample', () => 42), 42); assert.throws(() => m.measure('sample', () => { throw new Error('same'); }), /same/);
    assert.deepEqual(m.snapshot().windows, []);
});


test('every calibration receipt remains nonacceptance without mutating failure or frozen gates', () => {
    for (const value of [{ state: 'failed' }, { idle: { ordinary_p95_ms: 123, total_php_cpu_seconds: 4 } }]) {
        const before = JSON.stringify(value); const result = calibrationReceipt(value, true);
        assert.equal(result.resource_acceptance, false); assert.equal(JSON.stringify(value), before);
        assert.equal(result.state, value.state); assert.deepEqual(result.idle, value.idle);
        assert.equal(calibrationReceipt(value, false), value);
    }
});


function syntheticSampler() {
    let t = 0;
    const meter = createObserverDuty({ enabled: true, clock: () => t }); meter.beginWindow('off_normal');
    const sampler = new PHPProcessSampler({ master: 2, ticks: 100, measure: meter.measure,
        clock: () => t / 1000, children: () => [3], inspect: pid => {
            t += 1; return { pid, start: pid * 100, cpu: t / 1000, rss: 100, pss: 50, pool: pid === 3 ? 'fixture' : 'unknown' };
        } });
    return { meter, sampler, at: value => { t = value; } };
}

test('actual sampler injected process lifecycle retains initial, CLI start/final and final samples', () => {
    const { meter, sampler, at } = syntheticSampler();
    sampler.sample(); at(100); assert.equal(sampler.registerCli(4), true);
    at(200); sampler.sample(); at(300); sampler.finalCli(4); sampler.closeCli(4); at(400); sampler.sample();
    const result = sampler.summary(); at(500); const duty = meter.endWindow(true);
    assert.equal(result.coverage_complete, true); assert.deepEqual(result.coverage_reasons, []);
    assert.equal(result.process_samples, 4); assert.equal(duty.kinds.sample.calls, 4);
    assert.deepEqual(result.initial_fpm_inventory, [{ pid: 2, start: 200, pool: 'master' }, { pid: 3, start: 300, pool: 'fixture' }]);
    assert.equal(result.maximum_sample_start_gap_ms, 200);
});

test('actual sampler retains the 500ms continuity boundary for every sample source', () => {
    for (const [gap, expected] of [[500, true], [501, false]]) {
        const { sampler, at } = syntheticSampler(); sampler.sample(); at(gap); sampler.sample();
        const result = sampler.summary(); assert.equal(result.maximum_sample_start_gap_ms, gap);
        assert.equal(result.coverage_complete, expected);
        assert.equal(result.coverage_reasons.includes('process_sampling_discontinuity'), !expected);
    }
});


test('shared cadence value used by scheduling and receipt metadata preserves default40 and fixed proof arms', () => {
    assert.equal(observerSampleCadence(false, '200'), 40);
    for (const value of ['40', '200']) assert.equal(observerSampleCadence(true, value), Number(value));
    for (const value of [undefined, '125', '0', 'NaN']) assert.throws(() => observerSampleCadence(true, value));
});
