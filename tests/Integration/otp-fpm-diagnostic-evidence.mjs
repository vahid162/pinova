/** Finite observations for disposable diagnostic probes; import performs no I/O. */
import { constants, openSync, closeSync, fstatSync, readSync, realpathSync } from 'node:fs';
const MAX_TEXT = 65536;
export const DIAGNOSTIC_CASES = Object.freeze([
    { id: '83-normal-baseline', php: '8.3', logging: 'normal', arm: 'a1' },
    { id: '84-normal-candidate', php: '8.4', logging: 'normal', arm: 'b1' },
    { id: '84-diagnostic-candidate', php: '8.4', logging: 'diagnostic', arm: 'b2' },
    { id: '83-diagnostic-control', php: '8.3', logging: 'diagnostic', arm: 'b1' },
]);
const PID_MAX = 2147483647;
const phases = new Set(['shell_prepare', 'shell_database', 'shell_config', 'shell_install', 'shell_activate', 'shell_setup',
    'native_start', 'controller_start', 'readiness', 'ordinary_warmup', 'headroom_wait', 'revision_warmup', 'warm_issue',
    'warm_response', 'warm_runner', 'warm_acceptance', 'warm_verify', 'warm_stabilize', 'revision_measurement',
    'measurement_issue', 'measurement_runner', 'measurement_acceptance', 'probe_completed', 'controller_failed', 'cleanup']);
const signals = { 1: 'SIGHUP', 2: 'SIGINT', 3: 'SIGQUIT', 4: 'SIGILL', 5: 'SIGTRAP', 6: 'SIGABRT', 7: 'SIGBUS',
    8: 'SIGFPE', 9: 'SIGKILL', 11: 'SIGSEGV', 13: 'SIGPIPE', 14: 'SIGALRM', 15: 'SIGTERM', 17: 'SIGCHLD',
    18: 'SIGCONT', 19: 'SIGSTOP', 20: 'SIGTSTP', 31: 'SIGSYS' };
const integer = (value, min, max) => Number.isSafeInteger(value) && value >= min && value <= max ? value : null;
const duration = value => typeof value === 'number' && Number.isFinite(value) && value >= 0 && value <= 3600 ? value : null;
function boundedText(text, truncated) {
    if (typeof text !== 'string') return { text: '', truncated: true, valid: false };
    const bytes = Buffer.from(text);
    return { text: bytes.subarray(Math.max(0, bytes.length - MAX_TEXT)).toString('utf8'),
        truncated: Boolean(truncated) || bytes.length > MAX_TEXT, valid: true };
}
export function executionEvidence(result, elapsedMs) {
    const signal = result?.signal;
    const error = result?.error?.code;
    const bytes = value => typeof value === 'string' ? Math.min(16777216, Buffer.byteLength(value)) : null;
    return { exit_code: integer(result?.status, 0, 255),
        signal: signal == null ? null : Object.values(signals).includes(signal) ? signal : 'unknown',
        error_code: error == null ? null : ['ETIMEDOUT', 'ENOBUFS', 'ENOENT', 'EACCES'].includes(error) ? error : 'OTHER',
        elapsed_ms: typeof elapsedMs === 'number' && Number.isFinite(elapsedMs) && elapsedMs >= 0 && elapsedMs <= 3600000 ? Math.round(elapsedMs) : null,
        timeout_ms: 180000, stdout_bytes: bytes(result?.stdout), stderr_bytes: bytes(result?.stderr) };
}
export function sanitizeCheckpoint(value) {
    const v = value && typeof value === 'object' && !Array.isArray(value) ? value : {};
    return { phase: phases.has(v.phase) ? v.phase : 'unknown',
        at_ms: integer(v.at_ms, Date.UTC(2020, 0, 1), Date.UTC(2100, 0, 1) - 1),
        elapsed_ms: integer(v.elapsed_ms, 0, 3600000), arm: typeof v.arm === 'string' && /^[ab][123]$/.test(v.arm) ? v.arm : null,
        flow_index: integer(v.flow_index, 1, 10), pid: integer(v.pid, 2, PID_MAX), resource_acceptance: false };
}
export function parseFpmTerminationTail(text, { truncated = false } = {}) {
    const input = boundedText(text, truncated);
    const events = [];
    let invalid = 0;
    for (const line of input.text.split('\n')) {
        if (!line.trim()) continue;
        if (Buffer.byteLength(line) > 8192) { invalid++; continue; }
        const prefix = line.match(/^\[\d{2}-[A-Za-z]{3}-20\d{2} \d{2}:\d{2}:\d{2}\] (?:WARNING|NOTICE|ERROR): \[pool ([^\]\r\n]{1,80})\] child (\d{1,10})\b/);
        const pid = prefix ? integer(Number(prefix[2]), 2, PID_MAX) : null;
        if (!pid) { invalid++; continue; }
        const base = { pid, pool: ['fixture', 'fixture_status'].includes(prefix[1]) ? prefix[1] : 'unknown' };
        const timeout = line.match(/execution timed out \(([0-9]+(?:\.[0-9]+)?) sec\), terminating\s*$/);
        const signal = line.match(/exited on signal ([0-9]{1,2}) \([^\)\r\n]{1,64}\) after ([0-9]+(?:\.[0-9]+)?) seconds from start\s*$/);
        const exit = line.match(/exited with code ([0-9]{1,3}) after ([0-9]+(?:\.[0-9]+)?) seconds from start\s*$/);
        if (timeout && duration(Number(timeout[1])) !== null) events.push({ ...base, event: 'request_timeout', timeout_seconds: Number(timeout[1]) });
        else if (signal && integer(Number(signal[1]), 1, 64) && duration(Number(signal[2])) !== null) events.push({ ...base,
            event: 'signal_exit', signal_number: Number(signal[1]), signal_name: signals[Number(signal[1])] || 'unknown', lifetime_seconds: Number(signal[2]) });
        else if (exit && integer(Number(exit[1]), 0, 255) !== null && duration(Number(exit[2])) !== null) events.push({ ...base,
            event: 'normal_exit', exit_code: Number(exit[1]), lifetime_seconds: Number(exit[2]) });
        else invalid++;
    }
    return { read_success: input.valid, tail_truncated: input.truncated, event_bound: events.length > 16,
        invalid_records: invalid, events: events.slice(-16) };
}
export function finiteMarkers(text, { truncated = false } = {}) {
    const input = boundedText(text, truncated);
    const known = new Set(['request_start', 'early_shutdown', 'finished', 'account_lookup', 'provider_enter', 'provider_return',
        'blocked_http', 'blocked_mail', 'cli_ready', 'cli_command']);
    const events = [];
    let invalid = 0;
    for (const line of input.text.split('\n')) {
        if (!line.trim()) continue;
        let row;
        try { if (Buffer.byteLength(line) > 8192) throw new Error(); row = JSON.parse(line); } catch { invalid++; continue; }
        if (!row || !known.has(row.event) || integer(row.pid, 2, PID_MAX) === null ||
            typeof row.at !== 'number' || !Number.isFinite(row.at) || row.at < 1577836800 || row.at >= 4102444800) { invalid++; continue; }
        const label = typeof row.case === 'string' && /^(?:setup|warm|observation|status|revision-warm-(?:page|settle|worker-(?:[1-9]|10)|(?:[1-9]|10)(?:-wrong|-verify)?))$/.test(row.case) ? row.case : 'unknown';
        events.push({ case: label, event: row.event, pid: row.pid, at: row.at });
    }
    return { read_success: input.valid, tail_truncated: input.truncated, event_bound: events.length > 64,
        invalid_records: invalid, events: events.slice(-64) };
}

