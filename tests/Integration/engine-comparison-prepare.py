"""Private diagnostic build preparation. Never imported by the plugin."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import signal
import stat
import subprocess
import time
import urllib.request

REPO = Path(__file__).resolve().parents[2]
CONTRACT = json.loads((REPO / 'tests/fixtures/engine-comparison.json').read_text())
PATCH = REPO / 'tests/fixtures/engine-comparison/gh24577-runtime.patch'
IDENTITY = os.environ.get('GITHUB_RUN_ID', '') + '-' + os.environ.get('GITHUB_RUN_ATTEMPT', '')
ROOT = Path('/tmp/pinova-engine-' + IDENTITY)
ACTIVE_VARIANT = None
COMMAND_OBSERVATIONS = []
PATCH_COMMAND = ['patch', '--batch', '--forward', '--fuzz=0', '--no-backup-if-mismatch', '-p1']


class PreparationCommandError(RuntimeError):
    pass


def sha(data):
    return hashlib.sha256(data).hexdigest()


def require(value, message):
    if not value:
        raise RuntimeError(message)


def hosted():
    require(os.environ.get('GITHUB_ACTIONS') == 'true'
            and os.environ.get('RUNNER_ENVIRONMENT') == 'github-hosted'
            and re.fullmatch(r'[0-9]+-[0-9]+', IDENTITY)
            and os.environ.get('GITHUB_REF') == 'refs/heads/' + CONTRACT['proof_branch']
            and os.environ.get('PINOVA_ENGINE_ROOT') == str(ROOT), 'wrong_host_or_run')


def log_evidence(output):
    result = {'log_read_success': False, 'log_bytes': None, 'log_bytes_clamped': False,
              'log_tail_bytes': 0, 'log_tail_sha256': None}
    if output is None:
        return result
    try:
        output.flush()
        path = Path(output.name)
        require(path.parent == ROOT / 'private', 'log_outside_owned_private_directory')
        original = os.fstat(output.fileno())
        with path.open('rb') as source:
            current = os.fstat(source.fileno())
            require(stat.S_ISREG(current.st_mode) and (original.st_dev, original.st_ino) == (current.st_dev, current.st_ino), 'log_identity_changed')
            source.seek(max(0, current.st_size - 65536))
            tail = source.read(65536)
        result.update(log_read_success=True, log_bytes=min(current.st_size, 16777216),
                      log_bytes_clamped=current.st_size > 16777216,
                      log_tail_bytes=len(tail), log_tail_sha256=sha(tail))
    except (OSError, ValueError, RuntimeError):
        pass
    return result


def run(command_key, args, cwd=None, deadline=None, stdout=None):
    require(command_key in {'source_extract', 'patch', 'configure', 'build', 'api_verify'}, 'unknown_preparation_command')
    timeout = 180 if deadline is None else max(1, deadline - time.monotonic())
    status, timed_out, launch_failed = None, False, False
    try:
        process = subprocess.Popen(args, cwd=cwd, env=os.environ.copy(), start_new_session=True,
                                   stdout=stdout, stderr=subprocess.STDOUT)
        try:
            status = process.wait(timeout=timeout)
        except subprocess.TimeoutExpired:
            timed_out = True
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            status = process.wait()
    except OSError:
        launch_failed = True
    observation = {'command': command_key, 'variant': ACTIVE_VARIANT,
                   'exit_code': status if type(status) is int and 0 <= status <= 255 else None,
                   'signal': -status if type(status) is int and -64 <= status < 0 else None,
                   'timeout': timed_out, 'launch_failed': launch_failed, **log_evidence(stdout)}
    COMMAND_OBSERVATIONS.append(observation)
    if status != 0 or timed_out or launch_failed:
        raise PreparationCommandError('preparation_command_failed')


def write_json(path, value):
    path.write_text(json.dumps(value, indent=2) + '\n')


def tree_identity(root):
    result = {}
    for path in sorted(root.rglob('*')):
        relative = str(path.relative_to(root))
        if path.is_symlink():
            result[relative] = 'link:' + os.readlink(path)
        elif path.is_file():
            result[relative] = sha(path.read_bytes())
    return result


def sources():
    hosted()
    require(not ROOT.exists() and not ROOT.is_symlink(), 'engine_root_already_exists')
    ROOT.mkdir(mode=0o755)
    (ROOT / 'private').mkdir(mode=0o700)
    (ROOT / 'receipt').mkdir(mode=0o755)
    (ROOT / 'owner').write_text(IDENTITY + '\n')
    downloads = ROOT / 'downloads'
    downloads.mkdir()
    for item in CONTRACT['archive_files']:
        with urllib.request.urlopen(item['url'], timeout=90) as response:
            data = response.read(item['bytes'] + 1)
        require(len(data) == item['bytes'] and sha(data) == item['sha256'], 'archive_identity_mismatch')
        (downloads / item['name']).write_bytes(data)
    require(sha(PATCH.read_bytes()) == CONTRACT['runtime_patch_sha256'], 'runtime_patch_identity_mismatch')
    dsc = downloads / CONTRACT['archive_files'][0]['name']
    with (ROOT / 'private/source.log').open('w') as log:
        # dpkg-source verifies archive checksums and applies the quilt series.
        # Signature trust is anchored separately; this receipt never upgrades it.
        run('source_extract', ['dpkg-source', '-x', str(dsc), str(ROOT / 'source-A')], stdout=log)
        shutil.copytree(ROOT / 'source-A', ROOT / 'source-B', symlinks=True)
        run('patch', PATCH_COMMAND + ['--input', str(PATCH)],
            cwd=ROOT / 'source-B', stdout=log)
    a, b = tree_identity(ROOT / 'source-A'), tree_identity(ROOT / 'source-B')
    require(a.keys() == b.keys(), 'unexpected_runtime_file_addition')
    changed = sorted(key for key in a if a[key] != b[key])
    require(changed == CONTRACT['runtime_files'], 'unexpected_source_difference')
    applied = (ROOT / 'source-A/.pc/applied-patches').read_text().splitlines()
    require(len(applied) == CONTRACT['debian_patch_count'], 'wrong_debian_patch_count')
    require((ROOT / 'source-A/debian/phpapi').read_text().strip() == CONTRACT['php_api'], 'wrong_source_api')
    write_json(ROOT / 'receipt/source-identity.json', {
        'schema': 'pinova.engine-source.v1', 'resource_acceptance': False,
        'archives': CONTRACT['archive_files'], 'source_signature_validation': CONTRACT['source_signature_validation'],
        'debian_patches': applied, 'runtime_patch_sha256': sha(PATCH.read_bytes()),
        'only_changed_files': changed, 'runtime_files': {key: {'A': a[key], 'B': b[key]} for key in changed},
        'source_tree_sha256': {key: sha(json.dumps(value, sort_keys=True).encode()) for key, value in [('A', a), ('B', b)]},
    })
    # This exact trusted source descriptor supplies the dependency expression.
    # Runner provisioning is a separate reviewed workflow step, never performed here.
    text = dsc.read_text()
    dependencies = re.search(r'^Build-Depends: (.+)$', text, re.M)
    require(dependencies is not None, 'build_dependencies_missing')
    (ROOT / 'build-depends.txt').write_text(dependencies[1] + '\n')


def build():
    global ACTIVE_VARIANT
    hosted()
    require((ROOT / 'owner').read_text().strip() == IDENTITY, 'engine_owner_mismatch')
    require(os.environ.get('DEB_BUILD_OPTIONS') == 'parallel=2', 'compiler_parallelism_changed')
    require(os.environ.get('SOURCE_DATE_EPOCH') == str(CONTRACT['source_date_epoch']) and os.environ.get('TZ') == 'UTC', 'build_clock_configuration_changed')
    artifacts = ROOT / 'artifacts'
    artifacts.mkdir()
    inventory = subprocess.check_output(['dpkg-query', '-W', '-f=${Package}=${Version}\n'])
    inventory_hash = sha(b'\n'.join(sorted(inventory.splitlines())))
    flags_hash = sha(subprocess.check_output(['dpkg-buildflags', '--export=sh']))
    toolchain_hash = sha(subprocess.check_output(['cc', '--version']))
    write_json(ROOT / 'receipt/build-environment.json', {'dependency_inventory_sha256': inventory_hash,
        'build_flags_sha256': flags_hash, 'compiler_version_sha256': toolchain_hash,
        'resource_acceptance': False})
    for variant in ['A', 'B']:
        ACTIVE_VARIANT = variant
        started = time.monotonic()
        deadline = started + CONTRACT['build_timeout_seconds']
        # Both variants compile at the same absolute path, with fresh caches.
        source = ROOT / ('source-' + variant)
        build_root = ROOT / 'build'
        require(not build_root.exists(), 'previous_build_not_retired')
        source.rename(build_root)
        with (ROOT / ('private/build-' + variant + '.log')).open('w') as log:
            common = ['make', '-f', 'debian/rules', 'TARGETS=ext fpm cli', 'ext_PACKAGES=opcache']
            run('configure', common + ['override_dh_auto_configure-arch'], cwd=build_root, deadline=deadline, stdout=log)
            run('build', common + ['override_dh_auto_build-arch'], cwd=build_root, deadline=deadline, stdout=log)
        target = artifacts / variant
        target.mkdir()
        files = {'php-fpm': 'fpm-build/sapi/fpm/php-fpm', 'php': 'cli-build/sapi/cli/php',
                 'opcache.so': 'ext-build/modules/opcache.so'}
        hashes = {}
        for name, relative in files.items():
            original = build_root / relative
            require(original.is_file() and not original.is_symlink(), 'missing_build_artifact')
            shutil.copy2(original, target / name)
            hashes[name] = sha((target / name).read_bytes())
        run('api_verify', ['sh', str(build_root / 'cli-build/scripts/php-config'), '--phpapi'],
            stdout=(ROOT / ('private/api-' + variant + '.txt')).open('w'))
        require((ROOT / ('private/api-' + variant + '.txt')).read_text().strip() == CONTRACT['php_api'], 'built_api_mismatch')
        write_json(ROOT / ('receipt/build-' + variant + '.json'), {
            'variant': variant, 'resource_acceptance': False, 'sha256': hashes,
            'php_api': CONTRACT['php_api'], 'compiler_jobs': 2,
            'elapsed_seconds': round(time.monotonic() - started, 3), 'build_timeout_seconds': 1800,
            'build_targets': CONTRACT['build_targets'], 'ext_packages': ['opcache'],
            'package_installation': False, 'tests_run_by_build': False,
            'configure_sha256': {name: sha((build_root / (name + '-build/config.nice')).read_bytes())
                                 for name in CONTRACT['build_targets']},
            'dependency_inventory_sha256': inventory_hash, 'build_flags_sha256': flags_hash,
            'compiler_version_sha256': toolchain_hash,
        })
        build_root.rename(ROOT / ('built-source-' + variant))
        ACTIVE_VARIANT = None


def ini():
    hosted()
    # Every source INI byte is retained except the single OPcache loader line.
    # Other DSO bytes are shared, hash-frozen and loaded from the packaged ABI directory.
    shared = ROOT / 'ini'
    shared.mkdir()
    records = {}
    extension_root = Path('/usr/lib/php') / CONTRACT['php_api']
    for sapi in ['cli', 'fpm']:
        origin = Path('/etc/php/8.4') / sapi
        target = shared / sapi
        target.mkdir()
        (target / 'conf.d').mkdir()
        inputs = [('php.ini', origin / 'php.ini')]
        inputs += [('conf.d/' + p.name, p) for p in sorted((origin / 'conf.d').iterdir())]
        loader_count = 0
        records[sapi] = {}
        for name, path in inputs:
            raw = path.read_bytes()
            lines = raw.decode().splitlines(keepends=True)
            result = []
            for line in lines:
                match = re.match(r'^\s*zend_extension\s*=\s*["\']?([^;\s"\']+)', line, re.I)
                if match:
                    require(Path(match[1]).name in ['opcache', 'opcache.so'], 'unexpected_zend_extension')
                    loader_count += 1
                else:
                    extension = re.match(r'^\s*extension\s*=\s*["\']?([^;\s"\']+)', line, re.I)
                    if extension:
                        value = extension[1]
                        module = Path(value) if value.startswith('/') else extension_root / (value if value.endswith('.so') else value + '.so')
                        require(module.name != 'opcache.so' and module.is_file() and module.resolve().parent == extension_root.resolve(), 'extension_outside_shared_abi_directory')
                    result.append(line)
            data = ''.join(result).encode()
            (target / name).write_bytes(data)
            records[sapi][name] = {'source_sha256': sha(raw), 'shared_sha256': sha(data)}
        require(loader_count == 1, 'opcache_loader_count_not_one')
    modules = {p.name: sha(p.read_bytes()) for p in sorted(extension_root.glob('*.so')) if p.name != 'opcache.so'}
    require(modules and 'pdo_mysql.so' in modules, 'shared_extensions_unavailable')
    wrappers = {}
    for variant in ['A', 'B']:
        output = ROOT / 'artifacts' / variant
        for sapi, binary in [('cli', 'php'), ('fpm', 'php-fpm')]:
            wrapper = output / (binary + '-runner')
            wrapper.write_text('#!/usr/bin/env bash\nset -euo pipefail\nulimit -c 0\n'
                + 'export PHP_INI_SCAN_DIR="' + str(shared / sapi / 'conf.d') + '"\n'
                + 'exec "' + str(output / binary) + '" -c "' + str(shared / sapi / 'php.ini')
                + '" -d "extension_dir=' + str(extension_root) + '" -d "zend_extension='
                + str(output / 'opcache.so') + '" "$@"\n')
            wrapper.chmod(0o755)
            wrappers[variant + '/' + wrapper.name] = sha(wrapper.read_bytes())
    write_json(ROOT / 'receipt/ini-identity.json', {'resource_acceptance': False, 'source_ini': records,
        'shared_extension_directory': str(extension_root), 'shared_extension_sha256': modules,
        'opcache_selection': 'one_explicit_variant_absolute_zend_extension_command_line',
        'wrapper_sha256': wrappers, 'core_dump_limit': 0})


def verify():
    hosted()
    require((ROOT / 'owner').read_text().strip() == IDENTITY, 'engine_owner_mismatch')
    frozen = json.loads((ROOT / 'receipt/ini-identity.json').read_text())
    directory = Path(frozen['shared_extension_directory'])
    current = {p.name: sha(p.read_bytes()) for p in sorted(directory.glob('*.so')) if p.name != 'opcache.so'}
    require(current == frozen['shared_extension_sha256'], 'shared_extension_bytes_changed')
    for name, digest in frozen['wrapper_sha256'].items():
        require(sha((ROOT / 'artifacts' / name).read_bytes()) == digest, 'engine_wrapper_changed')
    inventory = subprocess.check_output(['dpkg-query', '-W', '-f=${Package}=${Version}\n'])
    environment = json.loads((ROOT / 'receipt/build-environment.json').read_text())
    require(sha(b'\n'.join(sorted(inventory.splitlines()))) == environment['dependency_inventory_sha256'], 'dependency_inventory_changed')
    for sapi, entries in frozen['source_ini'].items():
        for name, hashes in entries.items():
            require(sha((ROOT / 'ini' / sapi / name).read_bytes()) == hashes['shared_sha256'], 'shared_ini_changed')
    a = json.loads((ROOT / 'receipt/build-A.json').read_text())
    b = json.loads((ROOT / 'receipt/build-B.json').read_text())
    require(a['configure_sha256'] == b['configure_sha256'], 'configure_options_differ')
    proofs = {}
    for variant, evidence in [('A', a), ('B', b)]:
        artifacts = ROOT / 'artifacts' / variant
        for name, digest in evidence['sha256'].items():
            require(sha((artifacts / name).read_bytes()) == digest, 'engine_binary_changed')
        code = 'echo json_encode(["binary"=>PHP_BINARY,"version"=>PHP_VERSION,"zts"=>PHP_ZTS,"debug"=>PHP_DEBUG,"sapi"=>PHP_SAPI,"extensions"=>get_loaded_extensions(),"zend"=>get_loaded_extensions(true),"ini"=>php_ini_loaded_file(),"scanned"=>php_ini_scanned_files()],JSON_THROW_ON_ERROR);'
        result = subprocess.run([str(artifacts / 'php-runner'), '-r', code], capture_output=True, text=True,
                                timeout=20, check=True)
        require(result.stderr == '', 'cli_startup_warning')
        row = json.loads(result.stdout)
        require(row['version'] == '8.4.27' and row['zts'] == 0 and row['debug'] == 0 and row['sapi'] == 'cli'
                and row['zend'] == ['Zend OPcache'] and row['ini'] == str(ROOT / 'ini/cli/php.ini'), 'cli_runtime_identity_mismatch')
        require(Path(row['binary']).resolve() == artifacts / 'php', 'cli_binary_selection_mismatch')
        require({'curl', 'dom', 'fileinfo', 'gd', 'mbstring', 'pdo_mysql', 'simplexml', 'xml', 'xmlreader', 'xmlwriter', 'zip'}
                <= {name.lower() for name in row['extensions']}, 'required_cli_extension_missing')
        require(all(Path(name.strip()).parent == ROOT / 'ini/cli/conf.d'
                    for name in row['scanned'].strip().split(',') if name.strip()), 'cli_scanned_foreign_ini')
        proofs[variant] = {key: row[key] for key in ['version', 'zts', 'debug', 'sapi', 'extensions', 'zend']}
        fpm = subprocess.run([str(artifacts / 'php-fpm-runner'), '-i'], capture_output=True, text=True,
                             timeout=20, check=True)
        require(not fpm.stderr and len(fpm.stdout) <= 2 * 1024 * 1024
                and not re.search(r'(?:PHP )?(?:Warning|Fatal error|Startup):', fpm.stdout), 'fpm_startup_warning')
    require(proofs['A'] == proofs['B'], 'cli_runtime_parity_failed')
    write_json(ROOT / 'receipt/runtime-preflight.json', {'resource_acceptance': False, 'cli': proofs,
        'shared_ini_and_extensions_unchanged': True, 'dependency_inventory_unchanged': True,
        'source_api': '20240924', 'fpm_worker_identity': 'required_in_each_arm_standard_response_observer'})


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('stage', choices=['sources', 'build', 'ini', 'verify'])
    stage = parser.parse_args().stage
    try:
        globals()[stage]()
        write_json(ROOT / ('receipt/preparation-' + stage + '.json'), {
            'stage': stage, 'state': 'complete', 'resource_acceptance': False,
            'commands': COMMAND_OBSERVATIONS[-8:],
        })
    except Exception as error:
        if ROOT.is_dir() and not ROOT.is_symlink() and (ROOT / 'owner').is_file() and (ROOT / 'owner').read_text().strip() == IDENTITY:
            write_json(ROOT / ('receipt/preparation-' + stage + '.json'), {
                'stage': stage, 'state': 'incomplete', 'resource_acceptance': False,
                'reason': 'timeout' if any(row['timeout'] for row in COMMAND_OBSERVATIONS) else 'preparation_failed',
                'commands': COMMAND_OBSERVATIONS[-8:],
                'variants': {variant: 'failed' if variant == ACTIVE_VARIANT else 'complete'
                             if (ROOT / ('receipt/build-' + variant + '.json')).is_file() else 'unexecuted'
                             for variant in ['A', 'B']},
            })
        raise
