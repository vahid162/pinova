import assert from 'node:assert/strict';
import test from 'node:test';
import { FpmSettingsObservation, interpretJitModeComparison, requestMatchedSignal11, completedWarmup } from '../Integration/fpm-settings-observation.mjs';
import { readFileSync } from 'node:fs';
const expected = JSON.parse(readFileSync(new URL('../fixtures/jit-mode-comparison.json', import.meta.url))).fpm_ini_expected_literals;
function headers(pid, overrides = {}) {
    const row = { pid, php_version: '8.4.27', sapi: 'fpm-fcgi', nts: true, debug: false, zend_only_opcache: true, stock_ini: true,
        jit: { enabled:true,on:true,kind:3,opt_level:5,opt_flags:6,buffer_size:268435440,buffer_free:260000000 },
        ini: Object.fromEntries(Object.entries(expected).map(([key, value]) => [key, { type: 'string', value }])) };
    return { 'x-pinova-fixture-php': '8.4.27', 'x-pinova-fixture-fpm-settings': JSON.stringify({ ...row, ...overrides }) };
}
function ready(executableWitness = () => true) {
    const observer = new FpmSettingsObservation(expected, executableWitness);
    for (let pid = 100; pid < 110; pid++) observer.observe(headers(pid), 200, 'warmup');
    observer.freezeBeforeVerification();
    return observer;
}
const marker = pid => ({ event: 'request_start', case: 'revision-warm-1-verify', pid });
test('502 without FPM metadata retains the completed pre-verification configuration fence', () => {
    const observer = ready();
    observer.observe({}, 502, 'revision_warmup');
    const receipt = observer.receipt([marker(100)]);
    assert.equal(receipt.complete, true);
    assert.equal(receipt.pre_verification_fence.observations.length, 10);
    assert.equal(receipt.non_php_responses[0].configuration, 'not_observed');
    assert.equal(receipt.unobserved_replacement_configuration, 'unknown');
});
test('selected FPM response with missing or malformed settings invalidates parity', () => {
    for (const value of [undefined, '{invalid']) {
        const observer = ready();
        observer.observe({ 'x-pinova-fixture-php': '8.4.27', 'x-pinova-fixture-fpm-settings': value }, 200, 'revision_warmup');
        assert.equal(observer.receipt([marker(100)]).complete, false);
        assert.equal(observer.receipt().metadata_unknown, true);
    }
});
test('unobserved new request worker cannot borrow the earlier pool settings', () => {
    const observer = ready();
    observer.observe({}, 502, 'revision_warmup');
    assert.deepEqual(observer.receipt([marker(200)]).unknown_request_workers, [200]);
    assert.equal(observer.receipt([marker(200)]).complete, false);
});
test('a newly observed replacement uses only its own known settings', () => {
    const observer = ready();
    observer.observe(headers(200), 200, 'revision_warmup');
    const receipt = observer.receipt([marker(200)]);
    assert.equal(receipt.complete, true);
    assert.deepEqual(receipt.new_request_workers, [200]);
    assert.equal(receipt.pre_verification_fence.observations.length, 10);
});
test('unknown actual values and incomplete earlier coverage stay unknown', () => {
    const observer = ready();
    const ini = Object.fromEntries(Object.entries(expected).map(([key, value]) => [key, { type: 'string', value }]));
    ini['opcache.jit'].value = null;
    observer.observe(headers(200, { ini }), 200, 'revision_warmup');
    assert.equal(observer.receipt([marker(200)]).complete, false);
    const incomplete = new FpmSettingsObservation(expected, () => true);
    incomplete.observe(headers(100), 200, 'warmup');
    incomplete.freezeBeforeVerification();
    incomplete.observe({}, 502, 'revision_warmup');
    assert.equal(incomplete.receipt([marker(100)]).complete, false);
});
test('an unrelated failure suppresses both A-only and B-signal seam conclusions', () => {
    for (const observations of [
        { A_signal11_arms: ['a1'], B_signal11_arms: [], completed_B: 3 },
        { A_signal11_arms: [], B_signal11_arms: ['b1'], completed_B: 2 },
    ]) assert.equal(interpretJitModeComparison({ comparable: true, all_arms_classified: false, ...observations }),
        'inconclusive_unrelated_or_incomplete_workload');
});
test('seam interpretation requires comparable identities and every arm classified', () => {
    const ready = { comparable: true, all_arms_classified: true,
        A_signal11_arms: ['a1'], B_signal11_arms: [], completed_B: 3 };
    assert.equal(interpretJitModeComparison(ready), 'mode_associated_stability_mechanism_unproven');
    assert.equal(interpretJitModeComparison({ ...ready, B_signal11_arms: ['b1'], completed_B: 2 }),
        'tracing_insufficient_for_this_workload_mechanism_unknown');
    assert.equal(interpretJitModeComparison({ ...ready, comparable: false }),
        'inconclusive_unrelated_or_incomplete_workload');
    assert.equal(interpretJitModeComparison({ ...ready, A_signal11_arms: [] }),
        'inconclusive_control_did_not_reproduce');
});

