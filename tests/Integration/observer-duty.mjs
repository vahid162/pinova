/** Calibration-only monotonic synchronous occupancy; nested work is counted once. */
export function createObserverDuty({ enabled = false, clock = () => performance.now(), maxCalls = 1000000 } = {}) {
    let window = null, depth = 0, outerStart, lastClock = null;
    const windows = [];
    const read = () => {
        const value = clock();
        if (!Number.isFinite(value) || (lastClock !== null && value < lastClock)) {
            if (window) window.unknown = true;
        }
        lastClock = value; return value;
    };
    const measure = (kind, operation) => {
        if (!enabled || !window) return operation();
        if (!['sample','identity','cli_poll','cli_close','status_setup','status_callback','status_parse','markers','pool_inventory','observer_io','cli_callback','resource_summary'].includes(kind)) {
            window.unknown = true; return operation();
        }
        const start = read(); if (depth++ === 0) outerStart = start;
        const row = window.kinds[kind] ||= { calls: 0, inclusive_ms: 0, max_ms: 0 };
        if (++window.calls > maxCalls) window.unknown = true;
        try { return operation(); }
        finally {
            const end = read(), duration = end - start;
            row.calls++; row.inclusive_ms += duration; row.max_ms = Math.max(row.max_ms, duration);
            if (--depth === 0) window.occupied_ms += end - outerStart;
        }
    };
    const endWindow = complete => {
        if (!window) return null;
        const end = read(), elapsed = end - window.start;
        const observed = complete && !window.unknown && depth === 0 && elapsed > 0;
        const result = { label: window.label, state: observed ? 'observed' : 'unknown',
            complete, target_met: observed && window.occupied_ms / elapsed <= 0.25, elapsed_ms: elapsed, occupied_ms: window.occupied_ms,
            duty_fraction: elapsed > 0 && !window.unknown ? window.occupied_ms / elapsed : null,
            calls: window.calls, kinds: window.kinds };
        windows.push(result); window = null; return result;
    };
    return { measure, wrap: (kind, operation) => (...args) => measure(kind, () => operation(...args)),
        beginWindow(label) { if (!enabled) return; if (!/^(off|on)_(idle|normal|slow|concurrent)$/.test(label)) throw new Error('Invalid observer window.'); if (window) throw new Error('Observer window already active.');
            window = { label, start: read(), occupied_ms: 0, calls: 0, kinds: {}, unknown: false }; },
        endWindow, snapshot() { return { schema: 'pinova.observer-duty-calibration.v1', resource_acceptance: false,
            target_maximum_duty_fraction: 0.25, windows: [...windows],
            limitation: 'synchronous_Node_observer_occupancy_not_async_wait_or_observer_PHP_CPU' }; } };
}

export const calibrationReceipt = (value, enabled) => enabled ? { ...value, resource_acceptance: false } : value;

export function observerSampleCadence(enabled, requested) {
    if (!enabled) return 40;
    const value = Number(requested);
    if (![40, 200].includes(value)) throw new Error('Invalid observer calibration cadence.');
    return value;
}
