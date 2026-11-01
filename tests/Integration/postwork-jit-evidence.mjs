/** Owned FPM startup identity and post-outcome profile evidence; import performs no I/O. */
import { createHash } from 'node:crypto';
import { constants, openSync, closeSync, fstatSync, readSync, lstatSync, readFileSync, realpathSync } from 'node:fs';
import { createConnection } from 'node:net';
import { inspectProcess } from './php-process-sampler.mjs';

export const MODE_OBSERVATION_TIMEOUT_MS = 3000;
const hash = value => createHash('sha256').update(value).digest('hex');
const fact = (value, code) => { if (!value) throw new Error(code); };
const integer = (value, max = 2147483647) => Number.isSafeInteger(value) && value >= 0 && value <= max;
const sha256 = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
const profiles = Object.freeze({
    '8.3': Object.freeze({ php_minor: '8.3', mode: 'tracing', buffer_ini: '0', buffer_bytes: 0 }),
    '8.4': Object.freeze({ php_minor: '8.4', mode: 'disable', buffer_ini: '64M', buffer_bytes: 67108864 }),
});
const sharedKeys = ['buffer_bytes', 'enable_cli', 'optimization_level', 'hot_loop', 'hot_func', 'hot_return', 'hot_side_exit'];
const endpoint = new URL('../fixtures/fpm-otp/startup-jit-mode.php', import.meta.url);
const declaredArgv = profile => ['FPM', '-d', `opcache.jit=${profile.mode}`, '-d', `opcache.jit_buffer_size=${profile.buffer_ini}`,
    '--allow-to-run-as-root', '-F', '-y', 'RUN/fpm.conf'];

export function expectedNativeFpmProfile(phpMinor) {
    fact(typeof phpMinor === 'string' && Object.hasOwn(profiles, phpMinor), 'native_profile_unsupported');
    return profiles[phpMinor];
}

export function parseJitMode(text, phpMinor) {
    const profile = expectedNativeFpmProfile(phpMinor);
    fact(typeof text === 'string' && Buffer.byteLength(text) <= 4096, 'mode_body_bound');
    const value = JSON.parse(text);
    fact(value?.schema === 'pinova.postwork-jit-mode.v1' && value.state === 'observed' &&
        value.sapi === 'fpm-fcgi' && /^8\.[34]\.[0-9]+$/.test(value.php_version || '') && value.php_version.startsWith(phpMinor + '.') &&
        integer(value.pid) && value.pid > 1 && value.opcache_enabled === true, 'mode_shape');
    const ini = value.ini, jit = value.jit;
    fact(ini?.mode === profile.mode && ini.buffer_bytes === profile.buffer_bytes &&
        typeof ini.enable_cli === 'boolean' && integer(ini.optimization_level, 4294967295) &&
        ['hot_loop', 'hot_func', 'hot_return', 'hot_side_exit'].every(key => integer(ini[key], 65535)), 'mode_ini_shape');
    fact(typeof jit?.enabled === 'boolean' && typeof jit.on === 'boolean' && integer(jit.kind, 5) &&
        jit.enabled === false && jit.on === false && jit.capacity_bytes === 0 && jit.free_bytes === 0, 'mode_active_mismatch');
    return { schema: value.schema, state: 'observed', sapi: value.sapi, php_version: value.php_version,
        pid: value.pid, opcache_enabled: true, ini: { mode: ini.mode, ...Object.fromEntries(sharedKeys.map(key => [key, ini[key]])) },
        jit: { enabled: jit.enabled, on: jit.on, kind: jit.kind, capacity_bytes: jit.capacity_bytes, free_bytes: jit.free_bytes } };
}

export function captureStartupLaunch({ root, master, tick, binary, phpMinor }) {
    const profile = expectedNativeFpmProfile(phpMinor);
    fact(/^\/tmp\/pinova-fpm-full-[0-9]+-[0-9]+$/.test(root), 'startup_scope');
    const before = inspectProcess(master, tick);
    const executable = realpathSync(`/proc/${master}/exe`), configured = realpathSync(binary);
    const command = readFileSync(`/proc/${master}/cmdline`);
    fact(executable === configured && command.length <= 4096 && command.includes(Buffer.from(`${root}/fpm.conf`)), 'startup_process');
    const binaryHash = hash(readFileSync(executable));
    const concrete = [binary, '-d', `opcache.jit=${profile.mode}`, '-d', `opcache.jit_buffer_size=${profile.buffer_ini}`,
        '--allow-to-run-as-root', '-F', '-y', `${root}/fpm.conf`];
    const after = inspectProcess(master, tick);
    fact(before.start === after.start, 'startup_identity_changed');
    return { php_minor: phpMinor, master_pid: master, master_start_ticks: after.start, binary_sha256: binaryHash,
        proc_cmdline_sha256: hash(command), owned_configuration_in_cmdline: true,
        declared_mode: profile.mode, configured_buffer_bytes: profile.buffer_bytes, declared_argv: declaredArgv(profile),
        concrete_launch_argv_sha256: hash(Buffer.from(concrete.join('\0') + '\0')), mode_endpoint_sha256: hash(readFileSync(endpoint)),
        flags_recoverable_from_proc_title: false, binding: 'pinned_launcher_source_and_binary_not_recovered_flags' };
}

function record(type, body) {
    const header = Buffer.alloc(8); header[0] = 1; header[1] = type;
    header.writeUInt16BE(1, 2); header.writeUInt16BE(body.length, 4);
    return Buffer.concat([header, body]);
}
export function modeRequest(script) {
    fact(/^\/tmp\/pinova-fpm-full-[0-9]+-[0-9]+\/startup-jit-mode\.php$/.test(script), 'mode_script_scope');
    const fields = { GATEWAY_INTERFACE: 'CGI/1.1', REQUEST_METHOD: 'GET', SCRIPT_FILENAME: script,
        SCRIPT_NAME: '/startup-jit-mode.php', REQUEST_URI: '/startup-jit-mode.php', QUERY_STRING: '',
        SERVER_PROTOCOL: 'HTTP/1.1', SERVER_NAME: 'localhost', SERVER_PORT: '80', CONTENT_LENGTH: '0', REDIRECT_STATUS: '200' };
    const pairs = [];
    for (const [key, value] of Object.entries(fields)) {
        fact(Buffer.byteLength(key) < 128 && Buffer.byteLength(value) < 128, 'mode_param_bound');
        pairs.push(Buffer.from([Buffer.byteLength(key), Buffer.byteLength(value)]), Buffer.from(key), Buffer.from(value));
    }
    const begin = Buffer.alloc(8); begin.writeUInt16BE(1, 0);
    return Buffer.concat([record(1, begin), record(4, Buffer.concat(pairs)), record(4, Buffer.alloc(0)), record(5, Buffer.alloc(0))]);
}