test('wrong NTS/debug/INI/Zend-extension facts cannot satisfy engine parity', () => {
    for (const fields of [{ nts: false }, { debug: true }, { stock_ini: false }, { zend_only_opcache: false }]) {
        const observer = ready();
        observer.observe(headers(200, fields), 200, 'revision_warmup');
        assert.equal(observer.receipt([marker(200)]).complete, false);
    }
});

test('missing, unreadable or wrong executable witnesses invalidate the initial fence', () => {
    for (const witness of [null, () => undefined, () => false, () => { throw new Error('unreadable_file_link'); }]) {
        const observer = ready(witness);
        const receipt = observer.receipt([marker(100)]);
        assert.equal(receipt.pre_verification_fence.complete, false);
        assert.equal(receipt.complete, false);
        assert.equal(receipt.observations[0].selected_engine_executable, false);
        assert.equal(JSON.stringify(receipt).includes('/proc/'), false);
    }
});

test('a late request worker needs its own positive executable witness', () => {
    for (const witness of [() => undefined, () => false, () => { throw new Error('unreadable_file_link'); }]) {
        const observer = ready(pid => pid < 200 ? true : witness(pid));
        observer.observe(headers(200), 200, 'revision_warmup');
        const receipt = observer.receipt([marker(200)]);
        assert.equal(receipt.pre_verification_fence.complete, true);
        assert.equal(receipt.complete, false);
        assert.deepEqual(receipt.unknown_request_workers, [200]);
        assert.equal(receipt.observations.find(row => row.pid === 200).selected_engine_executable, false);
    }
});

test('each response checks its own PID before a fence or replacement can be accepted', () => {
    const seen = [];
    const observer = ready(pid => { seen.push(pid); return true; });
    assert.deepEqual(seen, Array.from({ length: 10 }, (_, i) => 100 + i));
    observer.observe(headers(200), 200, 'revision_warmup');
    assert.equal(seen.at(-1), 200);
    assert.equal(observer.receipt([marker(200)]).complete, true);
    assert.equal(observer.receipt().observations.every(row => row.selected_engine_executable === true), true);
});

test('a later failed witness cannot borrow the same worker earlier executable identity', () => {
    let readable = true;
    const observer = ready(() => readable);
    readable = false;
    observer.observe(headers(100), 200, 'revision_warmup');
    assert.equal(observer.receipt([marker(100)]).complete, false);
    assert.equal(observer.receipt().metadata_unknown, true);
});

