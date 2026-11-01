/** Finite FPM facts; file-link identity is an injected capability, never process memory. */
export function completedWarmup(row) {
    return row.state === 'passed' && row.cleanup_status === 'complete' && row.diagnostic?.state === 'completed' &&
        row.diagnostic?.warm_flows_completed === 10 && row.diagnostic?.runner_opportunities === 10 &&
        row.diagnostic?.verification_workers === 10 && row.diagnostic?.provider_attempts_observed === 10;
}
export function requestMatchedSignal11(row) {
    const e = row.diagnostic_evidence;
    const phase = e?.checkpoint?.phase;
    if (row.failure_phase !== 'revision_warmup' || !['warm_issue', 'warm_acceptance', 'warm_verify'].includes(phase) ||
        e.collection_boundary !== 'controller_before_fpm_teardown' || e.fpm_termination?.read_success !== true || e.markers?.read_success !== true) return false;
    return (e.fpm_termination.events || []).some(event => {
        if (event.event !== 'signal_exit' || event.signal_number !== 11 || event.pool !== 'fixture' || !Number.isSafeInteger(event.pid) || event.pid < 2) return false;
        // A reused worker's earlier OTP request cannot explain a later unrelated crash.
        const marker = (e.markers.events || []).findLast(marker => marker.event === 'request_start' && marker.pid === event.pid);
        return !!marker && (phase === 'warm_verify' ? /^revision-warm-(?:[1-9]|10)-(?:wrong|verify)$/.test(marker.case) :
            Number.isSafeInteger(e.checkpoint.flow_index) && e.checkpoint.flow_index >= 1 && e.checkpoint.flow_index <= 10 &&
            marker.case === `revision-warm-${e.checkpoint.flow_index}`);
    });
}
export function interpretJitModeComparison(o) {
    if (o.known_B_native_failure === true) return 'tracing_insufficient_for_this_workload_mechanism_unknown';
    if (o.comparable !== true || o.all_arms_classified !== true) return 'inconclusive_unrelated_or_incomplete_workload';
    if (!o.A_signal11_arms.length) return 'inconclusive_control_did_not_reproduce';
    if (o.B_signal11_arms.length) return 'tracing_insufficient_for_this_workload_mechanism_unknown';
    if (o.completed_B === 3) return 'mode_associated_stability_mechanism_unproven';
    return 'inconclusive_incomplete_tracing_workload';
}

