import assert from 'node:assert/strict';
import test from 'node:test';
import { createHash } from 'node:crypto';
import { expectedNativeFpmProfile, parseJitMode, modeRequest, fastCgiModeBody, preserveOutcomeAndObserve,
    validatePostworkProfile, MODE_OBSERVATION_TIMEOUT_MS } from '../Integration/postwork-jit-evidence.mjs';

const example = (minor = '8.4') => ({ schema: 'pinova.postwork-jit-mode.v1', state: 'observed', sapi: 'fpm-fcgi',
    php_version: minor === '8.3' ? '8.3.36' : '8.4.27', pid: 777, opcache_enabled: true,
    ini: { mode: minor === '8.3' ? 'tracing' : 'disable', buffer_bytes: minor === '8.3' ? 0 : 67108864,
        enable_cli: false, optimization_level: 2147401727, hot_loop: 64, hot_func: 127, hot_return: 8, hot_side_exit: 8 },
    jit: { enabled: false, on: false, kind: minor === '8.3' ? 5 : 3, capacity_bytes: 0, free_bytes: 0 } });
const startup = (minor = '8.4') => ({ php_minor: minor, master_pid: 700, master_start_ticks: 12345,
    binary_sha256: 'a'.repeat(64), mode_endpoint_sha256: 'b'.repeat(64), proc_cmdline_sha256: 'c'.repeat(64),
    concrete_launch_argv_sha256: 'd'.repeat(64), owned_configuration_in_cmdline: true,
    flags_recoverable_from_proc_title: false, binding: 'pinned_launcher_source_and_binary_not_recovered_flags',
    declared_mode: minor === '8.3' ? 'tracing' : 'disable', configured_buffer_bytes: minor === '8.3' ? 0 : 67108864,
    declared_argv: ['FPM', '-d', minor === '8.3' ? 'opcache.jit=tracing' : 'opcache.jit=disable', '-d',
        minor === '8.3' ? 'opcache.jit_buffer_size=0' : 'opcache.jit_buffer_size=64M', '--allow-to-run-as-root', '-F', '-y', 'RUN/fpm.conf'] });
const witnessed = (minor = '8.4') => ({ ...example(minor), master_pid: 700, master_start_ticks: 12345,
    binary_sha256: 'a'.repeat(64), source_sha256: 'b'.repeat(64), responder_start_ticks: 12346,
    responder_owned_main_pool: true, primary_receipt_sha256: 'e'.repeat(64), primary_outcome_frozen: true,
    collection_boundary: 'after_primary_receipt_before_fpm_teardown', attests_signaled_worker: false });

test('selected native-default profiles distinguish configured capacity from inactive allocation and drop raw fields', () => {
    for (const minor of ['8.3', '8.4']) {
        const profile = expectedNativeFpmProfile(minor);
        assert.deepEqual(profile, minor === '8.3' ? { php_minor: '8.3', mode: 'tracing', buffer_ini: '0', buffer_bytes: 0 } :
            { php_minor: '8.4', mode: 'disable', buffer_ini: '64M', buffer_bytes: 67108864 });
        assert.equal(Object.isFrozen(profile), true);
        const value = example(minor); value.private = 'SECRET-PAYLOAD';
        const result = parseJitMode(JSON.stringify(value), minor);
        assert.equal(result.ini.buffer_bytes, profile.buffer_bytes);
        assert.deepEqual(result.jit, { enabled: false, on: false, kind: value.jit.kind, capacity_bytes: 0, free_bytes: 0 });
        assert.equal(JSON.stringify(result).includes('SECRET'), false);
    }
    assert.throws(() => expectedNativeFpmProfile('8.5'));
});

