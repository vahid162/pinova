/** U1 evidence only: characterize unchanged RC21 on a disposable hosted runner. */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chmodSync, lstatSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repo = path.resolve(fileURLToPath(new URL('../..', import.meta.url)));
const rc21 = '96386b9fdd803086e9ecfe2af421034976dc185d';
// Match the tracked runtime inputs archived by tools/build.sh, including the
// dependency locks. New diagnostic code cannot be labelled an RC21 baseline.
const runtimePaths = ['pinova.php', 'readme.txt', 'LICENSE', 'uninstall.php', 'composer.json',
    'composer.lock', 'assets', 'src', 'templates', 'utils'];
const kinds = ['idle', 'normal', 'slow', 'concurrent'];
const prefix = 'PINOVA_FULL_STACK_RESULT=';
class EvidenceGuard extends Error {}
const ensure = (condition, message) => { if (!condition) throw new EvidenceGuard(message); };
const finite = value => typeof value === 'number' && Number.isFinite(value) && value >= 0;
const genericFailure = 'RC21 baseline evidence validation failed; inspect the finite guards and private runner diagnostics.\n';
const failureMessage = error => error instanceof EvidenceGuard
    ? `RC21 baseline evidence validation failed: ${error.message}\n` : genericFailure;

function verifyLogging(observation, profile) {
    ensure(['normal', 'diagnostic'].includes(profile), 'Unknown baseline logging profile.');
    const debug = profile === 'diagnostic';
    ensure(observation?.profile === profile && observation.minimum_level === 'warning' &&
        observation.diagnostic_window_active === debug && observation.debug_enabled === debug &&
        observation.info_enabled === debug && observation.warning_enabled === true,
    'Effective logging configuration differs from the requested baseline profile.');
    // Copy only finite, known configuration facts, never the surrounding log.
    return { profile, minimum_level: 'warning', diagnostic_window_active: debug,
        debug_enabled: debug, info_enabled: debug, warning_enabled: true };
}

function summarize(receipt, source) {
    ensure(receipt.fixture === 'pinova-full-stack-fpm-v1' && receipt.state === 'passed' &&
        receipt.source_commit === source, 'Missing passing full-stack evidence for this exact checkout.');
    ensure(receipt.limits?.fast_tails === 2 && receipt.limits.provider_budget_seconds === 8 &&
        receipt.limits.normal_flows_per_resource_variant === 10 && receipt.resource_gates &&
        receipt.tests?.includes('frozen_idle_normal_slow_and_concurrent_resource_limits'),
    'Existing full-stack safety gates were not retained.');
    return Object.fromEntries([['immediate_off', receipt.baseline], ['immediate_on', receipt.candidate]].map(([mode, results]) =>
        [mode, Object.fromEntries(kinds.map(kind => {
            const result = results?.[kind];
            ensure(result && Array.isArray(result.ordinary_samples_ms) && result.ordinary_samples_ms.length >= 10 &&
                result.ordinary_samples_ms.every(finite) && finite(result.ordinary_p95_ms),
            'Incomplete ordinary-request baseline observations.');
            const resources = Object.fromEntries(['total_php_cpu_seconds', 'peak_sampled_worker_rss_kib',
                'peak_sampled_worker_pss_kib', 'retained_worker_pss_kib', 'wall_seconds',
                'process_samples', 'status_samples'].map(key => {
                ensure(finite(result.resources?.[key]), 'Missing finite resource evidence.');
                return [key, result.resources[key]];
            }));
            ensure(resources.process_samples > 0 && resources.status_samples > 0,
                'Resource observation coverage is empty.');
            return [kind, { ordinary_responses: result.ordinary_samples_ms.length,
                ordinary_p95_ms: result.ordinary_p95_ms, ...resources }];
        }))]));
}

function verifySource() {
    const git = args => execFileSync('git', ['-c', `safe.directory=${repo}`, ...args], {
        cwd: repo, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'],
    }).trim();
    const source = git(['rev-parse', 'HEAD']);
    ensure(/^[a-f0-9]{40}$/.test(source), 'Expected a full checkout commit.');
    try {
        git(['diff', '--exit-code', rc21, 'HEAD', '--', ...runtimePaths]);
        git(['diff', '--exit-code', 'HEAD', '--', ...runtimePaths]);
    } catch {
        throw new EvidenceGuard('Baseline requires byte-identical tracked RC21 runtime and dependency inputs.');
    }
    return source;
}