export class FpmSettingsObservation {
    constructor(expected, executableWitness = null) {
        this.expected = { ...expected };
        this.executableWitness = executableWitness;
        this.rows = new Map();
        this.metadataUnknown = false;
        this.fence = null;
        this.nonPhp = [];
        this.nonPhpTruncated = false;
    }
    known(row) {
        return row?.php_version === '8.4.27' && row.selected_engine_executable === true && row.nts === true && row.debug === false && row.zend_only_opcache === true && row.stock_ini === true && row.sapi === 'fpm-fcgi' && row.jit?.enabled === true && row.jit?.on === true &&
            row.jit?.kind === Number(this.expected['opcache.jit'][2]) && row.jit?.opt_level === 5 && row.jit?.opt_flags === 6 &&
            Number.isSafeInteger(row.jit?.buffer_size) && Math.abs(row.jit.buffer_size - 268435456) <= 4096 &&
            Number.isSafeInteger(row.jit?.buffer_free) && row.jit.buffer_free > 0 && row.jit.buffer_free <= row.jit.buffer_size && Object.entries(this.expected)
            .every(([key, value]) => row.ini[key]?.type === 'string' && row.ini[key]?.value === value);
    }
    observe(headers, status, phase) {
        const raw = headers['x-pinova-fixture-fpm-settings'];
        const version = headers['x-pinova-fixture-php'];
        const selected = typeof version === 'string' && /^8\.4\.[0-9]{1,3}$/.test(version);
        // A gateway response says nothing about FPM settings. Preserve the
        // pre-verification witness, without assigning settings to a new worker.
        if (status === 502 && !selected && raw === undefined) {
            if (this.nonPhp.length < 24) this.nonPhp.push({ status: 502,
                phase: ['readiness', 'warmup', 'revision_warmup'].includes(phase) ? phase : 'other',
                selected_php: false, configuration: 'not_observed' });
            else this.nonPhpTruncated = true;
            return;
        }
        try {
            if (!selected || typeof raw !== 'string' || raw.length > 2048) throw new Error();
            const row = JSON.parse(raw);
            if (!Number.isSafeInteger(row.pid) || row.pid < 2 || row.pid > 2147483647 ||
                (row.php_version !== null && row.php_version !== version) ||
                ![null, 'fpm-fcgi'].includes(row.sapi) || !row.ini ||
                JSON.stringify(Object.keys(row.ini)) !== JSON.stringify(Object.keys(this.expected))) throw new Error();
            const ini = {};
            for (const [key, value] of Object.entries(this.expected)) {
                const fact = row.ini[key];
                if (!fact || !['string', 'boolean'].includes(fact.type) || ![null, value].includes(fact.value)) throw new Error();
                ini[key] = { type: fact.type, value: fact.value };
            }
            if (!row.jit || JSON.stringify(Object.keys(row.jit)) !== JSON.stringify(['enabled','on','kind','opt_level','opt_flags','buffer_size','buffer_free'])) throw new Error();
            const jit = {};
            for (const key of ['enabled','on']) { if (row.jit[key] !== null && typeof row.jit[key] !== 'boolean') throw new Error(); jit[key] = row.jit[key]; }
            for (const key of ['kind','opt_level','opt_flags','buffer_size','buffer_free']) { if (row.jit[key] !== null && (!Number.isSafeInteger(row.jit[key]) || row.jit[key] < 0 || row.jit[key] > 1073741824)) throw new Error(); jit[key] = row.jit[key]; }
            let selectedEngineExecutable = false;
            try { selectedEngineExecutable = typeof this.executableWitness === 'function' && this.executableWitness(row.pid) === true; } catch {}
            const safe = { pid: row.pid, php_version: row.php_version, sapi: row.sapi, ini, jit,
                selected_engine_executable: selectedEngineExecutable,
                nts: row.nts === true, debug: row.debug !== false, zend_only_opcache: row.zend_only_opcache === true, stock_ini: row.stock_ini === true };
            const stable = fact => JSON.stringify({ ...fact, jit: { ...fact.jit, buffer_free: null } });
            if (!this.known(safe) || (this.rows.has(row.pid) && stable(this.rows.get(row.pid)) !== stable(safe))) this.metadataUnknown = true;
            if (!this.rows.has(row.pid) && this.rows.size >= 16) { this.metadataUnknown = true; return; }
            this.rows.set(row.pid, safe);
        } catch { this.metadataUnknown = true; }
    }
    freezeBeforeVerification() {
        if (this.fence !== null) { this.metadataUnknown = true; return; }
        const observations = [...this.rows.values()].sort((a, b) => a.pid - b.pid);
        this.fence = { phase: 'pre_auth_warmup', observations: JSON.parse(JSON.stringify(observations)),
            complete: !this.metadataUnknown && observations.length === 10 && observations.every(row => this.known(row)) };
    }
    receipt(markers = []) {
        const requestPids = [...new Set(markers.filter(event => event.event === 'request_start' &&
            /^revision-warm-(?:[1-9]|10)(?:-(?:wrong|verify))?$/.test(event.case)).map(event => event.pid))].sort((a, b) => a - b);
        const fencePids = new Set(this.fence?.observations.map(row => row.pid) || []);
        const newRequestPids = requestPids.filter(pid => !fencePids.has(pid));
        const unknownRequestPids = requestPids.filter(pid => !this.known(this.rows.get(pid)));
        return { source: 'fpm_request_standard_ini_get', executable_witness_source: 'proc_pid_exe_file_link', resource_acceptance: false,
            pre_verification_fence: this.fence, observations: [...this.rows.values()].sort((a, b) => a.pid - b.pid),
            complete: this.fence?.complete === true && !this.metadataUnknown && unknownRequestPids.length === 0 &&
                [...this.rows.values()].every(row => this.known(row)),
            metadata_unknown: this.metadataUnknown, new_request_workers: newRequestPids,
            unknown_request_workers: unknownRequestPids, non_php_responses: this.nonPhp,
            non_php_responses_truncated: this.nonPhpTruncated,
            unobserved_replacement_configuration: 'unknown', cli_parity: 'not_established' };
    }
}
