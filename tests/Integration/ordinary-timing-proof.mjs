/** Private proof only: finite ordinary-client timings; never response content. */
export function createOrdinaryTimingRecorder() {
    const rows = [];
    let incomplete = false;
    const finite = x => typeof x === 'number' && Number.isFinite(x) && x >= 0;
    return {
        start(label, hop, monotonic_ms, epoch_ms) {
            if (!/^(off|on)-normal-(?:[1-9]|10)-page$/.test(label)) return null;
            if (!Number.isInteger(hop) || hop < 0 || hop > 3 || !finite(monotonic_ms) || !finite(epoch_ms) || rows.length >= 80) {
                incomplete = true; return null;
            }
            const row = { label, hop, start_epoch_ms: epoch_ms, headers_after_ms: null, end_after_ms: null, status: null };
            rows.push(row);
            return { row, monotonic_ms };
        },
        headers(token, monotonic_ms, status) {
            if (!token) return;
            if (!finite(monotonic_ms) || monotonic_ms < token.monotonic_ms || !Number.isInteger(status) || status < 100 || status > 599) {
                incomplete = true; return;
            }
            token.row.headers_after_ms = monotonic_ms - token.monotonic_ms;
            token.row.status = status;
        },
        end(token, monotonic_ms) {
            if (!token) return;
            if (!finite(monotonic_ms) || token.row.headers_after_ms === null || monotonic_ms - token.monotonic_ms < token.row.headers_after_ms) {
                incomplete = true; return;
            }
            token.row.end_after_ms = monotonic_ms - token.monotonic_ms;
        },
        snapshot(phpMarkers = []) {
            const completed = new Set(rows.filter(row => row.status === 200 && row.end_after_ms !== null).map(row => row.label)).size;
            const clientPartial = incomplete || completed !== 20 || rows.some(row => row.end_after_ms === null);
            const reasons = new Set();
            let paired = 0;
            if (!Array.isArray(phpMarkers) || phpMarkers.length > 160) {
                reasons.add('marker_bound_or_shape'); phpMarkers = [];
            }
            for (const mode of ['off', 'on']) for (let index = 1; index <= 10; index++) {
                const label = `${mode}-normal-${index}-page`;
                const hops = rows.filter(row => row.label === label);
                const markers = phpMarkers.filter(marker => marker?.case === label);
                const starts = markers.filter(marker => marker.event === 'request_start');
                const ends = markers.filter(marker => marker.event === 'timing_wp_shutdown');
                if (!hops.length || starts.length !== hops.length || ends.length !== hops.length || markers.length !== hops.length * 2) {
                    reasons.add('missing_or_ambiguous_php_pair'); continue;
                }
                const used = new Set();
                for (const begin of starts) {
                    const matches = ends.filter(end => end.pid === begin.pid && finite(end.at) && finite(begin.at) && end.at >= begin.at);
                    // A repeated same-PID request is conservatively ambiguous rather than guessed.
                    if (!Number.isSafeInteger(begin.pid) || begin.pid < 2 || matches.length !== 1 || used.has(matches[0])) {
                        reasons.add('missing_or_ambiguous_php_pair'); continue;
                    }
                    const end = matches[0]; used.add(end);
                    if (!finite(begin.php_request_start_epoch_s) || begin.php_request_start_epoch_s > begin.at ||
                        !finite(end.mu_wall_ms) || !finite(end.mu_cpu_ms)) {
                        reasons.add('php_timing_or_cpu_unknown'); continue;
                    }
                    paired++;
                }
            }
            const phpPartial = reasons.size > 0 || paired !== rows.length || clientPartial;
            return { schema: 'pinova.ordinary-timing-proof.v1', resource_acceptance: false,
                incomplete: clientPartial || phpPartial,
                observation_state: clientPartial || phpPartial ? 'partial_or_unknown' : 'observed',
                client_coverage: { state: clientPartial ? 'partial_or_unknown' : 'observed',
                    expected_normal_requests: 20, completed_normal_requests: completed },
                php_observation_coverage: { state: phpPartial ? 'partial_or_unknown' : 'observed',
                    matched_hop_pairs: paired, reasons: [...reasons].sort(),
                    boundary: 'MU_bootstrap_to_late_WP_shutdown_not_preMU_CPU_or_worker_end' },
                expected_normal_requests: 20, completed_normal_requests: completed,
                units: 'epoch_milliseconds_and_monotonic_elapsed_milliseconds',
                hops: rows.map(row => ({ ...row })) };
        },
    };
}

/** Serialization metadata only: never changes the evaluation's threshold object. */
export function timingProofSerialization(name, value) {
    return name === 'frozen-resource-gates.json' ?
        { ...value, proof_metadata: { resource_acceptance: false, purpose: 'ordinary_timing_attribution_only' } } : value;
}
