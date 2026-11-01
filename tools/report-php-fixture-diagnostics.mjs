/** Disposable wp-env transport; only the finite PHP projection reaches stdout/artifacts. */
import { execFileSync } from 'node:child_process';
import { existsSync, lstatSync, mkdirSync, readFileSync, realpathSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { cleanupFailureEvidence } from '../tests/browser/cli-failure-evidence.mjs';

const phase = process.argv[2];
const expected = `pinova-browser-${process.env.GITHUB_RUN_ID}-${process.env.GITHUB_RUN_ATTEMPT}`;
if (!['preflight', 'collect'].includes(phase) || process.env.CI !== 'true' || process.env.GITHUB_ACTIONS !== 'true' ||
    !/^\d+$/.test(process.env.GITHUB_RUN_ID || '') || !/^\d+$/.test(process.env.GITHUB_RUN_ATTEMPT || '') ||
    process.env.PINOVA_TEST_PROFILE !== 'third-party' || process.env.COMPOSE_PROJECT_NAME !== expected ||
    process.env.WP_ENV_HOME !== `/tmp/${expected}` || realpathSync(process.env.WP_ENV_HOME) !== `/tmp/${expected}`) {
    throw new Error('PHP diagnostics require the exact disposable wp-env.');
}
const directory = 'test-results/php-diagnostics';
const statePath = path.join(process.env.WP_ENV_HOME, '.pinova-php-diagnostics-cursor.json');
const schema = 'pinova.fixture-php-diagnostics.v1';
const countKeys = ['deprecated', 'warning', 'fatal', 'notice', 'parse', 'other', 'elementor_false_to_array', 'headers_already_sent'];
const reasons = new Set(['none', 'missing', 'unreadable', 'non_regular', 'file_bound', 'changed_during_read',
    'invalid_capture', 'identity_changed', 'read_length', 'partial_line', 'line_count_bound', 'line_bound', 'baseline_unknown', 'cursor_mismatch']);
const integer = (v, max) => Number.isSafeInteger(v) && v >= 0 && v <= max;
const cursor = v => v && typeof v.dev === 'string' && typeof v.ino === 'string' && /^\d{1,20}$/.test(v.dev) && /^\d{1,20}$/.test(v.ino) && integer(v.size, 262144) &&
    typeof v.sha256 === 'string' && /^[a-f0-9]{64}$/.test(v.sha256) && v.line_complete === true;
let report;
try {
    let baseline = null;
    if (phase === 'collect' && existsSync(statePath)) {
        const stateInfo = lstatSync(statePath);
        if (!stateInfo.isFile() || stateInfo.size > 2048) throw new Error('Invalid finite PHP diagnostic cursor.');
        baseline = JSON.parse(readFileSync(statePath, 'utf8'));
    }
    const plugin = path.basename(process.cwd());
    const output = execFileSync('npx', ['--no-install', 'wp-env', 'run', 'cli', `--env-cwd=wp-content/plugins/${plugin}`,
        'wp', 'eval-file', '--use-include', 'tests/Integration/php-diagnostics.php', phase,
        JSON.stringify(cursor(baseline) ? baseline : null)], { cwd: process.cwd(), env: process.env,
        encoding: 'utf8', timeout: 30000, maxBuffer: 1048576, stdio: ['ignore', 'pipe', 'pipe'] });
    const prefix = 'PINOVA_BROWSER_PHP_DIAGNOSTICS ';
    const lines = output.split('\n').filter(line => line.startsWith(prefix));
    if (lines.length !== 1) throw new Error('Missing finite PHP diagnostic receipt.');
    const value = JSON.parse(lines[0].slice(prefix.length));
    const settings = value.settings;
    const result = value.result;
    if (value.schema !== schema || value.phase !== phase || !settings || !result ||
        !['observed', 'unknown'].includes(result.state) || !reasons.has(result.reason) ||
        ![true, false].includes(settings.debug) || ![true, false].includes(settings.log) || ![true, false, null].includes(settings.display)) {
        throw new Error('Invalid finite PHP diagnostic receipt.');
    }
    const configurationOK = settings.debug === true && settings.log === true && settings.display === false;
    report = { schema, phase, settings: { debug: settings.debug, log: settings.log, display: settings.display },
        configuration_ok: configurationOK, state: result.state, reason: result.reason };
    if (phase === 'preflight') {
        report.logging_ready = result.logging_ready === true;
        report.logging_route_expected = result.logging_route_expected === true;
        if (configurationOK && result.state === 'observed' && report.logging_ready && report.logging_route_expected && cursor(result.cursor)) writeFileSync(statePath,
            JSON.stringify({ dev: result.cursor.dev, ino: result.cursor.ino, size: result.cursor.size,
                sha256: result.cursor.sha256, line_complete: true }), { flag: 'wx', mode: 0o600 });
        else process.exitCode = 1;
    } else {
        if (!['preflight_to_before_cleanup', 'current_file_only', 'unavailable'].includes(result.scope) ||
            !(result.bytes === null || integer(result.bytes, 262144)) || !(result.lines === null || integer(result.lines, 4096)) ||
            !(result.counts === null || countKeys.every(key => integer(result.counts[key], 4096)))) {
            throw new Error('Invalid finite PHP diagnostic counters.');
        }
        report.scope = result.scope; report.bytes = result.bytes; report.lines = result.lines;
        report.counts = result.counts === null ? null : Object.fromEntries(countKeys.map(key => [key, result.counts[key]]));
    }
    if (!configurationOK || result.state !== 'observed') process.exitCode = 1;
} catch (error) {
    report = { schema, phase, state: 'unknown', reason: 'collector_failure', child: cleanupFailureEvidence(error) };
    process.exitCode = 1;
}
try {
    mkdirSync(directory, { recursive: true });
    writeFileSync(path.join(directory, `${phase}.json`), JSON.stringify(report, null, 2) + '\n', { mode: 0o600 });
} catch {
    report = { schema, phase, state: 'unknown', reason: 'artifact_write_failure' };
    process.exitCode = 1;
}
console.log(JSON.stringify(report));
