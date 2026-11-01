/** Private diagnostic only. Import performs no I/O; never retains response content, URLs or raw header values. */
import { constants, openSync, closeSync, fstatSync, readSync } from 'node:fs';
const LIMITS = { hops: 80, callbacks: 4096, logBytes: 65536, nginxRows: 80, clockMs: 5, nginxDecimalAllowanceMs: 3 };
const labels = ['off', 'on'].flatMap(mode => Array.from({ length: 10 }, (_, i) => `${mode}-normal-${i + 1}-page`));
const caseId = label => { const i = labels.indexOf(label); return i < 0 ? null : `c${String(i + 1).padStart(2, '0')}`; };
const finite = x => typeof x === 'number' && Number.isFinite(x) && x >= 0;
const overlap = (a, b, c, d) => Math.max(0, Math.min(b, d) - Math.max(a, c));

export function proofSerialization(name, value) {
    return name === 'frozen-resource-gates.json' ? { ...value,
        proof_metadata: { resource_acceptance: false, purpose: 'sampler_gateway_attribution_only' } } : value;
}

/** Call only after work; unsafe targets and unstable/oversized files are unknown. */
export function readNginxTiming(file, io = { constants, openSync, closeSync, fstatSync, readSync }) {
    let fd;
    try {
        for (const flag of ['O_RDONLY', 'O_NOFOLLOW', 'O_NONBLOCK']) if (!Number.isInteger(io.constants[flag])) return null;
        fd = io.openSync(file, io.constants.O_RDONLY | io.constants.O_NOFOLLOW | io.constants.O_NONBLOCK);
        const before = io.fstatSync(fd);
        if (!before.isFile() || !Number.isSafeInteger(before.size) || before.size < 0 || before.size > LIMITS.logBytes) return null;
        const bytes = Buffer.alloc(before.size + 1);
        const count = io.readSync(fd, bytes, 0, bytes.length, 0);
        const after = io.fstatSync(fd);
        if (!after.isFile() || count !== before.size || ['dev', 'ino', 'size', 'mtimeMs', 'ctimeMs'].some(key => before[key] !== after[key])) return null;
        return bytes.subarray(0, count).toString('utf8');
    } catch { return null; }
    finally { if (fd !== undefined) { try { io.closeSync(fd); } catch { /* Observation only. */ } } }
}

export function parseNginxTiming(text) {
    const reasons = new Set();
    const rows = [];
    if (typeof text !== 'string' || text.length > LIMITS.logBytes) return { rows, reasons: ['nginx_read_or_byte_bound'] };
    const lines = text.trim() ? text.trim().split('\n') : [];
    if (!lines.length || lines.length > LIMITS.nginxRows) return { rows, reasons: ['nginx_missing_or_row_bound'] };
    const time = value => typeof value === 'string' && /^(?:0|[1-9][0-9]{0,10})\.[0-9]{3}$/.test(value) ? Number(value) : NaN;
    for (const line of lines) {
        try {
            const row = JSON.parse(line);
            if (Object.keys(row).sort().join(',') !== 'body_bytes,bytes,case_id,msec,request_time,status,upstream_response_time' ||
                !/^c(?:0[1-9]|1[0-9]|20)$/.test(row.case_id) ||
                !Number.isInteger(row.status) || row.status < 100 || row.status > 599 ||
                !Number.isSafeInteger(row.bytes) || row.bytes < 1 || !Number.isSafeInteger(row.body_bytes) ||
                row.body_bytes < 0 || row.body_bytes > row.bytes) throw new Error();
            const end = time(row.msec) * 1000;
            const duration = time(row.request_time) * 1000;
            const upstream = time(row.upstream_response_time) * 1000;
            if (![end, duration, upstream].every(finite) || duration > 25000 || upstream > duration + LIMITS.nginxDecimalAllowanceMs || end < duration) throw new Error();
            rows.push({ case_id: row.case_id, logged_epoch_ms: end, request_ms: duration,
                upstream_ms: upstream, status: row.status, bytes: row.bytes, body_bytes: row.body_bytes });
        } catch { reasons.add('nginx_record_or_upstream_timing_unknown'); }
    }
    return { rows, reasons: [...reasons] };
}

