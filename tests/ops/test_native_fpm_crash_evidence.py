"""Unprivileged parser/ownership tests; never mount, dump or inspect live processes."""
import contextlib
from datetime import datetime, timezone
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import struct
import tempfile
import types
import unittest
from unittest import mock

HELPER = Path(__file__).resolve().parents[1] / 'Integration/native-fpm-crash-evidence.py'
SPEC = importlib.util.spec_from_file_location('native_crash_evidence', HELPER)
capture = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(capture)
NANO = 1000000000


def clock_mapping(real, boot, *, offset=0):
    before_ns = round(real * NANO)
    origin_ns = round(real - boot) * NANO
    return {'realtime_before_ns': before_ns, 'boottime_ns': before_ns - origin_ns,
            'realtime_after_ns': before_ns + 1000000, 'clock_ticks_per_second': 100,
            'utc_offset_seconds': offset}


def timing_metadata(base, arm, *, core_epoch=1793462978, prewarm=1793462960, start_ticks=100,
                    lifetime=23, reap=1793462978, drain_end=None):
    """Clock/log/owned-drain metadata only; never touches proc or a native clock."""
    origin = 1793462954
    facts = json.loads((base / 'inventory-a1.json').read_text())
    facts['workers'][0].update({'start_ticks': start_ticks, 'started_epoch': origin + start_ticks / 100})
    facts['started_at'] = int(prewarm)
    facts['master'] = {'pid': 500, 'start_ticks': 50, 'exe_identity': [1, 2]}
    facts['clock_mapping'] = clock_mapping(prewarm, prewarm - origin)
    facts['prewarm_wall_interval_ns'] = [facts['clock_mapping']['realtime_before_ns'], facts['clock_mapping']['realtime_after_ns']]
    (base / 'inventory-a1.json').write_text(json.dumps(facts))
    stamp = datetime.fromtimestamp(reap, timezone.utc).strftime('%d-%b-%Y %H:%M:%S')
    (arm / 'private/fpm.log').write_text(f'[{stamp}] WARNING: [pool fixture] child 123 exited on signal 11 (SIGSEGV - core dumped) after {lifetime:.6f} seconds from start\n')
    end = reap + 1.1 if drain_end is None else drain_end
    drain = {'state': 'drained', 'resource_acceptance': False, 'master_verified': True, 'master': facts['master'],
             'master_terminated': False, 'core_dumping_remaining': 0, 'exit_match_count': 1,
             'started_realtime_ns': round((core_epoch + 0.1) * NANO), 'completed_realtime_ns': round(end * NANO),
             'clock_mapping': clock_mapping(end, end - origin), 'duration_seconds': end - core_epoch - 0.1}
    (base / 'drain-a1.json').write_text(json.dumps(drain))
    return facts, drain


def note(name, kind, desc):
    name += b'\0'
    return struct.pack('<III', len(name), len(desc), kind) + name + b'\0' * (-len(name) % 4) + desc + b'\0' * (-len(desc) % 4)


def elf(notes, kind=4):
    header = bytearray(64)
    header[:6] = b'\x7fELF\x02\x01'
    struct.pack_into('<HH', header, 16, kind, 62)
    struct.pack_into('<Q', header, 32, 64)
    struct.pack_into('<HH', header, 54, 56, 1)
    program = bytearray(56)
    struct.pack_into('<I', program, 0, 4)
    struct.pack_into('<Q', program, 8, 120)
    struct.pack_into('<Q', program, 32, len(notes))
    return bytes(header + program) + notes


def environment():
    return {'php_version': '8.4.27', 'sapi': 'fpm-fcgi', 'zend_version': '4.4.27',
            'extensions': {'curl': '8.4.27', 'Zend OPcache': '8.4.27'},
            'settings': {key: '' for key in ('opcache.enable', 'opcache.enable_cli', 'opcache.optimization_level',
                'opcache.jit', 'opcache.jit_buffer_size', 'opcache.protect_memory', 'opcache.validate_timestamps',
                'opcache.file_update_protection', 'opcache.revalidate_freq')}}


@contextlib.contextmanager
def core_collection_fixture(*, malformed_environment=False, core_pid=123, core_signal=11, core_executable='/usr/sbin/php-fpm8.4', core_epoch=1793462978):
    """Tiny synthetic ELF/files; every ownership/native/process seam is mocked."""
    with tempfile.TemporaryDirectory() as temp:
        base, arm = Path(temp) / 'capture', Path(temp) / 'arm'
        (base / 'cores').mkdir(parents=True)
        (arm / 'private').mkdir(parents=True)
        executable = '/usr/sbin/php-fpm8.4'
        binary = b'synthetic-executable-identity'
        identity = '0011223344556677'
        worker = {'pid': 123, 'start_ticks': 100, 'pool': 'fixture', 'exe_identity': [1, 2], 'started_epoch': 1793462955}
        facts = {'executable': executable, 'build_id': identity, 'executable_sha256': hashlib.sha256(binary).hexdigest(),
                 'workers': [worker], 'started_at': 1793462960}
        (base / 'inventory-a1.json').write_text(json.dumps(facts))
        (arm / 'private/native-environment.json').write_text('{"extensions":[' if malformed_environment else json.dumps(environment()))
        (arm / 'private/fpm.log').write_text('[31-Oct-2026 14:00:00] WARNING: [pool fixture] child 123 exited on signal 11 (SIGSEGV) after 23.0 seconds from start\n')
        status = bytearray(36)
        struct.pack_into('<h', status, 12, core_signal)
        struct.pack_into('<i', status, 32, core_pid)
        files = struct.pack('<QQQQQ', 1, 4096, 0, 1, 0) + core_executable.encode() + b'\0'
        core = base / f'cores/core.123.11.{core_epoch}'
        core.write_bytes(elf(note(b'CORE', 1, status) + note(b'CORE', 0x46494C45, files)))
        timing_metadata(base, arm, core_epoch=core_epoch)
        original_read, original_lstat = capture.read_bytes, Path.lstat

        def bounded_read(path, limit):
            return binary if str(path) == executable else original_read(path, limit)

        def owned_core_lstat(path, *args, **kwargs):
            info = original_lstat(path, *args, **kwargs)
            if path == core:
                fields = list(info)
                fields[4] = 0
                return os.stat_result(fields)
            return info

        def gdb_receipt(command, timeout):
            if command[0] != 'gdb' or '-nx' not in command or '-nh' not in command:
                raise AssertionError('Only the mocked guarded GDB extraction is expected.')
            (base / 'gdb-output.json').write_text(json.dumps({'state': 'captured', 'resource_acceptance': False,
                'frame_bound': False, 'pid': 123, 'signal': 11, 'reason': None,
                'frames': [{'index': 0, 'symbol': 'zend_execute', 'module': 'php-fpm8.4', 'build_id': identity}]}))

        with (mock.patch.dict(os.environ, {'PINOVA_FPM_REVISION_ARM': 'a1'}),
              mock.patch.object(capture, 'state_load', return_value={}),
              mock.patch.object(capture, 'mount_matches', return_value=True),
              mock.patch.object(capture, 'build_id', return_value=identity),
              mock.patch.object(capture, 'read_bytes', side_effect=bounded_read),
              mock.patch.object(Path, 'lstat', owned_core_lstat),
              mock.patch.object(capture.time, 'localtime', return_value=types.SimpleNamespace(tm_gmtoff=0)),
              mock.patch.object(capture.time, 'time', return_value=1793462980),
              mock.patch.object(capture.time, 'time_ns', return_value=1793462980 * NANO),
              mock.patch.object(capture.time, 'clock_gettime_ns', side_effect=lambda clock: 1793462980 * NANO if clock == capture.time.CLOCK_REALTIME else 26 * NANO),
              mock.patch.object(capture, 'process_identity', side_effect=FileNotFoundError),
              mock.patch.object(capture, 'run', side_effect=gdb_receipt) as debugger):
            yield base, arm, core, debugger


