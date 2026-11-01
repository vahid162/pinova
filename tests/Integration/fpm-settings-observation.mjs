/** Finite standard FPM observations only; no I/O or engine introspection. */
export function interpretVerificationSeam(observations) {
    if (observations.comparable !== true || observations.all_arms_classified !== true)
        return 'inconclusive_unrelated_or_incomplete_workload';
    if (observations.B_signal11_arms.length) return 'verification_seam_not_necessary_for_observed_signal11';
    if (observations.A_signal11_arms.length && observations.completed_B === 3)
        return 'verification_seam_associated_mechanism_unknown';
    return 'inconclusive_no_reproducing_control_or_incomplete_workload';
}

export class FpmSettingsObservation {
    constructor(expected) {
        this.expected = { ...expected };
        this.rows = new Map();
        this.metadataUnknown = false;
        this.fence = null;
        this.nonPhp = [];
        this.nonPhpTruncated = false;
    }
    known(row) {
        return row?.php_version && row.sapi === 'fpm-fcgi' && Object.entries(this.expected)
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
            const safe = { pid: row.pid, php_version: row.php_version, sapi: row.sapi, ini };
            if (this.rows.has(row.pid) && JSON.stringify(this.rows.get(row.pid)) !== JSON.stringify(safe)) this.metadataUnknown = true;
            if (!this.rows.has(row.pid) && this.rows.size >= 16) { this.metadataUnknown = true; return; }
            this.rows.set(row.pid, safe);
        } catch { this.metadataUnknown = true; }
    }
    freezeBeforeVerification() {
        if (this.fence !== null) { this.metadataUnknown = true; return; }
        const observations = [...this.rows.values()].sort((a, b) => a.pid - b.pid);
        this.fence = { phase: 'warm_verify', observations: JSON.parse(JSON.stringify(observations)),
            complete: !this.metadataUnknown && observations.length === 10 && observations.every(row => this.known(row)) };
    }
    receipt(markers = []) {
        const requestPids = [...new Set(markers.filter(event => event.event === 'request_start' &&
            /^revision-warm-(?:[1-9]|10)-(?:wrong|verify)$/.test(event.case)).map(event => event.pid))].sort((a, b) => a - b);
        const fencePids = new Set(this.fence?.observations.map(row => row.pid) || []);
        const newRequestPids = requestPids.filter(pid => !fencePids.has(pid));
        const unknownRequestPids = requestPids.filter(pid => !this.known(this.rows.get(pid)));
        return { source: 'fpm_request_standard_ini_get', resource_acceptance: false,
            pre_verification_fence: this.fence, observations: [...this.rows.values()].sort((a, b) => a.pid - b.pid),
            complete: this.fence?.complete === true && !this.metadataUnknown && unknownRequestPids.length === 0 &&
                [...this.rows.values()].every(row => this.known(row)),
            metadata_unknown: this.metadataUnknown, new_request_workers: newRequestPids,
            unknown_request_workers: unknownRequestPids, non_php_responses: this.nonPhp,
            non_php_responses_truncated: this.nonPhpTruncated,
            unobserved_replacement_configuration: 'unknown', cli_parity: 'not_established' };
    }
}
