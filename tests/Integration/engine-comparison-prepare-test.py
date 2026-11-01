"""GitHub-only preparation controls: mocked commands and one synthetic text patch."""
import hashlib
import importlib.util
import json
from pathlib import Path
import signal
import subprocess
import tempfile
import unittest
from unittest import mock

spec = importlib.util.spec_from_file_location('engine_prepare', Path(__file__).with_name('engine-comparison-prepare.py'))
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)


class PreparationControls(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='pinova-engine-preparation-control-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / 'private').mkdir()
        self.patch_root = mock.patch.object(helper, 'ROOT', self.root)
        self.patch_root.start()
        self.addCleanup(self.patch_root.stop)
        helper.COMMAND_OBSERVATIONS.clear()

    def command(self, status=0, timeout=False, launch=False):
        process = mock.Mock(pid=424242)
        process.wait.side_effect = [subprocess.TimeoutExpired('never_published', 1), -9] if timeout else None
        process.wait.return_value = status
        output = self.root / 'private/command.log'
        data = b'x' * 70000
        with output.open('w+') as log:
            log.write(data.decode())
            with mock.patch.object(helper.subprocess, 'Popen', side_effect=FileNotFoundError('never_published') if launch else None,
                                   return_value=process) as popen, mock.patch.object(helper.os, 'killpg') as kill:
                if status != 0 or timeout or launch:
                    with self.assertRaises(helper.PreparationCommandError):
                        helper.run('configure', ['never_executed'], stdout=log)
                else:
                    helper.run('configure', ['never_executed'], stdout=log)
                popen.assert_called_once()
                if timeout:
                    kill.assert_called_once_with(424242, signal.SIGKILL)
                else:
                    kill.assert_not_called()
        row = helper.COMMAND_OBSERVATIONS[-1]
        self.assertEqual(row['command'], 'configure')
        self.assertEqual(row['log_bytes'], len(data))
        self.assertEqual(row['log_tail_bytes'], 65536)
        self.assertEqual(row['log_tail_sha256'], hashlib.sha256(data[-65536:]).hexdigest())
        self.assertEqual(row['log_read_success'], True)
        public = json.dumps(row)
        self.assertNotIn('never_published', public)
        self.assertNotIn('never_executed', public)
        self.assertNotIn(str(self.root), public)
        return row

    def test_success_and_failed_exit_retain_only_finite_command_facts(self):
        self.assertEqual(self.command()['exit_code'], 0)
        self.assertEqual(self.command(status=7)['exit_code'], 7)

    def test_signal_is_separate_from_exit_code(self):
        row = self.command(status=-11)
        self.assertIsNone(row['exit_code'])
        self.assertEqual(row['signal'], 11)
        self.assertFalse(row['timeout'])

    def test_timeout_kills_owned_group_and_retains_timeout_fact(self):
        row = self.command(timeout=True)
        self.assertTrue(row['timeout'])
        self.assertEqual(row['signal'], 9)

    def test_launch_failure_keeps_command_without_argv_or_exception_message(self):
        row = self.command(launch=True)
        self.assertTrue(row['launch_failed'])
        self.assertIsNone(row['exit_code'])

    def test_exact_context_with_line_offset_creates_no_backup_file(self):
        tree = self.root / 'text-tree'
        tree.mkdir()
        sample = tree / 'sample.txt'
        sample.write_text('prefix\none\ntwo\nthree\n')
        patch = self.root / 'change.patch'
        patch.write_text('--- a/sample.txt\n+++ b/sample.txt\n@@ -1,3 +1,3 @@\n one\n-two\n+TWO\n three\n')
        self.assertIn('--no-backup-if-mismatch', helper.PATCH_COMMAND)
        self.assertIn('--fuzz=0', helper.PATCH_COMMAND)
        with (self.root / 'private/patch.log').open('w') as log:
            helper.run('patch', helper.PATCH_COMMAND + ['--input', str(patch)], cwd=tree, stdout=log)
        self.assertEqual(sample.read_text(), 'prefix\none\nTWO\nthree\n')
        self.assertEqual(sorted(path.name for path in tree.iterdir()), ['sample.txt'])
        self.assertEqual(helper.COMMAND_OBSERVATIONS[-1]['command'], 'patch')
        self.assertEqual(helper.COMMAND_OBSERVATIONS[-1]['exit_code'], 0)


if __name__ == '__main__':
    helper.require(helper.os.environ.get('GITHUB_ACTIONS') == 'true'
                   and helper.os.environ.get('RUNNER_ENVIRONMENT') == 'github-hosted', 'controls_require_disposable_github_runner')
    unittest.main()