def declare_missing_load(core):
    """Tiny complete notes plus a declared load beyond EOF; no large file/truncate."""
    original = core.read_bytes()
    header, notes = bytearray(original[:64]), original[120:]
    struct.pack_into('<H', header, 56, 2)
    program = bytearray(original[64:120])
    struct.pack_into('<Q', program, 8, 176)
    load = bytearray(56)
    struct.pack_into('<I', load, 0, 1)
    struct.pack_into('<Q', load, 8, 176 + len(notes) + 4096)
    struct.pack_into('<Q', load, 32, 4096)
    core.write_bytes(bytes(header + program + load) + notes)


@contextlib.contextmanager
def gdb_entry_fixture(*, unwind_failure=False, debug_case=None):
    """Existing GDB entry, entirely fake GDB/process/module/kernel boundaries."""
    with tempfile.TemporaryDirectory() as temp:
        campaign = Path(temp)
        base, arm = campaign / 'private/native-capture', campaign / 'arm'
        (base / 'cores').mkdir(parents=True)
        identity = '0011223344556677'
        (campaign / 'private').chmod(0o700)
        executable = '/usr/sbin/php-fpm8.4'
        core = base / 'cores/core.123.11.1793462978'
        directory = campaign / 'private/debug-symbols/usr/lib/debug'
        debug_file = directory / '.build-id/00/11223344556677.debug'
        debug_file.parent.mkdir(parents=True)
        (campaign / 'private/debug-symbols').chmod(0o700)
        debug_file.write_bytes(elf(note(b'GNU', 3, bytes.fromhex(identity)), 3))
        debug_file.chmod(0o644)
        data = {'executable': executable, 'core': str(core), 'pid': 123, 'signal': 11, 'build_id': identity,
                'main_ranges': [[0x400000, 0x401000]]}
        if debug_case is not None:
            data.update({'debug_directory': str(directory), 'debug_file': str(debug_file)})
            if debug_case == 'outside':
                data['debug_file'] = '/private/SECRET.debug'
        frame = mock.Mock()
        frame.pc.return_value = 0x400100
        frame.name.return_value = 'zend_execute'
        if unwind_failure:
            frame.older.side_effect = RuntimeError('SECRET /private/?otp=123456')
        else:
            frame.older.return_value = None
        fake = types.ModuleType('gdb')
        fake.execute = mock.Mock(return_value='SECRET debugger chatter')
        fake.selected_inferior = mock.Mock(return_value=types.SimpleNamespace(pid=123))
        fake.newest_frame = mock.Mock(return_value=frame)
        fake.solib_name = mock.Mock(return_value=None)
        main_object = types.SimpleNamespace(filename=executable, build_id=identity, owner=None)
        debug_object = types.SimpleNamespace(filename=str(debug_file), owner=main_object,
            build_id='ffeeddccbbaa9988' if debug_case == 'mismatch' else identity)
        fake.objfiles = mock.Mock(return_value=[main_object, debug_object] if debug_case is not None else [main_object])

        def identity_of(path):
            if str(path) == executable:
                return identity
            if str(path) == str(debug_file):
                return 'ffeeddccbbaa9988' if debug_case == 'mismatch' else identity
            raise capture.CaptureError('debug_path_invalid')

        original_stat, original_lstat = Path.stat, Path.lstat

        def owned_stat(path, *args, **kwargs):
            info = original_stat(path, *args, **kwargs)
            fields = list(info)
            fields[4] = 0
            return os.stat_result(fields)

        def owned_lstat(path, *args, **kwargs):
            info = original_lstat(path, *args, **kwargs)
            fields = list(info)
            fields[4] = 0
            return os.stat_result(fields)

        with (mock.patch.dict(capture.sys.modules, {'gdb': fake}),
              mock.patch.object(capture, 'context', return_value=(campaign, arm, base)),
              mock.patch.object(capture, 'read_bytes', return_value=json.dumps(data).encode()),
              mock.patch.object(capture, 'build_id', side_effect=identity_of),
              mock.patch.object(Path, 'resolve', lambda path, *args, **kwargs: path),
              mock.patch.object(Path, 'stat', owned_stat), mock.patch.object(Path, 'lstat', owned_lstat),
              mock.patch.object(capture, 'write_json') as written,
              mock.patch.object(capture, 'run') as commands,
              mock.patch.object(capture.subprocess, 'run') as native_commands,
              mock.patch.object(capture, 'elf_notes') as elf_reader,
              mock.patch.object(capture, 'core_identity') as core_reader,
              mock.patch.object(capture, 'process_identity') as processes,
              mock.patch.object(os, 'posix_fallocate') as allocation,
              mock.patch.object(os, 'ftruncate') as truncation,
              mock.patch.object(os, 'pidfd_open') as opened,
              mock.patch.object(capture.signal, 'pidfd_send_signal') as signals,
              mock.patch.object(os.path, 'ismount') as mounts):
            yield base, fake, written, directory, debug_file
            for operation in (commands, native_commands, elf_reader, core_reader, processes, allocation, truncation, opened, signals, mounts):
                operation.assert_not_called()