/** Null means incomplete framing; no raw response ever escapes. */
export function fastCgiModeBody(bytes) {
    fact(Buffer.isBuffer(bytes) && bytes.length <= 32768, 'mode_wire_bound');
    let offset = 0, stdout = Buffer.alloc(0);
    while (offset + 8 <= bytes.length) {
        const type = bytes[offset + 1], length = bytes.readUInt16BE(offset + 4), padding = bytes[offset + 6];
        fact(bytes[offset] === 1 && bytes.readUInt16BE(offset + 2) === 1, 'mode_frame_identity');
        if (offset + 8 + length + padding > bytes.length) return null;
        const body = bytes.subarray(offset + 8, offset + 8 + length); offset += 8 + length + padding;
        if (type === 6) { stdout = Buffer.concat([stdout, body]); fact(stdout.length <= 8192, 'mode_stdout_bound'); }
        else if (type === 7) fact(body.length === 0, 'mode_stderr');
        else if (type === 3) {
            fact(body.length === 8 && body.readUInt32BE(0) === 0 && body[4] === 0 && offset === bytes.length, 'mode_end_status');
            const marker = stdout.indexOf('\r\n\r\n') >= 0 ? '\r\n\r\n' : '\n\n';
            const split = stdout.indexOf(marker); fact(split >= 0 && split <= 4096, 'mode_header_bound');
            return stdout.subarray(split + marker.length).toString('utf8');
        } else fact(false, 'mode_frame_type');
    }
    return null;
}

export async function readPostworkJit({ root, master, tick, phpMinor, startupLaunch, signal }) {
    const script = `${root}/startup-jit-mode.php`;
    fact(hash(readFileSync(script)) === hash(readFileSync(endpoint)), 'mode_source_changed');
    const request = modeRequest(script);
    const text = await new Promise((resolve, reject) => {
        let bytes = Buffer.alloc(0), settled = false;
        const socket = createConnection({ path: `${root}/fpm.sock` });
        const finish = (error, value) => {
            if (settled) return; settled = true;
            signal.removeEventListener('abort', abort); socket.destroy();
            error ? reject(error) : resolve(value);
        };
        const abort = () => finish(new Error('mode_observation_aborted'));
        signal.addEventListener('abort', abort, { once: true });
        if (signal.aborted) { abort(); return; }
        socket.once('connect', () => socket.write(request));
        socket.on('data', chunk => {
            try { bytes = Buffer.concat([bytes, chunk]); const body = fastCgiModeBody(bytes); if (body !== null) finish(null, body); }
            catch { finish(new Error('mode_transport_invalid')); }
        });
        socket.once('error', () => finish(new Error('mode_transport_failed')));
        socket.once('end', () => { if (!settled) finish(new Error('mode_transport_incomplete')); });
    });
    const value = parseJitMode(text, phpMinor);
    const responder = inspectProcess(value.pid, tick), observedMaster = inspectProcess(master, tick);
    const binaryHash = hash(readFileSync(realpathSync(`/proc/${master}/exe`)));
    const children = readFileSync(`/proc/${master}/task/${master}/children`, 'utf8').trim().split(/\s+/).filter(Boolean).map(Number);
    fact(children.length <= 64 && children.includes(value.pid) && responder.pool === 'fixture', 'mode_responder_unowned');
    fact(startupLaunch?.master_pid === master && startupLaunch.master_start_ticks === observedMaster.start &&
        startupLaunch.binary_sha256 === binaryHash, 'mode_master_changed');
    return { ...value, master_pid: master, master_start_ticks: observedMaster.start, binary_sha256: binaryHash,
        responder_start_ticks: responder.start, responder_owned_main_pool: true,
        source_sha256: hash(readFileSync(endpoint)), attests_signaled_worker: false,
        limitation: 'post_failure_responder_may_be_replacement_not_signaled_worker' };
}

/** A separate evidence gate; never converts or replaces the primary workload outcome. */
export function validatePostworkProfile(value, { phpMinor, primaryReceiptHash, startupLaunch } = {}) {
    try {
        const profile = expectedNativeFpmProfile(phpMinor);
        parseJitMode(JSON.stringify(value), phpMinor);
        return sha256(primaryReceiptHash) && value.primary_receipt_sha256 === primaryReceiptHash && value.primary_outcome_frozen === true &&
            value.collection_boundary === 'after_primary_receipt_before_fpm_teardown' && value.attests_signaled_worker === false &&
            value.responder_owned_main_pool === true && integer(value.responder_start_ticks, Number.MAX_SAFE_INTEGER) && value.responder_start_ticks > 0 &&
            startupLaunch?.php_minor === phpMinor && startupLaunch.owned_configuration_in_cmdline === true &&
            startupLaunch.flags_recoverable_from_proc_title === false && startupLaunch.binding === 'pinned_launcher_source_and_binary_not_recovered_flags' &&
            startupLaunch.declared_mode === profile.mode && startupLaunch.configured_buffer_bytes === profile.buffer_bytes &&
            JSON.stringify(startupLaunch.declared_argv) === JSON.stringify(declaredArgv(profile)) &&
            integer(startupLaunch.master_pid) && startupLaunch.master_pid > 1 && value.master_pid === startupLaunch.master_pid &&
            integer(startupLaunch.master_start_ticks, Number.MAX_SAFE_INTEGER) && startupLaunch.master_start_ticks > 0 &&
            value.master_start_ticks === startupLaunch.master_start_ticks && sha256(startupLaunch.binary_sha256) &&
            value.binary_sha256 === startupLaunch.binary_sha256 && sha256(startupLaunch.mode_endpoint_sha256) &&
            value.source_sha256 === startupLaunch.mode_endpoint_sha256 && sha256(startupLaunch.proc_cmdline_sha256) &&
            sha256(startupLaunch.concrete_launch_argv_sha256);
    } catch { return false; }
}

/** Preserve primary outcome first; secondary failure must never rewrite it. */
export async function preserveOutcomeAndObserve(receipt, writeReceipt, observe) {
    const primaryHash = hash(JSON.stringify(receipt, null, 2) + '\n');
    writeReceipt('full-stack-result.json', receipt);
    const controller = new AbortController(); let timer;
    const timeout = new Promise(resolve => { timer = setTimeout(() => {
        controller.abort(); resolve({ state: 'unknown', reason: 'mode_observation_timeout' });
    }, MODE_OBSERVATION_TIMEOUT_MS); });
    let mode;
    try { mode = await Promise.race([Promise.resolve().then(() => observe(controller.signal)), timeout]); }
    catch { mode = { state: 'unknown', reason: 'mode_observation_failed' }; }
    finally { clearTimeout(timer); controller.abort(); }
    const evidence = { ...mode, primary_receipt_sha256: primaryHash, primary_outcome_frozen: true,
        collection_boundary: 'after_primary_receipt_before_fpm_teardown', attests_signaled_worker: false };
    try { writeReceipt('postwork-jit-mode.json', evidence); } catch { /* Missing sidecar stays unknown to the separate evidence gate. */ }
    return evidence;
}

// Supplemental never-merge proof. The native-default functions above remain unchanged.

const activeError = code => { const error = new Error('Active proof observation failed.'); error.proofCode = code; return error; };
const activeFact = (condition, code) => { if (!condition) throw activeError(code); };

