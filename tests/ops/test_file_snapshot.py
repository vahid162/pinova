"""Parser/mock tests; native root coverage runs only with explicit CI mode.

Required isolated native command (never against a live site's files):
  sudo --preserve-env=GITHUB_ACTIONS python3 -I -B tests/ops/test_file_snapshot.py --native-characterization
"""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import select
import subprocess
import sys
import tempfile
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


M = load("native_file_snapshot", ROOT / "tools/monitoring/file_snapshot.py")
L = load("snapshot_log_cursor", ROOT / "tools/monitoring/log_cursor.py")
S = load("snapshot_source_assurance", ROOT / "tools/monitoring/source_assurance.py")
PINS = {key: value * 64 for key, value in (("adapter", "a"), ("cursor", "b"), ("assurance", "c"), ("lsof", "d"))}
CAP = {"receipt_sha256": "e" * 64, "scope_identity": "f" * 64}
SOURCE = {"writer": "1" * 64, "rotation_inventory": "2" * 64, "configuration": "3" * 64}
NATIVE_FAILURE_REASONS = {
    "native_root_visibility_required", "native_threaded_controller", "native_source_shape", "native_output_shape",
    "native_access_flags_unknown", "native_nonappend_writer", "native_output_bound", "native_read_bound",
    "native_writer_churn", "native_scan_incomplete", "native_scan_timeout", "native_child_not_reaped",
    "native_code_pin_changed", "native_scope_changed", "native_scope_unavailable", "native_read_failed",
    "native_file_argument_error", "native_visibility_denied",
    "isolated_root_ci_required", "native_fixture_child_failed", "cross_uid_writer_not_observed",
    "nonappend_writer_not_rejected", "late_generation_not_preserved", "retired_writer_not_drained",
    "native_fixture_failed",
}
NATIVE_PHASES = {
    "not_started", "root_guard", "adapter_setup", "fixture_setup", "writer_ready", "cross_uid_scan",
    "nonappend_scan", "rotation_late_write", "late_scan", "writer_drain", "drained_scan",
}


def output(records):
    """records=(pid,fd,access,device,inode,fileflags,fdflags)."""
    result, prior = bytearray(), None
    for pid, fd, access, device, inode, flags, fdflags in records:
        if pid != prior:
            result.extend(f"p{pid}".encode() + b"\0\n"); prior = pid
        fields = (f"f{fd}", f"a{access}", f"G0x{flags:x};0x{fdflags:x}", f"D0x{device:x}", f"i{inode}")
        result.extend(b"\0".join(x.encode() for x in fields) + b"\0\n")
    return bytes(result)


class ParserTests(unittest.TestCase):
    def test_native_null_fields_and_append_flags_are_decoded_without_names(self):
        rows = M.parse_lsof(output([(3, 3, "w", 64768, 123, 0x88401, 0)]), {(64768, 123)})
        self.assertEqual((3, 3, (64768, 123), b"w", 0x88401, 0), rows[0])

    def test_read_and_append_readwrite_records_are_supported(self):
        rows = M.parse_lsof(output([(3, 3, "r", 1, 2, 0, 1), (3, 4, "u", 1, 2, 0x402, 0)]), {(1, 2)})
        self.assertEqual(2, len(rows))

    def test_nonappend_write_and_access_flag_disagreement_refuse(self):
        for access, flags in (("w", 1), ("u", 2), ("w", 0x402), ("r", 1)):
            with self.subTest(access=access, flags=flags), self.assertRaises(M.NativeFailure):
                M.parse_lsof(output([(3, 3, access, 1, 2, flags, 0)]), {(1, 2)})

    def test_unknown_secret_fields_missing_flags_and_truncation_refuse(self):
        healthy = output([(3, 3, "w", 1, 2, 0x401, 0)])
        bad = [healthy.replace(b"G0x401;0x0\0", b""), healthy[:-2], healthy + b"p4\0\n",
               healthy.replace(b"f3\0", b"fmem\0"), healthy.replace(b"aw\0", b"a?\0"),
               healthy.replace(b"D0x1\0", b"Dprivate-secret\0"),
               healthy.replace(b"G0x401;0x0\0", b"Gunknown\0"),
               healthy.replace(b"i2\0", b"n/private/customer-secret\0i2\0")]
        for payload in bad:
            with self.subTest(payload=payload), self.assertRaises(M.NativeFailure) as error:
                M.parse_lsof(payload, {(1, 2)})
            self.assertNotIn("private", str(error.exception))

    def test_duplicates_unselected_inodes_and_record_bound_refuse(self):
        for rows, ids, limit in [
            ([(3, 3, "r", 1, 2, 0, 0)] * 2, {(1, 2)}, 10),
            ([(3, 3, "r", 1, 9, 0, 0)], {(1, 2)}, 10),
            ([(3, 3, "r", 1, 2, 0, 0), (3, 4, "r", 1, 2, 0, 0)], {(1, 2)}, 1)]:
            with self.subTest(rows=rows), self.assertRaises(M.NativeFailure):
                M.parse_lsof(output(rows), ids, max_records=limit)


class SnapshotTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.path = Path(self.directory.name) / "source.log"; self.path.write_bytes(b"before\n")
        self.cursor = L.LogCursor(self.path)
        self.pin = mock.patch.object(M, "_pin", return_value=(1, 2, 0, 0, 0, 1, 1, 1)); self.pin.start()
        self.scope = mock.patch.object(M, "scope_identity", return_value=(CAP["scope_identity"], "4" * 64)); self.scope.start()
        self.root = mock.patch.object(M.os, "geteuid", return_value=0); self.root.start()
        self.ticks = mock.patch.object(M, "_start_ticks", return_value=100); self.ticks.start()
        self.thread = mock.patch.object(M.threading, "active_count", return_value=1); self.thread.start()
        self.snapshot = M.NativeFileSnapshot(pins=PINS, capability=CAP, attempts=1)
        self.assurance = S.FileSourceAssurance(source_protocol="append_rename_unlink_v1", pins=SOURCE,
            adapter_sha256=PINS["adapter"], producer_identity="5" * 64, boot_identity="4" * 64,
            max_witness_age_seconds=30)
        self.sources = {"one": self.cursor}; self.assurances = {"one": self.assurance}

    def tearDown(self):
        self.cursor.close(); self.thread.stop(); self.ticks.stop(); self.root.stop(); self.scope.stop(); self.pin.stop()
        self.directory.cleanup()

    def rows(self, writers=()):
        own = [(os.getpid(), state.fd, "r", identity[0], identity[1], 0, 0)
               for identity, state in self.cursor._files.items()]
        return output(sorted([*own, *writers]))

    def test_all_sources_are_selected_in_one_batch_twice_without_pid_filter(self):
        second_path = self.path.with_name("second.log"); second_path.write_bytes(b"before\n")
        second = L.LogCursor(second_path)
        try:
            sources = {"one": self.cursor, "two": second}; assurances = {"one": self.assurance, "two": self.assurance}
            def native(selectors):
                self.assertEqual(2, len(selectors))
                rows = [(os.getpid(), state.fd, "r", identity[0], identity[1], 0, 0)
                        for cursor in sources.values() for identity, state in cursor._files.items()]
                return output(sorted(rows))
            with mock.patch.object(self.snapshot, "_run_lsof", side_effect=native) as run:
                result = self.snapshot.capture(sources, assurances, 1)
            self.assertEqual("observed", result["state"])
            self.assertEqual(2, run.call_count)
            self.assertEqual({"one", "two"}, set(result["witnesses"]))
        finally:
            second.close()

    def test_actual_cursor_and_assurance_are_composed_without_coverage_promotion(self):
        with mock.patch.object(self.snapshot, "_run_lsof", side_effect=lambda _: self.rows()):
            result = self.snapshot.observe_sources(self.sources, self.assurances, 1)
        self.assertEqual("observed", result["state"])
        self.assertFalse(result["readers"]["one"]["coverage_complete"])
        self.assertFalse(result["assurance"]["one"]["coverage_complete"])
        self.assertEqual([], result["assurance"]["one"]["reasons"])

    def test_missing_own_selected_fd_in_either_scan_is_incomplete(self):
        identity = next(iter(self.cursor._files))
        writer = (500, 8, "w", *identity, 0x401, 0)
        for sequence in ([output([writer]), self.rows([writer])], [self.rows([writer]), output([writer])]):
            with self.subTest(sequence=sequence), mock.patch.object(self.snapshot, "_run_lsof", side_effect=sequence):
                self.assertEqual("failed", self.snapshot.capture(self.sources, self.assurances, 1)["state"])

    def test_pid_reuse_and_writer_churn_cannot_produce_a_witness(self):
        identity = next(iter(self.cursor._files)); row = (500, 8, "w", *identity, 0x401, 0)
        with mock.patch.object(self.snapshot, "_run_lsof", side_effect=lambda _: self.rows([row])), \
                mock.patch.object(M, "_start_ticks", side_effect=[100, 101]):
            result = self.snapshot.capture(self.sources, self.assurances, 1)
        self.assertEqual("native_writer_churn", result["reason"])

    def test_nonappend_writer_or_unprivileged_scope_fail_closed(self):
        identity = next(iter(self.cursor._files)); row = (500, 8, "w", *identity, 1, 0)
        with mock.patch.object(self.snapshot, "_run_lsof", side_effect=lambda _: self.rows([row])):
            result = self.snapshot.observe_sources(self.sources, self.assurances, 1)
        self.assertEqual("native_nonappend_writer", result["reason"])
        self.assertIn("native_read_failed", self.assurance.receipt()["reasons"])
        with mock.patch.object(M.os, "geteuid", return_value=1000):
            self.assertEqual("native_root_visibility_required", self.snapshot.capture(self.sources, self.assurances, 2)["reason"])

    def test_module_binary_scope_and_source_bounds_are_checked(self):
        with mock.patch.object(M, "_pin", side_effect=M.NativeFailure("native_code_pin_changed")):
            self.assertEqual("native_code_pin_changed", self.snapshot.capture(self.sources, self.assurances, 1)["reason"])
        with mock.patch.object(M, "scope_identity", return_value=("9" * 64, "4" * 64)):
            self.assertEqual("native_code_pin_changed", self.snapshot.capture(self.sources, self.assurances, 1)["reason"])
        self.snapshot.max_files = 0
        self.assertEqual("native_output_bound", self.snapshot.capture(self.sources, self.assurances, 1)["reason"])

    def test_rename_unlink_late_writer_uses_retained_descriptors(self):
        writer = self.path.open("ab"); identity = next(iter(self.cursor._files))
        try:
            rotated = self.path.with_suffix(".old"); self.path.rename(rotated); self.path.write_bytes(b"PHP Warning: current\n")
            writer.write(b"PHP Fatal error: old\n"); writer.flush(); rotated.unlink()
            row = (500, 8, "w", *identity, 0x401, 0)
            with mock.patch.object(self.snapshot, "_run_lsof", side_effect=lambda _: self.rows([row])):
                result = self.snapshot.capture(self.sources, self.assurances, 1)
            self.assertEqual("observed", result["state"])
            self.assertEqual(2, len(result["witnesses"]["one"]["retained"]))
            self.assertEqual(1, result["readers"]["one"]["totals"]["php_fatal"])
            self.assertEqual(list(identity), result["witnesses"]["one"]["writer_refs"][0]["identity"])
        finally:
            writer.close()

    def test_subprocess_is_argv_only_and_raw_stderr_never_escapes(self):
        payload = self.rows()
        class Child:
            returncode = 0
            def wait(self, timeout):
                return 0
        def launch(argv, **kwargs):
            self.assertEqual(["-nP", "-F0pfaDiG", "--"], argv[1:4])
            self.assertNotIn("shell", kwargs)
            self.assertNotIn("-p", argv)
            kwargs["stdout"].write(payload); kwargs["stdout"].flush()
            return Child()
        with mock.patch.object(M.subprocess, "Popen", side_effect=launch):
            self.assertEqual(payload, self.snapshot._run_lsof(["/proc/1/fd/3"]))
        def stderr_launch(argv, **kwargs):
            kwargs["stderr"].write(b"/private/customer-secret"); kwargs["stderr"].flush()
            return Child()
        with mock.patch.object(M.subprocess, "Popen", side_effect=stderr_launch), self.assertRaises(M.NativeFailure) as error:
            self.snapshot._run_lsof(["/proc/1/fd/3"])
        self.assertEqual("native_scan_incomplete", str(error.exception))

    def test_native_stderr_failures_are_finite_and_private_through_capture(self):
        payload = self.rows()
        class Child:
            returncode = 1
            def wait(self, timeout):
                return self.returncode
        for stderr, expected in (
                (b"lsof: status error on /private/customer-secret: No such file or directory\n", "native_file_argument_error"),
                (b"lsof: /private/customer-secret: Permission denied\n", "native_visibility_denied"),
                (b"lsof: opaque failure /private/customer-secret\n", "native_scan_incomplete")):
            with self.subTest(reason=expected):
                def launch(argv, **kwargs):
                    kwargs["stdout"].write(payload); kwargs["stdout"].flush()
                    kwargs["stderr"].write(stderr); kwargs["stderr"].flush()
                    return Child()
                with mock.patch.object(M.subprocess, "Popen", side_effect=launch):
                    result = self.snapshot.capture(self.sources, self.assurances, 1)
                    with self.assertRaises(M.NativeFailure) as error:
                        self.snapshot._run_lsof(["/proc/1/fd/3"])
                self.assertEqual("failed", result["state"])
                for private in ("/private/", "customer-secret"):
                    self.assertNotIn(private, str(error.exception))
                    self.assertNotIn(private, json.dumps(result))
                self.assertEqual(expected, result["reason"])

    def test_child_output_bound_and_owned_timeout_cleanup(self):
        class Child:
            returncode = 0
            calls = 0
            killed = False
            def wait(self, timeout):
                self.calls += 1
                if self.calls == 1:
                    raise subprocess.TimeoutExpired("owned-lsof", timeout)
                return 0
            def kill(self):
                self.killed = True
        child = Child()
        with mock.patch.object(M.subprocess, "Popen", return_value=child), self.assertRaises(M.NativeFailure) as error:
            self.snapshot._run_lsof(["/proc/1/fd/3"])
        self.assertEqual("native_scan_timeout", str(error.exception)); self.assertTrue(child.killed)
        def large(argv, **kwargs):
            kwargs["stdout"].write(b"x" * self.snapshot.max_output_bytes); kwargs["stdout"].flush()
            fake = Child(); fake.calls = 1; return fake
        with mock.patch.object(M.subprocess, "Popen", side_effect=large), self.assertRaises(M.NativeFailure) as error:
            self.snapshot._run_lsof(["/proc/1/fd/3"])
        self.assertEqual("native_output_bound", str(error.exception))

    def test_wait_interruption_kills_owned_child_and_reaps_without_masking_failure(self):
        class Stop(BaseException):
            pass
        for reap_error in (None, subprocess.TimeoutExpired("owned-lsof", 1)):
            with self.subTest(reap_error=type(reap_error).__name__):
                interruption = Stop("synthetic interruption")
                events = []
                class Child:
                    returncode = None
                    def poll(self):
                        return self.returncode
                    def wait(self, timeout):
                        events.append(("wait", timeout))
                        if len(events) == 1:
                            raise interruption
                        if reap_error is not None:
                            raise reap_error
                        self.returncode = -9
                        return -9
                    def kill(self):
                        events.append(("kill",))
                child = Child()
                with mock.patch.object(M.subprocess, "Popen", return_value=child), \
                        mock.patch.object(M.os, "kill") as kill_pid, \
                        mock.patch.object(M.os, "killpg") as kill_group, self.assertRaises(Stop) as error:
                    self.snapshot._run_lsof(["/proc/1/fd/3"])
                self.assertIs(interruption, error.exception)
                self.assertEqual([("wait", self.snapshot.timeout_seconds), ("kill",), ("wait", 1)], events)
                kill_pid.assert_not_called(); kill_group.assert_not_called()


