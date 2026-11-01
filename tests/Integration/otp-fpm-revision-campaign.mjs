/** Six fresh arms on one disposable hosted runner. Never an on-site benchmark. */
import { execFileSync, spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { chmodSync, cpSync, existsSync, lstatSync, mkdirSync, readFileSync, readdirSync, realpathSync, rmSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { evaluateCampaign } from './revision-resource-gates.mjs';
import { completedWarmup, requestMatchedSignal11, interpretJitModeComparison } from './fpm-settings-observation.mjs';
import { DIAGNOSTIC_CASES, REVISION_ARM_TIMEOUT_MS, executionEvidence, readDiagnosticEvidence } from './otp-fpm-diagnostic-evidence.mjs';

const repo = path.resolve(fileURLToPath(new URL('../..', import.meta.url)));
const id = process.env.GITHUB_RUN_ID;
const attempt = process.env.GITHUB_RUN_ATTEMPT;
const runRoot = `/tmp/pinova-fpm-full-${id}-${attempt}`;
const campaignRoot = `/tmp/pinova-fpm-revisions-${id}-${attempt}`;
const requireFact = (value, message) => { if (!value) throw new Error(message); };
requireFact(process.env.GITHUB_ACTIONS === 'true' && process.env.RUNNER_ENVIRONMENT === 'github-hosted' &&
    /^\d+$/.test(id || '') && /^\d+$/.test(attempt || '') && process.getuid() === 0 &&
    process.env.PINOVA_FPM_FULL_STACK_ROOT === runRoot && process.env.PINOVA_FPM_CAMPAIGN_ROOT === campaignRoot,
'Revision campaign requires exact disposable hosted ownership.');
requireFact(!existsSync(runRoot) && realpathSync(campaignRoot) === campaignRoot &&
    lstatSync(campaignRoot).uid === 0 && !existsSync(`${campaignRoot}/fixture-owned`), 'Revision campaign path is already owned.');
const jitMode = process.env.PINOVA_FPM_JIT_MODE_COMPARE === '1';
requireFact(!jitMode || process.env.GITHUB_REF === 'refs/heads/proof/php84-jit-mode-20261101', 'Wrong source-bisection branch.');
const contractBytes = readFileSync(path.join(repo, jitMode ? 'tests/fixtures/jit-mode-comparison.json' : 'tests/fixtures/diagnostics-campaign.json'));
const contract = JSON.parse(contractBytes);
const php = process.env.PINOVA_FPM_PHP_VERSION;
const logging = process.env.PINOVA_FPM_LOGGING_PROFILE;
requireFact(contract.php.includes(php) && contract.logging_profiles.includes(logging), 'Invalid revision campaign profile.');
const probeId = process.env.PINOVA_FPM_PROBE_CASE || '';
const probeCase = jitMode ? { id: 'jit-mode-comparison', php: '8.4', logging: 'diagnostic' } : DIAGNOSTIC_CASES.find(item => item.id === probeId);
requireFact(!probeId || (probeCase && probeCase.php === php && probeCase.logging === logging), 'Invalid focused diagnostic case.');
const order = jitMode ? contract.order : probeCase ? [probeCase.arm] : contract.order;
requireFact(!jitMode || probeId === 'jit-mode-comparison', 'Wrong source-bisection probe.');
const git = args => execFileSync('git', args, { cwd: repo, encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 });
const workflowSource = git(['rev-parse', 'HEAD']).trim();
requireFact(workflowSource === process.env.GITHUB_SHA && /^[a-f0-9]{40}$/.test(workflowSource), 'Candidate is not this exact workflow revision.');
const candidate = jitMode ? contract.runtime_source : workflowSource;
if (jitMode) requireFact(candidate === 'a1bb4a8985502e38498bb1df53b719868ced40b8' &&
    JSON.stringify(order) === '["a1","b1","b2","a2","a3","b3"]' && contract.resource_acceptance === false,
'Source-bisection contract changed.');
requireFact(git(['rev-parse', `${contract.baseline_source}^{commit}`]).trim() === contract.baseline_source, 'Pinned baseline commit is unavailable.');
const manifestShape = sha => {
    const manifest = JSON.parse(git(['show', `${sha}:composer.json`]));
    return JSON.stringify({ require: manifest.require, autoload: manifest.autoload });
};
requireFact(manifestShape(candidate) === manifestShape(contract.baseline_source), 'Runtime manifest requirements differ; normalization needs review.');
const hash = bytes => createHash('sha256').update(bytes).digest('hex');
const inventoryBytes = readFileSync(path.join(repo, 'vendor/composer/installed.json'));
const inventory = hash(inventoryBytes);
const lock = readFileSync(path.join(repo, 'composer.lock'));
if (jitMode) requireFact(lock.equals(Buffer.from(git(['show', `${candidate}:composer.lock`]))), 'Source bisection changed locked dependencies.');
const runtimeSource = arm => jitMode ? candidate : arm.startsWith('a') ? contract.baseline_source : candidate;
function stockIdentity() {
    const files = { fpm: '/usr/sbin/php-fpm8.4', cli: '/usr/bin/php8.4', opcache: '/usr/lib/php/20240924/opcache.so' };
    const binary = Object.fromEntries(Object.entries(files).map(([role, file]) => [role, hash(readFileSync(realpathSync(file)))]));
    const config = {};
    for (const sapi of ['cli', 'fpm']) {
        const iniRoot = `/etc/php/8.4/${sapi}`;
        config[`${sapi}/php.ini`] = hash(readFileSync(`${iniRoot}/php.ini`));
        for (const name of readdirSync(`${iniRoot}/conf.d`).filter(name => name.endsWith('.ini')).sort())
            config[`${sapi}/conf.d/${name}`] = hash(readFileSync(`${iniRoot}/conf.d/${name}`));
    }
    const extensions = Object.fromEntries(readdirSync('/usr/lib/php/20240924').filter(name => name.endsWith('.so')).sort()
        .map(name => [name, hash(readFileSync(`/usr/lib/php/20240924/${name}`))]));
    const packages = {};
    for (const name of ['php8.4-fpm', 'php8.4-cli', 'php8.4-opcache']) {
        const version = execFileSync('dpkg-query', ['-W', '-f=${Version}', name], { encoding: 'utf8', maxBuffer: 4096 }).trim();
        requireFact(version === contract.package_version, 'Unexpected stock package version.'); packages[name] = version;
    }
    return { binary_sha256: binary, ini_sha256: config, extension_sha256: extensions, packages, resource_acceptance: false };
}
const stock = jitMode ? stockIdentity() : null;
function prepareJitMode(arm) {
    requireFact(jitMode && JSON.stringify(stockIdentity()) === JSON.stringify(stock), 'Stock runtime bytes changed.');
    const variant = contract.variants[arm];
    const mode = contract.jit_modes[variant];
    requireFact(['A', 'B'].includes(variant) && mode === (variant === 'A' ? '1235' : '1255'), 'Invalid JIT mode.');
    activeRow.jit_variant = variant;
    activeRow.jit_mode = mode;
    activeRow.stock_identity_sha256 = hash(JSON.stringify(stock));
    writeFileSync(`${runRoot}/jit-mode-comparison`, `${mode}\n`, { mode: 0o600 });
    return { PINOVA_FPM_JIT_MODE: mode, PINOVA_STOCK_FPM_REALPATH: realpathSync('/usr/sbin/php-fpm8.4') };
}
writeFileSync(`${campaignRoot}/fixture-owned`, `${id}:${attempt}\n`, { mode: 0o600 });
chmodSync(campaignRoot, 0o755); // Traversal for sanitized receipts; config/private files retain private modes.
mkdirSync(`${campaignRoot}/receipt`, { mode: 0o755 });
mkdirSync(`${campaignRoot}/private`, { mode: 0o700 });
const identity = { candidate, inventory, php, logging, profile: null };
const campaign = { schema: jitMode ? contract.schema : probeCase ? 'pinova.warmup-diagnostic.v1' : contract.schema, state: 'running', contract, contract_sha256: hash(contractBytes),
    identity, workflow_source: workflowSource, ...(jitMode ? { stock_engine: stock } : {}), composer_lock_sha256: hash(lock), dependency_normalization: 'both_code_revisions_use_identical_candidate_locked_production_vendor',
    ...(probeCase ? { resource_acceptance: false, probe_case: probeCase } : {}),
    active_arm: null, arms: order.map(arm => ({ arm, source: runtimeSource(arm), ...(jitMode ? { resource_acceptance: false } : {}),
        inventory_sha256: inventory, profile_sha256: null, source_archive_sha256: null,
        state: 'unexecuted', measurement: null, failure_phase: null, cleanup_status: 'not_started' })) };
function save() {
    const target = `${campaignRoot}/receipt/${jitMode ? 'jit-mode-comparison-bisection' : probeCase ? 'warmup-diagnostic' : 'revision-comparison'}.json`;
    writeFileSync(target, JSON.stringify(campaign, null, 2) + '\n', { mode: 0o600 }); chmodSync(target, 0o644);
}
save();
let campaignPhase = 'arm_setup';
let activeRow;
function cleanupError(code, witness = null) {
    const error = new Error('Owned arm cleanup is incomplete.'); error.code = code; error.witness = witness; return error;
}
function removeOwnedArm() {
    requireFact(realpathSync(runRoot) === runRoot && lstatSync(runRoot).uid === 0 &&
        readFileSync(`${runRoot}/fixture-owned`, 'utf8').trim() === `${id}:${attempt}`, 'Refusing cleanup of an unowned revision arm.');
    for (const file of ['fpm.pid', 'nginx.pid']) {
        if (!existsSync(`${runRoot}/${file}`)) continue;
        const pid = Number(readFileSync(`${runRoot}/${file}`, 'utf8').trim());
        if (Number.isSafeInteger(pid) && pid > 1 && existsSync(`/proc/${pid}/cmdline`)) {
            if (readFileSync(`/proc/${pid}/cmdline`, 'utf8').includes(runRoot)) {
                throw cleanupError('owned_process_live', { pid, role: file === 'fpm.pid' ? 'fpm_master' : 'nginx' });
            }
        }
    }
    rmSync(runRoot, { recursive: true });
}
try {
    for (const arm of order) {
        campaignPhase = 'arm_setup';
        activeRow = campaign.arms.find(row => row.arm === arm);
        activeRow.state = 'running'; activeRow.cleanup_status = 'pending';
        campaign.active_arm = arm; save();
        const source = runtimeSource(arm);
        mkdirSync(runRoot, { mode: 0o700 });
        cpSync(`${campaignRoot}/wordpress`, `${runRoot}/wordpress`, { recursive: true, errorOnExist: true, force: false });
        mkdirSync(`${runRoot}/runtime`, { mode: 0o700 });
        // Archive bytes, rather than an old dependency install, define each
        // first-party code revision. Only dependency lock/vendor are normalized.
        const archive = execFileSync('git', ['archive', '--format=tar', source], { cwd: repo, maxBuffer: 64 * 1024 * 1024 });
        activeRow.source_archive_sha256 = hash(archive); save();
        execFileSync('tar', ['--extract', '--directory', `${runRoot}/runtime`, '--no-same-owner'], { input: archive });
        const selectedMode = jitMode ? prepareJitMode(arm) : {};
        cpSync(path.join(repo, 'vendor'), `${runRoot}/runtime/vendor`, { recursive: true, errorOnExist: true, force: false });
        writeFileSync(`${runRoot}/runtime/composer.lock`, lock, { mode: 0o600 });
        requireFact(hash(readFileSync(`${runRoot}/runtime/vendor/composer/installed.json`)) === inventory, 'Normalized inventory changed.');
        const armDirectory = `${campaignRoot}/receipt/${arm}`;
        mkdirSync(armDirectory, { mode: 0o755 });
        const stdout = `${campaignRoot}/private/${arm}.log`;
        campaignPhase = 'arm_execution';
        const executionStarted = performance.now();
        const execution = spawnSync('bash', [path.join(repo, 'tests/Integration/otp-fpm-full-stack.sh')], {
            cwd: repo, env: { ...process.env, ...selectedMode, PINOVA_FPM_PLUGIN_ROOT: `${runRoot}/runtime`,
                PINOVA_FPM_REVISION_ARM: arm, PINOVA_FPM_REVISION_SOURCE: source },
            encoding: 'utf8', maxBuffer: 2 * 1024 * 1024, timeout: REVISION_ARM_TIMEOUT_MS,
        });
        // Persist child outcome before any receipt parsing or cleanup can fail.
        activeRow.execution = executionEvidence(execution, performance.now() - executionStarted);
        if (probeCase || execution.status !== 0 || execution.error || execution.signal) activeRow.parent_diagnostic_evidence = { ...readDiagnosticEvidence(runRoot),
            collection_boundary: 'after_fixture_exit_or_timeout', termination_causation_unverified: true };
        save();
        writeFileSync(stdout, `${execution.stdout || ''}\n${execution.stderr || ''}`, { mode: 0o600 });
        let receipt;
        if (existsSync(`${runRoot}/receipt/full-stack-result.json`)) {
            const bytes = readFileSync(`${runRoot}/receipt/full-stack-result.json`);
            requireFact(bytes.length <= 2 * 1024 * 1024, 'Revision arm receipt exceeded its bound.');
            receipt = JSON.parse(bytes);
            const target = `${armDirectory}/full-stack-result.json`;
            writeFileSync(target, bytes, { mode: 0o600 }); chmodSync(target, 0o644);
        }
        const profile = receipt?.profile ? hash(JSON.stringify(receipt.profile)) : null;
        if (identity.profile === null && profile) identity.profile = profile;
        Object.assign(activeRow, { profile_sha256: profile,
            state: execution.status === 0 && receipt?.state === 'passed' ? 'passed' : 'failed',
            measurement: probeCase ? null : receipt?.revision_arm || null,
            ...(probeCase ? { diagnostic: receipt?.diagnostic_probe || null } : {}),
            ...(jitMode ? { fpm_runtime: receipt?.fpm_runtime || null, resource_acceptance: false,
                fpm_configuration_sha256: existsSync(`${runRoot}/fpm.conf`) ? hash(readFileSync(`${runRoot}/fpm.conf`)) : null,
                nginx_configuration_sha256: existsSync(`${runRoot}/nginx.conf`) ? hash(readFileSync(`${runRoot}/nginx.conf`)) : null } : {}),
            ...(activeRow.parent_diagnostic_evidence ? {
                diagnostic_evidence: receipt?.diagnostic_evidence || activeRow.parent_diagnostic_evidence } : {}),
            failure_phase: receipt?.failure_phase || (execution.status === 0 ? null : 'fixture_execution'),
        });
        save();
        // No arm is rerun. Subsequent predeclared arms still run after an
        // ordinary assertion failure, preserving the complete campaign.
        campaignPhase = 'arm_cleanup';
        removeOwnedArm();
        activeRow.cleanup_status = 'complete';
        campaign.active_arm = null; save();
    }
    campaignPhase = 'campaign_evaluation';
    if (jitMode) {
        const completed = completedWarmup;
        const signal11 = requestMatchedSignal11;
        const first = campaign.arms[0];
        const identitiesMatch = campaign.arms.every(row => row.inventory_sha256 === inventory && identity.profile && row.profile_sha256 === identity.profile &&
            row.source === candidate && row.source_archive_sha256 === first.source_archive_sha256 &&
            row.fpm_configuration_sha256 && row.fpm_configuration_sha256 === first.fpm_configuration_sha256 &&
            row.nginx_configuration_sha256 && row.nginx_configuration_sha256 === first.nginx_configuration_sha256 &&
            row.jit_variant === contract.variants[row.arm] && row.jit_mode === contract.jit_modes[row.jit_variant] &&
            row.stock_identity_sha256 === hash(JSON.stringify(stock)) && row.fpm_runtime?.complete === true);
        const allCleaned = campaign.arms.every(row => row.cleanup_status === 'complete');
        const bufferSizes = campaign.arms.flatMap(row => row.fpm_runtime?.observations || []).map(row => row.jit?.buffer_size);
        const sameBufferSize = bufferSizes.length >= 60 && bufferSizes.every(value => Number.isSafeInteger(value) && value > 0 && value === bufferSizes[0]);
        const allClassified = campaign.arms.every(row => completed(row) || signal11(row));
        campaign.observations = { comparable: identitiesMatch && allCleaned && sameBufferSize, all_arms_classified: allClassified,
            all_arms_completed: campaign.arms.every(completed),
            A_signal11_arms: campaign.arms.filter(row => row.jit_variant === 'A' && signal11(row)).map(row => row.arm),
            B_signal11_arms: campaign.arms.filter(row => row.jit_variant === 'B' && signal11(row)).map(row => row.arm),
            completed_A: campaign.arms.filter(row => row.jit_variant === 'A' && completed(row)).length,
            completed_B: campaign.arms.filter(row => row.jit_variant === 'B' && completed(row)).length };
        campaign.tracing_mode_native_failures = campaign.arms.filter(row => row.jit_variant === 'B' && signal11(row) && row.fpm_runtime?.complete === true).map(row => row.arm);
        campaign.interpretation = interpretJitModeComparison({ ...campaign.observations, known_B_native_failure: campaign.tracing_mode_native_failures.length > 0 });
        campaign.resource_acceptance = false;
        campaign.state = campaign.interpretation === 'mode_associated_stability_mechanism_unproven' ? 'diagnostic_recorded' : 'needs_review';
    } else if (probeCase) {
        // A focused warmup is never an input to the six-arm resource gate.
        campaign.state = campaign.arms.every(row => row.state === 'passed' && row.cleanup_status === 'complete' &&
            row.diagnostic?.state === 'completed' && row.diagnostic?.warm_flows_completed === 10 &&
            row.diagnostic?.runner_opportunities === 10) ? 'diagnostic_completed' : 'needs_review';
    } else {
        campaign.acceptance = evaluateCampaign(campaign.arms, contract, identity);
        campaign.state = campaign.acceptance.state;
    }
    save();
    process.stdout.write(`${probeCase ? 'Focused warmup diagnosis (no resource acceptance)' : 'Revision comparison'} ${campaign.state}: ${campaign.arms.length} predeclared arms retained.\n`);
    if (!['passed', 'diagnostic_completed', 'diagnostic_recorded'].includes(campaign.state)) process.exitCode = 1;
} catch (error) {
    campaign.state = 'needs_review';
    campaign.infrastructure_failure = 'campaign_ownership_or_execution_incomplete';
    campaign.failure_phase = campaignPhase;
    const cleanupCodes = ['owned_process_live', 'arm_owner_mismatch', 'arm_remove_failed'];
    if (campaignPhase === 'arm_cleanup' && activeRow) {
        activeRow.cleanup_reason = cleanupCodes.includes(error.code) ? error.code : 'cleanup_unavailable';
        if (error.code === 'owned_process_live') activeRow.cleanup_witness = error.witness;
    }
    if (activeRow && campaign.active_arm !== null) {
        activeRow.state = 'failed'; activeRow.failure_phase = campaignPhase;
        try {
            if (existsSync(runRoot)) removeOwnedArm();
            activeRow.cleanup_status = 'complete';
        } catch (cleanup) {
            activeRow.cleanup_status = 'needs_review';
            activeRow.cleanup_reason = cleanupCodes.includes(cleanup.code) ? cleanup.code : 'cleanup_unavailable';
            if (cleanup.code === 'owned_process_live') activeRow.cleanup_witness = cleanup.witness;
        }
    }
    for (const row of campaign.arms) if (row.state === 'unexecuted') row.failure_phase = 'not_reached_after_incomplete_arm';
    save();
    process.stderr.write('Revision comparison incomplete; retain all receipts and inspect private runner diagnostics.\n');
    process.exitCode = 1;
}
