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
const startupProof = process.env.PINOVA_FPM_STARTUP_JIT_PROOF === '1';
requireFact(!startupProof || process.env.GITHUB_REF === 'refs/heads/proof/diagnostics-startup-jit', 'Wrong startup proof branch.');
const contractBytes = readFileSync(path.join(repo, startupProof ? 'tests/fixtures/startup-jit-comparison.json' : 'tests/fixtures/diagnostics-campaign.json'));
const contract = JSON.parse(contractBytes);
const php = process.env.PINOVA_FPM_PHP_VERSION;
const logging = process.env.PINOVA_FPM_LOGGING_PROFILE;
requireFact(contract.php.includes(php) && contract.logging_profiles.includes(logging), 'Invalid revision campaign profile.');
const probeId = process.env.PINOVA_FPM_PROBE_CASE || '';
const probeCase = startupProof ? { id: 'startup-jit', php: '8.4', logging: 'diagnostic' } : DIAGNOSTIC_CASES.find(item => item.id === probeId);
requireFact(!probeId || (probeCase && probeCase.php === php && probeCase.logging === logging), 'Invalid focused diagnostic case.');
const order = startupProof ? contract.order : probeCase ? [probeCase.arm] : contract.order;
requireFact(!startupProof || probeId === 'startup-jit', 'Wrong startup probe.');
const git = args => execFileSync('git', args, { cwd: repo, encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 });
const workflowSource = git(['rev-parse', 'HEAD']).trim();
requireFact(workflowSource === process.env.GITHUB_SHA && /^[a-f0-9]{40}$/.test(workflowSource), 'Workflow source identity changed.');
const candidate = startupProof ? contract.application_source : workflowSource;
if (startupProof) requireFact(candidate === '09e9d334ead9b06d0888736fb467915ecb272230' && git(['rev-parse', `${candidate}^{tree}`]).trim() === '09ca0560d8febcc0d1ba6688244903ee1e0459e3' &&
    contract.application_tree === '09ca0560d8febcc0d1ba6688244903ee1e0459e3' && contract.baseline_source === candidate && php === '8.4' && logging === 'diagnostic' &&
    JSON.stringify(order) === '["a1","b1","b2","a2","a3","b3"]' &&
    JSON.stringify(contract.pairs) === '[["a1","b1"],["a2","b2"],["a3","b3"]]' &&
    JSON.stringify(contract.variants) === '{"a1":"A","b1":"B","b2":"B","a2":"A","a3":"A","b3":"B"}' &&
    contract.startup_modes.A === '1235' && contract.startup_modes.B === 'disable' && contract.common_jit_buffer_bytes === 268435456 &&
    contract.resource_acceptance === false && contract.all_application_logging_retained === true, 'Startup comparison contract changed.');
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
if (startupProof) requireFact(lock.equals(Buffer.from(git(['show', `${candidate}:composer.lock`]))), 'Startup comparison changed locked dependencies.');
const runtimeSource = arm => startupProof ? candidate : arm.startsWith('a') ? contract.baseline_source : candidate;
const startupBinaryHash = startupProof ? hash(readFileSync(realpathSync(process.env.PINOVA_FPM_BIN))) : null;
const cliBinaryHash = startupProof ? hash(readFileSync(realpathSync(process.env.PINOVA_FPM_PHP_BIN))) : null;
function prepareStartupVariant(arm) {
    if (!startupProof) return;
    for (const [file, expected] of Object.entries(contract.unchanged_harness_sha256)) {
        requireFact(hash(readFileSync(path.join(repo, file))) === expected, 'Original PHP fixture or workload changed.');
    }
    requireFact(hash(readFileSync(path.join(repo, 'tests/Integration/otp-fpm-full-stack.sh'))) === contract.proof_shell_sha256 &&
        hash(readFileSync(path.join(repo, 'tests/Integration/postwork-jit-evidence.mjs'))) === contract.mode_helper_sha256 &&
        hash(readFileSync(path.join(repo, 'tests/fixtures/fpm-otp/startup-jit-mode.php'))) === contract.mode_endpoint_sha256,
        'Pinned proof launcher or postwork reader changed.');
    for (const [file, expected] of Object.entries(contract.application_pins)) {
        requireFact(hash(readFileSync(`${runRoot}/runtime/${file}`)) === expected, 'Application or logging bytes changed.');
    }
    const variant = contract.variants[arm]; requireFact(['A', 'B'].includes(variant), 'Unknown startup variant.');
    activeRow.startup_variant = variant;
    activeRow.startup_jit_mode = contract.startup_modes[variant];
    activeRow.application_byte_proof = { source: candidate, tree: contract.application_tree,
        pins: contract.application_pins, all_product_bytes: 'unchanged_exact_source_archive', all_logging_retained: true };
}
writeFileSync(`${campaignRoot}/fixture-owned`, `${id}:${attempt}\n`, { mode: 0o600 });
chmodSync(campaignRoot, 0o755); // Traversal for sanitized receipts; config/private files retain private modes.
mkdirSync(`${campaignRoot}/receipt`, { mode: 0o755 });
mkdirSync(`${campaignRoot}/private`, { mode: 0o700 });
const identity = { candidate, inventory, php, logging, profile: null };
const campaign = { schema: startupProof ? contract.schema : probeCase ? 'pinova.warmup-diagnostic.v1' : contract.schema, state: 'running', contract, contract_sha256: hash(contractBytes),
    identity, workflow_source: workflowSource, startup_binary_sha256: startupBinaryHash, cli_binary_sha256: cliBinaryHash, composer_lock_sha256: hash(lock), dependency_normalization: 'both_code_revisions_use_identical_candidate_locked_production_vendor',
    ...(probeCase ? { resource_acceptance: false, probe_case: probeCase } : {}),
    active_arm: null, arms: order.map(arm => ({ arm, source: runtimeSource(arm),
        inventory_sha256: inventory, profile_sha256: null, source_archive_sha256: null,
        state: 'unexecuted', measurement: null, failure_phase: null, cleanup_status: 'not_started' })) };