test('wrong runtime, mode, active state, allocation or missing/unbounded data cannot attest a profile', () => {
    for (const minor of ['8.3', '8.4']) {
        assert.throws(() => parseJitMode(JSON.stringify(example(minor === '8.3' ? '8.4' : '8.3')), minor));
        for (const mutate of [value => { value.ini.mode = '1235'; }, value => { value.ini.buffer_bytes = 268435456; },
            value => { value.jit.enabled = true; }, value => { value.jit.on = true; }, value => { value.jit.capacity_bytes = 1; },
            value => { value.jit.free_bytes = 1; }, value => { value.opcache_enabled = false; }]) {
            const value = example(minor); mutate(value);
            assert.throws(() => parseJitMode(JSON.stringify(value), minor));
        }
        assert.throws(() => parseJitMode('{"state":"unknown"}', minor));
        assert.throws(() => parseJitMode('x'.repeat(4097), minor));
    }
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
    const request = modeRequest('/tmp/pinova-fpm-full-123-1/startup-jit-mode.php');
    assert.equal(request.includes(Buffer.from('wp-load')), false);
    assert.equal(request.includes(Buffer.from('PHP_VALUE')), false);
    assert.throws(() => modeRequest('/production/wp-load.php'));
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
    assert.equal(result.attests_signaled_worker, false);
    assert.equal(result.primary_receipt_sha256, createHash('sha256').update(JSON.stringify(primary, null, 2) + '\n').digest('hex'));
    assert.deepEqual(writes.map(row => row.name), ['full-stack-result.json', 'postwork-jit-mode.json']);
    assert.equal(Object.hasOwn(result, 'resource_acceptance'), false);
});

test('successful post-outcome observation remains separate from completed workload', async () => {
    for (const minor of ['8.3', '8.4']) {
        const primary = { state: 'passed' }; const writes = [];
        const result = await preserveOutcomeAndObserve(primary, name => writes.push(name), async () => parseJitMode(JSON.stringify(example(minor)), minor));
        assert.equal(primary.state, 'passed'); assert.equal(result.state, 'observed');
        assert.deepEqual(writes, ['full-stack-result.json', 'postwork-jit-mode.json']);
    }
});

test('unresponsive observation receives one fixed deadline and abort without losing primary outcome', async () => {
    assert.equal(MODE_OBSERVATION_TIMEOUT_MS, 3000);
    const primary = { state: 'failed' }; let aborted = false;
    const result = await preserveOutcomeAndObserve(primary, () => {}, signal => {
        signal.addEventListener('abort', () => { aborted = true; }, { once: true });
        return new Promise(() => {});
    });
    assert.equal(result.reason, 'mode_observation_timeout'); assert.equal(aborted, true);
    assert.equal(primary.state, 'failed');
});

test('separate evidence gate requires selected mode, primary binding, startup identity and owned responder', () => {
    for (const minor of ['8.3', '8.4']) {
        const options = { phpMinor: minor, primaryReceiptHash: 'e'.repeat(64), startupLaunch: startup(minor) };
        assert.equal(validatePostworkProfile(witnessed(minor), options), true);
        for (const mutate of [value => { value.primary_receipt_sha256 = 'f'.repeat(64); }, value => { value.primary_outcome_frozen = false; },
            value => { value.collection_boundary = 'before_primary'; }, value => { value.attests_signaled_worker = true; },
            value => { value.responder_owned_main_pool = false; }, value => { value.responder_start_ticks = 0; },
            value => { value.master_start_ticks++; }, value => { value.master_pid++; }, value => { value.binary_sha256 = 'f'.repeat(64); },
            value => { value.source_sha256 = 'f'.repeat(64); }]) {
            const value = witnessed(minor); mutate(value); assert.equal(validatePostworkProfile(value, options), false);
        }
        for (const mutate of [value => { value.php_minor = '8.5'; }, value => { value.declared_mode = '1235'; },
            value => { value.configured_buffer_bytes = 268435456; }, value => { value.declared_argv[2] = 'opcache.jit=1235'; },
            value => { value.owned_configuration_in_cmdline = false; }]) {
            const launch = startup(minor); mutate(launch);
            assert.equal(validatePostworkProfile(witnessed(minor), { ...options, startupLaunch: launch }), false);
        }
        assert.equal(validatePostworkProfile(null, options), false);
        assert.equal(validatePostworkProfile({ state: 'unknown' }, options), false);
        assert.equal(validatePostworkProfile(witnessed(minor), {}), false);
    }
});

