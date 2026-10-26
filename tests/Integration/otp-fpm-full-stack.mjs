/** Actual WordPress/plugin/FPM acceptance. Only run through the guarded shell entrypoint. */
import { spawn, execFileSync } from 'node:child_process';
import { createServer, request } from 'node:http';
import { chmodSync, closeSync, existsSync, fstatSync, openSync, readFileSync, readSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const repo = path.resolve(fileURLToPath(new URL('../..', import.meta.url)));
const root = process.env.PINOVA_FPM_FULL_STACK_ROOT;
const expected = `/tmp/pinova-fpm-full-${process.env.GITHUB_RUN_ID}-${process.env.GITHUB_RUN_ATTEMPT}`;
const ensure = (condition, message) => {
    if (!condition) {
        const error = new Error(message);
        error.fixtureAssertion = true;
        throw error;
    }
};
ensure(process.env.GITHUB_ACTIONS === 'true' && process.env.RUNNER_ENVIRONMENT === 'github-hosted' &&
    /^\d+$/.test(process.env.GITHUB_RUN_ID || '') && /^\d+$/.test(process.env.GITHUB_RUN_ATTEMPT || '') && root === expected,
'Full-stack fixture requires exact disposable GitHub ownership.');
const manifest = JSON.parse(readFileSync(path.join(repo, 'tests/fixtures/third-party-baseline.json'), 'utf8'));
const verified = JSON.parse(readFileSync(path.join(repo, '.build/third-party/verified.json'), 'utf8'));
ensure(JSON.stringify(verified.plugins) === JSON.stringify(manifest.deployed_plugins) &&
    verified.wordpress === manifest.wordpress &&
    verified.woocommerce === manifest.deployed_plugins.find(plugin => plugin.slug === 'woocommerce').version,
'Full-stack fixture requires the exact checksum-verified deployed plugin set.');
for (const plugin of manifest.deployed_plugins) {
    ensure(existsSync(path.join(repo, '.build/third-party', plugin.slug, plugin.main)), 'A pinned plugin entrypoint is missing.');
}
const versionFile = readFileSync(path.join(root, 'wordpress/wp-includes/version.php'), 'utf8');
ensure(versionFile.includes(`$wp_version = '${manifest.wordpress}'`) ||
    new RegExp(`\$wp_version\s*=\s*'${manifest.wordpress.replaceAll('.', '\\.')}';`).test(versionFile),
'Unexpected WordPress core version.');
if (process.argv.includes('--verify-profile')) {
    process.stdout.write('Full-stack profile ownership and archive receipt verified.\n');
    process.exit(0);
}
ensure(process.env.PINOVA_FPM_NETWORK_ISOLATED === '1' && process.env.PINOVA_FPM_FULL_STACK_FIXTURE === '1',
'Full-stack controller must run in the isolated namespace.');
const interfaces = JSON.parse(execFileSync('ip', ['-j', 'link', 'show'], { encoding: 'utf8' }));
ensure(interfaces.length === 1 && interfaces[0].ifname === 'lo' &&
    execFileSync('ip', ['route', 'show'], { encoding: 'utf8' }).trim() === '', 'External network route detected.');

const privateRoot = path.join(root, 'private');
const receiptRoot = path.join(root, 'receipt');
const base = `http://127.0.0.1:${process.env.PINOVA_FPM_HTTP_PORT}`;
const providerPort = Number(process.env.PINOVA_FPM_PROVIDER_PORT);
const master = Number(process.env.PINOVA_FPM_MASTER_PID);
ensure(Number.isInteger(master) && master > 1 && readFileSync(`/proc/${master}/cmdline`, 'utf8').includes(root),
'FPM master does not belong to this fixture.');
const tick = Number(execFileSync('getconf', ['CLK_TCK'], { encoding: 'utf8' }));
let sequence = 0;
let activeProvider = 0;
let peakProvider = 0;
const records = [];
const pendingTimers = new Set();
const mobileCases = new Map();
const delays = new Map();
const codes = new Map();
const tests = [];
const httpDiagnostics = [];
let phase = 'bootstrap';
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const now = () => Date.now() / 1000;
const mobile = index => `0910000${String(index).padStart(4, '0')}`;
const providerMobile = index => mobile(index).replace(/^0/, '+98');

function sanitizedReceipt(name, value) {
    ensure(['frozen-resource-gates.json', 'full-stack-result.json'].includes(name), 'Unexpected sanitized receipt target.');
    const target = path.join(receiptRoot, name);
    writeFileSync(target, JSON.stringify(value, null, 2) + '\n', { mode: 0o600 });
    // The shell's private umask also applies to Node's requested creation mode.
    // Widen only these allowlisted, sanitized receipts, never logs or WP config.
    chmodSync(target, 0o644);
}

function http(url, { body, label = 'ordinary', source = 120, headers = {}, method = body ? 'POST' : 'GET',
    category = new URL(url).pathname === '/fpm-status' ? 'fpm_status' : body ? 'authentication' : 'ordinary', redirects = 0 } = {}) {
    const started = performance.now();
    return new Promise((resolve, reject) => {
        const serialized = body === undefined ? undefined : JSON.stringify(body);
        const outgoing = request(url, {
            method, localAddress: `127.0.0.${source}`, timeout: 25000,
            headers: { 'X-Pinova-Fixture-Case': label, ...(serialized ? {
                'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(serialized),
            } : {}), ...headers },
        }, incoming => {
            let text = '';
            incoming.setEncoding('utf8');
            incoming.on('data', chunk => {
                text += chunk;
                if (text.length > 2 * 1024 * 1024) outgoing.destroy(new Error('Fixture response exceeds its bound.'));
            });
            incoming.on('end', async () => {
                httpDiagnostics.push({ phase, category, status: incoming.statusCode,
                    selected_php: /^8\.3\.[0-9]+$/.test(incoming.headers['x-pinova-fixture-php'] || '') });
                if (httpDiagnostics.length > 24) httpDiagnostics.shift();
                if (redirects > 0 && [301, 302, 303, 307, 308].includes(incoming.statusCode)) {
                    try {
                        ensure(typeof incoming.headers.location === 'string', 'Ordinary-page redirect omitted its location.');
                        const target = new URL(incoming.headers.location, url);
                        ensure(method === 'GET' && body === undefined && target.origin === base && !target.username && !target.password,
                            'Ordinary-page redirect escaped the exact fixture origin.');
                        const next = await http(target.href, { label, source, headers, category, redirects: redirects - 1 });
                        resolve({ ...next, ms: performance.now() - started });
                    } catch (error) { reject(error); }
                    return;
                }
                resolve({ status: incoming.statusCode, headers: incoming.headers, text,
                    ms: performance.now() - started, completed: now() });
            });
            incoming.on('error', reject);
        });
        outgoing.on('timeout', () => outgoing.destroy(new Error('Fixture HTTP deadline exceeded.')));
        outgoing.on('error', error => {
            const codes = { ECONNREFUSED: 'connection_refused', ECONNRESET: 'connection_reset', ETIMEDOUT: 'timeout' };
            httpDiagnostics.push({ phase, category, status: null, failure: codes[error.code] || 'transport_failure' });
            if (httpDiagnostics.length > 24) httpDiagnostics.shift();
            reject(error);
        });
        outgoing.end(serialized);
    });
}

const provider = createServer((incoming, outgoing) => {
    if (incoming.method !== 'POST' || incoming.url !== '/send') { outgoing.writeHead(404).end(); return; }
    let body = '';
    incoming.setEncoding('utf8');
    incoming.on('data', chunk => { body += chunk; if (body.length > 2048) incoming.destroy(); });
    incoming.on('end', () => {
        let data;
        try { data = JSON.parse(body); } catch { outgoing.writeHead(400).end(); return; }
        const label = mobileCases.get(data.to);
        if (!label || !/^\d{4,6}$/.test(String(data.code)) || data.username !== 'fixture-user' ||
            data.password !== 'fixture-secret' || data.from !== 'fixture-sender') {
            outgoing.writeHead(400).end(); return;
        }
        const record = { case: label, entered: now(), accepted: null, pid: Number(incoming.headers['x-pinova-fixture-pid']) };
        records.push(record);
        codes.set(label, String(data.code)); // Private in-memory proof only; never persisted.
        activeProvider++;
        peakProvider = Math.max(peakProvider, activeProvider);
        const timer = setTimeout(() => {
            pendingTimers.delete(timer);
            record.accepted = now();
            activeProvider--;
            outgoing.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify({ Value: '12345678901234567' }));
        }, delays.get(label) ?? 200);
        pendingTimers.add(timer);
    });
});

