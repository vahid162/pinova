/** Proof-only startup identity and post-outcome JSON; import performs no I/O. */
import { createHash } from 'node:crypto';
import { lstatSync, readFileSync, realpathSync } from 'node:fs';
import { createConnection } from 'node:net';
import { inspectProcess } from './php-process-sampler.mjs';

export const MODE_OBSERVATION_TIMEOUT_MS = 3000;
const hash = value => createHash('sha256').update(value).digest('hex');
const fact = (value, code) => { if (!value) throw new Error(code); };
const integer = (value, max = 2147483647) => Number.isSafeInteger(value) && value >= 0 && value <= max;
const variants = new Set(['A', 'B']);
const sha256 = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
const fileHash = (file, limit) => {
    const info = lstatSync(file); fact(info.isFile() && info.size <= limit, 'mode_file_bound');
    const bytes = readFileSync(file); fact(bytes.length <= limit, 'mode_file_bound'); return hash(bytes);
};
const sharedKeys = ['buffer_bytes', 'enable_cli', 'optimization_level', 'hot_loop', 'hot_func', 'hot_return', 'hot_side_exit',
    'file_cache_empty', 'preload_empty'];
export const CREATOR_RELATIVE = 'runtime/vendor/nesbot/carbon/src/Carbon/Traits/Creator.php';
export const BLACKLIST_FILE = 'startup-opcache-blacklist.txt';
const endpoint = new URL('../fixtures/fpm-otp/startup-jit-mode.php', import.meta.url);
export const declaredStartupArgv = () => ['FPM', '-d', 'opcache.jit=1235', '-d', 'opcache.jit_buffer_size=256M',
    '-d', 'opcache.file_cache=', '-d', 'opcache.preload=', '-d', `opcache.blacklist_filename=RUN/${BLACKLIST_FILE}`,
    '--allow-to-run-as-root', '-F', '-y', 'RUN/fpm.conf'];

/** Only this resolved, run-owned source prefix is permitted; never a glob or runtime input. */
export function expectedBlacklistContents(root, variant, resolvedCreator) {
    fact(variants.has(variant) && /^\/tmp\/pinova-fpm-full-[0-9]+-[0-9]+$/.test(root) &&
        resolvedCreator === `${root}/${CREATOR_RELATIVE}`, 'blacklist_input_scope');
    return variant === 'B' ? `${resolvedCreator}\n` : '';
}

function inspectBlacklist(root, variant) {
    const creator = realpathSync(`${root}/${CREATOR_RELATIVE}`), file = `${root}/${BLACKLIST_FILE}`;
    const expected = expectedBlacklistContents(root, variant, creator);
    fact(realpathSync(file) === file && lstatSync(file).isFile() && lstatSync(file).size <= 1024 &&
        lstatSync(creator).isFile() && lstatSync(creator).size <= 131072, 'blacklist_file_bound');
    const bytes = readFileSync(file);
    fact(bytes.equals(Buffer.from(expected)), 'blacklist_contents_mismatch');
    return { variant, file_sha256: hash(bytes), file_bytes: bytes.length, configured_path_sha256: hash(file),
        creator_path_sha256: hash(creator), creator_source_sha256: hash(readFileSync(creator)),
        configured_path_matches_owned_file: true, entry_count: variant === 'B' ? 1 : 0,
        entry_semantics: 'absolute_resolved_creator_prefix' };
}

export function parseJitMode(text, variant) {
    fact(typeof text === 'string' && Buffer.byteLength(text) <= 4096 && variants.has(variant), 'mode_body_bound');
    const value = JSON.parse(text);
    fact(value?.schema === 'pinova.postwork-creator-blacklist.v1' && value.state === 'observed' &&
        value.sapi === 'fpm-fcgi' && value.php_version === '8.4.27' &&
        integer(value.pid) && value.pid > 1 && value.opcache_enabled === true, 'mode_shape');
    const ini = value.ini, jit = value.jit;
    fact(ini?.mode === '1235' && ini.buffer_bytes === 268435456 && ini.file_cache_empty === true && ini.preload_empty === true &&
        typeof ini.enable_cli === 'boolean' && integer(ini.optimization_level, 4294967295) &&
        ['hot_loop', 'hot_func', 'hot_return', 'hot_side_exit'].every(key => integer(ini[key], 65535)), 'mode_ini_shape');
    fact(typeof jit?.enabled === 'boolean' && typeof jit.on === 'boolean' && integer(jit.kind, 5) &&
        integer(jit.capacity_bytes, 268435456) && integer(jit.free_bytes, jit.capacity_bytes), 'mode_jit_shape');
    fact(jit.enabled && jit.on && jit.kind === 3 && jit.capacity_bytes > 0, 'mode_active_mismatch');
    const blacklist = value.blacklist;
    fact(blacklist?.variant === variant && blacklist.configured_path_matches_owned_file === true &&
        blacklist.entry_semantics === 'absolute_resolved_creator_prefix' && blacklist.entry_count === (variant === 'B' ? 1 : 0) &&
        integer(blacklist.file_bytes, 1024) && (variant === 'A' ? blacklist.file_bytes === 0 : blacklist.file_bytes > 0) &&
        ['file_sha256', 'configured_path_sha256', 'creator_path_sha256', 'creator_source_sha256'].every(key => sha256(blacklist[key])),
    'blacklist_shape');
    fact(typeof value.cache?.creator_cached === 'boolean', 'cache_shape');
    return { schema: value.schema, state: 'observed', sapi: value.sapi, php_version: value.php_version,
        pid: value.pid, opcache_enabled: true, ini: { mode: ini.mode, ...Object.fromEntries(sharedKeys.map(key => [key, ini[key]])) },
        jit: { enabled: jit.enabled, on: jit.on, kind: jit.kind, capacity_bytes: jit.capacity_bytes, free_bytes: jit.free_bytes },
        blacklist: Object.fromEntries(['variant', 'file_sha256', 'file_bytes', 'configured_path_sha256', 'creator_path_sha256',
            'creator_source_sha256', 'configured_path_matches_owned_file', 'entry_count', 'entry_semantics'].map(key => [key, blacklist[key]])),
        cache: { creator_cached: value.cache.creator_cached } };
}