test('secondary receipt write failure stays outside the preserved primary workload result', async () => {
    const primary = { state: 'passed' }, writes = [];
    const result = await preserveOutcomeAndObserve(primary, (name, value) => {
        if (name === 'postwork-jit-mode.json') throw new Error('SECRET-write-failure');
        writes.push({ name, value: structuredClone(value) });
    }, async () => parseJitMode(JSON.stringify(example()), '8.4'));
    assert.deepEqual(writes, [{ name: 'full-stack-result.json', value: { state: 'passed' } }]);
    assert.equal(primary.state, 'passed'); assert.equal(result.state, 'observed');
    assert.equal(JSON.stringify(result).includes('SECRET'), false);
});


// Supplemental proof controls exercise exported classifiers, not copies of their implementations.
import { readFileSync } from 'node:fs';
import { activeProofSelected, ACTIVE_PROOF_BRANCH, ACTIVE_CANDIDATE, ACTIVE_CANDIDATE_TREE, ACTIVE_BASELINE_TREE,
    expectedActiveFpmProfile, readActiveContract, validateActiveContract, expectedActiveBlacklistContents,
    CREATOR_RELATIVE, declaredActiveArgv, parseActiveJitMode, validateActiveProfile, validActiveIdentity,
    activeNativeGate, preserveActiveOutcome, validateActiveCleanup, activeLoggingGate } from '../Integration/postwork-jit-evidence.mjs';
const proofEnvironment = () => ({ PINOVA_FPM_ACTIVE_JIT_PROOF: 'creator-prefix-v1', GITHUB_REF: ACTIVE_PROOF_BRANCH,
    GITHUB_ACTIONS: 'true', RUNNER_ENVIRONMENT: 'github-hosted', GITHUB_RUN_ATTEMPT: '1' });
const activeIdentity = (baseline = false) => ({ schema: 'pinova.active-runtime-source.v1',
    application_source: baseline ? '96386b9fdd803086e9ecfe2af421034976dc185d' : ACTIVE_CANDIDATE,
    application_tree: baseline ? ACTIVE_BASELINE_TREE : ACTIVE_CANDIDATE_TREE, workflow_source: 'f'.repeat(40),
    source_archive_sha256: '1'.repeat(64), inventory_sha256: '2'.repeat(64), composer_lock_sha256: '3'.repeat(64),
    logging_profile: 'diagnostic', source_recipe: 'pinned_git_archive_with_normalized_locked_production_vendor', crashed_constructor_copy_attested: false });
const blacklist = () => ({ variant: 'B', file_sha256: '4'.repeat(64), file_bytes: 120, configured_path_sha256: '5'.repeat(64),
    creator_path_sha256: '6'.repeat(64), creator_source_sha256: '7'.repeat(64), configured_path_matches_owned_file: true,
    entry_count: 1, entry_semantics: 'absolute_resolved_creator_prefix' });
const activeMode = (minor = '8.4') => ({ ...example(minor), schema: 'pinova.postwork-active-jit.v1',
    ini: { ...example(minor).ini, mode: '1235', buffer_bytes: 268435456, file_cache_empty: true, preload_empty: true },
    jit: { enabled: true, on: true, kind: 3, capacity_bytes: 268435440, free_bytes: 267000000 },
    blacklist: blacklist(), cache: { creator_cached: false }, inventory_sha256: '2'.repeat(64), composer_lock_sha256: '3'.repeat(64) });
const activeLaunch = (minor = '8.4', baseline = false) => ({ ...startup(minor), declared_mode: '1235', configured_buffer_bytes: 268435456,
    declared_file_cache_empty: true, declared_preload_empty: true, declared_argv: declaredActiveArgv(), blacklist: blacklist(),
    fpm_configuration_sha256: '8'.repeat(64), runtime_identity: activeIdentity(baseline), contract_sha256: '9'.repeat(64) });
const activeWitness = (minor = '8.4', baseline = false) => ({ ...witnessed(minor), ...activeMode(minor),
    fpm_configuration_sha256: '8'.repeat(64), runtime_identity: activeIdentity(baseline), contract_sha256: '9'.repeat(64),
    resource_acceptance: false, shipping_fix: false });