let cliCounter = 0;
async function cli(args, { input, label = 'observation', sensitive = false } = {}) {
    const id = ++cliCounter;
    const timeFile = path.join(privateRoot, `time-${id}.json`);
    const wpArgs = [process.env.PINOVA_FPM_WP_CLI, '--allow-root', `--path=${path.join(root, 'wordpress')}`, ...args];
    return new Promise((resolve, reject) => {
        const child = spawn('/usr/bin/time', ['-f', '{"user":%U,"system":%S,"max_rss_kib":%M,"wall":%e}', '-o', timeFile, '--', ...wpArgs], {
            env: { ...process.env, PINOVA_FPM_CASE: label }, stdio: ['pipe', 'pipe', 'pipe'],
        });
        let output = '';
        let errors = '';
        const timeout = setTimeout(() => child.kill('SIGTERM'), 25000);
        child.stdout.on('data', chunk => { output += chunk; });
        child.stderr.on('data', chunk => { errors += chunk; });
        child.on('error', reject);
        child.on('close', code => {
            clearTimeout(timeout);
            if (!sensitive) writeFileSync(path.join(privateRoot, `cli-${id}.log`), `${output}\n${errors}`, { mode: 0o600 });
            if (code !== 0) { reject(new Error(`Fixture CLI invocation ${id} failed; inspect private runner diagnostics.`)); return; }
            let timing;
            try { timing = JSON.parse(readFileSync(timeFile, 'utf8')); } catch { reject(new Error('Missing CLI resource receipt.')); return; }
            if (measuring) measuredCli.push(timing);
            resolve({ output, timing });
        });
        child.stdin.end(input === undefined ? '' : JSON.stringify(input));
    });
}

