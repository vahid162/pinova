import assert from 'node:assert/strict';
import test from 'node:test';
import { FpmSettingsObservation, interpretEngineComparison } from '../Integration/fpm-settings-observation.mjs';
import { readFileSync } from 'node:fs';
const expected = JSON.parse(readFileSync(new URL('../fixtures/engine-comparison.json', import.meta.url))).fpm_ini_expected_literals;
function headers(pid, overrides = {}) {
    const row = { pid, php_version: '8.4.27', sapi: 'fpm-fcgi', nts: true, debug: false, zend_only_opcache: true, engine_ini: true,
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
    ]) assert.equal(interpretEngineComparison({ comparable: true, all_arms_classified: false, ...observations }),
        'inconclusive_unrelated_or_incomplete_workload');
});
test('seam interpretation requires comparable identities and every arm classified', () => {
    const ready = { comparable: true, all_arms_classified: true,
        A_signal11_arms: ['a1'], B_signal11_arms: [], completed_B: 3 };
    assert.equal(interpretEngineComparison(ready), 'gh24577_patch_associated_engine_mechanism_unproven');
    assert.equal(interpretEngineComparison({ ...ready, B_signal11_arms: ['b1'], completed_B: 2 }),
        'gh24577_patch_did_not_eliminate_observed_signal11');
    assert.equal(interpretEngineComparison({ ...ready, comparable: false }),
        'inconclusive_unrelated_or_incomplete_workload');
    assert.equal(interpretEngineComparison({ ...ready, A_signal11_arms: [] }),
        'inconclusive_custom_control_did_not_reproduce');
});

test('wrong NTS/debug/INI/Zend-extension facts cannot satisfy engine parity', () => {
    for (const fields of [{ nts: false }, { debug: true }, { engine_ini: false }, { zend_only_opcache: false }]) {
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