export const ACTIVE_PROOF_SELECTOR = 'creator-prefix-v1';
export const ACTIVE_PROOF_BRANCH = 'refs/heads/proof/diagnostics-active-jit-acceptance';
export const ACTIVE_CANDIDATE = '117e434bf65f093ad9d05f15cd180d34b4193ba9';
export const ACTIVE_CANDIDATE_TREE = '2f52e09f470431bfb3b1320e9812dca2515c201c';
export const ACTIVE_BASELINE_TREE = 'dc72906dfd80f4d254fb99b94e495995a2e8fe7c';
const activePatches = { '8.3': '8.3.36', '8.4': '8.4.27' };
const activeSharedKeys = [...sharedKeys, 'file_cache_empty', 'preload_empty'];
export function activeProofSelected(env = process.env) {
    if (env.PINOVA_FPM_ACTIVE_JIT_PROOF === undefined || env.PINOVA_FPM_ACTIVE_JIT_PROOF === '') return false;
    activeFact(env.PINOVA_FPM_ACTIVE_JIT_PROOF === ACTIVE_PROOF_SELECTOR && env.GITHUB_REF === ACTIVE_PROOF_BRANCH &&
        env.GITHUB_ACTIONS === 'true' && env.RUNNER_ENVIRONMENT === 'github-hosted' && env.GITHUB_RUN_ATTEMPT === '1' &&
        !env.PINOVA_FPM_PROBE_CASE, 'active_proof_scope');
    return true;
}
export function expectedActiveFpmProfile(phpMinor) {
    activeFact(Object.hasOwn(activePatches, phpMinor), 'active_profile_unsupported');
    return { php_minor: phpMinor, php_version: activePatches[phpMinor], mode: '1235', buffer_ini: '256M',
        buffer_bytes: 268435456, file_cache: '', preload: '', blacklist: 'resolved_owned_Creator_prefix' };
}
export function validateActiveContract(contract, original) {
    activeFact(contract?.schema === 'pinova.active-jit-source-campaign.v1' && contract.proof_selector === ACTIVE_PROOF_SELECTOR &&
        contract.proof_branch === ACTIVE_PROOF_BRANCH && contract.candidate_source === ACTIVE_CANDIDATE &&
        contract.candidate_tree === ACTIVE_CANDIDATE_TREE && contract.baseline_tree === ACTIVE_BASELINE_TREE &&
        contract.retries === 0 && contract.shipping_fix === false && contract.full_suite_logging === 'diagnostic' &&
        contract.full_suite_expected_groups === 16 && contract.source_copy_origin_attested === false &&
        contract.configuration_incremental_cost_attributed === false, 'active_contract_scope');
    for (const key of Object.keys(original)) {
        if (['schema', 'fpm_runtime_profiles'].includes(key)) continue;
        activeFact(JSON.stringify(contract[key]) === JSON.stringify(original[key]), 'active_contract_gate_changed');
    }
    for (const minor of ['8.3', '8.4']) activeFact(JSON.stringify(contract.fpm_runtime_profiles[minor]) ===
        JSON.stringify(expectedActiveFpmProfile(minor)), 'active_contract_profile_changed');
    return contract;
}
const fileHash = (file, limit) => {
    const info = lstatSync(file); activeFact(info.isFile() && info.size <= limit, 'mode_file_bound');
    const bytes = readFileSync(file); activeFact(bytes.length <= limit, 'mode_file_bound'); return hash(bytes);
};
const activeContractFile = new URL('../fixtures/diagnostics-active-jit-campaign.json', import.meta.url);
const originalContractFile = new URL('../fixtures/diagnostics-campaign.json', import.meta.url);
export function readActiveContract() {
    return validateActiveContract(JSON.parse(readFileSync(activeContractFile)), JSON.parse(readFileSync(originalContractFile)));
}
function runtimeIdentity(root) {
    const file = `${root}/runtime-identity.json`;
    activeFact(realpathSync(file) === file && lstatSync(file).isFile() && lstatSync(file).size <= 2048, 'runtime_identity_bound');
    const value = JSON.parse(readFileSync(file));
    const contract = readActiveContract();
    activeFact(value.schema === 'pinova.active-runtime-source.v1' &&
        [contract.baseline_source, contract.candidate_source].includes(value.application_source) &&
        value.application_tree === (value.application_source === contract.candidate_source ? contract.candidate_tree : contract.baseline_tree) &&
        /^[a-f0-9]{40}$/.test(value.workflow_source || '') && value.workflow_source === process.env.GITHUB_SHA &&
        ['source_archive_sha256', 'inventory_sha256', 'composer_lock_sha256'].every(key => sha256(value[key])) &&
        value.inventory_sha256 === fileHash(`${root}/runtime/vendor/composer/installed.json`, 4 * 1024 * 1024) &&
        value.composer_lock_sha256 === fileHash(`${root}/runtime/composer.lock`, 4 * 1024 * 1024) &&
        ['normal', 'diagnostic'].includes(value.logging_profile), 'runtime_identity_mismatch');
    return { schema: value.schema, application_source: value.application_source, application_tree: value.application_tree,
        workflow_source: value.workflow_source, source_archive_sha256: value.source_archive_sha256,
        inventory_sha256: value.inventory_sha256, composer_lock_sha256: value.composer_lock_sha256,
        logging_profile: value.logging_profile, source_recipe: 'pinned_git_archive_with_normalized_locked_production_vendor',
        crashed_constructor_copy_attested: false };
}
export const CREATOR_RELATIVE = 'runtime/vendor/nesbot/carbon/src/Carbon/Traits/Creator.php';
export const BLACKLIST_FILE = 'startup-opcache-blacklist.txt';
export const declaredActiveArgv = () => ['FPM', '-d', 'opcache.jit=1235', '-d', 'opcache.jit_buffer_size=256M',
    '-d', 'opcache.file_cache=', '-d', 'opcache.preload=', '-d', `opcache.blacklist_filename=RUN/${BLACKLIST_FILE}`,
    '--allow-to-run-as-root', '-F', '-y', 'RUN/fpm.conf'];

/** Only this resolved, run-owned source prefix is permitted; never a glob or runtime input. */
export function expectedActiveBlacklistContents(root, variant, resolvedCreator) {
    activeFact(variant === 'B' && /^\/tmp\/pinova-fpm-full-[0-9]+-[0-9]+$/.test(root) &&
        resolvedCreator === `${root}/${CREATOR_RELATIVE}`, 'blacklist_input_scope');
    return variant === 'B' ? `${resolvedCreator}\n` : '';
}