async function site(input) {
    const { output } = await cli(['eval-file', path.join(repo, 'tests/fixtures/fpm-otp/full-stack-site.php'), '--use-include'], {
        input, sensitive: input.action === 'nonce',
    });
    const lines = output.split('\n').filter(line => line.startsWith('PINOVA_FULL_STACK_RESULT='));
    ensure(lines.length === 1, 'Missing finite full-stack observation receipt.');
    return JSON.parse(lines[0].slice('PINOVA_FULL_STACK_RESULT='.length));
}
async function runWorker(label = 'fallback') {
    await cli(['pinova', 'otp', 'run'], { label });
}
async function drainDue(label) {
    for (let attempt = 0; attempt < 20; attempt++) {
        if ((await site({ action: 'stats' })).due_jobs === 0) return;
        await runWorker(label);
    }
    throw new Error('Existing bounded CLI did not drain this fixture queue.');
}
function events() {
    const text = existsSync(path.join(root, 'events.jsonl')) ? readFileSync(path.join(root, 'events.jsonl'), 'utf8') : '';
    ensure(text.length < 1024 * 1024, 'Fixture marker budget exceeded.');
    return text.trim() ? text.trim().split('\n').map(line => JSON.parse(line)) : [];
}
async function until(predicate, message, limit = 15000) {
    const end = performance.now() + limit;
    do { if (await predicate()) return; await sleep(40); } while (performance.now() < end);
    ensure(false, message);
}
async function quiescent() {
    await until(async () => {
        const response = await http(`${base}/fpm-status?json&full`);
        return response.status === 200 && JSON.parse(response.text)['active processes'] === 0 && activeProvider === 0;
    }, 'FPM/provider work did not quiesce.', 22000);
}
async function headroom() {
    const start = performance.now();
    await until(() => {
        const match = readFileSync('/proc/meminfo', 'utf8').match(/^MemAvailable:\s+(\d+) kB$/m);
        return match && Number(match[1]) >= 1048576 && Number(readFileSync('/proc/loadavg', 'utf8').split(' ')[0]) < 3;
    }, 'Representative host did not satisfy the real runtime resource admission guard.', 180000);
    return Math.round(performance.now() - start);
}

