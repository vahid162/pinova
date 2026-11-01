import assert from 'node:assert/strict';
import test from 'node:test';
import { createHash } from 'node:crypto';
import { parseJitMode, modeRequest, fastCgiModeBody, preserveOutcomeAndObserve, MODE_OBSERVATION_TIMEOUT_MS,
    expectedBlacklistContents, CREATOR_RELATIVE, declaredStartupArgv, validatePostworkBinding, classifyStartupArm } from '../Integration/postwork-jit-evidence.mjs';

const hash = value => createHash('sha256').update(value).digest('hex');
const root = '/tmp/pinova-fpm-full-123-1';
const creator = `${root}/${CREATOR_RELATIVE}`;
const example = (variant = 'A') => ({ schema: 'pinova.postwork-creator-blacklist.v1', state: 'observed', sapi: 'fpm-fcgi',
    php_version: '8.4.27', pid: 777, opcache_enabled: true,
    ini: { mode: '1235', buffer_bytes: 268435456, enable_cli: false, optimization_level: 2147401727,
        hot_loop: 64, hot_func: 127, hot_return: 8, hot_side_exit: 8, file_cache_empty: true, preload_empty: true },
    jit: { enabled: true, on: true, kind: 3, capacity_bytes: 268435440, free_bytes: 268400000 },
    blacklist: { variant, file_sha256: hash(expectedBlacklistContents(root, variant, creator)),
        file_bytes: Buffer.byteLength(expectedBlacklistContents(root, variant, creator)),
        configured_path_sha256: hash('owned-blacklist-path'), creator_path_sha256: hash(creator), creator_source_sha256: hash('creator-source'),
        configured_path_matches_owned_file: true, entry_count: variant === 'B' ? 1 : 0, entry_semantics: 'absolute_resolved_creator_prefix' },
    cache: { creator_cached: variant === 'A' } });

test('only empty input or the actual resolved run-owned Creator prefix is allowed', () => {
    assert.equal(expectedBlacklistContents(root, 'A', creator), '');
    assert.equal(expectedBlacklistContents(root, 'B', creator), `${creator}\n`);
    for (const value of ['/production/Creator.php', creator + '*', creator + '\n/other/file', creator + '.suffix']) {
        assert.throws(() => expectedBlacklistContents(root, 'B', value));
    }
    assert.throws(() => expectedBlacklistContents('/tmp/foreign', 'A', creator));
    assert.throws(() => expectedBlacklistContents(root, 'disable', creator));
});

test('both cohorts require active1235 with configured256M and preserve truthful cache observations', () => {
    for (const variant of ['A', 'B']) {
        const value = example(variant); value.private = 'SECRET-PAYLOAD'; value.blacklist.path = '/private/input';
        const result = parseJitMode(JSON.stringify(value), variant);
        assert.equal(result.ini.buffer_bytes, 268435456);
        assert.equal(result.jit.enabled, true); assert.equal(result.jit.on, true); assert.equal(result.jit.kind, 3);
        assert.equal(result.cache.creator_cached, variant === 'A');
        assert.equal(JSON.stringify(result).includes('SECRET'), false);
        assert.equal(JSON.stringify(result).includes('/private'), false);
        value.cache.creator_cached = !value.cache.creator_cached;
        assert.equal(parseJitMode(JSON.stringify(value), variant).cache.creator_cached, value.cache.creator_cached);
    }
});

