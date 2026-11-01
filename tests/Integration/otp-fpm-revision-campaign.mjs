/** Six fresh arms on one disposable hosted runner. Never an on-site benchmark. */
import { execFileSync, spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { chmodSync, cpSync, existsSync, lstatSync, mkdirSync, readFileSync, realpathSync, rmSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { evaluateCampaign } from './revision-resource-gates.mjs';
import { expectedNativeFpmProfile, validatePostworkProfile, activeProofSelected, readActiveContract,
    expectedActiveFpmProfile, validateActiveProfile, activeNativeGate, validateActiveCleanup, activeLoggingGate } from './postwork-jit-evidence.mjs';
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
const activeProof = activeProofSelected();
if (activeProof) readActiveContract();
const contractBytes = readFileSync(path.join(repo, activeProof ? 'tests/fixtures/diagnostics-active-jit-campaign.json' : 'tests/fixtures/diagnostics-campaign.json'));
const contract = JSON.parse(contractBytes);
const php = process.env.PINOVA_FPM_PHP_VERSION;
const logging = process.env.PINOVA_FPM_LOGGING_PROFILE;
requireFact(contract.php.includes(php) && contract.logging_profiles.includes(logging), 'Invalid revision campaign profile.');
const expectedRuntimeProfile = activeProof ? expectedActiveFpmProfile(php) : expectedNativeFpmProfile(php);
requireFact(JSON.stringify(contract.fpm_runtime_profiles?.[php]) === JSON.stringify(expectedRuntimeProfile),
    'Declared native FPM profile does not match the fixed runtime profile.');
const probeId = process.env.PINOVA_FPM_PROBE_CASE || '';
const probeCase = DIAGNOSTIC_CASES.find(item => item.id === probeId);
requireFact(!probeId || (probeCase && probeCase.php === php && probeCase.logging === logging), 'Invalid focused diagnostic case.');
const order = probeCase ? [probeCase.arm] : contract.order;
const git = args => execFileSync('git', args, { cwd: repo, encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 });
const workflowSource = git(['rev-parse', 'HEAD']).trim();
const candidate = activeProof ? contract.candidate_source : workflowSource;
requireFact(workflowSource === process.env.GITHUB_SHA && /^[a-f0-9]{40}$/.test(candidate), 'Candidate is not this exact workflow revision.');
if (activeProof) {
    requireFact(git(['rev-parse', `${candidate}^{tree}`]).trim() === contract.candidate_tree &&
        git(['rev-parse', `${contract.baseline_source}^{tree}`]).trim() === contract.baseline_tree, 'Active proof application trees changed.');
}
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
const fpmBinaryHash = hash(readFileSync(realpathSync(process.env.PINOVA_FPM_BIN)));
writeFileSync(`${campaignRoot}/fixture-owned`, `${id}:${attempt}\n`, { mode: 0o600 });
chmodSync(campaignRoot, 0o755); // Traversal for sanitized receipts; config/private files retain private modes.
mkdirSync(`${campaignRoot}/receipt`, { mode: 0o755 });
mkdirSync(`${campaignRoot}/private`, { mode: 0o700 });
const identity = { candidate, inventory, php, logging, profile: null };
const campaign = { schema: probeCase ? 'pinova.warmup-diagnostic.v1' : contract.schema, state: 'running', contract, contract_sha256: hash(contractBytes),
    identity, fpm_binary_sha256: fpmBinaryHash, composer_lock_sha256: hash(lock), dependency_normalization: 'both_code_revisions_use_identical_candidate_locked_production_vendor',
    ...(probeCase ? { resource_acceptance: false, probe_case: probeCase } : {}),
    ...(activeProof ? { workflow_source: workflowSource, shipping_fix: false, configuration_incremental_cost_attributed: false,
        crashed_constructor_copy_attested: false, profile_scope: 'absolute_cost_and_logging_source_regression_under_identical_active_exclusion' } : {}),
    active_arm: null, arms: order.map(arm => ({ arm, source: arm.startsWith('a') ? contract.baseline_source : candidate,
        inventory_sha256: inventory, profile_sha256: null, source_archive_sha256: null,
        state: 'unexecuted', measurement: null, failure_phase: null, cleanup_status: 'not_started' })) };
function save() {
    const target = `${campaignRoot}/receipt/${probeCase ? 'warmup-diagnostic' : 'revision-comparison'}.json`;
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
        let expectedIdentity;
        if (activeProof) {
            expectedIdentity = { schema: 'pinova.active-runtime-source.v1', application_source: source,
                application_tree: arm.startsWith('a') ? contract.baseline_tree : contract.candidate_tree, workflow_source: workflowSource,
                source_archive_sha256: activeRow.source_archive_sha256, inventory_sha256: inventory, composer_lock_sha256: hash(lock),
                logging_profile: logging, source_recipe: 'pinned_git_archive_with_normalized_locked_production_vendor', crashed_constructor_copy_attested: false };
            writeFileSync(`${runRoot}/runtime-identity.json`, JSON.stringify(expectedIdentity) + '\n', { mode: 0o600 });
            activeRow.runtime_identity = expectedIdentity;
        }
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
        writeFileSync(stdout, `${execution.stdout || ''}\n${execution.stderr || ''}`, { mode: 0o600 });
        let receipt;
        let primaryReceiptHash = null;
        if (existsSync(`${runRoot}/receipt/full-stack-result.json`)) {
            const bytes = readFileSync(`${runRoot}/receipt/full-stack-result.json`);
            requireFact(bytes.length <= 2 * 1024 * 1024, 'Revision arm receipt exceeded its bound.');
            receipt = JSON.parse(bytes);
            primaryReceiptHash = hash(bytes);
            const target = `${armDirectory}/full-stack-result.json`;
            writeFileSync(target, bytes, { mode: 0o600 }); chmodSync(target, 0o644);
        }
        let runtimeProfile = { state: 'unknown', reason: 'runtime_profile_evidence_unavailable_or_invalid' };
        try {
            const bytes = readFileSync(`${runRoot}/receipt/postwork-jit-mode.json`);
            requireFact(bytes.length <= 8192, 'Postwork runtime profile exceeded its bound.');
            const value = JSON.parse(bytes);
            requireFact((activeProof ? validateActiveProfile(value, { phpMinor: php, primaryReceiptHash,
                startupLaunch: receipt?.fpm_startup_profile, expectedIdentity, contractHash: hash(contractBytes) }) :
                validatePostworkProfile(value, { phpMinor: php, primaryReceiptHash, startupLaunch: receipt?.fpm_startup_profile })) &&
                receipt.fpm_startup_profile.binary_sha256 === fpmBinaryHash, 'Runtime profile evidence does not match this arm.');
            runtimeProfile = value;
        } catch { /* Unknown profile evidence cannot replace the primary workload outcome. */ }
        try {
            const target = `${armDirectory}/postwork-jit-mode.json`;
            writeFileSync(target, JSON.stringify(runtimeProfile, null, 2) + '\n', { mode: 0o600 }); chmodSync(target, 0o644);
        } catch { runtimeProfile = { state: 'unknown', reason: 'runtime_profile_evidence_copy_failed' }; }
        const profile = receipt?.profile ? hash(JSON.stringify(receipt.profile)) : null;
        if (identity.profile === null && profile) identity.profile = profile;
        Object.assign(activeRow, { profile_sha256: profile, fpm_startup_profile: receipt?.fpm_startup_profile || null,
            runtime_profile: runtimeProfile,
            state: execution.status === 0 && receipt?.state === 'passed' ? 'passed' : 'failed',
            measurement: probeCase ? null : receipt?.revision_arm || null,
            ...(probeCase ? { diagnostic: receipt?.diagnostic_probe || null } : {}),
            ...(activeProof || activeRow.parent_diagnostic_evidence ? {
                diagnostic_evidence: receipt?.diagnostic_evidence || activeRow.parent_diagnostic_evidence } : {}),
            failure_phase: receipt?.failure_phase || (execution.status === 0 ? null : 'fixture_execution'),
        });
        if (activeProof) {
            activeRow.native_cleanup_complete = false;
            try {
                const cleanupBytes = readFileSync(`${runRoot}/receipt/active-native-cleanup.json`);
                requireFact(cleanupBytes.length <= 2048, 'Native cleanup receipt exceeded its bound.');
                const cleanup = JSON.parse(cleanupBytes);
                activeRow.native_cleanup_complete = validateActiveCleanup(cleanup, { primaryReceiptHash,
                    modeReceiptHash: hash(readFileSync(`${runRoot}/receipt/postwork-jit-mode.json`)),
                    startupLaunch: receipt?.fpm_startup_profile, runId: id, attempt });
                activeRow.native_cleanup = cleanup;
                const target = `${armDirectory}/active-native-cleanup.json`;
                writeFileSync(target, cleanupBytes, { mode: 0o600 }); chmodSync(target, 0o644);
            } catch { /* Missing native cleanup cannot be accepted. */ }
            activeRow.native_gate = activeNativeGate(receipt?.diagnostic_evidence);
            activeRow.logging_gate = activeLoggingGate(receipt?.active_proof_logging, { applicationSource: source, loggingProfile: logging });
            activeRow.owned_controller_cleanup = receipt?.cli_cleanup_complete === true && receipt?.ordinary_http_worker?.complete === true;
            if (receipt?.source_commit !== source || receipt?.workflow_source !== workflowSource ||
                receipt?.active_proof_logging?.profile !== logging || receipt?.profile?.php_cli !== expectedRuntimeProfile.php_version ||
                receipt?.profile?.php_fpm !== expectedRuntimeProfile.php_version) activeRow.state = 'failed';
        }
        save();
        // No arm is rerun. Subsequent predeclared arms still run after an
        // ordinary assertion failure, preserving the complete campaign.
        campaignPhase = 'arm_cleanup';
        removeOwnedArm();
        activeRow.cleanup_status = 'complete';
        campaign.active_arm = null; save();
    }
    campaignPhase = 'campaign_evaluation';
    const runtimeProfilesComplete = campaign.arms.every(row => row.runtime_profile?.state === 'observed' &&
        row.fpm_startup_profile?.binary_sha256 === fpmBinaryHash && row.cleanup_status === 'complete');
    const sharedIni = runtimeProfilesComplete && campaign.arms.every(row =>
        JSON.stringify(row.runtime_profile.ini) === JSON.stringify(campaign.arms[0].runtime_profile.ini));
    campaign.runtime_profile_gate = { state: runtimeProfilesComplete && sharedIni ? 'passed' : 'needs_review',
        expected: expectedRuntimeProfile, complete_arms: campaign.arms.filter(row => row.runtime_profile?.state === 'observed').length,
        common_selected_ini: Boolean(sharedIni), scope: 'postwork_responder_and_pinned_startup_not_signaled_worker' };
    if (probeCase) {
        // A focused warmup is never an input to the six-arm resource gate.
        campaign.state = campaign.arms.every(row => row.state === 'passed' && row.cleanup_status === 'complete' &&
            row.diagnostic?.state === 'completed' && row.diagnostic?.warm_flows_completed === 10 &&
            row.diagnostic?.runner_opportunities === 10) ? 'diagnostic_completed' : 'needs_review';
    } else {
        campaign.acceptance = evaluateCampaign(campaign.arms, contract, identity);
        campaign.state = campaign.acceptance.state;
    }
    if (campaign.runtime_profile_gate.state !== 'passed') campaign.state = 'needs_review';
    if (activeProof) {
        const masters = campaign.arms.map(row => `${row.fpm_startup_profile?.master_pid}:${row.fpm_startup_profile?.master_start_ticks}`);
        campaign.active_proof_gate = { state: campaign.arms.every(row => row.native_gate?.state === 'passed' && row.logging_gate?.state === 'passed' &&
            row.owned_controller_cleanup && row.native_cleanup_complete && row.cleanup_status === 'complete') && new Set(masters).size === contract.order.length ? 'passed' : 'needs_review',
            fresh_master_count: new Set(masters).size, shipping_fix: false, crashed_constructor_copy_attested: false,
            configuration_incremental_cost_attributed: false };
        if (campaign.active_proof_gate.state !== 'passed') campaign.state = 'needs_review';
    }
    save();
    process.stdout.write(`${probeCase ? 'Focused warmup diagnosis (no resource acceptance)' : 'Revision comparison'} ${campaign.state}: ${campaign.arms.length} predeclared arms retained.\n`);
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
}
