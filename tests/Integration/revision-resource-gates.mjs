/** Pure acceptance of all predeclared arms. No resource retries or tuning. */
export function evaluateCampaign(rows, contract, identity) {
    const reasons = new Set();
    const result = { state: 'needs_review', reasons: [], metrics: {}, arm_count: rows.length };
    const positive = value => typeof value === 'number' && Number.isFinite(value) && value > 0;
    const median = values => [...values].sort((a, b) => a - b)[1];
    if (rows.length !== 6 || JSON.stringify(rows.map(row => row.arm)) !== JSON.stringify(contract.order)) reasons.add('arm_order_or_count');
    for (const row of rows) {
        const arm = row.measurement;
        if (row.state !== 'passed' || row.cleanup_status !== 'complete' || !arm || arm.arm !== row.arm ||
            arm.source_commit !== (row.arm.startsWith('a') ? contract.baseline_source : identity.candidate) ||
            row.inventory_sha256 !== identity.inventory || !identity.profile || row.profile_sha256 !== identity.profile || arm.php_minor !== identity.php ||
            arm.logging_profile !== identity.logging || arm.immediate_delivery !== true) reasons.add('arm_identity_or_completion');
        if (!arm || !Number.isSafeInteger(arm.ordinary_count) || arm.ordinary_count < contract.ordinary_responses_per_arm ||
            !Number.isSafeInteger(arm.completed_stub_flows) || arm.completed_stub_flows < contract.completed_stub_flows_per_arm ||
            arm.resources?.measured_runner_invocations !== contract.runner_opportunities_per_arm ||
            arm.resources?.process_coverage?.coverage_complete !== true) reasons.add('workload_or_process_coverage');
        const times = arm?.ordinary_samples_ms;
        if (!Array.isArray(times) || times.length !== arm?.ordinary_count || times.length < contract.ordinary_responses_per_arm ||
            times.some(value => !positive(value)) ||
            Math.abs([...times].sort((a, b) => a - b)[Math.ceil(times.length * 0.95) - 1] - arm.ordinary_p95_ms) > 1e-6) {
            reasons.add('ordinary_latency_samples_incomplete');
        }
    }
    const definitions = {
        total_php_cpu_seconds: arm => arm?.resources?.total_php_cpu_seconds,
        peak_sampled_total_php_pss_kib: arm => arm?.resources?.peak_sampled_total_php_pss_kib,
        ordinary_p95_ms: arm => arm?.ordinary_p95_ms,
    };
    for (const [name, get] of Object.entries(definitions)) {
        const paired = contract.pairs.map(([a, b]) => ({ baseline: get(rows.find(row => row.arm === a)?.measurement),
            candidate: get(rows.find(row => row.arm === b)?.measurement) }));
        if (paired.some(pair => !positive(pair.baseline) || !positive(pair.candidate))) {
            reasons.add('metric_missing_or_invalid'); continue;
        }
        const baseline = paired.map(pair => pair.baseline);
        const ratios = paired.map(pair => pair.candidate / pair.baseline);
        const spread = Math.max(...baseline) / Math.min(...baseline);
        const p95 = name === 'ordinary_p95_ms';
        const margins = paired.map(pair => pair.candidate - pair.baseline * contract.p95_ratio - contract.p95_allowance_ms);
        const accepted = p95 ? median(margins) <= 0 : median(ratios) <= contract.maximum_median_cpu_pss_ratio;
        if (!accepted) reasons.add('median_acceptance_exceeded');
        if (spread > contract.maximum_baseline_spread) reasons.add('baseline_unstable');
        if (ratios.some(ratio => ratio > contract.maximum_individual_pair_ratio)) reasons.add('individual_pair_degradation');
        result.metrics[name] = { pairs: paired, pair_ratios: ratios, median_ratio: median(ratios), baseline_spread: spread,
            ...(p95 ? { paired_allowance_margins_ms: margins, median_allowance_margin_ms: median(margins) } : {}), accepted };
    }
    result.reasons = [...reasons].sort();
    if (!reasons.size) result.state = 'passed';
    return result;
}
