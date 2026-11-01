/** Ordinary-page HTTP only. No response content, raw headers or URLs leave the worker. */
import { request } from 'node:http';
import { Worker, isMainThread, parentPort, workerData } from 'node:worker_threads';

const failures = new Set(['connection_refused', 'connection_reset', 'timeout', 'transport_failure']);
const assertions = {
    invalid_request: 'Invalid ordinary HTTP worker request.',
    missing_location: 'Ordinary-page redirect omitted its location.',
    unsafe_redirect: 'Ordinary-page redirect escaped the exact fixture origin.',
    runtime_unknown: 'Actual full-stack FPM request is not running the selected native PHP runtime.',
};
const validBase = base => {
    const u = new URL(base);
    if (u.protocol !== 'http:' || u.hostname !== '127.0.0.1' || u.username || u.password || u.origin !== base) throw new Error('Invalid ordinary worker origin.');
    return u.origin;
};
function failure(code, diagnostics = [], assertion = null) {
    const error = new Error(assertions[assertion] || 'Ordinary HTTP transport or worker failed.');
    error.code = code; error.diagnostics = diagnostics; error.assertion = assertion;
    if (assertion) error.fixtureAssertion = true;
    return error;
}

const finitePhpVersion = value => typeof value === 'string' && /^8\.[34]\.[0-9]{1,3}$/.test(value) ? value : null;

/** Shared actual warmup consumer: unknown/wrong-minor evidence cannot populate the runtime profile. */
export function ordinaryRuntimeVersion(responses, phpMinor) {
    if (!/^8\.[34]$/.test(phpMinor) || !Array.isArray(responses) || !responses.length ||
        responses.some(response => finitePhpVersion(response?.php_version) === null || !response.php_version.startsWith(phpMinor + '.'))) {
        throw failure('runtime_unknown', [], 'runtime_unknown');
    }
    return responses[0].php_version;
}

/** Same GET/source/header/redirect/body/inactivity-timeout contract as original ordinary HTTP. */
export async function ordinaryTransport({ base, phpMinor }, { url, label, category }) {
    const diagnostics = [];
    let target;
    try {
        base = validBase(base); target = new URL(url);
        if (target.origin !== base || target.username || target.password || !/^[a-z][a-z0-9_-]{0,47}$/.test(label) ||
            !['ordinary', 'shop', 'cart'].includes(category) || !/^8\.[34]$/.test(phpMinor)) throw new Error();
    } catch { throw failure('invalid_request', diagnostics, 'invalid_request'); }
    const selected = value => new RegExp(`^${phpMinor.replace('.', '\\.')}\\.[0-9]+$`).test(value || '');
    const started = performance.now();
    for (let redirects = 3; ; redirects--) {
        let observedSelected = false;
        const response = await new Promise((resolve, reject) => {
            const outgoing = request(target, { method: 'GET', localAddress: '127.0.0.120', timeout: 25000,
                headers: { 'X-Pinova-Fixture-Case': label } }, incoming => {
                const phpVersion = finitePhpVersion(incoming.headers['x-pinova-fixture-php']);
                observedSelected = phpVersion !== null && selected(phpVersion);
                let length = 0;
                incoming.setEncoding('utf8');
                incoming.on('data', chunk => {
                    length += chunk.length;
                    if (length > 2 * 1024 * 1024) outgoing.destroy(new Error('Fixture response exceeds its bound.'));
                });
                incoming.on('end', () => resolve({ status: incoming.statusCode, location: incoming.headers.location,
                    selected_php: observedSelected, php_version: phpVersion, ended: performance.now(), completed: Date.now() / 1000 }));
                incoming.on('error', reject);
            });
            outgoing.on('timeout', () => outgoing.destroy(new Error('Fixture HTTP deadline exceeded.')));
            outgoing.on('error', reject);
            outgoing.end();
        }).catch(error => {
            const code = { ECONNREFUSED: 'connection_refused', ECONNRESET: 'connection_reset', ETIMEDOUT: 'timeout' }[error.code] || 'transport_failure';
            diagnostics.push({ category, status: null, selected_php: observedSelected, failure: code });
            throw failure(code, diagnostics);
        });
        diagnostics.push({ category, status: response.status, selected_php: response.selected_php });
        if (redirects > 0 && [301, 302, 303, 307, 308].includes(response.status)) {
            if (typeof response.location !== 'string') throw failure('missing_location', diagnostics, 'missing_location');
            try {
                target = new URL(response.location, target);
                if (target.origin !== base || target.username || target.password) throw new Error();
            } catch { throw failure('unsafe_redirect', diagnostics, 'unsafe_redirect'); }
            continue;
        }
        return { status: response.status, ms: response.ended - started, completed: response.completed, php_version: response.php_version, diagnostics };
    }
}

function validDiagnostics(rows) {
    return Array.isArray(rows) && rows.length <= 4 && rows.every(row => row &&
        ['ordinary', 'shop', 'cart'].includes(row.category) && typeof row.selected_php === 'boolean' &&
        (row.status === null ? failures.has(row.failure) : Number.isInteger(row.status) && row.status >= 100 && row.status <= 599));
}