test('inactive JIT, nonempty cache/preload, wrong prefix identity and missing status are rejected', () => {
    const badChanges = [
        v => { v.ini.mode = 'disable'; }, v => { v.jit.enabled = false; }, v => { v.jit.on = false; },
        v => { v.jit.kind = 5; }, v => { v.jit.capacity_bytes = 0; v.jit.free_bytes = 0; },
        v => { v.ini.buffer_bytes = 67108864; }, v => { v.ini.file_cache_empty = false; }, v => { v.ini.preload_empty = false; },
        v => { v.blacklist.configured_path_matches_owned_file = false; }, v => { v.blacklist.variant = 'B'; },
        v => { v.blacklist.entry_count = 1; }, v => { v.blacklist.file_sha256 = '/private/input'; },
        v => { delete v.cache.creator_cached; }, v => { v.php_version = '8.4.28'; },
    ];
    for (const change of badChanges) {
        const value = example(); change(value);
        assert.throws(() => parseJitMode(JSON.stringify(value), 'A'));
    }
    assert.throws(() => parseJitMode('{"state":"unknown"}', 'A'));
    assert.throws(() => parseJitMode('x'.repeat(4097), 'A'));
});

function frame(type, body) {
    const h = Buffer.alloc(8); h[0] = 1; h[1] = type; h.writeUInt16BE(1, 2); h.writeUInt16BE(body.length, 4);
    return Buffer.concat([h, body]);
}
test('actual framing parser is bounded and accepts only a complete native response', () => {
    const body = JSON.stringify(example());
    const response = Buffer.concat([frame(6, Buffer.from('Content-Type: text/html\r\n\r\n' + body)), frame(3, Buffer.alloc(8))]);
    assert.equal(fastCgiModeBody(response), body);
    assert.equal(fastCgiModeBody(response.subarray(0, response.length - 1)), null);
    assert.throws(() => fastCgiModeBody(Buffer.alloc(32769)));
    const wrong = Buffer.from(response); wrong.writeUInt16BE(2, 2);
    assert.throws(() => fastCgiModeBody(wrong));
    const request = modeRequest(`${root}/startup-jit-mode.php`);
    assert.equal(request.includes(Buffer.from('wp-load')), false);
    assert.equal(request.includes(Buffer.from('PHP_VALUE')), false);
    assert.throws(() => modeRequest('/production/wp-load.php'));
});

function boundExample(variant = 'A') {
    const value = example(variant), primaryHash = hash('frozen-primary');
    const launch = { master_pid: 555, master_start_ticks: 123456, binary_sha256: hash('binary'),
        fpm_configuration_sha256: hash('configuration'), mode_endpoint_sha256: hash('endpoint'),
        proc_cmdline_sha256: hash('title'), concrete_launch_argv_sha256: hash('argv'),
        owned_configuration_in_cmdline: true, flags_recoverable_from_proc_title: false,
        binding: 'pinned_launcher_source_and_binary_not_recovered_flags', declared_mode: '1235', configured_buffer_bytes: 268435456,
        declared_file_cache_empty: true, declared_preload_empty: true, declared_argv: declaredStartupArgv(),
        blacklist: structuredClone(value.blacklist) };
    Object.assign(value, { master_pid: launch.master_pid, master_start_ticks: launch.master_start_ticks,
        binary_sha256: launch.binary_sha256, fpm_configuration_sha256: launch.fpm_configuration_sha256,
        source_sha256: launch.mode_endpoint_sha256, responder_start_ticks: 123457, responder_owned_main_pool: true,
        primary_receipt_sha256: primaryHash, primary_outcome_frozen: true, collection_boundary: 'after_primary_receipt_before_fpm_teardown',
        attests_signaled_worker: false, resource_acceptance: false, shipping_fix: false });
    return { value, options: { variant, primaryReceiptHash: primaryHash, startupLaunch: launch } };
}
test('actual evidence validator binds primary bytes, cold master, binary, config and blacklist content/path/source', () => {
    for (const variant of ['A', 'B']) {
        const { value, options } = boundExample(variant);
        assert.equal(validatePostworkBinding(value, options), true);
    }
    const changes = [
        v => { v.primary_receipt_sha256 = hash('other-primary'); }, v => { v.primary_outcome_frozen = false; },
        v => { v.collection_boundary = 'before_primary'; }, v => { v.master_start_ticks++; }, v => { v.master_pid++; },
        v => { v.binary_sha256 = hash('other-binary'); }, v => { v.fpm_configuration_sha256 = hash('other-config'); },
        v => { v.source_sha256 = hash('other-endpoint'); }, v => { v.responder_owned_main_pool = false; },
        v => { v.blacklist.file_sha256 = hash('other-content'); }, v => { v.blacklist.configured_path_sha256 = hash('other-path'); },
        v => { v.blacklist.creator_source_sha256 = hash('other-source'); }, v => { v.blacklist.creator_path_sha256 = hash('other-creator'); },
        v => { v.attests_signaled_worker = true; }, v => { v.resource_acceptance = true; }, v => { v.shipping_fix = true; },
    ];
    for (const change of changes) {
        const { value, options } = boundExample(); change(value);
        assert.equal(validatePostworkBinding(value, options), false);
    }
    const { value, options } = boundExample(); options.startupLaunch.declared_argv[2] = 'opcache.jit=disable';
    assert.equal(validatePostworkBinding(value, options), false);
});