export function captureStartupLaunch({ root, master, tick, binary, variant }) {
    fact(variants.has(variant) && /^\/tmp\/pinova-fpm-full-[0-9]+-[0-9]+$/.test(root), 'startup_scope');
    const blacklist = inspectBlacklist(root, variant);
    const before = inspectProcess(master, tick);
    const executable = realpathSync(`/proc/${master}/exe`), configured = realpathSync(binary);
    const command = readFileSync(`/proc/${master}/cmdline`);
    fact(executable === configured && command.length <= 4096 && command.includes(Buffer.from(`${root}/fpm.conf`)), 'startup_process');
    const binaryHash = fileHash(executable, 64 * 1024 * 1024);
    const concrete = [binary, '-d', 'opcache.jit=1235', '-d', 'opcache.jit_buffer_size=256M',
        '-d', 'opcache.file_cache=', '-d', 'opcache.preload=', '-d', `opcache.blacklist_filename=${root}/${BLACKLIST_FILE}`,
        '--allow-to-run-as-root', '-F', '-y', `${root}/fpm.conf`];
    const after = inspectProcess(master, tick);
    fact(before.start === after.start, 'startup_identity_changed');
    return { master_pid: master, master_start_ticks: after.start, binary_sha256: binaryHash,
        proc_cmdline_sha256: hash(command), owned_configuration_in_cmdline: true,
        declared_mode: '1235', configured_buffer_bytes: 268435456, blacklist,
        declared_file_cache_empty: true, declared_preload_empty: true,
        fpm_configuration_sha256: fileHash(`${root}/fpm.conf`, 32768), mode_endpoint_sha256: fileHash(endpoint, 16384),
        declared_argv: declaredStartupArgv(),
        concrete_launch_argv_sha256: hash(Buffer.from(concrete.join('\0') + '\0')),
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

export async function readPostworkJit({ root, master, tick, variant, startupLaunch, signal }) {
    const script = `${root}/startup-jit-mode.php`;
    const source = new URL('../fixtures/fpm-otp/startup-jit-mode.php', import.meta.url);
    fact(fileHash(script, 16384) === fileHash(source, 16384), 'mode_source_changed');
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
    const value = parseJitMode(text, variant);
    const responder = inspectProcess(value.pid, tick), observedMaster = inspectProcess(master, tick);
    const children = readFileSync(`/proc/${master}/task/${master}/children`, 'utf8').trim().split(/\s+/).filter(Boolean).map(Number);
    fact(children.length <= 64 && children.includes(value.pid) && responder.pool === 'fixture', 'mode_responder_unowned');
    const binaryHash = fileHash(realpathSync(`/proc/${master}/exe`), 64 * 1024 * 1024);
    const blacklist = inspectBlacklist(root, variant), configurationHash = fileHash(`${root}/fpm.conf`, 32768);
    fact(startupLaunch?.master_pid === master && startupLaunch.master_start_ticks === observedMaster.start &&
        startupLaunch.binary_sha256 === binaryHash && startupLaunch.fpm_configuration_sha256 === configurationHash &&
        JSON.stringify(startupLaunch.blacklist) === JSON.stringify(blacklist) &&
        JSON.stringify(value.blacklist) === JSON.stringify(blacklist), 'postwork_binding_changed');
    return { ...value, master_pid: master, master_start_ticks: observedMaster.start, binary_sha256: binaryHash,
        fpm_configuration_sha256: configurationHash, responder_start_ticks: responder.start, responder_owned_main_pool: true,
        source_sha256: fileHash(source, 16384), attests_signaled_worker: false,
        limitation: 'post_failure_responder_may_be_replacement_not_signaled_worker' };
}

/** Separate acceptance evidence only. A mismatch never rewrites the primary workload outcome. */
export function validatePostworkBinding(value, { variant, primaryReceiptHash, startupLaunch } = {}) {
    try {
        const parsed = parseJitMode(JSON.stringify(value), variant);
        return sha256(primaryReceiptHash) && value.primary_receipt_sha256 === primaryReceiptHash && value.primary_outcome_frozen === true &&
            value.collection_boundary === 'after_primary_receipt_before_fpm_teardown' && value.attests_signaled_worker === false &&
            value.resource_acceptance === false && value.shipping_fix === false && value.responder_owned_main_pool === true &&
            integer(value.responder_start_ticks, Number.MAX_SAFE_INTEGER) && value.responder_start_ticks > 0 &&
            startupLaunch?.owned_configuration_in_cmdline === true && startupLaunch.flags_recoverable_from_proc_title === false &&
            startupLaunch.binding === 'pinned_launcher_source_and_binary_not_recovered_flags' &&
            startupLaunch.declared_mode === '1235' && startupLaunch.configured_buffer_bytes === 268435456 &&
            startupLaunch.declared_file_cache_empty === true && startupLaunch.declared_preload_empty === true &&
            JSON.stringify(startupLaunch.declared_argv) === JSON.stringify(declaredStartupArgv()) &&
            integer(startupLaunch.master_pid) && startupLaunch.master_pid > 1 && value.master_pid === startupLaunch.master_pid &&
            integer(startupLaunch.master_start_ticks, Number.MAX_SAFE_INTEGER) && startupLaunch.master_start_ticks > 0 &&
            value.master_start_ticks === startupLaunch.master_start_ticks &&
            ['binary_sha256', 'fpm_configuration_sha256'].every(key => sha256(startupLaunch[key]) && value[key] === startupLaunch[key]) &&
            sha256(startupLaunch.mode_endpoint_sha256) && value.source_sha256 === startupLaunch.mode_endpoint_sha256 &&
            sha256(startupLaunch.proc_cmdline_sha256) && sha256(startupLaunch.concrete_launch_argv_sha256) &&
            JSON.stringify(parsed.blacklist) === JSON.stringify(startupLaunch.blacklist);
    } catch { return false; }
}

/** Retained native evidence is separate from functional counts; no observation or primary mutation here. */
export function classifyStartupArm(row, expectedCompleted) {
    const functional = row?.state === 'passed' && row.cleanup_status === 'complete' && row.diagnostic?.state === 'completed' &&
        expectedCompleted && Object.entries(expectedCompleted).every(([key, value]) => row.diagnostic[key] === value);
    const evidence = row?.diagnostic_evidence, native = evidence?.fpm_termination, markers = evidence?.markers;
    const nativeShape = Array.isArray(native?.events) && native.events.length <= 16 && native.events.every(event =>
        event && ['fixture', 'fixture_status'].includes(event.pool) && integer(event.pid) && event.pid > 1 &&
        ((event.event === 'signal_exit' && integer(event.signal_number, 64) && event.signal_number > 0) ||
         (event.event === 'normal_exit' && integer(event.exit_code, 255)) ||
         (event.event === 'request_timeout' && typeof event.timeout_seconds === 'number' &&
            Number.isFinite(event.timeout_seconds) && event.timeout_seconds >= 0 && event.timeout_seconds <= 3600000))));
    const nativeComplete = evidence?.collection_boundary === 'controller_before_fpm_teardown' &&
        native?.read_success === true && native.tail_truncated === false && native.event_bound === false &&
        integer(native.invalid_records, 65536) && nativeShape;
    const fault = nativeShape && native.events.some(event => event.event === 'signal_exit' || event.event === 'request_timeout' ||
        (event.event === 'normal_exit' && event.exit_code !== 0));
    const markersComplete = markers?.read_success === true && markers.tail_truncated === false && markers.event_bound === false &&
        markers.invalid_records === 0 && Array.isArray(markers.events) && markers.events.length <= 256 &&
        markers.events.every(marker => marker && integer(marker.pid) && marker.pid > 1 && typeof marker.event === 'string' && typeof marker.case === 'string');
    const matchedSignal11 = row?.failure_phase === 'revision_warmup' && nativeComplete && markersComplete &&
        native.events.some(event => event.event === 'signal_exit' && event.signal_number === 11 && event.pool === 'fixture' &&
            markers.events.some(marker => marker.pid === event.pid && marker.event === 'request_start' &&
                /^revision-warm-(?:[1-9]|10)(?:-verify)?$/.test(marker.case)));
    const cleanCompletion = Boolean(functional && nativeComplete && !fault);
    return { functional_completed: Boolean(functional), native_tail_complete: Boolean(nativeComplete),
        native_fault_observed: Boolean(fault), no_retained_native_fault_completion: cleanCompletion,
        matched_warmup_signal11: Boolean(matchedSignal11), classified: cleanCompletion || Boolean(matchedSignal11),
        native_unparsed_records: integer(native?.invalid_records, 65536) ? native.invalid_records : null,
        limitation: 'native_parser_unmatched_lines_include_startup_messages_not_complete_semantic_log_assurance' };
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
        collection_boundary: 'after_primary_receipt_before_fpm_teardown', attests_signaled_worker: false, resource_acceptance: false, shipping_fix: false };
    try { writeReceipt('postwork-jit-mode.json', evidence); } catch { /* Missing sidecar stays unknown to the parent. */ }
    return evidence;
}
