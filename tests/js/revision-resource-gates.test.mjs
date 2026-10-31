import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { evaluateCampaign } from '../Integration/revision-resource-gates.mjs';
const contract = JSON.parse(readFileSync(new URL('../fixtures/diagnostics-campaign.json', import.meta.url)));
const identity = { candidate: 'b'.repeat(40), inventory: 'c'.repeat(64), profile: 'e'.repeat(64), php: '8.3', logging: 'normal' };
function rows() { return contract.order.map(arm => ({ arm, state: 'passed', cleanup_status: 'complete', inventory_sha256: identity.inventory, profile_sha256: identity.profile, measurement: {
    arm, source_commit: arm.startsWith('a') ? contract.baseline_source : identity.candidate,
    php_minor: identity.php, logging_profile: identity.logging, immediate_delivery: true,
    ordinary_count: 100, completed_stub_flows: 10, ordinary_p95_ms: arm.startsWith('a') ? 200 : 220,
    ordinary_samples_ms: Array(100).fill(arm.startsWith('a') ? 200 : 220),
    resources: { total_php_cpu_seconds: arm.startsWith('a') ? 10 : 11, peak_sampled_total_php_pss_kib: arm.startsWith('a') ? 1000 : 1100,
        measured_runner_invocations: 10, process_coverage: { coverage_complete: true } },
} })); }
test('acceptance uses all three declared pairs at exact boundaries', () => {
    assert.equal(evaluateCampaign(rows(), contract, identity).state, 'passed');
});
test('single-pair degradation cannot be hidden by a passing median', () => {
    const data = rows(); data.find(row => row.arm === 'b2').measurement.resources.total_php_cpu_seconds = 12.01;
    const result = evaluateCampaign(data, contract, identity);
    assert.equal(result.state, 'needs_review'); assert.ok(result.reasons.includes('individual_pair_degradation'));
});
test('unstable baselines, missing process coverage and altered order remain needs review', () => {
    const data = rows(); data[0].measurement.resources.total_php_cpu_seconds = 8;
    data[1].measurement.resources.process_coverage.coverage_complete = false;
    [data[2], data[3]] = [data[3], data[2]];
    const reasons = evaluateCampaign(data, contract, identity).reasons;
    assert.ok(reasons.includes('baseline_unstable')); assert.ok(reasons.includes('workload_or_process_coverage'));
    assert.ok(reasons.includes('arm_order_or_count'));
});
test('p95 retains its declared 20ms allowance and median pair test', () => {
    const data = rows();
    for (const row of data.filter(row => row.arm.startsWith('b'))) {
        row.measurement.ordinary_p95_ms = 240; row.measurement.ordinary_samples_ms.fill(240);
    }
    assert.equal(evaluateCampaign(data, contract, identity).state, 'passed');
    data.find(row => row.arm === 'b1').measurement.ordinary_p95_ms = 240.01;
    data.find(row => row.arm === 'b1').measurement.ordinary_samples_ms.fill(240.01);
    data.find(row => row.arm === 'b3').measurement.ordinary_p95_ms = 240.01;
    data.find(row => row.arm === 'b3').measurement.ordinary_samples_ms.fill(240.01);
    assert.ok(evaluateCampaign(data, contract, identity).reasons.includes('median_acceptance_exceeded'));
});
test('failed or missing arms and wrong dependency/source identity cannot pass', () => {
    const data = rows(); data[1].inventory_sha256 = 'd'.repeat(64); data[4].state = 'failed';
    assert.equal(evaluateCampaign(data, contract, identity).state, 'needs_review');
    data.pop(); assert.equal(evaluateCampaign(data, contract, identity).state, 'needs_review');
});