function inspectActiveBlacklist(root, variant) {
    const creator = realpathSync(`${root}/${CREATOR_RELATIVE}`), file = `${root}/${BLACKLIST_FILE}`;
    const expected = expectedActiveBlacklistContents(root, variant, creator);
    activeFact(realpathSync(file) === file && lstatSync(file).isFile() && lstatSync(file).size <= 1024 &&
        lstatSync(creator).isFile() && lstatSync(creator).size <= 131072, 'blacklist_file_bound');
    const bytes = readFileSync(file);
    activeFact(bytes.equals(Buffer.from(expected)), 'blacklist_contents_mismatch');
    return { variant, file_sha256: hash(bytes), file_bytes: bytes.length, configured_path_sha256: hash(file),
        creator_path_sha256: hash(creator), creator_source_sha256: hash(readFileSync(creator)),
        configured_path_matches_owned_file: true, entry_count: variant === 'B' ? 1 : 0,
        entry_semantics: 'absolute_resolved_creator_prefix' };
}

export function parseActiveJitMode(text, phpMinor) {
    activeFact(typeof text === 'string' && Buffer.byteLength(text) <= 4096 && Object.hasOwn(activePatches, phpMinor), 'mode_body_bound');
    const value = JSON.parse(text);
    activeFact(value?.schema === 'pinova.postwork-active-jit.v1' && value.state === 'observed' &&
        value.sapi === 'fpm-fcgi' && value.php_version === activePatches[phpMinor] &&
        integer(value.pid) && value.pid > 1 && value.opcache_enabled === true, 'mode_shape');
    const ini = value.ini, jit = value.jit;
    activeFact(ini?.mode === '1235' && ini.buffer_bytes === 268435456 && ini.file_cache_empty === true && ini.preload_empty === true &&
        typeof ini.enable_cli === 'boolean' && integer(ini.optimization_level, 4294967295) &&
        ['hot_loop', 'hot_func', 'hot_return', 'hot_side_exit'].every(key => integer(ini[key], 65535)), 'mode_ini_shape');
    activeFact(typeof jit?.enabled === 'boolean' && typeof jit.on === 'boolean' && integer(jit.kind, 5) &&
        integer(jit.capacity_bytes, 268435456) && integer(jit.free_bytes, jit.capacity_bytes), 'mode_jit_shape');
    activeFact(jit.enabled && jit.on && jit.kind === 3 && jit.capacity_bytes > 0, 'mode_active_mismatch');
    const blacklist = value.blacklist;
    activeFact(blacklist?.variant === 'B' && blacklist.configured_path_matches_owned_file === true &&
        blacklist.entry_semantics === 'absolute_resolved_creator_prefix' && blacklist.entry_count === 1 &&
        integer(blacklist.file_bytes, 1024) && blacklist.file_bytes > 0 &&
        ['file_sha256', 'configured_path_sha256', 'creator_path_sha256', 'creator_source_sha256'].every(key => sha256(blacklist[key])),
    'blacklist_shape');
    activeFact(value.cache?.creator_cached === false, 'cache_shape');
    activeFact(sha256(value.inventory_sha256) && sha256(value.composer_lock_sha256), 'runtime_hash_shape');
    return { inventory_sha256: value.inventory_sha256, composer_lock_sha256: value.composer_lock_sha256, schema: value.schema, state: 'observed', sapi: value.sapi, php_version: value.php_version,
        pid: value.pid, opcache_enabled: true, ini: { mode: ini.mode, ...Object.fromEntries(activeSharedKeys.map(key => [key, ini[key]])) },
        jit: { enabled: jit.enabled, on: jit.on, kind: jit.kind, capacity_bytes: jit.capacity_bytes, free_bytes: jit.free_bytes },
        blacklist: Object.fromEntries(['variant', 'file_sha256', 'file_bytes', 'configured_path_sha256', 'creator_path_sha256',
            'creator_source_sha256', 'configured_path_matches_owned_file', 'entry_count', 'entry_semantics'].map(key => [key, blacklist[key]])),
        cache: { creator_cached: value.cache.creator_cached } };
}

export function captureActiveLaunch({ root, master, tick, binary, phpMinor }) {
    activeFact(Object.hasOwn(activePatches, phpMinor) && /^\/tmp\/pinova-fpm-full-[0-9]+-[0-9]+$/.test(root), 'startup_scope');
    const blacklist = inspectActiveBlacklist(root, 'B');
    const before = inspectProcess(master, tick);
    const executable = realpathSync(`/proc/${master}/exe`), configured = realpathSync(binary);
    const command = readFileSync(`/proc/${master}/cmdline`);
    activeFact(executable === configured && command.length <= 4096 && command.includes(Buffer.from(`${root}/fpm.conf`)), 'startup_process');
    const binaryHash = fileHash(executable, 64 * 1024 * 1024);
    const concrete = [binary, '-d', 'opcache.jit=1235', '-d', 'opcache.jit_buffer_size=256M',
        '-d', 'opcache.file_cache=', '-d', 'opcache.preload=', '-d', `opcache.blacklist_filename=${root}/${BLACKLIST_FILE}`,
        '--allow-to-run-as-root', '-F', '-y', `${root}/fpm.conf`];
    const after = inspectProcess(master, tick);
    activeFact(before.start === after.start, 'startup_identity_changed');
    return { php_minor: phpMinor, runtime_identity: runtimeIdentity(root), contract_sha256: fileHash(activeContractFile, 16384), master_pid: master, master_start_ticks: after.start, binary_sha256: binaryHash,
        proc_cmdline_sha256: hash(command), owned_configuration_in_cmdline: true,
        declared_mode: '1235', configured_buffer_bytes: 268435456, blacklist,
        declared_file_cache_empty: true, declared_preload_empty: true,
        fpm_configuration_sha256: fileHash(`${root}/fpm.conf`, 32768), mode_endpoint_sha256: fileHash(endpoint, 16384),
        declared_argv: declaredActiveArgv(),
        concrete_launch_argv_sha256: hash(Buffer.from(concrete.join('\0') + '\0')),
        flags_recoverable_from_proc_title: false, binding: 'pinned_launcher_source_and_binary_not_recovered_flags' };
}