const activeOptions = (minor = '8.4', baseline = false) => ({ phpMinor: minor, primaryReceiptHash: 'e'.repeat(64),
    startupLaunch: activeLaunch(minor, baseline), expectedIdentity: activeIdentity(baseline), contractHash: '9'.repeat(64) });
const cleanNative = () => ({ collection_boundary: 'controller_before_fpm_teardown', fpm_termination:
    { read_success: true, tail_truncated: false, event_bound: false, invalid_records: 3, events: [] } });

test('active selector is absent by default and refuses any broader execution route or retry', () => {
    assert.equal(activeProofSelected({}), false);
    assert.equal(activeProofSelected({ PINOVA_FPM_ACTIVE_JIT_PROOF: '' }), false);
    assert.equal(activeProofSelected(proofEnvironment()), true);
    for (const [key, value] of [['PINOVA_FPM_ACTIVE_JIT_PROOF', 'other'], ['GITHUB_REF', 'refs/heads/main'],
        ['GITHUB_ACTIONS', 'false'], ['RUNNER_ENVIRONMENT', 'self-hosted'], ['GITHUB_RUN_ATTEMPT', '2'],
        ['PINOVA_FPM_PROBE_CASE', '84-normal-candidate']]) {
        assert.throws(() => activeProofSelected({ ...proofEnvironment(), [key]: value }));
    }
});

test('proof contract freezes both patches, source trees and all original numeric/order gates', () => {
    const original = JSON.parse(readFileSync(new URL('../fixtures/diagnostics-campaign.json', import.meta.url)));
    const contract = readActiveContract();
    for (const minor of ['8.3', '8.4']) assert.deepEqual(contract.fpm_runtime_profiles[minor], expectedActiveFpmProfile(minor));
    assert.throws(() => expectedActiveFpmProfile('8.5'));
    for (const mutate of [v => { v.order.reverse(); }, v => { v.maximum_median_cpu_pss_ratio = 1.2; },
        v => { v.p95_allowance_ms++; }, v => { v.runner_opportunities_per_arm++; }, v => { v.retries = 1; },
        v => { v.fpm_runtime_profiles['8.4'].mode = 'disable'; }, v => { v.candidate_source = 'f'.repeat(40); },
        v => { v.full_suite_logging = 'normal'; }, v => { v.source_copy_origin_attested = true; }]) {
        const copy = structuredClone(contract); mutate(copy); assert.throws(() => validateActiveContract(copy, original));
    }
});

test('active proof permits one owned resolved Creator prefix only', () => {
    const root = '/tmp/pinova-fpm-full-123-1', creator = `${root}/${CREATOR_RELATIVE}`;
    assert.equal(expectedActiveBlacklistContents(root, 'B', creator), creator + '\n');
    for (const args of [[root, 'A', creator], ['/production', 'B', '/production/' + CREATOR_RELATIVE],
        [root, 'B', creator + '*'], [root, 'B', creator.replace('/runtime/', '/other/')]]) {
        assert.throws(() => expectedActiveBlacklistContents(...args));
    }
});

test('active parser requires actual on/enabled function JIT on each exact patch and uncached Creator', () => {
    for (const minor of ['8.3', '8.4']) {
        const input = activeMode(minor); input.private = 'SECRET';
        const parsed = parseActiveJitMode(JSON.stringify(input), minor);
        assert.equal(parsed.cache.creator_cached, false); assert.equal(parsed.jit.kind, 3);
        assert.equal(JSON.stringify(parsed).includes('SECRET'), false);
        for (const mutate of [v => { v.php_version += '.1'; }, v => { v.ini.mode = 'disable'; },
            v => { v.ini.buffer_bytes = 67108864; }, v => { v.ini.file_cache_empty = false; }, v => { v.ini.preload_empty = false; },
            v => { v.jit.enabled = false; }, v => { v.jit.on = false; }, v => { v.jit.kind = 5; }, v => { v.jit.capacity_bytes = 0; },
            v => { v.jit.free_bytes = 268435457; }, v => { v.blacklist.variant = 'A'; }, v => { v.blacklist.entry_count = 2; },
            v => { v.cache.creator_cached = true; }, v => { delete v.inventory_sha256; }]) {
            const bad = activeMode(minor); mutate(bad); assert.throws(() => parseActiveJitMode(JSON.stringify(bad), minor));
        }
        assert.throws(() => parseActiveJitMode(JSON.stringify(example(minor)), minor));
        assert.throws(() => parseJitMode(JSON.stringify(activeMode(minor)), minor));
    }
});