function readBounded(target) {
    const stat = lstatSync(target);
    ensure(stat.isFile() && !stat.isSymbolicLink() && stat.size <= 2 * 1024 * 1024,
        'Baseline input is not a bounded regular fixture file.');
    return readFileSync(target, 'utf8');
}

function observation(text) {
    const lines = text.split('\n').filter(line => line.startsWith(prefix));
    ensure(lines.length <= 1, 'Ambiguous private fixture observation.');
    return lines.length ? JSON.parse(lines[0].slice(prefix.length)) : null;
}

function selfTest() {
    // Exercise the real settings contract without booting WordPress or a DB.
    // Its non-idempotent duration sanitizer also explains the two-step setup.
    const durationProof = JSON.parse(execFileSync('php', ['-r', String.raw`
        namespace Nabik\Utils\V1 { class Settings {} }
        namespace {
            define('DAY_IN_SECONDS', 86400);
            function absint($value) { return abs((int) $value); }
            require $argv[1];
            $before = time();
            $deadline = \Pinova\Admin\Settings::sanitize_diagnostic_window(3600);
            echo json_encode([
                'duration_creates_deadline' => $deadline >= $before + 3600 && $deadline <= time() + 3600,
                'absolute_epoch_is_rejected' => 0 === \Pinova\Admin\Settings::sanitize_diagnostic_window(time() + 3600),
                'second_sanitize_rejects_deadline' => 0 === \Pinova\Admin\Settings::sanitize_diagnostic_window($deadline),
            ], JSON_THROW_ON_ERROR);
        }
    `, path.join(repo, 'src/Admin/Settings.php')], {
        encoding: 'utf8', timeout: 5000, stdio: ['ignore', 'pipe', 'pipe'],
    }));
    assert.equal(durationProof.duration_creates_deadline, true);
    assert.equal(durationProof.absolute_epoch_is_rejected, true);
    assert.equal(durationProof.second_sanitize_rejects_deadline, true);
    const good = { profile: 'normal', minimum_level: 'warning', diagnostic_window_active: false,
        debug_enabled: false, info_enabled: false, warning_enabled: true, untrusted: 'must not escape' };
    assert.equal(JSON.stringify(verifyLogging(good, 'normal')).includes('untrusted'), false);
    assert.throws(() => verifyLogging(good, 'diagnostic'));
    assert.throws(() => verifyLogging({ ...good, debug_enabled: true }, 'normal'));
    assert.throws(() => verifyLogging({ ...good, minimum_level: 'info' }, 'normal'));
    assert.throws(() => verifyLogging(good, 'unknown'));
    const debug = { ...good, profile: 'diagnostic', diagnostic_window_active: true, debug_enabled: true, info_enabled: true };
    assert.equal(verifyLogging(debug, 'diagnostic').debug_enabled, true);
    assert.throws(() => verifyLogging({ ...debug, diagnostic_window_active: false }, 'diagnostic'));
    const resources = Object.fromEntries(['total_php_cpu_seconds', 'peak_sampled_worker_rss_kib',
        'peak_sampled_worker_pss_kib', 'retained_worker_pss_kib', 'wall_seconds', 'process_samples', 'status_samples'].map(key => [key, 1]));
    const results = Object.fromEntries(kinds.map(kind => [kind, {
        ordinary_samples_ms: Array(10).fill(1), ordinary_p95_ms: 1, resources,
    }]));
    const receipt = { fixture: 'pinova-full-stack-fpm-v1', state: 'passed', source_commit: rc21,
        limits: { fast_tails: 2, provider_budget_seconds: 8, normal_flows_per_resource_variant: 10 },
        resource_gates: {}, tests: ['frozen_idle_normal_slow_and_concurrent_resource_limits'],
        baseline: results, candidate: results };
    assert.equal(summarize(receipt, rc21).immediate_on.normal.ordinary_responses, 10);
    assert.throws(() => summarize({ ...receipt, state: 'failed' }, rc21));
    assert.throws(() => summarize(receipt, 'wrong-source'));
    assert.throws(() => summarize({ ...receipt, tests: [] }, rc21));
    assert.throws(() => summarize({ ...receipt, candidate: {} }, rc21));
    assert.throws(() => summarize({ ...receipt, candidate: { ...results,
        normal: { ...results.normal, resources: { ...resources, total_php_cpu_seconds: NaN } } } }, rc21));
    assert.throws(() => observation(`${prefix}{}\n${prefix}{}`));
    assert.match(failureMessage(new EvidenceGuard('Synthetic finite guard.')), /Synthetic finite guard\./);
    assert.equal(failureMessage(new Error('Synthetic private IO output.')), genericFailure);
    assert.equal(failureMessage(new SyntaxError('Synthetic private JSON output.')), genericFailure);
    process.stdout.write('Baseline evidence guards passed 20 bounded synthetic checks.\n');
}

