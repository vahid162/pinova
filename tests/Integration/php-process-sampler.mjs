/** Bounded Linux observations of this disposable fixture's PHP processes. */
import { readFileSync } from 'node:fs';

function poolRole(commandLine) {
    if (typeof commandLine !== 'string' || commandLine.length > 4096) return 'unknown';
    const title = commandLine.split('\0').join(' ').trim().replace(/\s+/g, ' ');
    return title.match(/^php-fpm(?:[0-9.]+)?: pool (fixture|fixture_status)$/)?.[1] || 'unknown';
}

export function inspectProcess(pid, ticks, read = file => readFileSync(file, 'utf8')) {
    if (!Number.isSafeInteger(pid) || pid < 2 || !Number.isFinite(ticks) || ticks <= 0) throw new Error('invalid_process_identity');
    const parse = text => {
        if (!text.startsWith(`${pid} (`) || text.length > 8192) throw new Error('invalid_process_stat');
        const fields = text.slice(text.lastIndexOf(') ') + 2).trim().split(/\s+/);
        if (['Z', 'X'].includes(fields[0])) { const error = new Error('process_exited'); error.code = 'PROCESS_EXITED'; throw error; }
        const start = Number(fields[19]);
        const cpu = (Number(fields[11]) + Number(fields[12])) / ticks;
        if (!Number.isSafeInteger(start) || start <= 0 || !Number.isFinite(cpu) || cpu < 0) throw new Error('invalid_process_stat');
        return { start, cpu };
    };
    const before = parse(read(`/proc/${pid}/stat`));
    const status = read(`/proc/${pid}/status`);
    const rollup = read(`/proc/${pid}/smaps_rollup`);
    const pool = poolRole(read(`/proc/${pid}/cmdline`));
    const rss = Number(status.match(/^VmRSS:\s+(\d+) kB$/m)?.[1]);
    const pss = Number(rollup.match(/^Pss:\s+(\d+) kB$/m)?.[1]);
    const after = parse(read(`/proc/${pid}/stat`));
    if (before.start !== after.start || !Number.isSafeInteger(rss) || rss <= 0 || !Number.isSafeInteger(pss) || pss <= 0) {
        throw new Error('incomplete_process_observation');
    }
    return { pid, start: after.start, cpu: after.cpu, rss, pss, pool };
}