test('active binding requires the independently expected application, archive, inventory and workflow identities', () => {
    for (const minor of ['8.3', '8.4']) for (const baseline of [false, true]) {
        assert.equal(validActiveIdentity(activeIdentity(baseline)), true);
        assert.equal(validateActiveProfile(activeWitness(minor, baseline), activeOptions(minor, baseline)), true);
        for (const key of ['workflow_source', 'application_source', 'application_tree', 'source_archive_sha256',
            'inventory_sha256', 'composer_lock_sha256', 'logging_profile']) {
            const value = activeWitness(minor, baseline); value.runtime_identity[key] = 'different';
            assert.equal(validateActiveProfile(value, activeOptions(minor, baseline)), false);
        }
        assert.equal(validateActiveProfile(activeWitness(minor, baseline), { ...activeOptions(minor, baseline), expectedIdentity: undefined }), false);
    }
});

test('active binding fails closed on changed startup, config, contract, binary, primary or cache evidence', () => {
    for (const mutate of [v => { v.primary_receipt_sha256 = 'f'.repeat(64); }, v => { v.primary_outcome_frozen = false; },
        v => { v.collection_boundary = 'before_primary'; }, v => { v.attests_signaled_worker = true; },
        v => { v.responder_owned_main_pool = false; }, v => { v.master_pid++; }, v => { v.master_start_ticks++; },
        v => { v.binary_sha256 = 'f'.repeat(64); }, v => { v.source_sha256 = 'f'.repeat(64); },
        v => { v.fpm_configuration_sha256 = 'f'.repeat(64); }, v => { v.contract_sha256 = 'f'.repeat(64); },
        v => { v.blacklist.file_sha256 = 'f'.repeat(64); }, v => { v.resource_acceptance = true; }, v => { v.shipping_fix = true; }]) {
        const value = activeWitness(); mutate(value); assert.equal(validateActiveProfile(value, activeOptions()), false);
    }
    for (const mutate of [v => { v.declared_mode = 'disable'; }, v => { v.declared_argv[2] = 'opcache.jit=disable'; },
        v => { v.configured_buffer_bytes = 0; }, v => { v.declared_file_cache_empty = false; }, v => { v.declared_preload_empty = false; },
        v => { v.runtime_identity.crashed_constructor_copy_attested = true; }]) {
        const options = activeOptions(); mutate(options.startupLaunch); assert.equal(validateActiveProfile(activeWitness(), options), false);
    }
    for (const value of [null, {}, { state: 'unknown' }]) assert.equal(validateActiveProfile(value, activeOptions()), false);
});

test('recognized native faults cannot be accepted even after functional completion', () => {
    assert.equal(activeNativeGate(cleanNative()).state, 'passed');
    for (const event of [{ event: 'signal_exit', signal_number: 11 }, { event: 'request_timeout', timeout_seconds: 20 },
        { event: 'normal_exit', exit_code: 1 }]) {
        const evidence = cleanNative(); evidence.fpm_termination.events.push({ pool: 'fixture', pid: 777, ...event });
        const gate = activeNativeGate(evidence); assert.equal(gate.state, 'needs_review'); assert.equal(gate.recognized_native_fault, true);
    }
    const normal = cleanNative(); normal.fpm_termination.events.push({ pool: 'fixture_status', pid: 778, event: 'normal_exit', exit_code: 0 });
    assert.equal(activeNativeGate(normal).state, 'passed');
});