const counts = { warm_flows_completed: 10, runner_opportunities: 10, verification_workers: 10, provider_attempts_observed: 10 };
const completedArm = () => ({ state: 'passed', cleanup_status: 'complete', failure_phase: null,
    diagnostic: { state: 'completed', ...counts }, diagnostic_evidence: {
        collection_boundary: 'controller_before_fpm_teardown',
        fpm_termination: { read_success: true, tail_truncated: false, event_bound: false, invalid_records: 3, events: [] },
        markers: { read_success: true, tail_truncated: false, event_bound: false, invalid_records: 0,
            events: [{ pid: 777, case: 'revision-warm-4', event: 'request_start' }] },
    } });
test('actual arm classifier rejects a completed response cohort with retained native faults without rewriting its outcome', () => {
    for (const pool of ['fixture', 'fixture_status']) for (const event of [
        { event: 'signal_exit', signal_number: 11 }, { event: 'request_timeout', timeout_seconds: 20 },
        { event: 'normal_exit', exit_code: 1 },
    ]) {
        const row = completedArm(); row.diagnostic_evidence.fpm_termination.events = [{ pid: 777, pool, ...event }];
        const before = JSON.stringify(row); const result = classifyStartupArm(row, counts);
        assert.equal(result.functional_completed, true); assert.equal(result.native_fault_observed, true);
        assert.equal(result.no_retained_native_fault_completion, false); assert.equal(result.classified, false);
        assert.equal(JSON.stringify(row), before);
    }
    const clean = completedArm(); clean.diagnostic_evidence.fpm_termination.events = [{ pid: 777, pool: 'fixture', event: 'normal_exit', exit_code: 0 }];
    const result = classifyStartupArm(clean, counts);
    assert.equal(result.no_retained_native_fault_completion, true); assert.equal(result.native_unparsed_records, 3);
    assert.match(result.limitation, /not_complete_semantic_log_assurance/);
});
test('native read gaps, truncation, bounds, wrong boundaries and malformed records cannot classify functional completion as clean', () => {
    const changes = [
        row => { delete row.diagnostic_evidence; }, row => { row.diagnostic_evidence.collection_boundary = 'after_fixture_exit_or_timeout'; },
        row => { row.diagnostic_evidence.fpm_termination.read_success = false; }, row => { row.diagnostic_evidence.fpm_termination.tail_truncated = true; },
        row => { row.diagnostic_evidence.fpm_termination.event_bound = true; }, row => { row.diagnostic_evidence.fpm_termination.invalid_records = null; },
        row => { row.diagnostic_evidence.fpm_termination.events = [{ pid: 777, pool: 'unknown', event: 'normal_exit', exit_code: 0 }]; },
        row => { row.diagnostic_evidence.fpm_termination.events = [{ pid: 777, pool: 'fixture', event: 'unknown' }]; },
    ];
    for (const change of changes) {
        const row = completedArm(); change(row); const result = classifyStartupArm(row, counts);
        assert.equal(result.functional_completed, true); assert.equal(result.native_tail_complete, false);
        assert.equal(result.no_retained_native_fault_completion, false); assert.equal(result.classified, false);
    }
});
test('matched warmup SIG11 remains distinct from clean completion and requires bounded matching marker evidence', () => {
    const row = completedArm(); row.state = 'failed'; row.failure_phase = 'revision_warmup';
    row.diagnostic_evidence.fpm_termination.events = [{ pid: 777, pool: 'fixture', event: 'signal_exit', signal_number: 11 }];
    const result = classifyStartupArm(row, counts);
    assert.equal(result.functional_completed, false); assert.equal(result.no_retained_native_fault_completion, false);
    assert.equal(result.matched_warmup_signal11, true); assert.equal(result.classified, true);
    for (const change of [
        copy => { copy.diagnostic_evidence.markers.tail_truncated = true; },
        copy => { copy.diagnostic_evidence.markers.event_bound = true; },
        copy => { copy.diagnostic_evidence.markers.invalid_records = 1; },
        copy => { copy.diagnostic_evidence.markers.events[0].pid = 778; },
    ]) {
        const copy = structuredClone(row); change(copy);
        assert.equal(classifyStartupArm(copy, counts).matched_warmup_signal11, false);
    }
});