export class PHPProcessSampler {
    constructor({ master, ticks, inspect = pid => inspectProcess(pid, ticks), children = () =>
        readFileSync(`/proc/${master}/task/${master}/children`, 'utf8').trim().split(/\s+/).filter(Boolean).map(Number),
    clock = () => performance.now() / 1000 }) {
        this.master = master;
        this.inspect = inspect;
        this.children = children;
        this.clock = clock;
        this.failures = new Set();
        this.cli = new Map();
        this.first = new Map();
        this.last = new Map();
        this.expectedFpm = null;
        this.expectedFpmRows = [];
        this.firstIdentityDelta = null;
        this.peak = 0;
        this.samples = 0;
        this.started = this.clock();
        this.previousAt = null;
    }
    fail(reason) { this.failures.add(reason); }
    registerCli(pid) {
        if (this.cli.size >= 32 || this.cli.has(pid)) { this.fail('cli_registration_bound'); return false; }
        try {
            const observed = this.inspect(pid);
            this.cli.set(pid, { start: observed.start, final: false });
            return true;
        } catch { this.fail('cli_start_unreadable'); return false; }
    }
    finalCli(pid) {
        const state = this.cli.get(pid);
        if (!state) { this.fail('cli_final_without_registration'); return; }
        const sample = this.sample();
        if (sample.workers.some(worker => worker.kind === 'cli' && worker.pid === pid && worker.start === state.start)) state.final = true;
        else this.fail('cli_final_unreadable');
    }
    closeCli(pid) {
        const state = this.cli.get(pid);
        if (!state?.final) this.fail('cli_missing_final_sample');
        this.cli.delete(pid);
    }
    sample() {
        const at = this.clock();
        if (!Number.isFinite(at) || (this.previousAt !== null && (at < this.previousAt || at - this.previousAt > 0.5))) {
            this.fail('process_sampling_discontinuity');
        }
        this.previousAt = at;
        let pids = [];
        try {
            const children = this.children();
            if (!Array.isArray(children) || children.length < 1 || children.length > 64 ||
                children.some(pid => !Number.isSafeInteger(pid) || pid < 2) || new Set(children).size !== children.length || children.includes(this.master)) {
                throw new Error('invalid_process_list');
            }
            pids = [this.master, ...children];
        } catch { this.fail('fpm_process_list_unavailable'); }
        const workers = [];
        for (const pid of [...pids, ...this.cli.keys()]) {
            try {
                const observed = this.inspect(pid);
                const kind = this.cli.has(pid) ? 'cli' : pid === this.master ? 'fpm_master' : 'fpm_worker';
                if (kind === 'cli' && observed.start !== this.cli.get(pid).start) throw new Error('cli_identity_changed');
                const key = `${pid}:${observed.start}`;
                if (kind !== 'cli') {
                    if (!this.first.has(key)) this.first.set(key, observed.cpu);
                    if (this.last.has(key) && this.last.get(key) > observed.cpu) this.fail('process_cpu_reversed');
                    this.last.set(key, observed.cpu);
                }
                workers.push({ ...observed, kind });
            } catch (error) {
                // A positively sampled shutdown fence authorizes normal exit;
                // ENOENT/zombie afterwards is not an unexplained lost process.
                if (!(this.cli.get(pid)?.final && ['ENOENT', 'ESRCH', 'PROCESS_EXITED'].includes(error.code))) {
                    this.fail(this.cli.has(pid) ? 'cli_process_unreadable' : 'fpm_process_unreadable');
                }
            }
        }
        const current = workers.filter(worker => worker.kind !== 'cli').map(worker => ({ pid: worker.pid, start: worker.start,
            pool: worker.kind === 'fpm_master' ? 'master' : ['fixture', 'fixture_status'].includes(worker.pool) ? worker.pool : 'unknown' }))
            .sort((a, b) => a.pid - b.pid);
        const identity = current.map(worker => `${worker.pid}:${worker.start}`).sort().join(',');
        if (this.expectedFpm === null) { this.expectedFpm = identity; this.expectedFpmRows = current; }
        else if (identity !== this.expectedFpm) {
            this.fail('fpm_process_identity_changed');
            if (this.firstIdentityDelta === null) {
                const key = worker => `${worker.pid}:${worker.start}`;
                const oldKeys = new Set(this.expectedFpmRows.map(key));
                const newKeys = new Set(current.map(key));
                const elapsed = (at - this.started) * 1000;
                this.firstIdentityDelta = { after_ms: Number.isFinite(elapsed) && elapsed >= 0 && elapsed <= 3600000 ? Math.round(elapsed) : null,
                    initial: this.expectedFpmRows, observed: current,
                    added: current.filter(worker => !oldKeys.has(key(worker))),
                    removed: this.expectedFpmRows.filter(worker => !newKeys.has(key(worker))) };
            }
        }
        this.peak = Math.max(this.peak, workers.reduce((sum, worker) => sum + worker.pss, 0));
        this.samples++;
        return { at, workers };
    }
    summary() {
        if (this.cli.size) this.fail('cli_still_live_at_measurement_end');
        if (this.samples < 2 || !this.expectedFpm) this.fail('process_samples_missing');
        return {
            coverage_complete: this.failures.size === 0,
            coverage_reasons: [...this.failures].sort(),
            peak_sampled_total_php_pss_kib: this.peak,
            fpm_cpu_seconds: [...this.last].reduce((sum, [key, value]) => sum + value - this.first.get(key), 0),
            process_samples: this.samples,
            process_sample_scope: 'run_owned_fpm_master_workers_and_measured_cli',
            initial_fpm_inventory: this.expectedFpmRows,
            first_identity_delta: this.firstIdentityDelta,
            limits: ['sampled_not_allocator_peak', 'cli_first_sample_precedes_wp_cli_bootstrap', 'cpu_excludes_database_and_host'],
        };
    }
}
