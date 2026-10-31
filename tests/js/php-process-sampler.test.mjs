import assert from 'node:assert/strict';
import test from 'node:test';
import { inspectProcess, PHPProcessSampler } from '../Integration/php-process-sampler.mjs';

function fixture() {
    let at = 1;
    const processes = new Map([[11, { pid: 11, start: 10, cpu: 1, rss: 100, pss: 70 }],
        [12, { pid: 12, start: 20, cpu: 2, rss: 200, pss: 130 }]]);
    const sampler = new PHPProcessSampler({ master: 11, ticks: 100, children: () => [12], clock: () => at,
        inspect: pid => { if (!processes.has(pid)) throw new Error('unreadable'); return { ...processes.get(pid) }; } });
    return { sampler, processes, advance: value => { at += value; } };
}
test('PSS peak sums simultaneous FPM and CLI observations and includes master CPU', () => {
    const { sampler, processes, advance } = fixture();
    sampler.sample();
    processes.set(13, { pid: 13, start: 30, cpu: 0, rss: 90, pss: 60 });
    assert.equal(sampler.registerCli(13), true);
    advance(0.04); sampler.sample();
    processes.get(11).cpu += 0.1; processes.get(12).cpu += 0.2;
    advance(0.04); sampler.finalCli(13); sampler.closeCli(13); processes.delete(13);
    advance(0.04); sampler.sample();
    const result = sampler.summary();
    assert.equal(result.coverage_complete, true);
    assert.equal(result.peak_sampled_total_php_pss_kib, 260);
    assert.ok(Math.abs(result.fpm_cpu_seconds - 0.3) < 1e-9);
});
test('process disappearance and PID reuse latch incomplete coverage', () => {
    const { sampler, processes, advance } = fixture(); sampler.sample();
    processes.delete(12); advance(0.04); sampler.sample();
    processes.set(12, { pid: 12, start: 99, cpu: 0, rss: 200, pss: 130 });
    advance(0.04); sampler.sample();
    const result = sampler.summary();
    assert.equal(result.coverage_complete, false);
    assert.ok(result.coverage_reasons.includes('fpm_process_unreadable'));
    assert.ok(result.coverage_reasons.includes('fpm_process_identity_changed'));
});
test('missing final CLI witness and sample discontinuity cannot become healthy later', () => {
    const { sampler, processes, advance } = fixture(); sampler.sample();
    processes.set(13, { pid: 13, start: 30, cpu: 0, rss: 90, pss: 60 }); sampler.registerCli(13);
    sampler.closeCli(13); advance(0.6); sampler.sample(); advance(0.04); sampler.sample();
    assert.deepEqual(sampler.summary().coverage_reasons, ['cli_missing_final_sample', 'process_sampling_discontinuity']);
});
test('CLI PID reuse rejects the final witness', () => {
    const { sampler, processes, advance } = fixture(); sampler.sample();
    processes.set(13, { pid: 13, start: 30, cpu: 0, rss: 90, pss: 60 }); sampler.registerCli(13);
    processes.get(13).start = 31; advance(0.04); sampler.finalCli(13); sampler.closeCli(13);
    assert.equal(sampler.summary().coverage_complete, false);
});
test('positive final sampling distinguishes normal exit from unobserved disappearance', () => {
    let at = 0;
    const sampler = new PHPProcessSampler({ master: 11, ticks: 100, children: () => [12], clock: () => at,
        inspect: pid => { if (pid === 13 && at > 0.1) { const error = new Error('exited'); error.code = 'ENOENT'; throw error; }
            return { pid, start: pid, cpu: 1, rss: 10, pss: 8 }; } });
    sampler.sample(); sampler.registerCli(13); at = 0.08; sampler.finalCli(13);
    at = 0.12; sampler.sample(); sampler.closeCli(13);
    assert.equal(sampler.summary().coverage_complete, true);
});
test('native process inspection refuses missing PSS and identity changes between reads', () => {
    const stat = start => `15 (php test) S ${Array.from({ length: 20 }, (_, i) => i === 18 ? start : 1).join(' ')}\n`;
    let reads = 0;
    assert.throws(() => inspectProcess(15, 100, file => file.endsWith('/stat') ? stat(10) : file.endsWith('/status') ? 'VmRSS: 100 kB\n' : ''), /incomplete_process_observation/);
    assert.throws(() => inspectProcess(15, 100, file => file.endsWith('/stat') ? stat(++reads === 1 ? 10 : 11) : file.endsWith('/status') ? 'VmRSS: 100 kB\n' : 'Pss: 60 kB\n'), /incomplete_process_observation/);
});