test('actual collector writes primary outcome before observation and never replaces it on failure', async () => {
    const primary = { state: 'failed', diagnostic_evidence: { collection_boundary: 'controller_before_fpm_teardown' } };
    const before = JSON.stringify(primary); const writes = [];
    const result = await preserveOutcomeAndObserve(primary, (name, value) => writes.push({ name, value: structuredClone(value) }), async () => {
        assert.deepEqual(writes.map(row => row.name), ['full-stack-result.json']);
        throw new Error('SECRET-PAYLOAD');
    });
    assert.equal(JSON.stringify(primary), before);
    assert.equal(result.state, 'unknown'); assert.equal(JSON.stringify(result).includes('SECRET'), false);
    assert.equal(result.collection_boundary, 'after_primary_receipt_before_fpm_teardown');
    assert.equal(result.attests_signaled_worker, false); assert.equal(result.resource_acceptance, false); assert.equal(result.shipping_fix, false);
    assert.equal(result.primary_receipt_sha256, hash(JSON.stringify(primary, null, 2) + '\n'));
    assert.deepEqual(writes.map(row => row.name), ['full-stack-result.json', 'postwork-jit-mode.json']);
});
test('successful post-primary cache observation cannot turn into shipping or resource acceptance', async () => {
    const primary = { state: 'passed', diagnostic_probe: { state: 'completed' } }; const writes = [];
    const result = await preserveOutcomeAndObserve(primary, name => writes.push(name), async () => ({
        ...parseJitMode(JSON.stringify(example('B')), 'B'), shipping_fix: true, resource_acceptance: true,
    }));
    assert.equal(primary.state, 'passed'); assert.equal(result.state, 'observed');
    assert.equal(result.shipping_fix, false); assert.equal(result.resource_acceptance, false);
    assert.deepEqual(writes, ['full-stack-result.json', 'postwork-jit-mode.json']);
});
test('unresponsive observation receives one fixed deadline and abort without losing primary outcome', async () => {
    assert.equal(MODE_OBSERVATION_TIMEOUT_MS, 3000);
    const primary = { state: 'failed' }; let aborted = false;
    const result = await preserveOutcomeAndObserve(primary, () => {}, signal => {
        signal.addEventListener('abort', () => { aborted = true; }, { once: true });
        return new Promise(() => {});
    });
    assert.equal(result.reason, 'mode_observation_timeout'); assert.equal(aborted, true);
    assert.equal(primary.state, 'failed'); assert.equal(result.shipping_fix, false); assert.equal(result.resource_acceptance, false);
});