function processSample() {
    const children = readFileSync(`/proc/${master}/task/${master}/children`, 'utf8').trim().split(/\s+/).filter(Boolean);
    return children.flatMap(pid => {
        try {
            const stat = readFileSync(`/proc/${pid}/stat`, 'utf8').split(') ')[1].split(' ');
            const status = readFileSync(`/proc/${pid}/status`, 'utf8');
            const pss = readFileSync(`/proc/${pid}/smaps_rollup`, 'utf8').match(/^Pss:\s+(\d+) kB$/m);
            return [{ pid: Number(pid), cpu: (Number(stat[11]) + Number(stat[12])) / tick,
                rss: Number(status.match(/^VmRSS:\s+(\d+) kB$/m)?.[1] || 0), pss: Number(pss?.[1] || 0) }];
        } catch { return []; }
    });
}
let measuring = false;
let samples = [];
let measuredCli = [];
let monitor;
let statusMonitor;
let statusBusy = false;
let statusSamples = [];
let statusError = false;
function beginMeasure(initialStatus) {
    samples = [{ at: now(), workers: processSample() }];
    measuredCli = [];
    statusSamples = [{ at: now(), active: initialStatus['active processes'], queue: initialStatus['listen queue'], reached: initialStatus['max children reached'] }];
    statusError = false;
    measuring = true;
    monitor = setInterval(() => samples.push({ at: now(), workers: processSample() }), 40);
    statusMonitor = setInterval(async () => {
        if (statusBusy) return;
        statusBusy = true;
        try {
            const response = await http(`${base}/fpm-status?json&full`, { label: 'status' });
            const status = JSON.parse(response.text);
            statusSamples.push({ at: now(), active: status['active processes'], queue: status['listen queue'], reached: status['max children reached'] });
        } catch { statusError = true; } finally { statusBusy = false; }
    }, 125);
}
async function endMeasure() {
    await quiescent();
    clearInterval(monitor);
    clearInterval(statusMonitor);
    await until(() => !statusBusy, 'FPM status observation did not finish.');
    samples.push({ at: now(), workers: processSample() });
    measuring = false;
    ensure(!statusError && statusSamples.length > 0, 'FPM status evidence is incomplete.');
    const first = new Map(samples[0].workers.map(worker => [worker.pid, worker.cpu]));
    const last = new Map();
    let peakWorkerRss = 0;
    let peakWorkerPss = 0;
    for (const sample of samples) for (const worker of sample.workers) {
        last.set(worker.pid, worker.cpu);
        peakWorkerRss = Math.max(peakWorkerRss, worker.rss);
        peakWorkerPss = Math.max(peakWorkerPss, worker.pss);
    }
    const fpmCpu = [...last].reduce((sum, [pid, cpu]) => sum + cpu - (first.get(pid) || 0), 0);
    const cliCpu = measuredCli.reduce((sum, timing) => sum + timing.user + timing.system, 0);
    const cliWall = measuredCli.reduce((sum, timing) => sum + timing.wall, 0);
    const fpmWorkerSeconds = statusSamples.slice(1).reduce((sum, sample, index) =>
        sum + (sample.at - statusSamples[index].at) * (sample.active + statusSamples[index].active) / 2, 0);
    const retained = samples.at(-1).workers.reduce((sum, worker) => sum + worker.rss, 0);
    const retainedPss = samples.at(-1).workers.reduce((sum, worker) => sum + worker.pss, 0);
    return {
        fpm_cpu_seconds: fpmCpu, measured_cli_cpu_seconds: cliCpu, total_php_cpu_seconds: fpmCpu + cliCpu,
        sampled_fpm_active_worker_seconds: fpmWorkerSeconds, measured_cli_wall_seconds: cliWall,
        total_sampled_active_worker_seconds: fpmWorkerSeconds + cliWall,
        measured_cli_max_rss_kib: Math.max(0, ...measuredCli.map(timing => timing.max_rss_kib)),
        peak_sampled_worker_rss_kib: peakWorkerRss, peak_sampled_worker_pss_kib: peakWorkerPss,
        retained_worker_rss_kib: retained, retained_worker_pss_kib: retainedPss,
        max_active_workers: Math.max(...statusSamples.map(status => status.active)),
        max_listen_queue: Math.max(...statusSamples.map(status => status.queue)),
        max_children_reached_delta: Math.max(...statusSamples.map(status => status.reached)) - Math.min(...statusSamples.map(status => status.reached)),
        wall_seconds: samples.at(-1).at - samples[0].at,
        status_samples: statusSamples.length, process_samples: samples.length,
    };
}
function percentile(values, ratio) { return [...values].sort((a, b) => a - b)[Math.ceil(values.length * ratio) - 1]; }
function dbDelta(before, after) {
    return Object.fromEntries(Object.entries(after.db).map(([key, value]) => [key, Number(value) - Number(before.db[key] || 0)]));
}
function finiteFailureSources() {
    const sources = [];
    const patterns = {
        php_fatal: /(?:PHP Fatal error|Uncaught (?:Error|Exception|TypeError))/, php_memory_limit: /Allowed memory size .* exhausted/,
        database_connection: /(?:Error establishing a database connection|Access denied for user|SQLSTATE\[HY000\].*\[2002\])/,
        permission_denied: /Permission denied/, upstream_unavailable: /connect\(\).*failed|No such file or directory/,
        upstream_timeout: /upstream timed out/, worker_terminated: /child .* exited on signal/,
    };
    for (const name of ['php.log', 'nginx.log', 'fpm.log', 'fpm-start.log', 'nginx-start.log']) {
        const target = path.join(privateRoot, name);
        if (!existsSync(target)) continue;
        const descriptor = openSync(target, 'r');
        let text;
        try {
            const size = fstatSync(descriptor).size;
            const buffer = Buffer.alloc(Math.min(size, 65536));
            const count = readSync(descriptor, buffer, 0, buffer.length, Math.max(0, size - buffer.length));
            text = buffer.subarray(0, count).toString('utf8');
        } finally { closeSync(descriptor); }
        for (const [tag, pattern] of Object.entries(patterns)) if (pattern.test(text) && !sources.includes(tag)) sources.push(tag);
    }
    return sources;
}
function normalized(response) {
    ensure(response.status === 200, 'Public issuance status was unhealthy.');
    ensure(/^\w{8}-\w{4}-4\w{3}-[89ab]\w{3}-\w{12}$/i.test(response.headers['x-pinova-correlation-id'] || ''),
        'Missing valid server-generated correlation header.');
    const body = JSON.parse(response.text);
    ensure(body.success === true && body.data.login_method === 'otp' && Number.isInteger(body.data.ttl) &&
        body.data.ttl > 0 && body.data.ttl <= 180 && typeof body.data.jwt === 'string', 'Unexpected public issuance envelope.');
    const payload = JSON.parse(Buffer.from(body.data.jwt.split('.')[1], 'base64url').toString('utf8'));
    ensure(Number.isInteger(payload.exp) && payload.exp > response.completed && payload.exp <= response.completed + 181 &&
        Math.abs(payload.exp - response.completed - body.data.ttl) <= 2, 'Signed deadline/countdown bounds changed.');
    return { token: body.data.jwt, body: { ...body, data: { ...body.data, jwt: '<fresh signed state>', ttl: '<validated remaining seconds>' } } };
}
async function issue(index, label, extra = {}, delay = 200) {
    ensure(index > 0 && index < 80, 'Invalid synthetic eligible account index.');
    mobileCases.set(providerMobile(index), label);
    delays.set(label, delay);
    const start = now();
    const source = ++sequence;
    const response = await http(`${base}/?rest_route=/pinova/user/authenticate`, {
        label, category: 'issuance', source, body: { identifier: mobile(index), ...extra }, headers: { Origin: base },
    });
    const value = normalized(response);
    return { ...value, label, source, start, ms: response.ms, completed: response.completed };
}
async function inspect(flow) {
    const result = await site({ action: 'inspect', jwt: flow.token });
    // WordPress boot precedes signed-state creation. Client request start is
    // not the beginning of the unchanged server-side 180-second deadline.
    ensure(result.signature_valid && result.expiry > now() && result.expiry <= (flow.completed ?? flow.start) + 181,
        'Real-plugin signed-state validation failed.');
    return result;
}
async function verifyFlow(flow, purpose = 'login') {
    const code = codes.get(flow.label);
    ensure(typeof code === 'string', 'Synthetic provider did not capture private proof.');
    const wrong = await http(`${base}/?rest_route=/pinova/${purpose === 'login' ? 'auth/forgot/verify' : 'user/login/otp'}`, {
        label: `${flow.label}-wrong`, source: flow.source, body: { jwt: flow.token, code }, headers: { Origin: base },
    });
    ensure(wrong.status === 401 && JSON.parse(wrong.text).success === false,
        'Wrong-purpose endpoint did not reject the delivered proof.');
    const correct = await http(`${base}/?rest_route=/pinova/${purpose === 'login' ? 'user/login/otp' : 'auth/forgot/verify'}`, {
        label: `${flow.label}-verify`, source: flow.source, body: { jwt: flow.token, code }, headers: { Origin: base },
    });
    ensure(correct.status === 200 && JSON.parse(correct.text).success === true, 'Purpose-correct delivered proof failed.');
    return correct;
}