test('missing, late, truncated, overbound or malformed native evidence stays unknown', () => {
    for (const mutate of [v => { v.collection_boundary = 'after_teardown'; }, v => { v.fpm_termination.read_success = false; },
        v => { v.fpm_termination.tail_truncated = true; }, v => { v.fpm_termination.event_bound = true; },
        v => { delete v.fpm_termination.events; }, v => { v.fpm_termination.invalid_records = -1; },
        v => { v.fpm_termination.events.push({ pool: 'unknown', pid: 777, event: 'normal_exit', exit_code: 0 }); }]) {
        const evidence = cleanNative(); mutate(evidence); assert.equal(activeNativeGate(evidence).state, 'needs_review');
    }
    for (const evidence of [null, {}]) assert.equal(activeNativeGate(evidence).state, 'needs_review');
    assert.equal(activeNativeGate(cleanNative()).native_unparsed_records, 3);
});

test('active collection freezes the primary before observation and forces no shipping/resource claim', async () => {
    const primary = { state: 'passed', diagnostic_evidence: cleanNative() }; const saved = structuredClone(primary), writes = [];
    const result = await preserveActiveOutcome(primary, (name, value) => writes.push({ name, value: structuredClone(value) }), async () => {
        assert.equal(writes.length, 1); assert.equal(writes[0].name, 'full-stack-result.json');
        return { ...activeMode(), shipping_fix: true, resource_acceptance: true };
    });
    assert.deepEqual(primary, saved); assert.equal(result.shipping_fix, false); assert.equal(result.resource_acceptance, false);
    assert.equal(result.primary_receipt_sha256, createHash('sha256').update(JSON.stringify(primary, null, 2) + '\n').digest('hex'));
    assert.equal(writes[1].value.shipping_fix, false); assert.equal(writes[1].value.resource_acceptance, false);
});

test('active observation timeout or exception preserves the primary and produces nonaccepting evidence', async () => {
    for (const observe of [async () => { throw new Error('SECRET'); }, () => new Promise(() => {})]) {
        const primary = { state: 'failed' }; const writes = [];
        const result = await preserveActiveOutcome(primary, (name, value) => writes.push({ name, value }), observe);
        assert.equal(primary.state, 'failed'); assert.equal(result.state, 'unknown');
        assert.equal(result.shipping_fix, false); assert.equal(result.resource_acceptance, false);
        assert.equal(writes[1].value.shipping_fix, false); assert.equal(JSON.stringify(result).includes('SECRET'), false);
    }
});

const cleanupReceipt = () => ({ schema: 'pinova.active-native-cleanup.v1', state: 'complete', run_id: '123', attempt: '1',
    master_pid: 700, nginx_pid: 701, primary_receipt_sha256: 'e'.repeat(64), postwork_receipt_sha256: 'f'.repeat(64),
    boundary: 'after_owned_term_wait', daemons_gone: true, fpm_wait_known: true, fpm_exit_status: 0,
    nginx_wait_known: true, nginx_exit_status: 0, shipping_fix: false });
const cleanupOptions = () => ({ primaryReceiptHash: 'e'.repeat(64), modeReceiptHash: 'f'.repeat(64),
    startupLaunch: activeLaunch(), runId: '123', attempt: '1' });
test('native cleanup is separately bound to both frozen receipts and this startup/run', () => {
    assert.equal(validateActiveCleanup(cleanupReceipt(), cleanupOptions()), true);
    for (const mutate of [v => { v.state = 'needs_review'; }, v => { v.run_id = '124'; }, v => { v.attempt = '2'; },
        v => { v.master_pid++; }, v => { v.nginx_pid = v.master_pid; }, v => { v.primary_receipt_sha256 = 'a'.repeat(64); },
        v => { v.postwork_receipt_sha256 = 'a'.repeat(64); }, v => { v.boundary = 'before_teardown'; }, v => { v.shipping_fix = true; },
        v => { v.daemons_gone = false; }, v => { v.fpm_wait_known = false; }, v => { v.nginx_wait_known = false; },
        v => { v.fpm_exit_status = 139; }, v => { v.nginx_exit_status = 139; }, v => { v.fpm_exit_status = null; },
        v => { delete v.nginx_exit_status; }, v => { delete v.fpm_wait_known; }]) {
        const value = cleanupReceipt(); mutate(value); assert.equal(validateActiveCleanup(value, cleanupOptions()), false);
    }
    assert.equal(validateActiveCleanup(null, cleanupOptions()), false);
});