export function readDiagnosticEvidence(root) {
    const read = (relative, limit = MAX_TEXT) => {
        let fd;
        try {
            const expected = `/tmp/pinova-fpm-full-${process.env.GITHUB_RUN_ID}-${process.env.GITHUB_RUN_ATTEMPT}`;
            if (process.env.GITHUB_ACTIONS !== 'true' || process.env.RUNNER_ENVIRONMENT !== 'github-hosted' ||
                !/^\d+$/.test(process.env.GITHUB_RUN_ID || '') || !/^\d+$/.test(process.env.GITHUB_RUN_ATTEMPT || '') ||
                root !== expected || realpathSync(root) !== root || realpathSync(`${root}/${relative}`) !== `${root}/${relative}`) throw new Error();
            fd = openSync(`${root}/${relative}`, constants.O_RDONLY | constants.O_NOFOLLOW);
            const info = fstatSync(fd);
            if (!info.isFile()) throw new Error();
            const bytes = Buffer.alloc(Math.min(limit, info.size));
            const count = readSync(fd, bytes, 0, bytes.length, Math.max(0, info.size - bytes.length));
            return { text: bytes.subarray(0, count).toString('utf8'), read_success: true, truncated: info.size > limit };
        } catch { return { text: '', read_success: false, truncated: false }; }
        finally { if (fd !== undefined) { try { closeSync(fd); } catch {} } }
    };
    const checkpoint = read('phase-checkpoint.json', 2048);
    let value;
    try { value = checkpoint.truncated ? null : JSON.parse(checkpoint.text); } catch { value = null; }
    const fpm = read('private/fpm.log');
    const markers = read('events.jsonl');
    return { checkpoint: sanitizeCheckpoint(value), checkpoint_read_success: checkpoint.read_success && value !== null && typeof value === 'object' && !Array.isArray(value),
        checkpoint_truncated: checkpoint.truncated,
        fpm_termination: { ...parseFpmTerminationTail(fpm.text, { truncated: fpm.truncated }), read_success: fpm.read_success },
        markers: { ...finiteMarkers(markers.text, { truncated: markers.truncated }), read_success: markers.read_success } };
}