let receipt = {
    fixture: 'pinova-full-stack-fpm-v1', state: 'running',
    source_commit: execFileSync('git', ['rev-parse', 'HEAD'], { cwd: repo, encoding: 'utf8' }).trim(),
    profile: { wordpress: manifest.wordpress, plugins: manifest.deployed_plugins.map(({ slug, version, sha256 }) => ({ slug, version, sha256 })),
        php_cli: execFileSync('php', ['-r', 'echo PHP_VERSION;'], { encoding: 'utf8' }), redis: 'not_applicable_not_installed' },
    isolation: 'fresh_loopback_only_network_namespace_and_run_owned_unix_socket_database',
    limits: { fpm_children: 10, fast_tails: 2, provider_budget_seconds: 8, normal_provider_delay_ms: 200,
        slow_provider_delay_ms: 6000, normal_flows_per_resource_variant: 10,
        fixed_resource_windows_ms: { idle: 20000, slow: 24000, concurrent: 32000 }, process_sample_interval_ms: 40, status_interval_ms: 125 },
    tests,
};
try {
    phase = 'readiness';
    await new Promise(resolve => provider.listen(providerPort, '127.0.0.1', resolve));
    await until(async () => {
        try { return (await http(`${base}/fpm-status?json`)).status === 200; } catch { return false; }
    }, 'Native FPM/nginx did not become ready.');
    const setupLine = readFileSync(path.join(privateRoot, 'setup.log'), 'utf8').split('\n').find(line => line.startsWith('PINOVA_FULL_STACK_RESULT='));
    ensure(setupLine, 'Missing representative-profile setup receipt.');
    const pages = JSON.parse(setupLine.slice('PINOVA_FULL_STACK_RESULT='.length));
    receipt.profile.hpos_enabled = pages.hpos_enabled;
    const pagePaths = [pages.ordinary, pages.shop, pages.cart].map(value => {
        const target = new URL(value, base);
        ensure(target.origin === base && !target.username && !target.password, 'Setup page escaped the exact fixture origin.');
        return target.href;
    });
    const ordinary = (index, label) => http(pagePaths[index % 3], {
        label, category: ['ordinary', 'shop', 'cart'][index % 3], redirects: 3,
    });
    phase = 'warmup';
    for (let round = 0; round < 5; round++) {
        const warm = await Promise.all(Array.from({ length: 8 }, (_, index) => ordinary(index, 'warm')));
        ensure(warm.every(response => response.status === 200), 'Representative-profile warm-up page failed.');
        ensure(warm.every(response => /^8\.3\.[0-9]+$/.test(response.headers['x-pinova-fixture-php'] || '')),
            'Actual full-stack FPM request is not running the selected PHP 8.3 runtime.');
        receipt.profile.php_fpm = warm[0].headers['x-pinova-fixture-php'];
    }
    await quiescent();
    const cooldownMs = await headroom();
    receipt.preflight = { admission_guard_satisfied: true, cooldown_ms: cooldownMs, warmed_workers: processSample().length };

    function fastTiming(flow) {
        const providerRecord = records.find(record => record.case === flow.label);
        const marker = events().find(event => event.case === flow.label && event.event === 'finished');
        const entry = events().find(event => event.case === flow.label && event.event === 'provider_enter');
        const lookup = events().find(event => event.case === flow.label && event.event === 'account_lookup');
        ensure(providerRecord && marker && entry && marker.pid === entry.pid && marker.pid === providerRecord.pid && marker.at <= entry.at,
            'Actual same-process provider entry preceded native successful response completion.');
        ensure(lookup && lookup.pid === marker.pid && lookup.at >= marker.at,
            'Actual delivery-time account resolution preceded native successful response completion.');
        ensure(entry.at - marker.at <= 1 && providerRecord.accepted - flow.start <= 15,
            'Uncontended admitted flow missed the approved latency target.');
        return { after_finish_ms: (entry.at - marker.at) * 1000, request_to_accept_ms: (providerRecord.accepted - flow.start) * 1000,
            public_http_ms: flow.ms, same_pid: true };
    }

    async function workload(mode, kind, firstIndex) {
        phase = `${mode}_${kind}`;
        writeFileSync(path.join(root, 'mode'), `${mode}\n`);
        await quiescent();
        await drainDue(`${mode}-${kind}-pre-drain`);
        await headroom();
        const before = await site({ action: 'stats' });
        const pageTimes = [];
        const pageIntervals = [];
        const flows = [];
        const initialStatus = JSON.parse((await http(`${base}/fpm-status?json&full`)).text);
        beginMeasure(initialStatus);
        const windowStart = performance.now();
        let worker;
        if (kind === 'normal') {
            for (let index = 0; index < 10; index++) {
                const label = `${mode}-${kind}-${index + 1}`;
                flows.push(await issue(firstIndex + index, label));
                const page = await ordinary(index, `${label}-page`);
                ensure(page.status === 200, 'Ordinary/WooCommerce page failed during delivery.');
                pageTimes.push(page.ms);
                if (mode === 'off') await runWorker(label);
                await until(() => records.some(record => record.case === label && record.accepted !== null), 'Expected provider acceptance missing.');
                await quiescent();
            }
        } else {
            const count = kind === 'idle' ? 0 : kind === 'slow' ? 1 : 3;
            for (let index = 0; index < count; index++) {
                const label = `${mode}-${kind}-${index + 1}`;
                const flow = await issue(firstIndex + index, label, {}, index < 2 ? 6000 : 200);
                flows.push(flow);
                if (mode === 'on' && index < 2) {
                    ensure(flow.ms < 3000, 'Real public response waited for the slow provider.');
                    await until(() => records.some(record => record.case === label), 'Intended FPM fast flow did not enter its provider.');
                }
            }
            if (kind === 'concurrent') {
                const queued = await inspect(flows[2]);
                ensure(queued.rows === 0 && queued.queue_ready && queued.queue_encrypted && queued.scheduled,
                    'Capacity overflow did not preserve untouched acknowledged durable work.');
            }
            if (count > 0) {
                // The baseline completes the same work through the real existing
                // runner. The candidate runner races both live fast claims and
                // processes overflow. All CLI PHP cost in this window is counted.
                if (mode === 'off' || kind === 'concurrent') {
                    // Attach rejection immediately while ordinary traffic is in
                    // progress, preserving the sanitized failure receipt.
                    worker = runWorker(`${mode}-${kind}-worker`).then(() => null, error => error);
                }
            }
            const duration = receipt.limits.fixed_resource_windows_ms[kind];
            // Equal sample count and fixed deadlines per variant include mixed
            // ordinary traffic while slow/over-capacity delivery is still live.
            for (let index = 0; index < 12; index++) {
                await sleep(Math.max(0, windowStart + 500 + index * 1000 - performance.now()));
                const page = await ordinary(index, `${mode}-${kind}-page`);
                ensure(page.status === 200, 'Ordinary/WooCommerce page failed in a fixed resource window.');
                pageTimes.push(page.ms);
                pageIntervals.push({ start: page.completed - page.ms / 1000, end: page.completed });
            }
            if (worker) { const failure = await worker; if (failure) throw failure; }
            for (const flow of flows) await until(() => records.some(record => record.case === flow.label && record.accepted !== null),
                'Comparable resource workload did not complete its provider work.');
            if (count > 0) {
                const completed = flows.map(flow => records.find(record => record.case === flow.label));
                ensure(pageIntervals.some(page => completed.some(record => page.start < record.accepted && page.end > record.entered)),
                    'Fixed resource window did not include ordinary traffic during live provider work.');
            }
            await quiescent();
            ensure(performance.now() - windowStart <= duration, 'Comparable resource workload exceeded its declared observation window.');
            await sleep(Math.max(0, windowStart + duration - performance.now()));
        }
        const resources = await endMeasure();
        const after = await site({ action: 'stats' });
        for (const flow of flows) {
            const result = await inspect(flow);
            ensure(result.rows === 1 && result.purpose === 'login' && result.accepted_channels.length === 1 &&
                result.accepted_channels[0] === 'sms' && result.otp_expiry === result.expiry,
            'Real durable delivery/purpose/deadline did not match signed state.');
            ensure(records.filter(record => record.case === flow.label).length === 1, 'A flow caused duplicate provider attempts.');
            await verifyFlow(flow);
        }
        const timings = mode === 'on' ? flows.slice(0, kind === 'concurrent' ? 2 : flows.length).map(fastTiming) : [];
        let maximumOriginatingFastTails;
        if (mode === 'on' && kind === 'concurrent') {
            const pids = new Set(timings.map((_, index) => records.find(record => record.case === flows[index].label).pid));
            ensure(pids.size === 2, 'Concurrent fast flows did not occupy independent FPM workers.');
            const intervals = flows.slice(0, 2).map(flow => records.find(record => record.case === flow.label));
            ensure(Math.max(...intervals.map(record => record.entered)) < Math.min(...intervals.map(record => record.accepted)),
                'The resource window did not actually overlap two slow FPM provider tails.');
            const overflow = records.find(record => record.case === flows[2].label);
            ensure(!pids.has(overflow.pid) && overflow.entered < Math.min(...intervals.map(record => record.accepted)),
                'The independent CLI did not actually race both live originating FPM claims.');
            const marked = events();
            const timeline = records.filter(record => flows.some(flow => flow.label === record.case) &&
                marked.some(event => event.case === record.case && event.pid === record.pid && event.event === 'finished'))
                .flatMap(record => [{ at: record.entered, delta: 1 }, { at: record.accepted, delta: -1 }])
                .sort((left, right) => left.at - right.at || left.delta - right.delta);
            let active = 0;
            maximumOriginatingFastTails = 0;
            for (const event of timeline) {
                active += event.delta;
                maximumOriginatingFastTails = Math.max(maximumOriginatingFastTails, active);
            }
            ensure(maximumOriginatingFastTails === 2, 'Observed originating FPM provider concurrency differs from its two-slot limit.');
        }
        return { resources, ordinary_p95_ms: percentile(pageTimes, 0.95), ordinary_samples_ms: pageTimes, fast_timings: timings,
            maximum_originating_fast_provider_tails: maximumOriginatingFastTails,
            db_statement_and_row_deltas: dbDelta(before, after), rows_created: after.otp_rows - before.otp_rows, flows };
    }

    const baseline = {};
    const candidate = {};
    const thresholds = {};
    receipt.baseline = {};
    for (const [kind, firstIndex] of [['idle', 1], ['normal', 1], ['slow', 31], ['concurrent', 33]]) {
        const result = await workload('off', kind, firstIndex);
        baseline[kind] = result;
        receipt.baseline[kind] = { ...result, flows: undefined };
        thresholds[kind] = {
            total_php_cpu_seconds: result.resources.total_php_cpu_seconds * 1.25,
            // Status is sampled every 125 ms; allow 500 ms of finite boundary noise
            // in addition to the predeclared 25% completed-work headroom.
            total_sampled_active_worker_seconds: result.resources.total_sampled_active_worker_seconds * 1.25 + 0.5,
            ordinary_p95_ms: result.ordinary_p95_ms * 1.25 + 50,
            peak_sampled_worker_rss_kib: result.resources.peak_sampled_worker_rss_kib + Math.max(32768, result.resources.peak_sampled_worker_rss_kib * 0.25),
            retained_worker_rss_kib: result.resources.retained_worker_rss_kib + Math.max(65536, result.resources.retained_worker_rss_kib * 0.1),
            active_workers: 8, listen_queue: 0, new_max_children_reached: 0,
        };
    }
    // Freeze numeric limits before running candidate, never tune after observing it.
    sanitizedReceipt('frozen-resource-gates.json', thresholds);
    receipt.resource_gates = thresholds;
    await headroom();
    receipt.candidate = {};
    for (const [kind, firstIndex] of [['idle', 11], ['normal', 11], ['slow', 32], ['concurrent', 36]]) {
        const result = await workload('on', kind, firstIndex);
        candidate[kind] = result;
        receipt.candidate[kind] = { ...result, flows: undefined };
        const gate = thresholds[kind];
        ensure(result.resources.total_php_cpu_seconds <= gate.total_php_cpu_seconds, 'Candidate exceeded frozen per-workload total PHP CPU gate.');
        ensure(result.resources.total_sampled_active_worker_seconds <= gate.total_sampled_active_worker_seconds,
            'Candidate occupied PHP workers beyond frozen per-workload headroom.');
        ensure(result.ordinary_p95_ms <= gate.ordinary_p95_ms, 'Candidate ordinary page latency regressed beyond frozen per-workload gate.');
        ensure(result.resources.peak_sampled_worker_rss_kib <= gate.peak_sampled_worker_rss_kib, 'Candidate per-worker sampled RSS exceeded frozen per-workload gate.');
        ensure(result.resources.retained_worker_rss_kib <= gate.retained_worker_rss_kib, 'Candidate retained PHP RSS exceeded frozen per-workload gate.');
        for (const measured of [baseline[kind], result]) {
            ensure(measured.resources.max_active_workers <= gate.active_workers && measured.resources.max_listen_queue === 0 &&
                measured.resources.max_children_reached_delta === 0, 'Representative workload saturated FPM.');
        }
    }
    receipt.normal_fast_timings = candidate.normal.fast_timings;
    receipt.concurrency = { maximum_originating_fast_provider_tails: candidate.concurrent.maximum_originating_fast_provider_tails,
        overflow_recovered_once: true, independent_cli_live_race: true };
    tests.push('ten_baseline_real_cli_deliveries', 'ten_candidate_real_same_process_deliveries', 'signed_state_original_deadline_and_purpose',
        'purpose_mismatch_denials_and_valid_verifications', 'frozen_idle_normal_slow_and_concurrent_resource_limits',
        'six_second_provider_http_nonblocking', 'two_fast_slots_overflow_ready_and_durable', 'independent_cli_live_claim_race_at_most_once');
    phase = 'functional';

    // Equivalent eligible/missing/native-only public responses. Validate signed
    // state and countdown before normalizing only legitimately fresh fields.
    const eligible = await issue(21, 'privacy-eligible');
    const missingResponse = await http(`${base}/?rest_route=/pinova/user/authenticate`, {
        label: 'privacy-missing', source: ++sequence, body: { identifier: mobile(999) }, headers: { Origin: base },
    });
    const nativeResponse = await http(`${base}/?rest_route=/pinova/user/authenticate`, {
        label: 'privacy-native', source: ++sequence, body: { identifier: mobile(80) }, headers: { Origin: base },
    });
    const missing = normalized(missingResponse);
    const native = normalized(nativeResponse);
    ensure(JSON.stringify(eligible.body) === JSON.stringify(missing.body) && JSON.stringify(eligible.body) === JSON.stringify(native.body),
        'Public response reveals eligibility or native-only policy.');
    await quiescent();
    const missingState = await inspect({ token: missing.token, start: missingResponse.completed });
    const nativeState = await inspect({ token: native.token, start: nativeResponse.completed });
    ensure(missingState.rows === 0 && nativeState.rows === 0, 'A decoy/native-only request created an OTP.');
    const beforeDenied = records.length;
    const foreign = await http(`${base}/?rest_route=/pinova/user/authenticate`, {
        label: 'foreign-origin', source: ++sequence, body: { identifier: mobile(22) }, headers: { Origin: 'https://foreign.example.test' },
    });
    const unsigned = await http(`${base}/?rest_route=/pinova/user/authenticate`, {
        label: 'unsigned-flow', source: ++sequence, body: { identifier: mobile(22), flow_id: 'a'.repeat(32) }, headers: { Origin: base },
    });
    ensure(foreign.status >= 400 && unsigned.status >= 400 && records.length === beforeDenied,
        'Origin or unsigned-flow boundary failed.');
    tests.push('uniform_public_eligible_missing_native_policy', 'foreign_origin_and_unsigned_flow_denied');

    const recovered = await issue(30, 'forget-positive', { forget: true });
    await quiescent();
    const recoveryState = await inspect(recovered);
    ensure(recoveryState.purpose === 'forget' && recoveryState.accepted_channels.includes('sms'),
        'Forgot API did not issue the recovery-specific delivered proof.');
    await verifyFlow(recovered, 'forget');

    // Obtain a real session through the real OTP login endpoint; only the
    // session-specific REST nonce is derived through a private CLI pipe.
    const loggedIn = await verifyFlow(eligible);
    // Native login expires old cookies before issuing fresh values. A browser
    // keeps the last value for each name; sending both can restore the old one.
    const cookieJar = new Map();
    for (const header of loggedIn.headers['set-cookie'] || []) {
        const part = header.split(';')[0];
        const separator = part.indexOf('=');
        ensure(separator > 0, 'Native login returned an invalid cookie field.');
        cookieJar.set(part.slice(0, separator), part);
    }
    const cookieParts = [...cookieJar.values()].filter(part => {
        const value = decodeURIComponent(part.slice(part.indexOf('=') + 1)).trim();
        return value !== '' && value !== 'deleted';
    });
    const loggedCookie = cookieParts.find(value => value.startsWith('wordpress_logged_in_'));
    ensure(loggedCookie, 'Real OTP login did not establish its native WordPress session.');
    const nonce = await site({ action: 'nonce', logged_in_cookie: decodeURIComponent(loggedCookie.slice(loggedCookie.indexOf('=') + 1)) });
    mobileCases.set(providerMobile(21), 'mobile-proof');
    delays.set('mobile-proof', 200);
    const mobileStart = now();
    const mobileSource = ++sequence;
    const mobileResponse = await http(`${base}/?rest_route=/pinova/mobile/request`, {
        label: 'mobile-proof', source: mobileSource, body: { mobile: mobile(21) },
        headers: { Origin: base, Cookie: cookieParts.join('; '), 'X-WP-Nonce': nonce.nonce },
    });
    const mobileBody = JSON.parse(mobileResponse.text);
    ensure(mobileResponse.status === 200 && mobileBody.success === true && typeof mobileBody.data.jwt === 'string',
        'Authenticated mobile proof initiation failed.');
    const mobileFlow = { token: mobileBody.data.jwt, start: mobileStart, completed: mobileResponse.completed, label: 'mobile-proof' };
    await quiescent();
    const mobileState = await inspect(mobileFlow);
    ensure(mobileState.rows === 1 && mobileState.purpose === 'verify_mobile' && mobileState.otp_expiry === mobileState.expiry,
        'Authenticated mobile proof did not preserve its signed account/purpose/deadline.');
    const mobileVerification = await http(`${base}/?rest_route=/pinova/mobile/verify`, {
        label: 'mobile-proof-verify', source: mobileSource, body: { jwt: mobileFlow.token, code: codes.get('mobile-proof') },
        headers: { Origin: base, Cookie: cookieParts.join('; '), 'X-WP-Nonce': nonce.nonce },
    });
    ensure(mobileVerification.status === 200 && JSON.parse(mobileVerification.text).success === true,
        'Authenticated purpose-correct mobile proof verification failed.');
    tests.push('forgot_purpose_real_http', 'authenticated_mobile_request_and_verification_real_http');

    // Time out after the real transport deadline, while the provider may still
    // accept. Recovery must retain the existing proof and must never resend.
    const uncertain = await issue(27, 'uncertain', {}, 9500);
    await until(() => events().some(event => event.case === 'uncertain' && event.event === 'provider_return'),
        'Real aggregate provider budget was not effective.', 11000);
    const uncertainStart = events().find(event => event.case === 'uncertain' && event.event === 'provider_enter');
    const uncertainEnd = events().find(event => event.case === 'uncertain' && event.event === 'provider_return');
    ensure(uncertainEnd.at - uncertainStart.at <= 9, 'Provider transport exceeded its bounded deadline.');
    await quiescent();
    await drainDue('uncertain-recovery');
    const uncertainty = await inspect(uncertain);
    ensure(uncertainty.rows === 1 && records.filter(record => record.case === uncertain.label).length === 1,
        'Uncertain provider acceptance caused automatic replay or lost proof.');
    receipt.uncertainty = { transport_seconds: uncertainEnd.at - uncertainStart.at, provider_attempts: 1, retained_otp: true };
    tests.push('real_transport_deadline_and_uncertain_provider_no_resend');

    writeFileSync(path.join(root, 'mode'), 'off\n');
    const cancelled = await issue(28, 'cancelled');
    await site({ action: 'cancel', jwt: cancelled.token });
    const expired = await issue(29, 'expired');
    await site({ action: 'expire', jwt: expired.token });
    await drainDue('cancel-expiry-recovery');
    ensure(!records.some(record => ['cancelled', 'expired'].includes(record.case)), 'Cancelled/expired queue work reached a provider.');
    tests.push('cancelled_and_expired_acknowledged_jobs_do_not_send');
    await quiescent();
    const finalStatus = JSON.parse((await http(`${base}/fpm-status?json&full`)).text);
    ensure(finalStatus['active processes'] === 0 && finalStatus['listen queue'] === 0 && activeProvider === 0,
        'Fixture did not finish with quiescent workers and an empty FPM listen queue.');
    receipt.state = 'passed';
    receipt.provider = { attempts: records.length, peak_all_workers_including_cli: peakProvider,
        measured_workload_accepted: records.filter(record => /^(off|on)-/.test(record.case) && record.accepted !== null).length };
    receipt.measurement_limits = [
        'Resource comparison excludes the minute scheduler wait: OFF uses an immediate independent existing CLI worker and includes its CPU/RSS.',
        'RSS/PSS and FPM status are sampled; they are not allocator-accurate peaks. Occupancy integrates native FPM status through full quiescence plus measured CLI wall time.',
        'Early PHP shutdown markers are not request-end or post-response-tail duration evidence and are excluded from worker occupancy.',
        'DB counters include equal harness observation activity and are scoped to the disposable job database server; Redis is absent.',
        'Native server response completion is established; remote browser packet ordering and physical SMS receipt are outside this fixture.',
    ];
    process.stdout.write(`Full-stack FPM acceptance passed: ${tests.length} assertions/groups; receipt contains no OTPs or identifiers.\n`);
} catch (error) {
    receipt.state = 'failed';
    // A native JSON/network error can quote arbitrary response text. Publish
    // only our finite assertions; no raw error, response or provider payload.
    receipt.failure = error?.fixtureAssertion === true ? error.message :
        'Full-stack fixture failed before a finite assertion; inspect private runner diagnostics.';
    receipt.failure_phase = phase;
    receipt.failure_sources = finiteFailureSources();
    receipt.http_diagnostics = httpDiagnostics;
    process.stderr.write(`Full-stack FPM acceptance failed: ${receipt.failure}\n`);
    process.exitCode = 1;
} finally {
    sanitizedReceipt('full-stack-result.json', receipt);
    for (const timer of pendingTimers) clearTimeout(timer);
    clearInterval(monitor);
    clearInterval(statusMonitor);
    provider.closeAllConnections();
    await new Promise(resolve => provider.close(resolve));
    codes.clear();
    mobileCases.clear();
}
