"""Unprivileged parser/ownership tests; never mount, dump or inspect live processes."""
import contextlib
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
def core_collection_fixture(*, malformed_environment=False, core_pid=123, core_signal=11, core_executable='/usr/sbin/php-fpm8.4'):
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
        core = base / 'cores/core.123.11.1793462978'
        core.write_bytes(elf(note(b'CORE', 1, status) + note(b'CORE', 0x46494C45, files)))
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
              mock.patch.object(capture.time, 'time', return_value=1793462980),
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
        data = {'executable': executable, 'core': str(core), 'pid': 123, 'signal': 11, 'build_id': identity}
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
            self.assertEqual(result['frames'][0], {'index': 0, 'symbol': 'zend_execute', 'module': 'php-fpm8.4', 'build_id': '0011223344556677'})
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