export function proofSerialization(name, value) {
    return name === 'frozen-resource-gates.json' ? { ...value,
        proof_metadata: { resource_acceptance: false, purpose: 'independent_ordinary_http_event_loop' } } : value;
}

export async function createOrdinaryHTTP({ base, phpMinor }) {
    validBase(base);
    const worker = new Worker(new URL(import.meta.url), { workerData: { role: 'ordinary-http', base, phpMinor } });
    const pending = new Map();
    let sequence = 0, ready = false, closing = false, closePromise, lifecycleFailure = null, completed = 0;
    let resolveReady, rejectReady;
    const readiness = new Promise((resolve, reject) => { resolveReady = resolve; rejectReady = reject; });
    const fail = code => {
        lifecycleFailure ||= code;
        rejectReady(failure(code));
        for (const item of pending.values()) item.reject(failure(code));
        pending.clear();
    };
    const readyTimer = setTimeout(() => fail('ordinary_worker_not_ready'), 5000);
    worker.on('error', () => fail('ordinary_worker_error'));
    worker.on('exit', () => { if (!closing) fail('ordinary_worker_exited'); });
    worker.on('message', message => {
        if (message?.type === 'ready' && !ready && !closing) { ready = true; clearTimeout(readyTimer); resolveReady(); return; }
        const item = pending.get(message?.id);
        if (!ready || closing || !item || !validDiagnostics(message.diagnostics)) { fail('ordinary_worker_protocol'); return; }
        pending.delete(message.id);
        if (message.type === 'result' && Number.isInteger(message.status) && message.status >= 100 && message.status <= 599 &&
            Number.isFinite(message.ms) && message.ms > 0 && Number.isFinite(message.completed) && message.completed > 0 &&
            (message.php_version === null || finitePhpVersion(message.php_version) !== null)) {
            completed++; item.resolve({ status: message.status, ms: message.ms, completed: message.completed, php_version: message.php_version, diagnostics: message.diagnostics });
        } else if (message.type === 'failure' && (failures.has(message.code) || Object.hasOwn(assertions, message.code)) &&
            (message.assertion === null || Object.hasOwn(assertions, message.assertion))) {
            item.reject(failure(message.code, message.diagnostics, message.assertion));
        } else { item.reject(failure('ordinary_worker_protocol')); fail('ordinary_worker_protocol'); }
    });
    const client = {
        request(input) {
            if (!ready || closing || lifecycleFailure) return Promise.reject(failure(lifecycleFailure || 'ordinary_worker_unavailable'));
            if (pending.size >= 32 || sequence >= 1000) { fail('ordinary_worker_request_bound'); return Promise.reject(failure('ordinary_worker_request_bound')); }
            const id = ++sequence;
            return new Promise((resolve, reject) => {
                pending.set(id, { resolve, reject });
                try { worker.postMessage({ type: 'request', id, input }); }
                catch { fail('ordinary_worker_protocol'); }
            });
        },
        close() {
            if (closePromise) return closePromise;
            closing = true; clearTimeout(readyTimer);
            const unresolved = pending.size;
            if (unresolved) fail('ordinary_worker_pending_at_cleanup');
            closePromise = (async () => {
                let timer;
                const terminated = await Promise.race([
                    worker.terminate().then(() => true, () => false),
                    new Promise(resolve => { timer = setTimeout(() => resolve(false), 3000); }),
                ]);
                clearTimeout(timer);
                if (!terminated) { lifecycleFailure ||= 'ordinary_worker_cleanup_timeout'; worker.unref(); }
                return { complete: terminated && unresolved === 0 && lifecycleFailure === null,
                    terminated, lifecycle_failure: lifecycleFailure, requests_started: sequence, requests_completed: completed,
                    unresolved_at_cleanup: unresolved, timing_scope: 'worker_outgoing_GET_to_response_end_excludes_parent_message_delivery' };
            })();
            return closePromise;
        },
    };
    try { await readiness; return client; }
    catch (error) { await client.close(); throw error; }
}

if (!isMainThread && workerData?.role === 'ordinary-http') {
    validBase(workerData.base);
    parentPort.on('message', async message => {
        if (message?.type !== 'request' || !Number.isSafeInteger(message.id)) throw new Error('Invalid ordinary worker protocol.');
        try {
            const result = await ordinaryTransport(workerData, message.input);
            parentPort.postMessage({ type: 'result', id: message.id, ...result });
        } catch (error) {
            parentPort.postMessage({ type: 'failure', id: message.id,
                code: failures.has(error.code) || Object.hasOwn(assertions, error.code) ? error.code : 'transport_failure',
                diagnostics: validDiagnostics(error.diagnostics) ? error.diagnostics : [],
                assertion: Object.hasOwn(assertions, error.assertion) ? error.assertion : null });
        }
    });
    parentPort.postMessage({ type: 'ready' });
}
