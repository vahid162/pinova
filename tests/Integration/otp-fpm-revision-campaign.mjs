/** Six fresh arms on one disposable hosted runner. Never an on-site benchmark. */
import { execFileSync, spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { chmodSync, cpSync, existsSync, lstatSync, mkdirSync, readFileSync, realpathSync, rmSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { evaluateCampaign } from './revision-resource-gates.mjs';
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
const contractBytes = readFileSync(path.join(repo, 'tests/fixtures/diagnostics-campaign.json'));
const contract = JSON.parse(contractBytes);
const php = process.env.PINOVA_FPM_PHP_VERSION;
const logging = process.env.PINOVA_FPM_LOGGING_PROFILE;
requireFact(contract.php.includes(php) && contract.logging_profiles.includes(logging), 'Invalid revision campaign profile.');
const probeId = process.env.PINOVA_FPM_PROBE_CASE || '';
const probeCase = DIAGNOSTIC_CASES.find(item => item.id === probeId);
requireFact(!probeId || (probeCase && probeCase.php === php && probeCase.logging === logging), 'Invalid focused diagnostic case.');
const nativeCapture = process.env.PINOVA_FPM_NATIVE_CAPTURE === '1';
requireFact(!nativeCapture || (php === '8.4' && logging === 'normal' && !probeId), 'Invalid native diagnostic cohort.');
const order = probeCase ? [probeCase.arm] : contract.order;
const git = args => execFileSync('git', args, { cwd: repo, encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 });
const candidate = git(['rev-parse', 'HEAD']).trim();
requireFact(candidate === process.env.GITHUB_SHA && /^[a-f0-9]{40}$/.test(candidate), 'Candidate is not this exact workflow revision.');
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
writeFileSync(`${campaignRoot}/fixture-owned`, `${id}:${attempt}\n`, { mode: 0o600 });
chmodSync(campaignRoot, 0o755); // Traversal for sanitized receipts; config/private files retain private modes.
mkdirSync(`${campaignRoot}/receipt`, { mode: 0o755 });
mkdirSync(`${campaignRoot}/private`, { mode: 0o700 });
const identity = { candidate, inventory, php, logging, profile: null };
const campaign = { schema: nativeCapture ? 'pinova.native-crash-diagnostic.v1' : probeCase ? 'pinova.warmup-diagnostic.v1' : contract.schema, state: 'running', contract, contract_sha256: hash(contractBytes),
    identity, composer_lock_sha256: hash(lock), dependency_normalization: 'both_code_revisions_use_identical_candidate_locked_production_vendor',
    ...(probeCase ? { resource_acceptance: false, probe_case: probeCase } : {}),
    ...(nativeCapture ? { resource_acceptance: false, diagnostic_only: 'one_predeclared_six_arm_native_crash_capture' } : {}),
    active_arm: null, arms: order.map(arm => ({ arm, source: arm.startsWith('a') ? contract.baseline_source : candidate,
        ...(nativeCapture ? { resource_acceptance: false } : {}),
        inventory_sha256: inventory, profile_sha256: null, source_archive_sha256: null,
        state: 'unexecuted', measurement: null, failure_phase: null, cleanup_status: 'not_started' })) };
function save() {
    const target = `${campaignRoot}/receipt/${nativeCapture ? 'native-crash-diagnostic' : probeCase ? 'warmup-diagnostic' : 'revision-comparison'}.json`;
    writeFileSync(target, JSON.stringify(campaign, null, 2) + '\n', { mode: 0o600 }); chmodSync(target, 0o644);
}
save();
let campaignPhase = 'arm_setup';
let activeRow;
function nativeEvidence(command, arm = null) {
    const result = spawnSync('python3', [path.join(repo, 'tests/Integration/native-fpm-crash-evidence.py'), command], {
        cwd: repo, env: { ...process.env, ...(arm ? { PINOVA_FPM_REVISION_ARM: arm } : {}) },
        encoding: 'utf8', maxBuffer: 131072, timeout: 120000,
    });
    try {
        const receipt = JSON.parse(result.stdout);
        requireFact(receipt.resource_acceptance === false && ['ready', 'recorded', 'no_crash_observed', 'crash_captured',
            'needs_review', 'complete', 'not_started'].includes(receipt.state), 'Invalid native capture receipt.');
        return receipt;
    } catch {
        return { state: 'needs_review', reason: result.error?.code === 'ETIMEDOUT' ? 'helper_timeout' : 'helper_receipt_unavailable', resource_acceptance: false };
    }
}
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
    if (nativeCapture) {
        campaign.native_capture_setup = nativeEvidence('setup'); save();
        requireFact(campaign.native_capture_setup.state === 'ready', 'Native capture route is unavailable.');
    }
    for (const arm of order) {
        campaignPhase = 'arm_setup';
        activeRow = campaign.arms.find(row => row.arm === arm);
        activeRow.state = 'running'; activeRow.cleanup_status = 'pending';
        campaign.active_arm = arm; save();
        const source = arm.startsWith('a') ? contract.baseline_source : candidate;
        mkdirSync(runRoot, { mode: 0o700 });
        cpSync(`${campaignRoot}/wordpress`, `${runRoot}/wordpress`, { recursive: true, errorOnExist: true, force: false });
        mkdirSync(`${runRoot}/runtime`, { mode: 0o700 });
        // Archive bytes, rather than an old dependency install, define each
        // first-party code revision. Only dependency lock/vendor are normalized.
        const archive = execFileSync('git', ['archive', '--format=tar', source], { cwd: repo, maxBuffer: 64 * 1024 * 1024 });
        activeRow.source_archive_sha256 = hash(archive); save();
        execFileSync('tar', ['--extract', '--directory', `${runRoot}/runtime`, '--no-same-owner'], { input: archive });
        cpSync(path.join(repo, 'vendor'), `${runRoot}/runtime/vendor`, { recursive: true, errorOnExist: true, force: false });
        writeFileSync(`${runRoot}/runtime/composer.lock`, lock, { mode: 0o600 });
        requireFact(hash(readFileSync(`${runRoot}/runtime/vendor/composer/installed.json`)) === inventory, 'Normalized inventory changed.');
        const armDirectory = `${campaignRoot}/receipt/${arm}`;
        mkdirSync(armDirectory, { mode: 0o755 });
        const stdout = `${campaignRoot}/private/${arm}.log`;
        campaignPhase = 'arm_execution';
        const executionStarted = performance.now();
        const execution = spawnSync('bash', [path.join(repo, 'tests/Integration/otp-fpm-full-stack.sh')], {
            cwd: repo, env: { ...process.env, PINOVA_FPM_PLUGIN_ROOT: `${runRoot}/runtime`,
                PINOVA_FPM_REVISION_ARM: arm, PINOVA_FPM_REVISION_SOURCE: source },
            encoding: 'utf8', maxBuffer: 2 * 1024 * 1024, timeout: REVISION_ARM_TIMEOUT_MS,
        });
        // Persist child outcome before any receipt parsing or cleanup can fail.
        activeRow.execution = executionEvidence(execution, performance.now() - executionStarted);
        if (probeCase || execution.status !== 0 || execution.error || execution.signal) activeRow.parent_diagnostic_evidence = { ...readDiagnosticEvidence(runRoot),
            collection_boundary: 'after_fixture_exit_or_timeout', termination_causation_unverified: true };
        save();
        if (nativeCapture) {
            activeRow.native_capture = nativeEvidence('collect', arm); save();
            requireFact(!activeRow.native_capture.cleanup_workers_alive?.length, 'An owned native worker survived fixture teardown.');
        }
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
            measurement: probeCase || nativeCapture ? null : receipt?.revision_arm || null,
            ...(nativeCapture ? { resource_acceptance: false, diagnostic_workload: receipt?.revision_arm || null } : {}),
            ...(probeCase ? { diagnostic: receipt?.diagnostic_probe || null } : {}),
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
        if (nativeCapture && receipt?.native_inventory_preflight?.state === 'needs_review') {
            campaign.infrastructure_failure = 'native_inventory_preflight_failed';
            campaign.failure_phase = 'native_inventory_preflight';
            for (const row of campaign.arms) if (row.state === 'unexecuted') row.failure_phase = 'not_reached_after_native_inventory_preflight';
            save();
            break;
        }
    }
    campaignPhase = 'campaign_evaluation';
    if (nativeCapture) {
        campaign.state = campaign.arms.every(row => ['passed', 'failed'].includes(row.state) && row.cleanup_status === 'complete' &&
            (row.native_capture?.state === 'crash_captured' || row.state === 'passed' && row.native_capture?.state === 'no_crash_observed')) ?
            'diagnostic_completed' : 'needs_review';
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
    process.stdout.write(`${nativeCapture ? 'Native crash diagnosis (no resource acceptance)' : probeCase ? 'Focused warmup diagnosis (no resource acceptance)' : 'Revision comparison'} ${campaign.state}: ${campaign.arms.length} predeclared arms retained.\n`);
    if (!['passed', 'diagnostic_completed'].includes(campaign.state)) process.exitCode = 1;
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
} finally {
    if (nativeCapture) {
        campaign.native_capture_cleanup = nativeEvidence('cleanup');
        if (campaign.native_capture_cleanup.state !== 'complete') { campaign.state = 'needs_review'; process.exitCode = 1; }
        save();
    }
}