class NativeCrashEvidenceTest(unittest.TestCase):
    def test_preflight_preserves_strict_partial_settings_from_one_native_snapshot(self):
        for case in ('extension_version', 'extensions_shape', 'zend_version', 'extra_key', 'missing_key', 'hostile_value'):
            with self.subTest(settings_case=case), core_collection_fixture() as (base, arm, core, debugger):
                value = environment()
                value['settings'].update({'opcache.jit': '1235', 'opcache.jit_buffer_size': '256M'})
                expected = value['settings'].copy()
                value['extensions']['zmq'] = 'SECRET /private/?otp=123456'
                inventory_reason, settings_reason = 'extension_version_invalid', None
                if case == 'extensions_shape':
                    value['extensions'] = []
                    inventory_reason = 'extensions_shape_invalid'
                elif case == 'zend_version':
                    value['extensions'] = environment()['extensions']
                    value['zend_version'] = 'SECRET /private/?otp=123456'
                    inventory_reason = 'zend_version_invalid'
                elif case == 'extra_key':
                    value['settings']['SECRET /private/?otp=123456'] = '1'
                    settings_reason = 'settings_keys_invalid'
                elif case == 'missing_key':
                    del value['settings']['opcache.jit']
                    settings_reason = 'settings_keys_invalid'
                elif case == 'hostile_value':
                    value['settings']['opcache.jit'] = 'SECRET /private/?otp=123456'
                    settings_reason = 'settings_value_invalid'
                (arm / 'private/native-environment.json').write_text(json.dumps(value))
                (arm / 'fixture-owned').write_text('123:1\n')
                with (mock.patch.object(capture, 'state_load', return_value={'owner': '123:1'}),
                      mock.patch.object(capture, 'read_native_environment_payload', wraps=capture.read_native_environment_payload) as snapshot,
                      mock.patch.object(capture.time, 'sleep') as sleeping):
                    result = capture.preflight(base.parent, arm, base)
                    self.assertEqual(result.get('actual_settings'), expected if settings_reason is None else None)
                    self.assertEqual(result.get('actual_settings_status'),
                                     {'state': 'recorded' if settings_reason is None else 'needs_review', 'reason': settings_reason})
                    self.assertEqual(result['state'], 'version_recorded')
                    self.assertIsNone(result['inventory'])
                    self.assertEqual(result['inventory_status'], {'state': 'needs_review', 'reason': inventory_reason})
                    self.assertFalse(result['resource_acceptance'])
                    snapshot.assert_called_once_with(arm)
                    sleeping.assert_not_called()
                    debugger.assert_not_called()
                    for rejected in ('SECRET', '/private/', '123456'):
                        self.assertNotIn(rejected, json.dumps(result))

    def test_collection_keeps_settings_independent_without_promoting_inventory_or_identity(self):
        for case in ('extension_version', 'extra_key', 'hostile_value', 'malformed_json', 'wrong_version', 'wrong_sapi'):
            with self.subTest(settings_case=case), core_collection_fixture() as (base, arm, core, debugger):
                value = environment()
                value['settings'].update({'opcache.jit': '1235', 'opcache.jit_buffer_size': '256M'})
                expected = value['settings'].copy()
                value['extensions']['zmq'] = 'SECRET /private/?otp=123456'
                reason = None
                if case == 'extra_key':
                    value['settings']['SECRET /private/?otp=123456'] = '1'
                    reason = 'settings_keys_invalid'
                elif case == 'hostile_value':
                    value['settings']['opcache.jit'] = 'SECRET /private/?otp=123456'
                    reason = 'settings_value_invalid'
                elif case == 'wrong_version':
                    value['php_version'] = '8.4.28'
                    reason = 'native_version_mismatch'
                elif case == 'wrong_sapi':
                    value['sapi'] = 'cli'
                    reason = 'native_version_mismatch'
                elif case == 'malformed_json':
                    reason = 'inventory_invalid'
                (arm / 'private/native-environment.json').write_text('{"settings":[' if case == 'malformed_json' else json.dumps(value))
                with (mock.patch.object(capture, 'read_native_environment_payload', wraps=capture.read_native_environment_payload) as snapshot,
                      mock.patch.object(capture.time, 'sleep') as sleeping):
                    result = capture.collect(base.parent, arm, base)
                    self.assertEqual(result.get('actual_settings'), expected if reason is None else None)
                    self.assertEqual(result.get('actual_settings_status'),
                                     {'state': 'recorded' if reason is None else 'needs_review', 'reason': reason})
                    self.assertEqual(result['state'], 'needs_review')
                    self.assertIsNone(result['inventory'])
                    self.assertEqual(result['inventory_status']['state'], 'needs_review')
                    if case in ('extension_version', 'extra_key', 'hostile_value'):
                        self.assertEqual(result['inventory_status']['reason'], 'extension_version_invalid')
                    self.assertEqual(result['cores'][0]['state'], 'captured')
                    self.assertEqual(result['cores'][0]['frames'][0]['symbol'], 'zend_execute')
                    self.assertFalse(result['resource_acceptance'])
                    snapshot.assert_called_once_with(arm)
                    sleeping.assert_not_called()
                    debugger.assert_called_once()
                    for rejected in ('SECRET', '/private/', '123456'):
                        self.assertNotIn(rejected, json.dumps(result))

    def test_gdb_symbol_status_distinguishes_missing_filtered_and_attached_debug(self):
        for case in ('missing', 'filtered_safe', 'filtered_payload', 'resolved', 'name_error',
                     'debug_missing', 'debug_orphan', 'debug_wrong_owner', 'debug_wrong_build',
                     'debug_wrong_path', 'objfiles_error'):
            with self.subTest(symbol_case=case), gdb_entry_fixture(debug_case='matching') as (base, debugger, written, directory, debug_file):
                frame = debugger.newest_frame.return_value
                frame.name.return_value = 'zend_execute' if case == 'resolved' else '_emalloc' if case == 'filtered_safe' else \
                    'SECRET /private/?otp=123456' if case == 'filtered_payload' else None
                if case == 'name_error':
                    frame.name.side_effect = RuntimeError('SECRET /private/?otp=123456')
                main, debug = debugger.objfiles.return_value
                if case == 'debug_missing':
                    debugger.objfiles.return_value = [main]
                elif case == 'debug_orphan':
                    debug.owner = None
                elif case == 'debug_wrong_owner':
                    debug.owner = types.SimpleNamespace(filename='/usr/sbin/other-fpm8.4', build_id=main.build_id, owner=None)
                elif case == 'debug_wrong_build':
                    debug.build_id = 'ffeeddccbbaa9988'
                elif case == 'debug_wrong_path':
                    debug.filename = '/private/SECRET.debug'
                elif case == 'objfiles_error':
                    debugger.objfiles.side_effect = RuntimeError('SECRET /private/?otp=123456')
                capture.gdb_extract()
                written.assert_called_once()
                result = written.call_args.args[1]
                self.assertTrue(result.get('frames'), 'The existing extraction seam must retain finite frame observability.')
                row = result['frames'][0]
                expected_status = 'resolved' if case == 'resolved' else 'filtered' if case.startswith('filtered_') else \
                    'unavailable' if case == 'name_error' else 'missing'
                self.assertEqual(row.get('name_status'), expected_status)
                self.assertEqual(row['symbol'], 'zend_execute' if case == 'resolved' else 'unknown')
                expected_attachment = None if case == 'objfiles_error' else not case.startswith('debug_')
                self.assertIn('debug_objfile_attached', result)
                self.assertIs(result['debug_objfile_attached'], expected_attachment)
                self.assertEqual(result['state'], 'captured' if case == 'resolved' else 'needs_review')
                self.assertFalse(result['resource_acceptance'])
                encoded = json.dumps(result)
                for rejected in ('SECRET', '/private/', '123456', '_emalloc', str(debug_file)):
                    self.assertNotIn(rejected, encoded)

    def test_gdb_module_attribution_requires_solib_or_verified_main_mapping(self):
        cases = (('shared', 0x500100, [[0x400000, 0x401000]], 'opcache.so'),
                 ('main_inside', 0x400100, [[0x400000, 0x401000]], 'php-fpm8.4'),
                 ('main_lower', 0x400000, [[0x400000, 0x401000]], 'php-fpm8.4'),
                 ('main_last', 0x400fff, [[0x400000, 0x401000]], 'php-fpm8.4'),
                 ('main_upper', 0x401000, [[0x400000, 0x401000]], 'unknown'),
                 ('before_main', 0x3fffff, [[0x400000, 0x401000]], 'unknown'),
                 ('anonymous', 0x500100, [[0x400000, 0x401000]], 'unknown'),
                 ('missing_ranges', 0x400100, None, 'unknown'),
                 ('reversed', 0x400100, [[0x401000, 0x400000]], 'unknown'),
                 ('empty', 0x400100, [[0x400000, 0x400000]], 'unknown'),
                 ('negative', 0x400100, [[-1, 0x401000]], 'unknown'),
                 ('boolean', 0x400100, [[True, 0x401000]], 'unknown'),
                 ('hostile', 0x400100, [['SECRET /private/?otp=123456', 0x401000]], 'unknown'),
                 ('range_bound', 0x400100, [[0x400000, 0x401000]] * 65, 'unknown'))
        for case, pc, ranges, expected_module in cases:
            with self.subTest(mapping_case=case), gdb_entry_fixture(debug_case='matching') as (base, debugger, written, directory, debug_file):
                data = json.loads(capture.read_bytes.return_value)
                data['main_ranges'] = ranges
                capture.read_bytes.return_value = json.dumps(data).encode()
                debugger.newest_frame.return_value.pc.return_value = pc
                debugger.solib_name.return_value = '/usr/lib/php/20240924/opcache.so' if case == 'shared' else None
                capture.gdb_extract()
                written.assert_called_once()
                result = written.call_args.args[1]
                if expected_module != 'unknown':
                    self.assertTrue(result.get('frames'))
                if result.get('frames'):
                    row = result['frames'][0]
                    self.assertEqual(row['module'], expected_module)
                    self.assertEqual(row['build_id'], '0011223344556677' if expected_module == 'php-fpm8.4' else None)
                    self.assertNotIn('pc', row)
                if expected_module == 'unknown':
                    self.assertEqual(result['state'], 'needs_review')
                self.assertFalse(result['resource_acceptance'])
                encoded = json.dumps(result)
                for rejected in ('main_ranges', 'SECRET', '/private/', '123456', str(pc)):
                    self.assertNotIn(rejected, encoded)
        with core_collection_fixture() as (base, arm, core, debugger):
            self.assertEqual(capture.core_identity(core, 123, 11, '/usr/sbin/php-fpm8.4'), [[0, 1]],
                             'Main mappings must come from the same bounded PID/signal/executable-verified ELF notes.')
            debugger.assert_not_called()

    def test_inventory_records_owned_master_and_paired_prewarm_clocks(self):
        with core_collection_fixture() as (base, arm, core, debugger):
            (arm / 'fixture-owned').write_text('123:1\n')
            original_text, original_bytes, original_read = Path.read_text, Path.read_bytes, capture.read_bytes
            original_link, original_stat = os.readlink, os.stat
            workers = list(range(101, 111))

            def proc_text(path, *args, **kwargs):
                value = str(path)
                if value == '/proc/500/task/500/children':
                    return ' '.join(map(str, workers))
                if value == '/proc/stat':
                    return 'btime 1793462954\n'
                if value == '/proc/500/stat':
                    return '500 (php-fpm) ' + ' '.join(['S'] + ['0'] * 18 + ['50'] + ['0'] * 8)
                if value.startswith('/proc/'):
                    raise AssertionError('Unmocked proc read refused.')
                return original_text(path, *args, **kwargs)

            def proc_bytes(path):
                if str(path) == '/proc/500/cmdline':
                    return ('php-fpm: master process ' + str(arm) + '/fpm.conf\0').encode()
                if str(path).startswith('/proc/'):
                    raise AssertionError('Unmocked proc read refused.')
                return original_bytes(path)

            def identity(pid, executable):
                return {'pid': pid, 'start_ticks': 50 if pid == 500 else 100,
                        'pool': 'master' if pid == 500 else 'fixture', 'exe_identity': [1, 2]}

            def bounded_read(path, limit):
                if str(path).startswith('/proc/'):
                    return proc_bytes(path) if str(path).endswith('/cmdline') else proc_text(path).encode()
                return original_read(path, limit)

            with (mock.patch.dict(os.environ, {'PINOVA_FPM_BIN': '/usr/sbin/php-fpm8.4', 'PINOVA_FPM_MASTER_PID': '500'}),
                  mock.patch.object(capture, 'state_load', return_value={'owner': '123:1'}),
                  mock.patch.object(Path, 'read_text', proc_text), mock.patch.object(Path, 'read_bytes', proc_bytes),
                  mock.patch.object(capture, 'read_bytes', side_effect=bounded_read),
                  mock.patch.object(os, 'readlink', side_effect=lambda path, *a, **k: '/usr/sbin/php-fpm8.4' if str(path).startswith('/proc/') else original_link(path, *a, **k)),
                  mock.patch.object(os, 'stat', side_effect=lambda path, *a, **k: types.SimpleNamespace(st_dev=1, st_ino=2) if str(path).startswith('/proc/') else original_stat(path, *a, **k)),
                  mock.patch.object(capture, 'process_identity', side_effect=identity),
                  mock.patch.object(capture.os, 'sysconf', return_value=100),
                  mock.patch.object(capture.time, 'localtime', return_value=types.SimpleNamespace(tm_gmtoff=0)),
                  mock.patch.object(capture, 'write_json') as saved,
                  mock.patch.object(capture, 'run') as native, mock.patch.object(capture.subprocess, 'run') as native_process,
                  mock.patch.object(os, 'kill') as kill, mock.patch.object(os, 'pidfd_open') as pidfd,
                  mock.patch.object(capture.signal, 'pidfd_send_signal') as signals):
                capture.inventory(base.parent, arm, base)
                value = saved.call_args.args[1]
                self.assertIn('clock_mapping', value, 'The inventory must record the paired clocks used by its birth-time fence.')
                self.assertEqual(value['clock_mapping']['utc_offset_seconds'], 0)
                self.assertEqual(value['clock_mapping']['clock_ticks_per_second'], 100)
                self.assertEqual(value['master']['pid'], 500)
                self.assertEqual(value['master']['start_ticks'], 50)
                self.assertEqual(len(value['prewarm_wall_interval_ns']), 2)
                self.assertLessEqual(*value['prewarm_wall_interval_ns'])
                for operation in (native, native_process, kill, pidfd, signals):
                    operation.assert_not_called()

    def test_native_drain_is_readonly_bounded_and_keeps_owned_master_alive(self):
        for fault in ('none', 'timeout', 'identity_drift', 'missing_exit'):
            with self.subTest(drain=fault), core_collection_fixture() as (base, arm, core, debugger):
                elapsed = [0.0]
                sleeps = [0]
                original_text, original_bytes, original_read = Path.read_text, Path.read_bytes, capture.read_bytes
                original_link, original_stat = os.readlink, os.stat
                facts = json.loads((base / 'inventory-a1.json').read_text())
                facts['workers'].append({**facts['workers'][0], 'pid': 124, 'start_ticks': 101})
                (base / 'inventory-a1.json').write_text(json.dumps(facts))
                (arm / 'private/fpm.log').write_text('')
                row = facts['workers'][0]

                def advance(seconds):
                    self.assertGreater(seconds, 0)
                    self.assertLessEqual(seconds, 30)
                    sleeps[0] += 1
                    if sleeps[0] > 1024:
                        raise AssertionError('The drain exceeded its finite polling budget.')
                    elapsed[0] += seconds
                    if elapsed[0] >= 0.2 and fault not in ('timeout', 'missing_exit'):
                        stamp = datetime.fromtimestamp(1793462980, timezone.utc).strftime('%d-%b-%Y %H:%M:%S')
                        (arm / 'private/fpm.log').write_text(f'[{stamp}] WARNING: [pool fixture] child 123 exited on signal 11 (SIGSEGV - core dumped) after 25.000000 seconds from start\n')

                def proc_data(path):
                    value = str(path)
                    if value in ('/proc/123/status', '/proc/124/status', '/proc/500/status'):
                        dumping = value == '/proc/123/status' and (fault == 'timeout' or elapsed[0] < 0.2)
                        return ('Name:\tphp-fpm\nCoreDumping:\t' + str(int(dumping)) + '\n').encode()
                    if value == '/proc/500/task/500/children':
                        return b'123 124'
                    if value.endswith('/stat') and value.split('/')[2] in ('123', '124', '500'):
                        pid = int(value.split('/')[2]); ticks = 50 if pid == 500 else 100 if pid == 123 else 101
                        return (str(pid) + ' (php-fpm) ' + ' '.join(['S'] + ['0'] * 18 + [str(ticks)] + ['0'] * 8)).encode()
                    if value == '/proc/500/cmdline':
                        return ('php-fpm: master process ' + str(arm) + '/fpm.conf\0').encode()
                    if value in ('/proc/123/cmdline', '/proc/124/cmdline'):
                        return b'php-fpm: pool fixture\0'
                    if value.startswith('/proc/'):
                        raise AssertionError('Unmocked proc read refused.')
                    return None

                def proc_text(path, *args, **kwargs):
                    data = proc_data(path)
                    return data.decode() if data is not None else original_text(path, *args, **kwargs)

                def proc_bytes(path):
                    data = proc_data(path)
                    return data if data is not None else original_bytes(path)

                def bounded_read(path, limit):
                    data = proc_data(path)
                    return data if data is not None else original_read(path, limit)

                def identity(pid, executable):
                    if pid == 500:
                        return {'pid': 500, 'start_ticks': 50, 'pool': 'master', 'exe_identity': [1, 2]}
                    result = {**row, 'pid': pid, 'start_ticks': 101 if pid == 124 or fault == 'identity_drift' else 100}
                    return result

                def proc_link(path, *args, **kwargs):
                    return '/usr/sbin/php-fpm8.4' if str(path).startswith('/proc/') else original_link(path, *args, **kwargs)

                def proc_stat(path, *args, **kwargs):
                    return types.SimpleNamespace(st_dev=1, st_ino=2) if str(path).startswith('/proc/') else original_stat(path, *args, **kwargs)

                output = io.StringIO()
                with contextlib.ExitStack() as stack:
                    stack.enter_context(mock.patch.object(capture, 'context', return_value=(base.parent, arm, base)))
                    stack.enter_context(mock.patch.object(capture, 'state_load', return_value={'owner': '123:1'}))
                    stack.enter_context(mock.patch.dict(os.environ, {'PINOVA_FPM_MASTER_PID': '500', 'PINOVA_FPM_BIN': '/usr/sbin/php-fpm8.4'}))
                    stack.enter_context(mock.patch.object(capture.sys, 'argv', ['helper', 'drain']))
                    stack.enter_context(mock.patch.object(Path, 'read_text', proc_text))
                    stack.enter_context(mock.patch.object(Path, 'read_bytes', proc_bytes))
                    stack.enter_context(mock.patch.object(capture, 'read_bytes', side_effect=bounded_read))
                    stack.enter_context(mock.patch.object(os, 'readlink', side_effect=proc_link))
                    stack.enter_context(mock.patch.object(os, 'stat', side_effect=proc_stat))
                    stack.enter_context(mock.patch.object(capture, 'process_identity', side_effect=identity))
                    stack.enter_context(mock.patch.object(capture.time, 'sleep', side_effect=advance))
                    stack.enter_context(mock.patch.object(capture.time, 'monotonic', side_effect=lambda: elapsed[0]))
                    stack.enter_context(mock.patch.object(capture.time, 'monotonic_ns', side_effect=lambda: round(elapsed[0] * NANO)))
                    stack.enter_context(mock.patch.object(capture.time, 'time', side_effect=lambda: 1793462980 + elapsed[0]))
                    stack.enter_context(mock.patch.object(capture.time, 'time_ns', side_effect=lambda: 1793462980 * NANO + round(elapsed[0] * NANO)))
                    stack.enter_context(mock.patch.object(capture.time, 'clock_gettime_ns', side_effect=lambda clock: (1793462980 * NANO if clock == capture.time.CLOCK_REALTIME else 26 * NANO) + round(elapsed[0] * NANO)))
                    native = stack.enter_context(mock.patch.object(capture, 'run'))
                    native_process = stack.enter_context(mock.patch.object(capture.subprocess, 'run'))
                    kill = stack.enter_context(mock.patch.object(os, 'kill'))
                    pidfd = stack.enter_context(mock.patch.object(os, 'pidfd_open'))
                    signal_send = stack.enter_context(mock.patch.object(capture.signal, 'pidfd_send_signal'))
                    stack.enter_context(contextlib.redirect_stdout(output))
                    capture.main()
                    result = json.loads(output.getvalue())
                    self.assertNotEqual(result.get('reason'), 'command_unavailable', 'The existing helper command entry must perform the native-only bounded drain.')
                    self.assertEqual(result['state'], 'drained' if fault == 'none' else 'needs_review')
                    self.assertFalse(result['resource_acceptance'])
                    self.assertFalse(result['master_terminated'])
                    self.assertLessEqual(result['duration_seconds'], 30)
                    if fault == 'none':
                        self.assertEqual(result['core_dumping_remaining'], 0)
                        self.assertEqual(result['exit_match_count'], 1)
                    for operation in (native, native_process, kill, pidfd, signal_send):
                        operation.assert_not_called()

    def test_shell_native_failure_drain_precedes_term_and_preserves_original_status(self):
        source = (HELPER.parent / 'otp-fpm-full-stack.sh').read_text()
        body = source.split('cleanup_processes() {', 1)[1].split('\n}\ntrap cleanup_processes EXIT', 1)[0]
        self.assertIn('native-fpm-crash-evidence.py', body, 'Native failure cleanup must invoke the bounded read-only drain.')
        self.assertIn('PINOVA_FPM_NATIVE_CAPTURE', body)
        self.assertLess(body.index(' drain'), body.index('kill -TERM'))
        self.assertIn('local status=$?', body)
        self.assertIn('exit "${status}"', body)

    def test_missing_duplicate_exit_keeps_finite_core_timing_facts_before_refusal(self):
        for fault in ('missing', 'duplicate'):
            with self.subTest(exit_record=fault), core_collection_fixture() as (base, arm, core, debugger):
                log = arm / 'private/fpm.log'
                content = log.read_text()
                log.write_text('' if fault == 'missing' else content + content)
                result = None
                try:
                    result = capture.collect(base.parent, arm, base)
                except capture.CaptureError:
                    pass
                self.assertIsNotNone(result, 'A refused lifetime guard must preserve finite core and clock facts instead of erasing them.')
                self.assertEqual(result['state'], 'needs_review')
                self.assertEqual(result['core_count'], 1)
                item = result['cores'][0]
                self.assertEqual((item['pid'], item['signal']), (123, 11))
                timing = item['timing']
                self.assertEqual(timing['core_timestamp_epoch'], 1793462978)
                self.assertEqual(timing['core_size_bytes'], core.stat().st_size)
                self.assertEqual(timing['worker_start_ticks'], 100)
                self.assertEqual(timing['exit_match_count'], 0 if fault == 'missing' else 2)
                self.assertEqual(len(timing['birth_epoch_interval_ns']), 2)
                self.assertEqual(len(timing['prewarm_wall_interval_ns']), 2)
                self.assertIn('drain', timing)
                self.assertEqual(item['frames'], [])
                debugger.assert_not_called()

    def test_reap_delay_and_fractional_core_second_use_endpoint_intersections(self):
        for case in ('long_dump_delay', 'fractional_core_second'):
            epoch = 1793462978 if case == 'long_dump_delay' else 1793462955
            with self.subTest(clock_case=case), core_collection_fixture(core_epoch=epoch) as (base, arm, core, debugger):
                if case == 'long_dump_delay':
                    timing_metadata(base, arm, lifetime=40, reap=1793462995)
                else:
                    timing_metadata(base, arm, core_epoch=epoch, prewarm=1793462955.05, start_ticks=101, lifetime=9.95, reap=1793462965)
                with (mock.patch.object(capture.time, 'time', return_value=1793462998),
                      mock.patch.object(capture.time, 'time_ns', return_value=1793462998 * NANO),
                      mock.patch.object(capture.time, 'clock_gettime_ns', side_effect=lambda clock: 1793462998 * NANO if clock == capture.time.CLOCK_REALTIME else 44 * NANO)):
                    result = None
                    try:
                        result = capture.collect(base.parent, arm, base)
                    except capture.CaptureError:
                        pass
                self.assertIsNotNone(result, 'Kernel core creation and parent reap are distinct events; valid endpoint intervals must reach diagnosis.')
                debugger.assert_called_once()
                self.assertEqual(result['cores'][0]['frames'][0]['symbol'], 'zend_execute')
                self.assertFalse(result['resource_acceptance'])

    def test_discontinuous_nonutc_and_inconsistent_endpoints_refuse_gdb(self):
        for fault in ('clock_step', 'nonutc', 'postfork_outside_birth_window', 'reap_after_drain'):
            with self.subTest(clock_guard=fault), core_collection_fixture() as (base, arm, core, debugger):
                facts, drain = timing_metadata(base, arm)
                if fault == 'clock_step':
                    drain['clock_mapping']['boottime_ns'] -= 5 * NANO
                elif fault == 'nonutc':
                    facts['clock_mapping']['utc_offset_seconds'] = 3600
                elif fault == 'postfork_outside_birth_window':
                    timing_metadata(base, arm, lifetime=1)
                else:
                    drain['completed_realtime_ns'] = 1793462977 * NANO
                if fault != 'postfork_outside_birth_window':
                    (base / 'inventory-a1.json').write_text(json.dumps(facts))
                    (base / 'drain-a1.json').write_text(json.dumps(drain))
                try:
                    capture.collect(base.parent, arm, base)
                except capture.CaptureError:
                    pass
                debugger.assert_not_called()

    def test_bounded_partial_core_retains_frames_without_complete_stack_claim(self):
        for mode in ('at_limit', 'missing_load'):
            with self.subTest(partial=mode), core_collection_fixture() as (base, arm, core, debugger):
                if mode == 'missing_load':
                    declare_missing_load(core)
                capture.core_identity(core, 123, 11, '/usr/sbin/php-fpm8.4')
                original_stat = Path.stat

                def bounded_stat(path, *args, **kwargs):
                    info = original_stat(path, *args, **kwargs)
                    if mode == 'at_limit' and path == core:
                        fields = list(info)
                        fields[6] = capture.CORE_LIMIT
                        return os.stat_result(fields)
                    return info

                with mock.patch.object(Path, 'stat', bounded_stat):
                    result = capture.collect(base.parent, arm, base)
                debugger.assert_called_once()
                self.assertEqual(result['state'], 'needs_review')
                self.assertFalse(result['resource_acceptance'])
                native = result['cores'][0]
                self.assertTrue(native.get('partial_core'))
                self.assertIs(native.get('stack_complete'), False)
                self.assertEqual(native['state'], 'needs_review')
                self.assertEqual(native['frames'][0]['symbol'], 'zend_execute')

    def test_partial_core_guard_mismatches_never_reach_gdb(self):
        for fault in ('pid', 'signal', 'executable', 'build', 'lifetime'):
            kwargs = {'core_pid': 124} if fault == 'pid' else {'core_signal': 7} if fault == 'signal' else \
                {'core_executable': '/usr/sbin/other-fpm8.4'} if fault == 'executable' else {}
            with self.subTest(guard=fault), core_collection_fixture(**kwargs) as (base, arm, core, debugger):
                declare_missing_load(core)
                if fault == 'lifetime':
                    (arm / 'private/fpm.log').write_text('[31-Oct-2026 14:00:00] WARNING: [pool fixture] child 123 exited on signal 11 (SIGSEGV) after 100.0 seconds from start\n')
                wrong_build = mock.patch.object(capture, 'build_id', return_value='ffeeddccbbaa9988') if fault == 'build' else contextlib.nullcontext()
                with wrong_build:
                    try:
                        result = capture.collect(base.parent, arm, base)
                    except capture.CaptureError:
                        result = None
                debugger.assert_not_called()
                if result is not None:
                    self.assertEqual(result['state'], 'needs_review')
                    self.assertEqual(result['cores'][0]['frames'], [])

    def test_gdb_unwind_failure_retains_first_frame_and_native_identity(self):
        with gdb_entry_fixture(unwind_failure=True) as (base, debugger, written, directory, debug_file):
            capture.gdb_extract()
            written.assert_called_once()
            result = written.call_args.args[1]
            self.assertEqual(result['frames'][0], {'index': 0, 'symbol': 'zend_execute', 'name_status': 'resolved', 'module': 'php-fpm8.4', 'build_id': '0011223344556677'})
            self.assertEqual((result.get('pid'), result.get('signal')), (123, 11))
            self.assertEqual(result['state'], 'needs_review')
            self.assertEqual(result['reason'], 'gdb_unwind_incomplete')
            self.assertIs(result.get('stack_complete'), False)
            self.assertFalse(result['resource_acceptance'])
            self.assertNotIn('SECRET', json.dumps(result))
            self.assertNotIn('/private/', json.dumps(result))

    def test_gdb_debug_symbols_require_matching_build_and_private_path(self):
        for case in ('matching', 'mismatch', 'outside'):
            with self.subTest(symbols=case), gdb_entry_fixture(debug_case=case) as (base, debugger, written, directory, debug_file):
                capture.gdb_extract()
                written.assert_called_once()
                result = written.call_args.args[1]
                commands = [call.args[0] for call in debugger.execute.call_args_list]
                if case == 'matching':
                    setting = 'set debug-file-directory ' + str(directory)
                    self.assertIn(setting, commands)
                    self.assertLess(commands.index(setting), commands.index('file /usr/sbin/php-fpm8.4'))
                    self.assertEqual(result['state'], 'captured')
                else:
                    self.assertFalse(any(command.startswith(('file ', 'core-file ', 'symbol-file ', 'add-symbol-file ', 'set debug-file-directory ')) for command in commands))
                    self.assertEqual(result['state'], 'needs_review')
                self.assertFalse(result['resource_acceptance'])
                self.assertNotIn('SECRET', json.dumps(result))
                self.assertNotIn(str(directory), json.dumps(result))

    def test_low_disk_refuses_image_allocation_mount_and_kernel_changes(self):
        with tempfile.TemporaryDirectory() as temp:
            campaign, arm, base = Path(temp), Path(temp) / 'arm', Path(temp) / 'capture'
            fd_info = types.SimpleNamespace(st_dev=1, st_ino=2)
            low = types.SimpleNamespace(f_bavail=1, f_frsize=4096)
            with (mock.patch.dict(os.environ, {'GITHUB_RUN_ID': '123', 'GITHUB_RUN_ATTEMPT': '1'}),
                  mock.patch.object(os, 'statvfs', return_value=low),
                  mock.patch.object(os, 'open', return_value=456) as image_open,
                  mock.patch.object(os, 'posix_fallocate') as allocation,
                  mock.patch.object(os, 'ftruncate') as truncation,
                  mock.patch.object(os, 'fstat', return_value=fd_info), mock.patch.object(os, 'close'),
                  mock.patch.object(capture, 'run') as commands,
                  mock.patch.object(capture.subprocess, 'run') as native_commands,
                  mock.patch.object(capture, 'mount_matches', return_value=True) as mounts,
                  mock.patch.object(capture, 'write_json') as saved,
                  mock.patch.object(os, 'chmod'), mock.patch.object(Path, 'rmdir'),
                  mock.patch.object(Path, 'read_text', return_value='original-core-pattern\n'),
                  mock.patch.object(Path, 'write_text') as kernel_write):
                failure = None
                try:
                    capture.setup(campaign, arm, base)
                except capture.CaptureError as error:
                    failure = error.code
                self.assertIsNotNone(failure)
                for operation in (image_open, allocation, truncation, commands, native_commands, mounts, saved, kernel_write):
                    operation.assert_not_called()
                self.assertEqual(failure, 'disk_headroom_unavailable')

    def test_preflight_allows_optional_shape_unknown_only_after_critical_identity_is_verified(self):
        with tempfile.TemporaryDirectory() as temp:
            campaign, arm, base = Path(temp), Path(temp) / 'arm', Path(temp) / 'capture'
            (arm / 'private').mkdir(parents=True)
            base.mkdir()
            (arm / 'fixture-owned').write_text('123:1\n')
            native = arm / 'private/native-environment.json'
            with (mock.patch.object(capture, 'state_load', return_value={'owner': '123:1'}),
                  mock.patch.object(capture, 'mount_matches', return_value=True),
                  mock.patch.object(capture, 'run') as commands,
                  mock.patch.object(capture, 'process_identity') as processes,
                  mock.patch.object(capture, 'core_identity') as cores,
                  mock.patch.object(os, 'pidfd_open') as opened,
                  mock.patch.object(capture.signal, 'pidfd_send_signal') as signals,
                  mock.patch.object(os.path, 'ismount') as mounts):
                # Critical evidence cannot be replaced by an optional-schema limit.
                for label in ('malformed_json', 'missing_file', 'wrong_version', 'wrong_sapi'):
                    with self.subTest(critical=label):
                        value = environment()
                        if label == 'wrong_version':
                            value['php_version'] = '8.4.28'
                        elif label == 'wrong_sapi':
                            value['sapi'] = 'cli'
                        native.write_text('{"extensions":[' if label == 'malformed_json' else json.dumps(value))
                        if label == 'missing_file':
                            native.unlink()
                        with self.assertRaises(capture.CaptureError):
                            capture.preflight(campaign, arm, base)

                for field, reason in [('extension_name', 'extension_name_invalid'), ('extension_version', 'extension_version_invalid'),
                                      ('settings_keys', 'settings_keys_invalid'), ('settings_value', 'settings_value_invalid'),
                                      ('zend_version', 'zend_version_invalid')]:
                    with self.subTest(optional=field):
                        value = environment()
                        rejected = 'SECRET /private/?otp=123456'
                        if field == 'extension_name':
                            value['extensions'][rejected] = '1.0'
                        elif field == 'extension_version':
                            value['extensions']['curl'] = rejected
                        elif field == 'settings_keys':
                            value['settings'][rejected] = '1'
                        elif field == 'settings_value':
                            value['settings']['opcache.jit'] = rejected
                        else:
                            value['zend_version'] = rejected
                        native.write_text(json.dumps(value))
                        failure, result = None, None
                        try:
                            result = capture.preflight(campaign, arm, base)
                        except capture.CaptureError as error:
                            failure = error.code
                        self.assertIsNone(failure, 'Verified critical identity must permit diagnosis despite an optional metadata-shape limit.')
                        self.assertEqual(result['state'], 'version_recorded')
                        self.assertEqual(result['identity'], {'php_version': '8.4.27', 'sapi': 'fpm-fcgi'})
                        self.assertFalse(result['resource_acceptance'])
                        self.assertEqual(result['inventory_status']['state'], 'needs_review')
                        self.assertEqual(result['inventory_status']['reason'], reason)
                        self.assertIsNone(result['inventory'])
                        published = json.dumps(result)
                        self.assertNotIn('SECRET', published)
                        self.assertNotIn('/private/', published)
                        self.assertNotIn('otp=123456', published)
                for operation in (commands, processes, cores, opened, signals, mounts):
                    operation.assert_not_called()

    def test_mysqlnd_named_version_prefix_is_accepted_only_for_matching_extension_and_php(self):
        value = environment()
        value['extensions']['mysqlnd'] = 'mysqlnd 8.4.27'
        failure, clean = None, None
        try:
            clean = capture.finite_environment(value)
        except capture.CaptureError as error:
            failure = error.code
        self.assertIsNone(failure, 'The actual PHP8.4.27 mysqlnd version format must be accepted.')
        self.assertEqual(clean['extensions']['mysqlnd'], 'mysqlnd 8.4.27')
        for name, version in [('curl', 'mysqlnd 8.4.27'), ('mysqlnd', 'mysqlnd 8.4.28'),
                              ('mysqlnd', 'mysqlnd 8.4.27 SECRET'), ('mysqlnd', 'mysqlnd 8.4.27\nOTP=123456'),
                              ('mysqlnd', 'other 8.4.27')]:
            invalid = environment()
            invalid['extensions'][name] = version
            with self.assertRaises(capture.CaptureError):
                capture.finite_environment(invalid)

    def test_native_cgi_fcgi_module_name_is_accepted_only_as_known_literal(self):
        value = environment()
        value['extensions']['cgi-fcgi'] = '8.4.27'
        failure, clean = None, None
        try:
            clean = capture.finite_environment(value)
        except capture.CaptureError as error:
            failure = error.code
        self.assertIsNone(failure, 'The native PHP8.4.27 FPM module name cgi-fcgi must be accepted as an exact known literal.')
        self.assertEqual(clean['extensions']['cgi-fcgi'], '8.4.27')
        self.assertFalse(clean['resource_acceptance'])
        for name in ('other-module', 'cgi-fcgi-extra', 'cgi-fcgi SECRET', 'cgi-fcgi\nOTP=123456', '/private/cgi-fcgi'):
            with self.subTest(rejected_name=name):
                invalid = environment()
                invalid['extensions'][name] = '8.4.27'
                with self.assertRaises(capture.CaptureError):
                    capture.finite_environment(invalid)

    def test_malformed_optional_inventory_does_not_suppress_verified_synthetic_core(self):
        with core_collection_fixture(malformed_environment=True) as (base, arm, core, debugger):
            # Independently prove this tiny ELF's PID/signal/executable binding.
            capture.core_identity(core, 123, 11, '/usr/sbin/php-fpm8.4')
            failed, result = False, None
            try:
                result = capture.collect(base.parent, arm, base)
            except (capture.CaptureError, json.JSONDecodeError):
                failed = True
            self.assertFalse(failed, 'Malformed optional metadata must not prevent independently verified core extraction.')
            debugger.assert_called_once()
            self.assertEqual(result['state'], 'needs_review')
            self.assertFalse(result['resource_acceptance'])
            self.assertIsNone(result['inventory'])
            self.assertEqual(result['cores'][0]['state'], 'captured')
            self.assertEqual(result['cores'][0]['frames'][0]['symbol'], 'zend_execute')

    def test_invalid_core_identity_refuses_gdb_even_with_valid_optional_inventory(self):
        with core_collection_fixture(core_pid=124) as (base, arm, core, debugger):
            result = capture.collect(base.parent, arm, base)
            debugger.assert_not_called()
            self.assertEqual(result['state'], 'needs_review')
            self.assertFalse(result['resource_acceptance'])
            self.assertEqual(result['cores'][0]['reason'], 'core_pid_signal_mismatch')
            self.assertEqual(result['cores'][0]['frames'], [])

    def test_core_pid_signal_and_executable_must_all_match(self):
        status = bytearray(36)
        struct.pack_into('<h', status, 12, 11)
        struct.pack_into('<i', status, 32, 123)
        files = struct.pack('<QQQQQ', 1, 4096, 0, 1, 0) + b'/usr/sbin/php-fpm8.4\0'
        with tempfile.TemporaryDirectory() as temp:
            core = Path(temp) / 'core'
            core.write_bytes(elf(note(b'CORE', 1, status) + note(b'CORE', 0x46494C45, files)))
            capture.core_identity(core, 123, 11, '/usr/sbin/php-fpm8.4')
            for pid, signal, executable in [(124, 11, '/usr/sbin/php-fpm8.4'), (123, 7, '/usr/sbin/php-fpm8.4'),
                                              (123, 11, '/private/SECRET-OTP')]:
                with self.assertRaises(capture.CaptureError):
                    capture.core_identity(core, pid, signal, executable)

    def test_truncated_or_unbounded_elf_is_not_accepted(self):
        with tempfile.TemporaryDirectory() as temp:
            target = Path(temp) / 'core'
            full = elf(note(b'GNU', 3, b'12345678'))
            for data in (full[:10], full[:-1], b'raw SECRET URI /private/otp=123456'):
                target.write_bytes(data)
                with self.assertRaises(capture.CaptureError):
                    capture.elf_notes(target)
            data = bytearray(full)
            struct.pack_into('<H', data, 56, 5000)
            target.write_bytes(data)
            with self.assertRaises(capture.CaptureError):
                capture.elf_notes(target)

    def test_exact_build_identity_and_symlink_rejection(self):
        with tempfile.TemporaryDirectory() as temp:
            target = Path(temp) / 'binary'
            target.write_bytes(elf(note(b'GNU', 3, b'12345678'), 3))
            self.assertEqual(capture.build_id(target), b'12345678'.hex())
            link = Path(temp) / 'link'
            link.symlink_to(target)
            with self.assertRaises(OSError):
                capture.build_id(link)
            target.write_bytes(elf(b'', 3))
            with self.assertRaises(capture.CaptureError):
                capture.build_id(target)

    def test_symbol_and_module_filters_drop_args_paths_and_payloads(self):
        self.assertEqual(capture.native_symbol('zend_std_read_property'), 'zend_std_read_property')
        for value in ('zend_read(password=SECRET)', '/private/otp=123456', 'SECRET_OTP', 'zend_x\nCOOKIE', 'zend_' + 'x' * 200, None):
            self.assertEqual(capture.native_symbol(value), 'unknown')
        self.assertEqual(capture.native_module('/usr/lib/x86_64-linux-gnu/libc.so.6'), 'libc.so.6')
        for value in ('/tmp/SECRET-OTP.so', '/usr/lib/evil\nTOKEN.so', '/private/path', None):
            self.assertEqual(capture.native_module(value), 'unknown')

    def test_inventory_is_finite_exact_version_and_no_arbitrary_fields(self):
        value = environment()
        value.update({'uri': '/private/?otp=123456', 'environment': 'SECRET', 'resource_acceptance': True})
        clean = capture.finite_environment(value)
        self.assertFalse(clean['resource_acceptance'])
        self.assertNotIn('SECRET', json.dumps(clean))
        self.assertNotIn('otp', json.dumps(clean))
        for mutation in ({'php_version': '8.4.28'}, {'sapi': 'cli'}, {'extensions': {'curl/SECRET': '1.0'}},
                         {'extensions': {'curl': 'password=SECRET'}}, {'extensions': {str(n): '1' for n in range(129)}}):
            bad = environment()
            bad.update(mutation)
            with self.assertRaises(capture.CaptureError):
                capture.finite_environment(bad)
        bad = environment()
        bad['settings']['opcache.jit'] = 'tracing\nSECRET'
        with self.assertRaises(capture.CaptureError):
            capture.finite_environment(bad)

    def test_non_hosted_or_foreign_root_cannot_reach_commands(self):
        with mock.patch.dict(os.environ, {}, clear=True), mock.patch.object(capture, 'run') as command:
            with self.assertRaises(capture.CaptureError):
                capture.context()
            command.assert_not_called()
        env = {'GITHUB_ACTIONS': 'true', 'RUNNER_ENVIRONMENT': 'github-hosted', 'GITHUB_RUN_ID': '123',
               'GITHUB_RUN_ATTEMPT': '1', 'PINOVA_FPM_NATIVE_CAPTURE': '1', 'PINOVA_FPM_PHP_VERSION': '8.4',
               'PINOVA_FPM_LOGGING_PROFILE': 'normal', 'PINOVA_FPM_CAMPAIGN_ROOT': '/private/SECRET'}
        with mock.patch.dict(os.environ, env, clear=True), mock.patch.object(os, 'geteuid', return_value=0):
            with self.assertRaises(capture.CaptureError):
                capture.context()

    def test_ordinary_directory_cannot_be_mistaken_for_bounded_mount(self):
        with tempfile.TemporaryDirectory() as temp:
            base = Path(temp)
            (base / 'cores').mkdir()
            self.assertFalse(capture.mount_matches(base, {}))
            with mock.patch.object(os.path, 'ismount', return_value=True):
                with self.assertRaises(capture.CaptureError):
                    capture.mount_matches(base, {})

    def test_failure_interface_never_emits_exception_text_or_traceback(self):
        output = io.StringIO()
        with (mock.patch.object(capture, 'context', side_effect=RuntimeError('OTP=123456 /private/SECRET')),
              mock.patch.object(capture.sys, 'argv', ['helper', 'setup']), contextlib.redirect_stdout(output)):
            self.assertEqual(capture.main(), 1)
        receipt = json.loads(output.getvalue())
        self.assertEqual(receipt['state'], 'needs_review')
        self.assertFalse(receipt['resource_acceptance'])
        self.assertNotIn('SECRET', output.getvalue())
        self.assertNotIn('Traceback', output.getvalue())

    def test_reused_pid_is_never_opened_or_signalled(self):
        with tempfile.TemporaryDirectory() as temp:
            base = Path(temp)
            expected = {'pid': 123, 'start_ticks': 100, 'pool': 'fixture', 'exe_identity': [1, 2]}
            (base / 'inventory-a1.json').write_text(json.dumps({'executable': '/usr/sbin/php-fpm8.4', 'workers': [expected]}))
            changed = {**expected, 'start_ticks': 101}
            with (mock.patch.object(capture, 'process_identity', return_value=changed),
                  mock.patch.object(os, 'pidfd_open') as opened,
                  mock.patch.object(capture.signal, 'pidfd_send_signal') as sent):
                self.assertEqual(capture.stop_known_workers(base), [])
                opened.assert_not_called()
                sent.assert_not_called()

    def test_exact_owned_identity_is_rechecked_before_pidfd_signal(self):
        with tempfile.TemporaryDirectory() as temp:
            base = Path(temp)
            expected = {'pid': 123, 'start_ticks': 100, 'pool': 'fixture', 'exe_identity': [1, 2]}
            (base / 'inventory-a1.json').write_text(json.dumps({'executable': '/usr/sbin/php-fpm8.4', 'workers': [expected]}))
            with (mock.patch.object(capture, 'process_identity', return_value=expected) as observed,
                  mock.patch.object(os, 'pidfd_open', return_value=456), mock.patch.object(os, 'close'),
                  mock.patch.object(capture.signal, 'pidfd_send_signal') as sent,
                  mock.patch('select.select', return_value=([456], [], []))):
                self.assertEqual(capture.stop_known_workers(base), [123])
                self.assertEqual(observed.call_count, 2)
                sent.assert_called_once_with(456, capture.signal.SIGTERM)

    def test_malformed_inventory_restores_route_without_unverified_cleanup(self):
        with tempfile.TemporaryDirectory() as temp:
            base = Path(temp) / 'native-capture'
            base.mkdir()
            (base / 'cores').mkdir()
            image = base / 'cores.img'
            image.write_bytes(b'exclusive-image-placeholder')
            core = base / 'cores/core.123.11.1793462978'
            core.write_bytes(b'private-core-placeholder')
            (base / 'inventory-a1.json').write_text('{"workers":[')
            state = {'closed': False, 'pattern': '/owned/private/core.%p.%s.%t', 'previous_pattern': 'original-core-pattern'}
            route = {'current': state['pattern']}
            kernel_path = Path('/proc/sys/kernel/core_pattern')

            def kernel_read(path, *args, **kwargs):
                self.assertEqual(path, kernel_path)
                return route['current'] + '\n'

            def kernel_write(path, text, *args, **kwargs):
                self.assertEqual(path, kernel_path)
                self.assertEqual(text, state['previous_pattern'] + '\n')
                route['current'] = text.rstrip('\n')
                return len(text)

            output = io.StringIO()
            with (mock.patch.object(capture, 'context', return_value=(Path(temp), Path(temp) / 'arm', base)),
                  mock.patch.object(capture, 'state_load', return_value=state),
                  mock.patch.object(capture.sys, 'argv', ['helper', 'cleanup']),
                  mock.patch.object(Path, 'read_text', kernel_read), mock.patch.object(Path, 'write_text', kernel_write),
                  mock.patch.object(capture, 'process_identity') as observed,
                  mock.patch.object(os, 'pidfd_open') as opened,
                  mock.patch.object(capture.signal, 'pidfd_send_signal') as sent,
                  mock.patch.object(capture, 'run') as commands,
                  mock.patch.object(capture, 'mount_matches') as mounted,
                  mock.patch.object(os.path, 'ismount', return_value=True) as mount_probe,
                  mock.patch.object(Path, 'unlink') as deleted,
                  mock.patch.object(capture, 'write_json') as saved,
                  contextlib.redirect_stdout(output)):
                self.assertEqual(capture.main(), 1)
                result = json.loads(output.getvalue())
                self.assertEqual(result['state'], 'needs_review')
                self.assertFalse(result['resource_acceptance'])
                self.assertEqual(route['current'], state['previous_pattern'])
                for operation in (observed, opened, sent, commands, mounted, mount_probe, deleted, saved):
                    operation.assert_not_called()
            self.assertTrue(image.exists())
            self.assertTrue(core.exists())


if __name__ == '__main__':
    unittest.main()