def native_characterization(progress=None):
    """Explicit isolated-root fixture; ordinary unittest never invokes this."""
    progress = {} if progress is None else progress
    progress["phase"] = "root_guard"
    if os.environ.get("GITHUB_ACTIONS") != "true" or os.geteuid() != 0:
        raise M.NativeFailure("isolated_root_ci_required")
    progress["phase"] = "adapter_setup"
    digest = lambda path: hashlib.sha256(Path(path).read_bytes()).hexdigest()
    pins = {"adapter": digest(ROOT / "tools/monitoring/file_snapshot.py"),
            "cursor": digest(ROOT / "tools/monitoring/log_cursor.py"),
            "assurance": digest(ROOT / "tools/monitoring/source_assurance.py"), "lsof": digest("/usr/bin/lsof")}
    scope, boot = M.scope_identity()
    # This fixture creates its own controlled cross-UID witness, not production permission.
    capability = {"scope_identity": scope, "receipt_sha256": hashlib.sha256(b"isolated-cross-uid-fixture-v1").hexdigest()}
    snapshot = M.NativeFileSnapshot(pins=pins, capability=capability)
    progress["phase"] = "fixture_setup"
    with tempfile.TemporaryDirectory() as directory:
        os.chmod(directory, 0o755)
        path = Path(directory) / "synthetic.log"; path.write_bytes(b"before\n"); os.chmod(path, 0o666)
        code = """import os,sys
with open(sys.argv[1], 'ab', buffering=0) as writer:
 sys.stdout.buffer.write(b'r');sys.stdout.buffer.flush()
 while True:
  command=sys.stdin.buffer.read(1)
  if command != b'w':break
  writer.write(b'PHP Warning: synthetic late row\\n')
  sys.stdout.buffer.write(b'a');sys.stdout.buffer.flush()
"""
        def unprivileged():
            os.setgroups([]); os.setgid(65534); os.setuid(65534)
        child = subprocess.Popen([sys.executable, "-I", "-B", "-c", code, str(path)], stdin=subprocess.PIPE,
                                 stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, preexec_fn=unprivileged)
        cursor = None
        try:
            progress["phase"] = "writer_ready"
            if not select.select([child.stdout], [], [], 5)[0] or child.stdout.read(1) != b"r":
                raise M.NativeFailure("native_fixture_child_failed")
            cursor = L.LogCursor(path)
            assurance = S.FileSourceAssurance(source_protocol="append_rename_unlink_v1", pins=SOURCE,
                adapter_sha256=pins["adapter"], producer_identity="5" * 64, boot_identity=boot,
                max_witness_age_seconds=30)
            sources, assurances = {"synthetic": cursor}, {"synthetic": assurance}
            progress["phase"] = "cross_uid_scan"
            first = snapshot.observe_sources(sources, assurances, 1)
            if first["state"] != "observed":
                raise M.NativeFailure(first.get("reason", "native_read_failed"))
            if not any(x["process"][0] == child.pid for x in first["witnesses"]["synthetic"]["writer_refs"]):
                raise M.NativeFailure("cross_uid_writer_not_observed")
            progress["phase"] = "nonappend_scan"
            with path.open("r+b"):
                nonappend = snapshot.capture(sources, assurances, 2)
                if nonappend.get("reason") != "native_nonappend_writer":
                    if nonappend.get("state") == "failed":
                        raise M.NativeFailure(nonappend.get("reason", "native_read_failed"))
                    raise M.NativeFailure("nonappend_writer_not_rejected")
            progress["phase"] = "rotation_late_write"
            rotated = path.with_suffix(".old"); path.rename(rotated); path.write_bytes(b"current\n"); os.chmod(path, 0o666)
            child.stdin.write(b"w"); child.stdin.flush()
            if not select.select([child.stdout], [], [], 5)[0] or child.stdout.read(1) != b"a":
                raise M.NativeFailure("native_fixture_child_failed")
            rotated.unlink()
            progress["phase"] = "late_scan"
            late = snapshot.capture(sources, assurances, 3)
            if late["state"] != "observed":
                raise M.NativeFailure(late.get("reason", "native_read_failed"))
            if (late["readers"]["synthetic"]["totals"]["php_warning"] != 1
                    or len(late["witnesses"]["synthetic"]["retained"]) != 2):
                raise M.NativeFailure("late_generation_not_preserved")
            progress["phase"] = "writer_drain"
            child.stdin.close(); child.wait(timeout=5)
            progress["phase"] = "drained_scan"
            drained = snapshot.capture(sources, assurances, 4)
            if drained["state"] != "observed":
                raise M.NativeFailure(drained.get("reason", "native_read_failed"))
            if drained["witnesses"]["synthetic"]["writer_refs"]:
                raise M.NativeFailure("retired_writer_not_drained")
            return {"schema": "pinova.native-file-fixture.v1", "state": "passed", "source_history_24h_verified": False,
                    "checks": ["all-source-inode-selection", "cross-uid-append", "nonappend-rejection",
                               "rename", "unlink", "late-write", "retained-fd", "writer-drain"],
                    "adapter_sha256": pins["adapter"]}
        finally:
            if cursor is not None:
                cursor.close()
            if child.poll() is None:
                child.kill(); child.wait(timeout=5)


if __name__ == "__main__":
    if sys.argv[1:] == ["--native-characterization"]:
        progress = {"phase": "not_started"}
        try:
            print(json.dumps(native_characterization(progress), sort_keys=True))
        except BaseException as error:
            reason = error.args[0] if (isinstance(error, M.NativeFailure) and len(error.args) == 1
                and type(error.args[0]) is str and error.args[0] in NATIVE_FAILURE_REASONS) else "native_fixture_failed"
            phase = progress.get("phase")
            print(json.dumps({"schema": "pinova.native-file-fixture.v1", "state": "failed", "reason": reason,
                "phase": phase if phase in NATIVE_PHASES else "not_reported", "cleanup_state": "not_reported"}, sort_keys=True))
            raise SystemExit(1)
    else:
        unittest.main()