const loggingObservation = (profile = 'diagnostic', legacy = false) => ({ profile, minimum_level: 'warning', warning_enabled: true,
    diagnostic_window_active: profile === 'diagnostic', debug_enabled: profile === 'diagnostic' && legacy,
    info_enabled: profile === 'diagnostic' && legacy, diagnostic_mode: profile === 'normal' ? 'off' : legacy ? 'legacy_global' : 'scoped_queued_otp',
    scope_matches: !legacy, budget_initialized: !legacy });
const loggingOptions = (profile = 'diagnostic', legacy = false) => ({ loggingProfile: profile,
    applicationSource: legacy ? '96386b9fdd803086e9ecfe2af421034976dc185d' : ACTIVE_CANDIDATE });

test('candidate diagnostic logging requires scoped authorization and budget with ordinary debug/info disabled', () => {
    const observed = loggingObservation(), options = loggingOptions();
    const result = activeLoggingGate(observed, options);
    assert.equal(result.state, 'passed'); assert.deepEqual(result.observed, observed);
    for (const [key, value] of [['scope_matches', false], ['budget_initialized', false], ['debug_enabled', true],
        ['info_enabled', true], ['diagnostic_mode', 'legacy_global'], ['diagnostic_window_active', false],
        ['minimum_level', 'info'], ['warning_enabled', false], ['profile', 'normal']]) {
        const wrong = { ...observed, [key]: value };
        const gate = activeLoggingGate(wrong, options);
        assert.equal(gate.state, 'needs_review'); assert.equal(gate.observed[key], value);
    }
    for (const key of Object.keys(observed)) {
        const missing = { ...observed }; delete missing[key];
        assert.equal(activeLoggingGate(missing, options).state, 'needs_review');
    }
});

test('RC21 diagnostic logging requires its legacy global debug/info behavior without candidate-only scope or budget requirements', () => {
    const observed = loggingObservation('diagnostic', true), options = loggingOptions('diagnostic', true);
    assert.equal(activeLoggingGate(observed, options).state, 'passed');
    assert.equal(activeLoggingGate({ ...observed, scope_matches: true, budget_initialized: true }, options).state, 'passed');
    for (const [key, value] of [['debug_enabled', false], ['info_enabled', false], ['diagnostic_mode', 'scoped_queued_otp'],
        ['diagnostic_window_active', false], ['minimum_level', 'info'], ['warning_enabled', false]]) {
        assert.equal(activeLoggingGate({ ...observed, [key]: value }, options).state, 'needs_review');
    }
    assert.equal(activeLoggingGate(observed, loggingOptions()).state, 'needs_review');
    assert.equal(activeLoggingGate(loggingObservation(), options).state, 'needs_review');
});

test('normal logging for either staged source stays off and malformed source/profile/evidence cannot pass', () => {
    for (const legacy of [false, true]) {
        const observed = loggingObservation('normal', legacy), options = loggingOptions('normal', legacy);
        assert.equal(activeLoggingGate(observed, options).state, 'passed');
        for (const [key, value] of [['debug_enabled', true], ['info_enabled', true], ['diagnostic_mode', 'legacy_global'],
            ['diagnostic_window_active', true], ['minimum_level', 'info'], ['warning_enabled', false]]) {
            assert.equal(activeLoggingGate({ ...observed, [key]: value }, options).state, 'needs_review');
        }
        assert.equal(activeLoggingGate(observed, { ...options, applicationSource: 'f'.repeat(40) }).state, 'needs_review');
        assert.equal(activeLoggingGate(observed, { ...options, loggingProfile: 'unknown' }).state, 'needs_review');
        assert.equal(activeLoggingGate(null, options).state, 'needs_review');
        const secret = activeLoggingGate({ ...observed, private: 'SECRET', diagnostic_mode: 'SECRET' }, options);
        assert.equal(secret.state, 'needs_review'); assert.equal(JSON.stringify(secret).includes('SECRET'), false);
    }
});