export function createSamplerGatewayTiming() {
    const hops = [], callbacks = [], stamps = [];
    const failures = new Set();
    const stamp = (mono, epoch) => {
        if (!finite(mono) || !finite(epoch)) { failures.add('clock_unknown'); return false; }
        if (stamps.length && (mono < stamps.at(-1).mono || epoch < stamps.at(-1).epoch)) failures.add('clock_reversed');
        // Store only a finite offset envelope, not an unbounded clock history.
        const offset = epoch - mono;
        if (!stamps.length) stamps.push({ mono, epoch, min: offset, max: offset });
        else { const state = stamps[0]; state.min = Math.min(state.min, offset); state.max = Math.max(state.max, offset); state.mono = mono; state.epoch = epoch; }
        if (stamps[0].max - stamps[0].min > LIMITS.clockMs) failures.add('cross_clock_discontinuity');
        return true;
    };
    return {
        clientStart(label, hop, mono, epoch) {
            const id = caseId(label);
            if (!id) return null;
            if (hops.length >= LIMITS.hops || !Number.isInteger(hop) || hop < 0 || hop > 3) { failures.add('client_hop_bound'); return null; }
            if (!stamp(mono, epoch)) return null;
            const row = { case_id: id, hop, start_mono_ms: mono, start_epoch_ms: epoch,
                headers_mono_ms: null, end_mono_ms: null, status: null };
            hops.push(row); return row;
        },
        clientHeaders(row, mono, epoch, status) {
            if (!row) return;
            if (!stamp(mono, epoch) || mono < row.start_mono_ms || !Number.isInteger(status) || status < 100 || status > 599) {
                failures.add('client_headers_unknown'); return;
            }
            row.headers_mono_ms = mono; row.status = status;
        },
        clientEnd(row, mono, epoch) {
            if (!row) return;
            if (!stamp(mono, epoch) || row.headers_mono_ms === null || mono < row.headers_mono_ms) {
                failures.add('client_end_unknown'); return;
            }
            row.end_mono_ms = mono;
        },
        samplerStart(phase, mono, epoch) {
            if (!['off_normal', 'on_normal'].includes(phase)) return null;
            if (callbacks.length >= LIMITS.callbacks) { failures.add('sampler_callback_bound'); return null; }
            if (!stamp(mono, epoch)) return null;
            const row = { phase, start_mono_ms: mono, end_mono_ms: null };
            callbacks.push(row); return row;
        },
        samplerEnd(row, mono, epoch) {
            if (!row) return;
            if (!stamp(mono, epoch) || mono < row.start_mono_ms) { failures.add('sampler_end_unknown'); return; }
            row.end_mono_ms = mono;
        },
        snapshot(nginxInput) {
            const reasons = new Set(failures);
            const nginx = parseNginxTiming(nginxInput);
            nginx.reasons.forEach(reason => reasons.add(reason));
            const samples = [];
            if (callbacks.some(row => row.end_mono_ms === null)) reasons.add('sampler_end_missing');
            for (let i = 1; i < callbacks.length; i++) if (callbacks[i].start_mono_ms < callbacks[i - 1].end_mono_ms) reasons.add('sampler_interval_ambiguous');
            for (const mode of ['off', 'on']) if (!callbacks.some(row => row.phase === `${mode}_normal` && row.end_mono_ms !== null)) reasons.add('sampler_phase_missing');
            let completeClients = 0;
            for (let i = 0; i < labels.length; i++) {
                const id = caseId(labels[i]);
                const client = hops.filter(row => row.case_id === id).sort((a, b) => a.hop - b.hop);
                const server = nginx.rows.filter(row => row.case_id === id).sort((a, b) => a.logged_epoch_ms - b.logged_epoch_ms);
                if (!client.length || client.some((row, n) => row.hop !== n || row.end_mono_ms === null || row.status === null ||
                    (n < client.length - 1 && ![301, 302, 303, 307, 308].includes(row.status)) ||
                    (n && row.start_mono_ms < client[n - 1].end_mono_ms)) || client.at(-1).status !== 200) {
                    reasons.add('client_logical_request_incomplete'); continue;
                }
                completeClients++;
                if (server.length !== client.length || server.some((row, n) => n && row.logged_epoch_ms - server[n - 1].logged_epoch_ms <= 2 * LIMITS.nginxDecimalAllowanceMs)) {
                    reasons.add('nginx_redirect_pairing_unknown'); continue;
                }
                for (let n = 0; n < client.length; n++) {
                    const c = client[n], s = server[n];
                    const clientEndEpoch = c.start_epoch_ms + c.end_mono_ms - c.start_mono_ms;
                    const estimatedServerStart = s.logged_epoch_ms - s.request_ms;
                    if (s.status !== c.status || estimatedServerStart < c.start_epoch_ms - LIMITS.nginxDecimalAllowanceMs ||
                        s.logged_epoch_ms > clientEndEpoch + LIMITS.nginxDecimalAllowanceMs) {
                        reasons.add('nginx_clock_or_hop_match_unknown'); continue;
                    }
                    // The min clock offset covers accepted downward drift. Nginx cached time
                    // freshness remains unknown, so this projection is an estimate, not a finish bound.
                    const estimatedLogMono = s.logged_epoch_ms + LIMITS.nginxDecimalAllowanceMs - stamps[0].min;
                    const phaseCallbacks = callbacks.filter(row => row.phase === `${i < 10 ? 'off' : 'on'}_normal` && row.end_mono_ms !== null);
                    samples.push({ case_id: id, hop: n, status: c.status,
                        client_ms: c.end_mono_ms - c.start_mono_ms,
                        headers_to_end_ms: c.end_mono_ms - c.headers_mono_ms,
                        nginx_request_ms: s.request_ms, nginx_upstream_ms: s.upstream_ms,
                        nginx_logged_epoch_ms: s.logged_epoch_ms, bytes: s.bytes, body_bytes: s.body_bytes,
                        estimated_nginx_log_to_callback_gap_ms: Math.max(0, c.end_mono_ms - estimatedLogMono),
                        sampler_overlap_client_ms: phaseCallbacks.reduce((sum, row) => sum + overlap(c.start_mono_ms, c.end_mono_ms, row.start_mono_ms, row.end_mono_ms), 0),
                        estimated_sampler_overlap_after_nginx_log_ms: phaseCallbacks.reduce((sum, row) => sum + overlap(estimatedLogMono, c.end_mono_ms, row.start_mono_ms, row.end_mono_ms), 0) });
                }
            }
            const known = reasons.size === 0 && completeClients === 20 && samples.length === hops.length;
            return { schema: 'pinova.sampler-gateway-proof.v1', resource_acceptance: false,
                state: known ? 'observed' : 'partial_or_unknown', reasons: [...reasons].sort(),
                expected_logical_requests: 20, complete_client_requests: completeClients,
                matched_physical_hops: samples.length, nginx_rows: nginx.rows.length,
                clock_offset_span_ms: stamps.length ? stamps[0].max - stamps[0].min : null,
                clock_offset_envelope_ms: stamps.length ? { min: stamps[0].min, max: stamps[0].max } : null,
                cross_process_state: 'estimated_cached_timestamp_freshness_unknown',
                nginx_timestamp_freshness: 'unknown_cached_ngx_timeofday',
                monotonic_timing_state: failures.size === 0 && completeClients === 20 && callbacks.every(row => row.end_mono_ms !== null) &&
                    ['off_normal', 'on_normal'].every(phase => callbacks.some(row => row.phase === phase)) ? 'observed_within_Node' : 'partial_or_unknown',
                limits: { ...LIMITS }, client_hops: hops.map(row => ({ ...row })),
                sampler_callbacks: callbacks.map(row => ({ ...row })), samples: known ? samples : [],
                attribution: 'Unknown: overlap is not causation; cached Nginx log time does not bound sending completion or packet arrival. Cross-process gaps are estimates. Logging and timing have observation cost.',
                interval_units: 'monotonic_milliseconds; Nginx_cached_epoch_milliseconds_with_3ms_decimal_allowance_not_freshness_bound' };
        },
    };
}