test('tracing1255 requires kind5 and all seven active status fields', () => {
    const mode = {...expected,'opcache.jit':'1255'};
    const observer = new FpmSettingsObservation(mode, () => true);
    for (let pid=100;pid<110;pid++) {
        const raw=JSON.parse(headers(pid)['x-pinova-fixture-fpm-settings']);
        raw.ini['opcache.jit'].value='1255'; raw.jit.kind=5;
        observer.observe({'x-pinova-fixture-php':'8.4.27','x-pinova-fixture-fpm-settings':JSON.stringify(raw)},200,'warmup');
    }
    observer.freezeBeforeVerification();assert.equal(observer.receipt([marker(100)]).complete,true);
    for (const field of ['enabled','on','kind','opt_level','opt_flags','buffer_size','buffer_free']) {
        const bad=ready();const raw=JSON.parse(headers(100)['x-pinova-fixture-fpm-settings']);raw.jit[field]=null;
        bad.observe({'x-pinova-fixture-php':'8.4.27','x-pinova-fixture-fpm-settings':JSON.stringify(raw)},200,'revision_warmup');
        assert.equal(bad.receipt([marker(100)]).complete,false);
    }
});
test('ordinary JIT buffer consumption is accepted but exhaustion remains latched unknown', () => {
    const observer=ready();const raw=JSON.parse(headers(100)['x-pinova-fixture-fpm-settings']);raw.jit.buffer_free-=100;
    const send=() => observer.observe({'x-pinova-fixture-php':'8.4.27','x-pinova-fixture-fpm-settings':JSON.stringify(raw)},200,'revision_warmup');
    send();assert.equal(observer.receipt([marker(100)]).complete,true);
    raw.jit.buffer_free=0;send();assert.equal(observer.receipt([marker(100)]).complete,false);
    raw.jit.buffer_free=250000000;send();assert.equal(observer.receipt([marker(100)]).complete,false);
});
const native = (phase='warm_issue') => ({failure_phase:'revision_warmup',diagnostic_evidence:{checkpoint:{phase,flow_index:3},
    collection_boundary:'controller_before_fpm_teardown',fpm_termination:{read_success:true,events:[{event:'signal_exit',signal_number:11,pool:'fixture',pid:123}]},
    markers:{read_success:true,events:[{event:'request_start',pid:123,case:phase==='warm_verify'?'revision-warm-3-verify':'revision-warm-3'}]}}});
test('prospective issuance, acceptance and verification failures require exact request PID and collection boundary', () => {
    for (const phase of ['warm_issue','warm_acceptance','warm_verify']) {
        assert.equal(requestMatchedSignal11(native(phase)),true);
        for (const mutate of [r=>r.diagnostic_evidence.markers.events[0].pid++,r=>r.diagnostic_evidence.markers.events[0].case='revision-warm-4',
            r=>r.diagnostic_evidence.collection_boundary='after_fixture_exit_or_timeout',r=>r.diagnostic_evidence.fpm_termination.read_success=false,
            r=>r.diagnostic_evidence.checkpoint.phase='readiness']) {
            const bad=native(phase);mutate(bad);assert.equal(requestMatchedSignal11(bad),false);
        }
    }
});
test('a reused worker final request must match instead of an earlier matching OTP request', () => {
    for (const phase of ['warm_issue','warm_acceptance','warm_verify']) {
        const bad=native(phase);
        bad.diagnostic_evidence.markers.events.push({event:'request_start',pid:123,case:'warm-page'});
        assert.equal(requestMatchedSignal11(bad),false);
        const good=native(phase);
        good.diagnostic_evidence.markers.events.unshift({event:'request_start',pid:123,case:'warm-page'});
        assert.equal(requestMatchedSignal11(good),true);
    }
});
test('known tracing native failure remains insufficient despite another unknown arm', () => {
    assert.equal(interpretJitModeComparison({known_B_native_failure:true,comparable:false,all_arms_classified:false}),
        'tracing_insufficient_for_this_workload_mechanism_unknown');
    assert.equal(interpretJitModeComparison({comparable:true,all_arms_classified:false,A_signal11_arms:['a1'],B_signal11_arms:[],completed_B:3}),
        'inconclusive_unrelated_or_incomplete_workload');
});
test('ten flows alone cannot stand in for verification/provider worker coverage or success', () => {
    const row={state:'passed',cleanup_status:'complete',diagnostic:{state:'completed',warm_flows_completed:10,runner_opportunities:10,verification_workers:10,provider_attempts_observed:10}};
    assert.equal(completedWarmup(row),true);
    for(const key of ['warm_flows_completed','runner_opportunities','verification_workers','provider_attempts_observed']) {
        const bad=structuredClone(row);bad.diagnostic[key]=null;assert.equal(completedWarmup(bad),false);
    }
    assert.equal(completedWarmup({...row,state:'failed'}),false);
});