export async function readActiveProofJit({ root, master, tick, phpMinor, startupLaunch, signal }) {
    let stage = 'endpoint_source', bound = null;
    const startupWarnings = readActiveStartupWarnings(root);
    try {
    const script = `${root}/startup-jit-mode.php`;
    const source = new URL('../fixtures/fpm-otp/startup-jit-mode.php', import.meta.url);
    activeFact(fileHash(script, 16384) === fileHash(source, 16384), 'mode_source_changed');
    stage = 'transport';
    const request = modeRequest(script);
    const text = await new Promise((resolve, reject) => {
        let bytes = Buffer.alloc(0), settled = false;
        const socket = createConnection({ path: `${root}/fpm.sock` });
        const finish = (error, value) => {
            if (settled) return; settled = true;
            signal.removeEventListener('abort', abort); socket.destroy();
            error ? reject(error) : resolve(value);
        };
        const abort = () => finish(activeError('mode_observation_aborted'));
        signal.addEventListener('abort', abort, { once: true });
        if (signal.aborted) { abort(); return; }
        socket.once('connect', () => socket.write(request));
        socket.on('data', chunk => {
            try { bytes = Buffer.concat([bytes, chunk]); const body = fastCgiModeBody(bytes); if (body !== null) finish(null, body); }
            catch { finish(activeError('mode_transport_invalid')); }
        });
        socket.once('error', () => finish(activeError('mode_transport_failed')));
        socket.once('end', () => { if (!settled) finish(activeError('mode_transport_incomplete')); });
    });
    stage = 'body_shape';
    const value = projectActiveNativeMode(text, phpMinor);
    stage = 'responder_binding';
    const responder = inspectProcess(value.pid, tick), observedMaster = inspectProcess(master, tick);
    const children = readFileSync(`/proc/${master}/task/${master}/children`, 'utf8').trim().split(/\s+/).filter(Boolean).map(Number);
    activeFact(children.length <= 64 && children.includes(value.pid) && responder.pool === 'fixture', 'mode_responder_unowned');
    const binaryHash = fileHash(realpathSync(`/proc/${master}/exe`), 64 * 1024 * 1024);
    const blacklist = inspectActiveBlacklist(root, 'B'), configurationHash = fileHash(`${root}/fpm.conf`, 32768);
    activeFact(startupLaunch?.master_pid === master && startupLaunch.master_start_ticks === observedMaster.start &&
        startupLaunch.binary_sha256 === binaryHash && startupLaunch.fpm_configuration_sha256 === configurationHash &&
        JSON.stringify(startupLaunch.blacklist) === JSON.stringify(blacklist) &&
        JSON.stringify(value.blacklist) === JSON.stringify(blacklist), 'postwork_binding_changed');
    stage = 'source_binding';
    const identity = runtimeIdentity(root), contractHash = fileHash(activeContractFile, 16384);
    activeFact(JSON.stringify(identity) === JSON.stringify(startupLaunch.runtime_identity) &&
        value.inventory_sha256 === identity.inventory_sha256 && value.composer_lock_sha256 === identity.composer_lock_sha256 &&
        contractHash === startupLaunch.contract_sha256, 'postwork_source_binding_changed');
    const binding = { runtime_identity: identity, contract_sha256: contractHash, master_pid: master, master_start_ticks: observedMaster.start, binary_sha256: binaryHash,
        fpm_configuration_sha256: configurationHash, responder_start_ticks: responder.start, responder_owned_main_pool: true,
        source_sha256: fileHash(source, 16384), attests_signaled_worker: false,
        limitation: 'post_failure_responder_may_be_replacement_not_signaled_worker' };
    bound = { ...value, ...binding };
    stage = 'profile';
    const accepted = parseActiveJitMode(text, phpMinor);
    return { ...accepted, extensions: value.extensions, ...binding };
    } catch (error) {
        return activeFailureEvidence(stage, error?.proofCode, startupWarnings, bound);
    }
}

export function validateActiveProfile(value, { phpMinor, primaryReceiptHash, startupLaunch, expectedIdentity, contractHash } = {}) {
    try {
        const parsed = parseActiveJitMode(JSON.stringify(value), phpMinor);
        return startupLaunch?.php_minor === phpMinor && sha256(contractHash) &&
            value.contract_sha256 === contractHash && startupLaunch.contract_sha256 === contractHash &&
            validActiveIdentity(expectedIdentity) && JSON.stringify(value.runtime_identity) === JSON.stringify(expectedIdentity) &&
            JSON.stringify(startupLaunch.runtime_identity) === JSON.stringify(expectedIdentity) &&
            value.inventory_sha256 === expectedIdentity.inventory_sha256 && value.composer_lock_sha256 === expectedIdentity.composer_lock_sha256 &&
            sha256(primaryReceiptHash) && value.primary_receipt_sha256 === primaryReceiptHash && value.primary_outcome_frozen === true &&
            value.collection_boundary === 'after_primary_receipt_before_fpm_teardown' && value.attests_signaled_worker === false &&
            value.resource_acceptance === false && value.shipping_fix === false && value.responder_owned_main_pool === true &&
            integer(value.responder_start_ticks, Number.MAX_SAFE_INTEGER) && value.responder_start_ticks > 0 &&
            startupLaunch?.owned_configuration_in_cmdline === true && startupLaunch.flags_recoverable_from_proc_title === false &&
            startupLaunch.binding === 'pinned_launcher_source_and_binary_not_recovered_flags' &&
            startupLaunch.declared_mode === '1235' && startupLaunch.configured_buffer_bytes === 268435456 &&
            startupLaunch.declared_file_cache_empty === true && startupLaunch.declared_preload_empty === true &&
            JSON.stringify(startupLaunch.declared_argv) === JSON.stringify(declaredActiveArgv()) &&
            integer(startupLaunch.master_pid) && startupLaunch.master_pid > 1 && value.master_pid === startupLaunch.master_pid &&
            integer(startupLaunch.master_start_ticks, Number.MAX_SAFE_INTEGER) && startupLaunch.master_start_ticks > 0 &&
            value.master_start_ticks === startupLaunch.master_start_ticks &&
            ['binary_sha256', 'fpm_configuration_sha256'].every(key => sha256(startupLaunch[key]) && value[key] === startupLaunch[key]) &&
            sha256(startupLaunch.mode_endpoint_sha256) && value.source_sha256 === startupLaunch.mode_endpoint_sha256 &&
            sha256(startupLaunch.proc_cmdline_sha256) && sha256(startupLaunch.concrete_launch_argv_sha256) &&
            JSON.stringify(parsed.blacklist) === JSON.stringify(startupLaunch.blacklist);
    } catch { return false; }
}