try {
    if (process.argv[2] === '--self-test') {
        selfTest();
    } else {
        ensure(process.env.GITHUB_ACTIONS === 'true' && process.env.RUNNER_ENVIRONMENT === 'github-hosted' &&
            /^\d+$/.test(process.env.GITHUB_RUN_ID || '') && /^\d+$/.test(process.env.GITHUB_RUN_ATTEMPT || ''),
        'Baseline requires a disposable GitHub-hosted runner.');
        const source = verifySource();
        if (process.argv[2] === '--verify-source') {
            process.stdout.write('Tracked RC21 runtime and dependency inputs are unchanged.\n');
        } else {
            ensure(process.argv.length === 2, 'Unknown baseline command.');
            const root = `/tmp/pinova-fpm-full-${process.env.GITHUB_RUN_ID}-${process.env.GITHUB_RUN_ATTEMPT}`;
            ensure(process.env.PINOVA_FPM_FULL_STACK_ROOT === root && !lstatSync(root).isSymbolicLink() &&
                readBounded(path.join(root, 'fixture-owned')).trim() === `${process.env.GITHUB_RUN_ID}:${process.env.GITHUB_RUN_ATTEMPT}`,
            'Missing exact disposable fixture ownership.');
            const profile = process.env.PINOVA_FPM_LOGGING_PROFILE;
            const privateRoot = path.join(root, 'private');
            const setup = observation(readBounded(path.join(privateRoot, 'setup.log')));
            const logging = verifyLogging(setup?.logging, profile);
            const files = readdirSync(privateRoot).filter(name => /^cli-\d+\.log$/.test(name));
            ensure(files.length <= 1000, 'Private observation count exceeds its bound.');
            const storage = [];
            for (const name of files.sort((a, b) => Number(a.slice(4, -4)) - Number(b.slice(4, -4)))) {
                const item = observation(readBounded(path.join(privateRoot, name)));
                if (!item?.log_storage) continue;
                verifyLogging(item.logging, profile);
                ensure(Number.isSafeInteger(item.log_storage.rows) && item.log_storage.rows >= 0 &&
                    Number.isSafeInteger(item.log_storage.context_bytes) && item.log_storage.context_bytes >= 0,
                'Missing finite aggregate log-storage evidence.');
                storage.push({ rows: item.log_storage.rows, context_bytes: item.log_storage.context_bytes });
            }
            ensure(storage.length >= kinds.length * 2 * 2, 'Missing before/after workload logging observations.');
            const full = JSON.parse(readBounded(path.join(root, 'receipt/full-stack-result.json')));
            const result = { schema: 'pinova.diagnostics-baseline.v1', state: 'passed',
                rc21_runtime_commit: rc21, harness_commit: source, logging,
                logging_observation_process: 'WP-CLI reads the same disposable options used by real FPM requests',
                workload_observations: summarize(full, source), log_storage_snapshots: storage,
                evidence: ['full-stack-result.json', 'frozen-resource-gates.json'],
                limitations: [
                    'Both modes run RC21: immediate OFF/ON is not an RC21/candidate revision comparison.',
                    'Logging snapshots are whole-fixture aggregate rows/context bytes, not per-flow counters or disk allocation.',
                    'Peak PSS is per sampled FPM worker; retained PSS is an aggregate. Neither is peak aggregate PHP PSS.',
                    'CPU is measured PHP FPM plus CLI work, not host or database CPU; existing DB deltas include harness observations.',
                    'U6 still requires three paired revision runs, 100 ordinary responses per arm, valid aggregate PHP PSS coverage and rotation acceptance.',
                    'Global diagnostic mode is the existing RC21 window, not the future scoped diagnostic contract.',
                ] };
            const target = path.join(root, 'receipt/diagnostics-baseline.json');
            ensure(!lstatSync(path.dirname(target)).isSymbolicLink(), 'Unexpected receipt directory.');
            writeFileSync(target, JSON.stringify(result, null, 2) + '\n', { mode: 0o600, flag: 'wx' });
            chmodSync(target, 0o644);
            process.stdout.write(`RC21 ${profile} baseline evidence validated; existing safety gates passed.\n`);
        }
    }
} catch (error) {
    // Never quote private CLI output, provider responses or parser exceptions.
    // Only EvidenceGuard messages are static assertions owned by this source.
    process.stderr.write(failureMessage(error));
    process.exitCode = 1;
}
