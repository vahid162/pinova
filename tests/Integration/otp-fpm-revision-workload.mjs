/** One predeclared revision arm; shares the genuine isolated FPM chain. */
export async function revisionWorkload({ ensure, root, writeFileSync, issue, ordinary, until, records, quiescent,
    runWorker, inspect, verifyFlow, beginMeasure, endMeasure, http, base, site, receipt, headroom, events, processSample, setPhase }) {
    receipt.revision_arm = { arm: process.env.PINOVA_FPM_REVISION_ARM, source_commit: process.env.PINOVA_FPM_REVISION_SOURCE,
        logging_profile: process.env.PINOVA_FPM_LOGGING_PROFILE, php_minor: process.env.PINOVA_FPM_PHP_VERSION,
        immediate_delivery: true, ordinary_count: 0, completed_stub_flows: 0, resources: null };
    setPhase('revision_warmup');
    writeFileSync(`${root}/mode`, 'on\n');
    await headroom();
    // Identical completed warmup and empty retained CLI opportunities in both
    // revisions. Synthetic credentials never leave this process.
    const warmFlows = [];
    for (let index = 0; index < 10; index++) {
        const flow = await issue(41 + index, `revision-warm-${index + 1}`);
        ensure((await ordinary(index, 'revision-warm-page')).status === 200, 'Revision warmup page failed.');
        await runWorker(`revision-warm-worker-${index + 1}`);
        await until(() => records.some(record => record.case === flow.label && record.accepted !== null), 'Revision warmup provider acceptance missing.');
        await quiescent();
        warmFlows.push(flow);
    }
    await Promise.all(warmFlows.map(flow => verifyFlow(flow)));
    await quiescent();
    const pool = new Set(processSample().map(worker => worker.pid));
    const warmed = new Set(events().filter(event => event.event === 'request_start' &&
        /^revision-warm-[0-9]+-verify$/.test(event.case) && pool.has(event.pid)).map(event => event.pid));
    ensure(warmed.size === receipt.limits.fpm_children, 'Revision authentication warmup did not cover the full fixed request pool.');
    for (let round = 0; round < 2; round++) {
        const pages = await Promise.all(Array.from({ length: 10 }, (_, index) => ordinary(index, 'revision-warm-settle')));
        ensure(pages.every(page => page.status === 200), 'Revision post-authentication stabilization failed.');
        await quiescent();
    }
    const before = await site({ action: 'stats' });
    const logging = before.logging;
    const diagnostic = process.env.PINOVA_FPM_LOGGING_PROFILE === 'diagnostic';
    ensure(logging?.minimum_level === 'warning' && logging.warning_enabled === true &&
        logging.diagnostic_window_active === diagnostic, 'Revision logging profile is not active as declared.');
    if (diagnostic) ensure(logging.diagnostic_mode === 'legacy_global' ? logging.debug_enabled === true :
        logging.diagnostic_mode === 'scoped_queued_otp' && logging.scope_matches && logging.budget_initialized && logging.debug_enabled === false,
    'Revision diagnostic scope does not match its declared legacy/scoped contract.');
    const initial = JSON.parse((await http(`${base}/fpm-status?json&full`)).text);
    setPhase('revision_measurement');
    beginMeasure(initial);
    const pageTimes = [];
    receipt.revision_arm.ordinary_samples_ms = pageTimes;
    let completed = 0;
    for (let chunk = 0; chunk < 10; chunk++) {
        const flow = await issue(chunk + 1, `revision-normal-${chunk + 1}`);
        for (let pageIndex = 0; pageIndex < 10; pageIndex++) {
            const page = await ordinary(chunk * 10 + pageIndex, 'revision-normal-page');
            ensure(page.status === 200, 'Revision ordinary page failed.');
            pageTimes.push(page.ms);
            receipt.revision_arm.ordinary_count = pageTimes.length;
        }
        await runWorker(`revision-normal-worker-${chunk + 1}`);
        await until(() => records.some(record => record.case === flow.label && record.accepted !== null), 'Revision provider acceptance missing.');
        await quiescent();
        const state = await inspect(flow);
        ensure(state.rows === 1 && state.purpose === 'login' && state.otp_expiry === state.expiry &&
            state.accepted_channels.length === 1 && state.accepted_channels[0] === 'sms' &&
            records.filter(record => record.case === flow.label).length === 1,
        'Revision delivery changed purpose, deadline or provider cardinality.');
        await verifyFlow(flow);
        completed++;
        receipt.revision_arm.completed_stub_flows = completed;
    }
    const resources = await endMeasure();
    const after = await site({ action: 'stats' });
    ensure(resources.measured_runner_invocations === 10 && completed === 10 && pageTimes.length === 100,
        'Revision arm did not complete its predeclared workload.');
    if (diagnostic && logging.diagnostic_mode === 'scoped_queued_otp') ensure(after.diagnostic_rows > before.diagnostic_rows,
        'Scoped diagnostic arm emitted no observed stage detail.');
    receipt.revision_arm = {
        arm: process.env.PINOVA_FPM_REVISION_ARM,
        source_commit: process.env.PINOVA_FPM_REVISION_SOURCE,
        logging_profile: process.env.PINOVA_FPM_LOGGING_PROFILE,
        diagnostic_mode: logging.diagnostic_mode,
        php_minor: process.env.PINOVA_FPM_PHP_VERSION,
        immediate_delivery: true,
        warmup_verification_workers: warmed.size,
        ordinary_count: pageTimes.length,
        completed_stub_flows: completed,
        ordinary_samples_ms: pageTimes,
        ordinary_p95_ms: [...pageTimes].sort((a, b) => a - b)[94],
        resources,
        log_storage_delta: { rows: after.log_storage.rows - before.log_storage.rows,
            context_bytes: after.log_storage.context_bytes - before.log_storage.context_bytes },
        limits: ['normalized_dependency_inventory_not_original_rc_package', 'legacy_global_debug_differs_from_scoped_candidate_detail',
            'PHP_cost_only_not_database_or_whole_host', 'sampled_aggregate_PSS_not_allocator_peak', 'no_physical_sms_receipt'],
    };
}