export function validActiveIdentity(value) {
    return value?.schema === 'pinova.active-runtime-source.v1' && /^[a-f0-9]{40}$/.test(value.workflow_source || '') &&
        ((value.application_source === ACTIVE_CANDIDATE && value.application_tree === ACTIVE_CANDIDATE_TREE) ||
         (value.application_source === '96386b9fdd803086e9ecfe2af421034976dc185d' && value.application_tree === ACTIVE_BASELINE_TREE)) &&
        ['source_archive_sha256', 'inventory_sha256', 'composer_lock_sha256'].every(key => sha256(value[key])) &&
        ['normal', 'diagnostic'].includes(value.logging_profile) &&
        value.source_recipe === 'pinned_git_archive_with_normalized_locked_production_vendor' && value.crashed_constructor_copy_attested === false;
}
/** Recognized pre-teardown events only; unmatched startup lines remain an explicit limitation. */
export function activeNativeGate(evidence) {
    const native = evidence?.fpm_termination;
    const shape = Array.isArray(native?.events) && native.events.length <= 16 && native.events.every(event =>
        event && ['fixture', 'fixture_status'].includes(event.pool) && integer(event.pid) && event.pid > 1 &&
        ((event.event === 'signal_exit' && integer(event.signal_number, 64) && event.signal_number > 0) ||
         (event.event === 'normal_exit' && integer(event.exit_code, 255)) ||
         (event.event === 'request_timeout' && typeof event.timeout_seconds === 'number' &&
            Number.isFinite(event.timeout_seconds) && event.timeout_seconds >= 0 && event.timeout_seconds <= 3600)));
    const complete = evidence?.collection_boundary === 'controller_before_fpm_teardown' && native?.read_success === true &&
        native.tail_truncated === false && native.event_bound === false && integer(native.invalid_records, 65536) && shape;
    const fault = Boolean(shape && native.events.some(event => event.event === 'signal_exit' || event.event === 'request_timeout' ||
        (event.event === 'normal_exit' && event.exit_code !== 0)));
    return { state: complete && !fault ? 'passed' : 'needs_review', native_tail_complete: Boolean(complete),
        recognized_native_fault: fault, native_unparsed_records: integer(native?.invalid_records, 65536) ? native.invalid_records : null,
        limitation: 'bounded_recognized_events_only_unmatched_lines_not_complete_semantic_log_assurance' };
}
export async function preserveActiveOutcome(receipt, writeReceipt, observe) {
    const decorate = value => value.reason === 'mode_observation_timeout' ? {
        ...value, failure_stage: 'deadline', failure_code: 'mode_observation_timeout', startup_warnings: null, bound_native: null,
    } : value;
    const writer = (name, value) => writeReceipt(name, name === 'postwork-jit-mode.json' ?
        { ...decorate(value), resource_acceptance: false, shipping_fix: false } : value);
    const result = await preserveOutcomeAndObserve(receipt, writer, async signal => {
        try { return { ...await observe(signal), resource_acceptance: false, shipping_fix: false }; }
        catch { return { ...activeFailureEvidence('unclassified', 'unclassified', null, null), resource_acceptance: false, shipping_fix: false }; }
    });
    return { ...decorate(result), resource_acceptance: false, shipping_fix: false };
}

export function validateActiveCleanup(value, { primaryReceiptHash, modeReceiptHash, startupLaunch, runId, attempt } = {}) {
    return value?.schema === 'pinova.active-native-cleanup.v1' && value.state === 'complete' &&
        value.boundary === 'after_owned_term_wait' && value.shipping_fix === false &&
        value.daemons_gone === true && value.fpm_wait_known === true && value.nginx_wait_known === true &&
        value.fpm_exit_status === 0 && value.nginx_exit_status === 0 &&
        typeof runId === 'string' && /^[0-9]+$/.test(runId) && value.run_id === runId && attempt === '1' && value.attempt === attempt &&
        integer(value.master_pid) && value.master_pid > 1 && value.master_pid === startupLaunch?.master_pid &&
        integer(value.nginx_pid) && value.nginx_pid > 1 && value.nginx_pid !== value.master_pid &&
        sha256(primaryReceiptHash) && sha256(modeReceiptHash) && value.primary_receipt_sha256 === primaryReceiptHash &&
        value.postwork_receipt_sha256 === modeReceiptHash;
}

/** Validate the existing logger's observed setup state against the separately bound staged source. */
export function activeLoggingGate(value, { applicationSource, loggingProfile } = {}) {
    const baseline = '96386b9fdd803086e9ecfe2af421034976dc185d';
    const allowedSource = [baseline, ACTIVE_CANDIDATE].includes(applicationSource);
    const boolean = key => typeof value?.[key] === 'boolean' ? value[key] : null;
    const observed = {
        profile: ['normal', 'diagnostic'].includes(value?.profile) ? value.profile : 'unknown',
        minimum_level: ['debug', 'info', 'warning', 'error', 'critical'].includes(value?.minimum_level) ? value.minimum_level : 'unknown',
        diagnostic_window_active: boolean('diagnostic_window_active'), debug_enabled: boolean('debug_enabled'),
        info_enabled: boolean('info_enabled'), warning_enabled: boolean('warning_enabled'),
        diagnostic_mode: ['off', 'legacy_global', 'scoped_queued_otp'].includes(value?.diagnostic_mode) ? value.diagnostic_mode : 'unknown',
        scope_matches: boolean('scope_matches'), budget_initialized: boolean('budget_initialized'),
    };
    const selected = ['normal', 'diagnostic'].includes(loggingProfile);
    const common = allowedSource && selected && observed.profile === loggingProfile &&
        observed.minimum_level === 'warning' && observed.warning_enabled === true;
    let matches = false;
    if (loggingProfile === 'normal') {
        matches = observed.diagnostic_mode === 'off' && observed.diagnostic_window_active === false &&
            observed.debug_enabled === false && observed.info_enabled === false;
    } else if (loggingProfile === 'diagnostic' && applicationSource === ACTIVE_CANDIDATE) {
        matches = observed.diagnostic_mode === 'scoped_queued_otp' && observed.diagnostic_window_active === true &&
            observed.scope_matches === true && observed.budget_initialized === true &&
            observed.debug_enabled === false && observed.info_enabled === false;
    } else if (loggingProfile === 'diagnostic' && applicationSource === baseline) {
        matches = observed.diagnostic_mode === 'legacy_global' && observed.diagnostic_window_active === true &&
            observed.debug_enabled === true && observed.info_enabled === true;
    }
    return { state: common && matches ? 'passed' : 'needs_review',
        application_source: allowedSource ? applicationSource : null,
        selected_profile: selected ? loggingProfile : 'unknown', observed };
}

/** Shape projection only. It never grants the active runtime profile. */
export function projectActiveNativeMode(text, phpMinor) {
    activeFact(typeof text === 'string' && Buffer.byteLength(text) <= 4096 && Object.hasOwn(activePatches, phpMinor), 'mode_body_bound');
    let value;
    try { value = JSON.parse(text); } catch { throw activeError('mode_json_invalid'); }
    activeFact(!(value?.schema === 'pinova.postwork-active-jit.v1' && value.state === 'unknown' && value.reason === 'native_mode_unavailable'), 'endpoint_native_unavailable');
    activeFact(value?.schema === 'pinova.postwork-active-jit.v1' && value.state === 'observed' &&
        value.sapi === 'fpm-fcgi' && Object.values(activePatches).includes(value.php_version) &&
        integer(value.pid) && value.pid > 1 && typeof value.opcache_enabled === 'boolean', 'mode_shape');
    const ini = value.ini, jit = value.jit;
    activeFact(['1235', 'tracing', 'disable'].includes(ini?.mode) && integer(ini.buffer_bytes, 1073741824) &&
        typeof ini.file_cache_empty === 'boolean' && typeof ini.preload_empty === 'boolean' &&
        typeof ini.enable_cli === 'boolean' && integer(ini.optimization_level, 4294967295) &&
        ['hot_loop', 'hot_func', 'hot_return', 'hot_side_exit'].every(key => integer(ini[key], 65535)), 'mode_ini_shape');
    activeFact(typeof jit?.enabled === 'boolean' && typeof jit.on === 'boolean' && integer(jit.kind, 5) &&
        integer(jit.capacity_bytes, 1073741824) && integer(jit.free_bytes, jit.capacity_bytes), 'mode_jit_shape');
    const blacklist = value.blacklist;
    activeFact(blacklist?.variant === 'B' && blacklist.configured_path_matches_owned_file === true &&
        blacklist.entry_semantics === 'absolute_resolved_creator_prefix' && blacklist.entry_count === 1 &&
        integer(blacklist.file_bytes, 1024) && blacklist.file_bytes > 0 &&
        ['file_sha256', 'configured_path_sha256', 'creator_path_sha256', 'creator_source_sha256'].every(key => sha256(blacklist[key])),
    'blacklist_shape');
    activeFact(typeof value.cache?.creator_cached === 'boolean', 'cache_shape');
    activeFact(sha256(value.inventory_sha256) && sha256(value.composer_lock_sha256), 'runtime_hash_shape');
    activeFact(typeof value.extensions?.xdebug_loaded === 'boolean' && typeof value.extensions?.pcov_loaded === 'boolean', 'extensions_shape');
    return { extensions: { xdebug_loaded: value.extensions.xdebug_loaded, pcov_loaded: value.extensions.pcov_loaded }, inventory_sha256: value.inventory_sha256, composer_lock_sha256: value.composer_lock_sha256, schema: value.schema, state: 'observed', sapi: value.sapi, php_version: value.php_version,
        pid: value.pid, opcache_enabled: value.opcache_enabled, ini: { mode: ini.mode, ...Object.fromEntries(activeSharedKeys.map(key => [key, ini[key]])) },
        jit: { enabled: jit.enabled, on: jit.on, kind: jit.kind, capacity_bytes: jit.capacity_bytes, free_bytes: jit.free_bytes },
        blacklist: Object.fromEntries(['variant', 'file_sha256', 'file_bytes', 'configured_path_sha256', 'creator_path_sha256',
            'creator_source_sha256', 'configured_path_matches_owned_file', 'entry_count', 'entry_semantics'].map(key => [key, blacklist[key]])),
        cache: { creator_cached: value.cache.creator_cached } };
}