function save() {
    const target = `${campaignRoot}/receipt/${startupProof ? 'startup-jit-comparison' : probeCase ? 'warmup-diagnostic' : 'revision-comparison'}.json`;
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
        prepareStartupVariant(arm);
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
                PINOVA_FPM_REVISION_ARM: arm, PINOVA_FPM_REVISION_SOURCE: source,
                ...(startupProof ? { PINOVA_FPM_STARTUP_JIT_MODE: activeRow.startup_jit_mode } : {}) },
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
        let postworkMode;
        if (startupProof) {
            try {
                const bytes = readFileSync(`${runRoot}/receipt/postwork-jit-mode.json`);
                requireFact(bytes.length <= 8192, 'Postwork mode receipt exceeded its bound.');
                postworkMode = JSON.parse(bytes);
                requireFact(postworkMode.primary_receipt_sha256 === hash(readFileSync(`${runRoot}/receipt/full-stack-result.json`)) &&
                    postworkMode.collection_boundary === 'after_primary_receipt_before_fpm_teardown' && postworkMode.attests_signaled_worker === false,
                    'Postwork observation is not bound to the frozen primary outcome.');
            } catch {
                postworkMode = { state: 'unknown', reason: 'postwork_receipt_unavailable_or_invalid', resource_acceptance: false };
            }
            try {
                const target = `${armDirectory}/postwork-jit-mode.json`;
                writeFileSync(target, JSON.stringify(postworkMode, null, 2) + '\n', { mode: 0o600 }); chmodSync(target, 0o644);
            } catch {
                postworkMode = { state: 'unknown', reason: 'postwork_receipt_copy_failed', resource_acceptance: false };
            }
        }
        const profile = receipt?.profile ? hash(JSON.stringify(receipt.profile)) : null;
        if (identity.profile === null && profile) identity.profile = profile;
        Object.assign(activeRow, { profile_sha256: profile,
            state: execution.status === 0 && receipt?.state === 'passed' ? 'passed' : 'failed',
            measurement: probeCase ? null : receipt?.revision_arm || null,
            ...(probeCase ? { diagnostic: receipt?.diagnostic_probe || null } : {}),
            ...(startupProof ? { startup_launch: receipt?.startup_jit_launch || null, postwork_mode: postworkMode || null } : {}),
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
    if (startupProof) {
        const completed = row => row.state === 'passed' && row.cleanup_status === 'complete' &&
            row.diagnostic?.state === 'completed' && Object.entries(contract.expected_completed)
                .every(([key, value]) => row.diagnostic?.[key] === value);
        const matchedSignal11 = row => {
            const evidence = row.diagnostic_evidence;
            if (row.failure_phase !== 'revision_warmup' || evidence?.collection_boundary !== 'controller_before_fpm_teardown' ||
                evidence?.fpm_termination?.read_success !== true || evidence?.markers?.read_success !== true) return false;
            return evidence.fpm_termination.events.some(event => event.event === 'signal_exit' && event.signal_number === 11 &&
                event.pool === 'fixture' && evidence.markers.events.some(marker => marker.pid === event.pid &&
                    marker.event === 'request_start' && /^revision-warm-(?:[1-9]|10)(?:-verify)?$/.test(marker.case)));
        };
        const first = campaign.arms[0];
        const comparable = campaign.arms.every(row => row.cleanup_status === 'complete' && row.source === candidate &&
            row.source_archive_sha256 === first.source_archive_sha256 && row.inventory_sha256 === inventory &&
            identity.profile && row.profile_sha256 === identity.profile && row.application_byte_proof?.all_logging_retained === true &&
            row.startup_launch?.binary_sha256 === startupBinaryHash && row.startup_launch?.declared_mode === row.startup_jit_mode &&
            row.startup_launch?.configured_buffer_bytes === 268435456 && row.startup_launch?.owned_configuration_in_cmdline === true &&
            JSON.stringify(row.startup_launch?.declared_argv) === JSON.stringify(contract.declared_startup_argv[row.startup_variant])) &&
            new Set(campaign.arms.map(row => `${row.startup_launch?.master_pid}:${row.startup_launch?.master_start_ticks}`)).size === 6;
        const observedModes = campaign.arms.every(row => row.postwork_mode?.state === 'observed' &&
            row.postwork_mode.ini?.mode === row.startup_jit_mode && row.postwork_mode.responder_owned_main_pool === true &&
            row.postwork_mode.primary_outcome_frozen === true && row.postwork_mode.resource_acceptance === false);
        const sharedIni = row => row.postwork_mode?.ini ? Object.fromEntries(Object.entries(row.postwork_mode.ini).filter(([key]) => key !== 'mode')) : null;
        const commonIni = observedModes && campaign.arms.every(row => JSON.stringify(sharedIni(row)) === JSON.stringify(sharedIni(first)));
        campaign.observations = { comparable, postwork_mode_complete: observedModes, common_selected_INI: commonIni,
            completed_A: campaign.arms.filter(row => row.startup_variant === 'A' && completed(row)).length,
            completed_B: campaign.arms.filter(row => row.startup_variant === 'B' && completed(row)).length,
            A_matched_signal11_arms: campaign.arms.filter(row => row.startup_variant === 'A' && matchedSignal11(row)).map(row => row.arm),
            B_matched_signal11_arms: campaign.arms.filter(row => row.startup_variant === 'B' && matchedSignal11(row)).map(row => row.arm),
            all_arms_completed: campaign.arms.every(completed),
            all_arms_classified: campaign.arms.every(row => completed(row) || matchedSignal11(row)),
            resource_acceptance: false, shipping_fix: false,
            limit: 'cold_start_configuration_contrast_not_resource_or_shipping_acceptance',
            native_pid_match_scope: 'retained_fixture_PID_not_proven_signaled_request_or_cause' };
        campaign.state = comparable && observedModes && commonIni && campaign.observations.all_arms_classified ? 'diagnostic_recorded' : 'needs_review';
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
