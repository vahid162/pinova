/** Owned FPM startup identity and post-outcome profile evidence; import performs no I/O. */
import { createHash } from 'node:crypto';
import { readFileSync, realpathSync } from 'node:fs';
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
