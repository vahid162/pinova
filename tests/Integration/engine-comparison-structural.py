"""Source-only controls; deliberately does not import or execute the fixture."""
import ast
import hashlib
import json
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[2]
HEAD = 'a1bb4a8985502e38498bb1df53b719868ced40b8'
manifest = json.loads((ROOT / 'tests/fixtures/engine-comparison.json').read_text())
assert manifest['runtime_source'] == manifest['baseline_source'] == HEAD
assert manifest['resource_acceptance'] is False
assert manifest['order'] == ['a1', 'b1', 'b2', 'a2', 'a3', 'b3']
assert [manifest['variants'][arm] for arm in manifest['order']] == list('ABBAAB')
assert manifest['compiler_jobs'] == 2 and manifest['build_timeout_seconds'] == 1800
assert manifest['job_timeout_minutes'] == 90 and manifest['retries'] == 0
assert manifest['php_api'] == '20240924' and manifest['php_version'] == '8.4.27'
patch = (ROOT / 'tests/fixtures/engine-comparison/gh24577-runtime.patch').read_bytes()
assert hashlib.sha256(patch).hexdigest() == manifest['runtime_patch_sha256']
paths = sorted(line.split(b' b/', 1)[1].decode() for line in patch.splitlines() if line.startswith(b'diff --git '))
assert paths == manifest['runtime_files'] and len(paths) == 5
assert b'PoC' not in patch and b'gh24577.phpt' not in patch
for path in ['tests/Integration/engine-comparison-prepare.py', 'tests/Integration/engine-comparison-structural.py',
             'tests/Integration/engine-comparison-prepare-test.py']:
    ast.parse((ROOT / path).read_text(), filename=path)
preparation = (ROOT / 'tests/Integration/engine-comparison-prepare.py').read_text()
assert "'--no-backup-if-mismatch'" in preparation and "'--fuzz=0'" in preparation
assert "changed == CONTRACT['runtime_files']" in preparation
assert "'commands': COMMAND_OBSERVATIONS[-8:]" in preparation
for path in ['tests/Integration/otp-fpm-revision-workload.mjs', 'tests/Integration/revision-resource-gates.mjs',
             'tests/fixtures/diagnostics-campaign.json', 'src/Services/OTPService.php', 'composer.lock']:
    original = subprocess.check_output(['git', 'show', HEAD + ':' + path], cwd=ROOT)
    assert (ROOT / path).read_bytes() == original, path
campaign = (ROOT / 'tests/Integration/otp-fpm-revision-campaign.mjs').read_text()
assert 'method_baseline_source' not in campaign and 'prepareVerificationVariant' not in campaign
assert "const source = runtimeSource(arm);" in campaign
assert "'inconclusive_custom_control_did_not_reproduce'" in (ROOT / 'tests/Integration/fpm-settings-observation.mjs').read_text()
observer = (ROOT / 'tests/Integration/fpm-settings-observation.mjs').read_text()
assert 'row.selected_engine_executable === true' in observer
assert 'this.executableWitness(row.pid) === true' in observer
controller = (ROOT / 'tests/Integration/otp-fpm-full-stack.mjs').read_text()
assert 'pid => readlinkSync(`/proc/${pid}/exe`) === process.env.PINOVA_ENGINE_FPM_REALPATH' in controller
assert controller.index('fpmObserver?.observe(incoming.headers') < controller.index("incoming.on('end'")
workflow = (ROOT / '.github/workflows/diagnostics-engine-comparison.yml').read_text()
assert "branches: ['proof/php84-ce-cache-20261101']" in workflow
assert 'timeout-minutes: 90' in workflow and 'ulimit -c 0' in workflow
assert 'engine-comparison-structural.py' in workflow
quality = (ROOT / '.github/workflows/quality.yml').read_text()
original = subprocess.check_output(['git', 'show', HEAD + ':.github/workflows/quality.yml'], cwd=ROOT).decode()
assert quality == original.replace('on:\n  push:\n', "on:\n  push:\n    branches-ignore: ['proof/php84-ce-cache-20261101']\n", 1)
print(json.dumps({'source_structural_controls': 'passed', 'runtime_source': HEAD,
                  'application_or_native_fixture_execution': False, 'resource_acceptance': False}))