function validateBoundNative(value, { phpMinor, primaryReceiptHash, startupLaunch, expectedIdentity, contractHash } = {}) {
    try {
        const parsed = projectActiveNativeMode(JSON.stringify(value), phpMinor);
        return startupLaunch?.php_minor === phpMinor && sha256(contractHash) &&
            value.contract_sha256 === contractHash && startupLaunch.contract_sha256 === contractHash &&
            validActiveIdentity(expectedIdentity) && JSON.stringify(value.runtime_identity) === JSON.stringify(expectedIdentity) &&
            JSON.stringify(startupLaunch.runtime_identity) === JSON.stringify(expectedIdentity) &&
            value.inventory_sha256 === expectedIdentity.inventory_sha256 && value.composer_lock_sha256 === expectedIdentity.composer_lock_sha256 &&
            sha256(primaryReceiptHash) && value.primary_receipt_sha256 === primaryReceiptHash && value.primary_outcome_frozen === true &&
            value.collection_boundary === 'after_primary_receipt_before_fpm_teardown' && value.attests_signaled_worker === false &&
            value.resource_acceptance === false && value.shipping_fix === false && value.responder_owned_main_pool === true &&
            integer(value.responder_start_ticks, Number.MAX_SAFE_INTEGER) && value.responder_start_ticks > 0 &&
            startupLaunch?.owned_configuration_in_cmdline === true && startupLaunch.flags_recoverable_from_proc_title === false &&
            startupLaunch.binding === 'pinned_launcher_source_and_binary_not_recovered_flags' &&
            startupLaunch.declared_mode === '1235' && startupLaunch.configured_buffer_bytes === 268435456 &&
            startupLaunch.declared_file_cache_empty === true && startupLaunch.declared_preload_empty === true &&
            JSON.stringify(startupLaunch.declared_argv) === JSON.stringify(declaredActiveArgv()) &&
            integer(startupLaunch.master_pid) && startupLaunch.master_pid > 1 && value.master_pid === startupLaunch.master_pid &&
            integer(startupLaunch.master_start_ticks, Number.MAX_SAFE_INTEGER) && startupLaunch.master_start_ticks > 0 &&
            value.master_start_ticks === startupLaunch.master_start_ticks &&
            ['binary_sha256', 'fpm_configuration_sha256'].every(key => sha256(startupLaunch[key]) && value[key] === startupLaunch[key]) &&
            sha256(startupLaunch.mode_endpoint_sha256) && value.source_sha256 === startupLaunch.mode_endpoint_sha256 &&
            sha256(startupLaunch.proc_cmdline_sha256) && sha256(startupLaunch.concrete_launch_argv_sha256) &&
            JSON.stringify(parsed.blacklist) === JSON.stringify(startupLaunch.blacklist);
    } catch { return false; }
}



// Exact warning literals from pinned PHP8.3.36 lines4916/4926/4942 and PHP8.4.27 lines3603/3613/3628.
const startupWarningLiterals = {
    "unsupported_vm": "JIT is compatible only with CALL and HYBRID VM. JIT disabled.",
    "executor_override": "JIT is incompatible with third party extensions that override zend_execute_ex(). JIT disabled.",
    "opcode_handlers": "JIT is incompatible with third party extensions that setup user opcode handlers. JIT disabled."
};

const failureCodes = {
    endpoint_source: ['mode_source_changed', 'mode_file_bound', 'unclassified'],
    transport: ['mode_transport_invalid', 'mode_transport_failed', 'mode_transport_incomplete', 'mode_observation_aborted', 'unclassified'],
    body_shape: ['mode_body_bound', 'mode_json_invalid', 'endpoint_native_unavailable', 'mode_shape', 'mode_ini_shape', 'mode_jit_shape', 'blacklist_shape', 'cache_shape', 'runtime_hash_shape', 'extensions_shape', 'unclassified'],
    responder_binding: ['mode_responder_unowned', 'postwork_binding_changed', 'mode_file_bound', 'blacklist_input_scope', 'blacklist_file_bound', 'blacklist_contents_mismatch', 'unclassified'],
    source_binding: ['runtime_identity_bound', 'runtime_identity_mismatch', 'postwork_source_binding_changed', 'active_contract_scope', 'active_contract_gate_changed', 'active_contract_profile_changed', 'mode_file_bound', 'unclassified'],
    profile: ['mode_shape', 'mode_ini_shape', 'mode_jit_shape', 'mode_active_mismatch', 'blacklist_shape', 'cache_shape', 'runtime_hash_shape', 'unclassified'],
    deadline: ['mode_observation_timeout'], unclassified: ['unclassified'],
};
const exactKeys = (value, keys) => value && typeof value === 'object' && !Array.isArray(value) &&
    Object.keys(value).length === keys.length && Object.keys(value).every(key => keys.includes(key));
