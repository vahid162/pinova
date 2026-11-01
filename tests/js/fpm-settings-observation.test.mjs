import assert from 'node:assert/strict';
import test from 'node:test';
import { FpmSettingsObservation, interpretVerificationSeam } from '../Integration/fpm-settings-observation.mjs';
import { readFileSync } from 'node:fs';
const expected = JSON.parse(readFileSync(new URL('../fixtures/verification-seam-bisection.json', import.meta.url))).fpm_ini_expected_literals;
function headers(pid, overrides = {}) {
    const row = { pid, php_version: '8.4.99', sapi: 'fpm-fcgi',
        ini: Object.fromEntries(Object.entries(expected).map(([key, value]) => [key, { type: 'string', value }])) };
    return { 'x-pinova-fixture-php': '8.4.99', 'x-pinova-fixture-fpm-settings': JSON.stringify({ ...row, ...overrides }) };
}
function ready() {
    const observer = new FpmSettingsObservation(expected);
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
        observer.observe({ 'x-pinova-fixture-php': '8.4.99', 'x-pinova-fixture-fpm-settings': value }, 200, 'revision_warmup');
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
    const incomplete = new FpmSettingsObservation(expected);
    incomplete.observe(headers(100), 200, 'warmup');
    incomplete.freezeBeforeVerification();
    incomplete.observe({}, 502, 'revision_warmup');
    assert.equal(incomplete.receipt([marker(100)]).complete, false);
});
test('an unrelated failure suppresses both A-only and B-signal seam conclusions', () => {
    for (const observations of [
        { A_signal11_arms: ['a1'], B_signal11_arms: [], completed_B: 3 },
        { A_signal11_arms: [], B_signal11_arms: ['b1'], completed_B: 2 },
    ]) assert.equal(interpretVerificationSeam({ comparable: true, all_arms_classified: false, ...observations }),
        'inconclusive_unrelated_or_incomplete_workload');
});
test('seam interpretation requires comparable identities and every arm classified', () => {
    const ready = { comparable: true, all_arms_classified: true,
        A_signal11_arms: ['a1'], B_signal11_arms: [], completed_B: 3 };
    assert.equal(interpretVerificationSeam(ready), 'verification_seam_associated_mechanism_unknown');
    assert.equal(interpretVerificationSeam({ ...ready, B_signal11_arms: ['b1'], completed_B: 2 }),
        'verification_seam_not_necessary_for_observed_signal11');
    assert.equal(interpretVerificationSeam({ ...ready, comparable: false }),
        'inconclusive_unrelated_or_incomplete_workload');
    assert.equal(interpretVerificationSeam({ ...ready, A_signal11_arms: [] }),
        'inconclusive_no_reproducing_control_or_incomplete_workload');
});