const warningKeys = Object.keys(startupWarningLiterals);
export function projectStartupWarnings(text, { readSuccess = true, truncated = false } = {}) {
    const bytes = typeof text === 'string' ? Buffer.from(text) : Buffer.alloc(0);
    const counts = Object.fromEntries(warningKeys.map(key => [key, 0])); let unmatched = 0;
    for (const line of bytes.subarray(Math.max(0, bytes.length - 65536)).toString('utf8').split('\n')) {
        if (!line.trim()) continue;
        let matched = false;
        for (const key of warningKeys) if (line.includes(startupWarningLiterals[key])) { counts[key]++; matched = true; }
        if (!matched) unmatched++;
    }
    return { read_success: readSuccess === true && typeof text === 'string', tail_truncated: truncated === true || bytes.length > 65536,
        class_counts: counts, unmatched_lines: unmatched };
}
function warningShape(value) {
    return exactKeys(value, ['read_success', 'tail_truncated', 'class_counts', 'unmatched_lines']) &&
        typeof value.read_success === 'boolean' && typeof value.tail_truncated === 'boolean' &&
        integer(value.unmatched_lines, 65536) && exactKeys(value.class_counts, warningKeys) &&
        warningKeys.every(key => integer(value.class_counts[key], 65536));
}
function readActiveStartupWarnings(root) {
    let fd;
    try {
        activeFact(activeProofSelected() && root === `/tmp/pinova-fpm-full-${process.env.GITHUB_RUN_ID}-${process.env.GITHUB_RUN_ATTEMPT}` &&
            realpathSync(root) === root && lstatSync(root).uid === 0 && realpathSync(`${root}/private`) === `${root}/private` &&
            lstatSync(`${root}/private`).uid === 0 && (lstatSync(`${root}/private`).mode & 0o077) === 0, 'startup_warning_scope');
        const file = `${root}/private/fpm-start.log`;
        activeFact(realpathSync(file) === file, 'startup_warning_scope');
        fd = openSync(file, constants.O_RDONLY | constants.O_NOFOLLOW);
        const info = fstatSync(fd);
        activeFact(info.isFile() && info.uid === 0 && (info.mode & 0o022) === 0, 'startup_warning_scope');
        const bytes = Buffer.alloc(Math.min(info.size, 65536));
        const count = readSync(fd, bytes, 0, bytes.length, Math.max(0, info.size - bytes.length));
        return projectStartupWarnings(bytes.subarray(0, count).toString('utf8'), { truncated: info.size > 65536 });
    } catch { return projectStartupWarnings('', { readSuccess: false }); }
    finally { if (fd !== undefined) { try { closeSync(fd); } catch {} } }
}
const boundKeys = ['extensions', 'inventory_sha256', 'composer_lock_sha256', 'schema', 'state', 'sapi', 'php_version', 'pid',
    'opcache_enabled', 'ini', 'jit', 'blacklist', 'cache', 'runtime_identity', 'contract_sha256', 'master_pid', 'master_start_ticks',
    'binary_sha256', 'fpm_configuration_sha256', 'responder_start_ticks', 'responder_owned_main_pool', 'source_sha256', 'attests_signaled_worker', 'limitation'];
const identityKeys = ['schema', 'application_source', 'application_tree', 'workflow_source', 'source_archive_sha256', 'inventory_sha256',
    'composer_lock_sha256', 'logging_profile', 'source_recipe', 'crashed_constructor_copy_attested'];
function boundShape(value) {
    return exactKeys(value, boundKeys) && exactKeys(value.runtime_identity, identityKeys) && validActiveIdentity(value.runtime_identity) &&
        exactKeys(value.ini, ['mode', ...activeSharedKeys]) && exactKeys(value.jit, ['enabled', 'on', 'kind', 'capacity_bytes', 'free_bytes']) &&
        exactKeys(value.extensions, ['xdebug_loaded', 'pcov_loaded']) && exactKeys(value.cache, ['creator_cached']) &&
        exactKeys(value.blacklist, ['variant', 'file_sha256', 'file_bytes', 'configured_path_sha256', 'creator_path_sha256',
            'creator_source_sha256', 'configured_path_matches_owned_file', 'entry_count', 'entry_semantics']) &&
        ['contract_sha256', 'binary_sha256', 'fpm_configuration_sha256', 'source_sha256'].every(key => sha256(value[key])) &&
        ['master_pid', 'master_start_ticks', 'responder_start_ticks'].every(key => integer(value[key], Number.MAX_SAFE_INTEGER) && value[key] > 1) &&
        value.responder_owned_main_pool === true && value.attests_signaled_worker === false &&
        value.limitation === 'post_failure_responder_may_be_replacement_not_signaled_worker';
}
/** No exception strings or arbitrary object fields leave this fixed projection. */
export function activeFailureEvidence(stage, code, warnings, bound) {
    const selectedStage = Object.hasOwn(failureCodes, stage) ? stage : 'unclassified';
    const selectedCode = failureCodes[selectedStage].includes(code) ? code : 'unclassified';
    let native = null;
    if (selectedStage === 'profile' && selectedCode !== 'unclassified' && boundShape(bound)) {
        try {
            const minor = Object.keys(activePatches).find(key => activePatches[key] === bound.php_version);
            projectActiveNativeMode(JSON.stringify(bound), minor);
            native = Object.fromEntries(boundKeys.map(key => [key, bound[key]]));
        } catch { /* A malformed shape is never promoted to bound native facts. */ }
    }
    return { state: 'unknown', reason: selectedStage === 'deadline' ? 'mode_observation_timeout' : 'mode_observation_failed',
        failure_stage: selectedStage, failure_code: selectedCode,
        startup_warnings: warningShape(warnings) ? warnings : null, bound_native: native };
}
/** Retaining an unknown witness is diagnostic only; successful-profile validation remains mandatory for acceptance. */
export function validateActiveFailureEvidence(value, options = {}) {
    try {
        if (!exactKeys(value, ['state', 'reason', 'failure_stage', 'failure_code', 'startup_warnings', 'bound_native',
            'resource_acceptance', 'shipping_fix', 'primary_receipt_sha256', 'primary_outcome_frozen', 'collection_boundary', 'attests_signaled_worker']) ||
            value.state !== 'unknown' || !Object.hasOwn(failureCodes, value.failure_stage) ||
            !failureCodes[value.failure_stage].includes(value.failure_code) ||
            value.reason !== (value.failure_stage === 'deadline' ? 'mode_observation_timeout' : 'mode_observation_failed') ||
            value.resource_acceptance !== false || value.shipping_fix !== false || value.attests_signaled_worker !== false ||
            !sha256(options.primaryReceiptHash) || value.primary_receipt_sha256 !== options.primaryReceiptHash || value.primary_outcome_frozen !== true ||
            value.collection_boundary !== 'after_primary_receipt_before_fpm_teardown' ||
            !(value.startup_warnings === null || warningShape(value.startup_warnings))) return false;
        if (value.bound_native === null) return true;
        if (value.failure_stage !== 'profile' || value.failure_code === 'unclassified' || !boundShape(value.bound_native)) return false;
        const merged = { ...value.bound_native, primary_receipt_sha256: value.primary_receipt_sha256, primary_outcome_frozen: true,
            collection_boundary: value.collection_boundary, resource_acceptance: false, shipping_fix: false };
        return validateBoundNative(merged, options) && !validateActiveProfile(merged, options);
    } catch { return false; }
}
