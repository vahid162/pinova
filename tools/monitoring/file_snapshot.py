"""Bounded native holder witnesses for existing LogCursor/FileSourceAssurance.

No scan runs on import. The private root controller first verifies its global
FD visibility on the actual host and freezes that capability receipt, scope
identity, module/binary pins and source writer/rotation contracts. A protocol
name or a caller boolean is not that verification. All selected retained inodes
are queried together, without a PID subset. Raw lsof output is never returned.

capture() polls the existing cursors before and after the native scans. Returned
reader receipts are genuine final polls: use their cumulative ``totals`` to
include bytes read by inner polls, not only their last-poll ``counts``. The
controller may poll cursors more frequently between native batches. Keep all
cursor descriptors open until assurance finalization completes.

Root-only native characterization belongs in an isolated required CI job. An
unprivileged parser/mock test does not establish global production visibility.
"""
import hashlib
import inspect
import json
import math
import os
from pathlib import Path
import re
import resource
import stat
import subprocess
import sys
import tempfile
import threading
import time


class NativeFailure(Exception):
    """Only fixed reasons; raw process output and exception text stay private."""


def _number(raw, base=10, minimum=0, maximum=2**64 - 1):
    pattern = rb"[0-9]{1,20}" if base == 10 else rb"0x[0-9a-fA-F]{1,16}"
    if not isinstance(raw, bytes) or re.fullmatch(pattern, raw) is None:
        raise NativeFailure("native_output_shape")
    value = int(raw, base)
    if not minimum <= value <= maximum:
        raise NativeFailure("native_output_shape")
    return value


def parse_lsof(data, identities, *, max_records=4096):
    """Parse only -F0pfaDiG numeric-FD records, rejecting incomplete frames."""
    if isinstance(data, bytes) and len(data) > 2097152:
        raise NativeFailure("native_output_bound")
    if (not isinstance(data, bytes) or not data or not data.endswith(b"\0\n")
            or type(max_records) is not int or not 1 <= max_records <= 16384):
        raise NativeFailure("native_output_shape")
    output, current, pid, pids = [], None, None, set()

    def finish():
        if current is None:
            return
        if set(current) != {b"f", b"a", b"D", b"i", b"G"}:
            raise NativeFailure("native_output_shape")
        fd = _number(current[b"f"], maximum=2**31 - 1)
        identity = (_number(current[b"D"], 16, 1), _number(current[b"i"], minimum=1))
        access = current[b"a"]
        flag_parts = current[b"G"].split(b";")
        if identity not in identities or access not in (b"r", b"w", b"u") or len(flag_parts) != 2:
            raise NativeFailure("native_output_shape")
        flags, descriptor_flags = (_number(x, 16) for x in flag_parts)
        if flags & os.O_ACCMODE != {b"r": os.O_RDONLY, b"w": os.O_WRONLY, b"u": os.O_RDWR}[access]:
            raise NativeFailure("native_access_flags_unknown")
        if access in (b"w", b"u") and not flags & os.O_APPEND:
            raise NativeFailure("native_nonappend_writer")
        output.append((pid, fd, identity, access, flags, descriptor_flags))
        if len(output) > max_records:
            raise NativeFailure("native_output_bound")

    for token in data.split(b"\0")[:-1]:
        if token.startswith(b"\n"):
            token = token[1:]
        if not token or b"\n" in token or token[:1] not in (b"p", b"f", b"a", b"D", b"i", b"G"):
            raise NativeFailure("native_output_shape")
        key, value = token[:1], token[1:]
        if key == b"p":
            finish()
            if pid is not None and current is None:
                raise NativeFailure("native_output_shape")
            pid = _number(value, minimum=1, maximum=2**31 - 1)
            if pid in pids:
                raise NativeFailure("native_output_shape")
            pids.add(pid); current = None
        elif key == b"f":
            finish()
            if pid is None:
                raise NativeFailure("native_output_shape")
            current = {key: value}
        else:
            if current is None or key in current:
                raise NativeFailure("native_output_shape")
            current[key] = value
    if pid is None or current is None:
        raise NativeFailure("native_output_shape")
    finish()
    if not output or len({(x[0], x[1]) for x in output}) != len(output):
        raise NativeFailure("native_output_shape")
    return tuple(sorted(output))


def _read(path, limit):
    with Path(path).open("rb") as stream:
        value = stream.read(limit + 1)
    if len(value) > limit:
        raise NativeFailure("native_read_bound")
    return value


def _identity(info):
    return info.st_dev, info.st_ino


def scope_identity():
    """Fingerprint only boot/namespaces/proc mount; never return raw boot UUID."""
    boot = _read("/proc/sys/kernel/random/boot_id", 128)
    if re.fullmatch(rb"[0-9a-f-]{36}\n?", boot) is None:
        raise NativeFailure("native_scope_unavailable")
    details = {"boot": hashlib.sha256(boot).hexdigest()}
    for key, path in (("pid", "/proc/self/ns/pid"), ("user", "/proc/self/ns/user"), ("proc", "/proc")):
        details[key] = _identity(os.stat(path))
    encoded = json.dumps(details, sort_keys=True, separators=(",", ":")).encode()
    return hashlib.sha256(encoded).hexdigest(), details["boot"]


def _start_ticks(pid):
    raw = _read(f"/proc/{pid}/stat", 4096)
    if not raw.startswith(str(pid).encode() + b" (") or b") " not in raw:
        raise NativeFailure("native_writer_churn")
    fields = raw[raw.rfind(b") ") + 2:].split()
    if len(fields) < 20:
        raise NativeFailure("native_writer_churn")
    return _number(fields[19], minimum=1)


def _pin(path, expected, *, executable=False):
    candidate = Path(path)
    before = candidate.lstat()
    if (not candidate.is_absolute() or candidate.is_symlink() or not stat.S_ISREG(before.st_mode)
            or before.st_nlink != 1 or before.st_size > 16 * 1024 * 1024
            or (executable and (before.st_uid != 0 or before.st_mode & 0o022
                                or not before.st_mode & 0o111))):
        raise NativeFailure("native_code_pin_changed")
    value = hashlib.sha256(_read(candidate, 16 * 1024 * 1024)).hexdigest()
    after = candidate.lstat()
    identity = lambda x: (x.st_dev, x.st_ino, x.st_uid, x.st_gid, x.st_mode,
                          x.st_size, x.st_mtime_ns, x.st_ctime_ns)
    if value != expected or identity(before) != identity(after):
        raise NativeFailure("native_code_pin_changed")
    return identity(after)


class NativeFileSnapshot:
    """One bounded all-source native batch; owns no cursor or service lifecycle.

    pins keys: adapter/cursor/assurance/lsof SHA256. capability keys:
    receipt_sha256 and scope_identity, frozen by independent root visibility
    characterization on this boot/namespaces. This class checks their binding,
    not the content of an arbitrary caller-created receipt. The production
    controller must retain and verify that reviewed capability artifact.
    """

    def __init__(self, *, pins, capability, lsof_binary="/usr/bin/lsof",
                 max_sources=16, max_files=64, max_records=4096,
                 max_output_bytes=1048576, timeout_seconds=5, attempts=2):
        hex_digest = lambda x: isinstance(x, str) and re.fullmatch(r"[0-9a-f]{64}", x) is not None
        if (not isinstance(pins, dict) or set(pins) != {"adapter", "cursor", "assurance", "lsof"}
                or not all(hex_digest(x) for x in pins.values()) or not isinstance(capability, dict)
                or set(capability) != {"receipt_sha256", "scope_identity"}
                or not all(hex_digest(x) for x in capability.values())
                or any(type(x) is not int or not 1 <= x <= cap for x, cap in
                       ((max_sources, 32), (max_files, 64), (max_records, 16384), (attempts, 3)))
                or type(max_output_bytes) is not int or not 1024 <= max_output_bytes <= 2097152
                or type(timeout_seconds) not in (int, float) or not math.isfinite(timeout_seconds)
                or not 0 < timeout_seconds <= 10 or sys.platform != "linux"):
            raise ValueError("invalid_native_file_configuration")
        self.pins, self.capability = dict(pins), dict(capability)
        self.binary = Path(lsof_binary)
        self.max_sources, self.max_files, self.max_records = max_sources, max_files, max_records
        self.max_output_bytes, self.timeout_seconds, self.attempts = max_output_bytes, timeout_seconds, attempts
        self._own_identity = _pin(Path(__file__).resolve(), pins["adapter"])
        self._binary_identity = _pin(self.binary, pins["lsof"], executable=True)
        actual_scope, self.boot_identity = scope_identity()
        if actual_scope != capability["scope_identity"]:
            raise NativeFailure("native_scope_changed")

    def _run_lsof(self, selectors):
        limit = self.max_output_bytes
        def limits():
            resource.setrlimit(resource.RLIMIT_FSIZE, (limit, limit))
        with tempfile.TemporaryFile() as stdout, tempfile.TemporaryFile() as stderr:
            process = subprocess.Popen([str(self.binary), "-nP", "-F0pfaDiG", "--", *selectors],
                stdin=subprocess.DEVNULL, stdout=stdout, stderr=stderr, close_fds=True,
                env={"LC_ALL": "C", "PATH": "/usr/bin:/bin"}, preexec_fn=limits)
            try:
                process.wait(timeout=self.timeout_seconds)
            except subprocess.TimeoutExpired:
                process.kill()
                try:
                    process.wait(timeout=1)
                except subprocess.TimeoutExpired:
                    raise NativeFailure("native_child_not_reaped") from None
                raise NativeFailure("native_scan_timeout") from None
            out_size, err_size = os.fstat(stdout.fileno()).st_size, os.fstat(stderr.fileno()).st_size
            if out_size >= limit or err_size >= limit:
                raise NativeFailure("native_output_bound")
            if process.returncode != 0 or err_size:
                raise NativeFailure("native_scan_incomplete")
            stdout.seek(0)
            return stdout.read(limit + 1)

    def _cursors(self, cursors, assurances):
        if (not isinstance(cursors, dict) or not 1 <= len(cursors) <= self.max_sources
                or set(cursors) != set(assurances)
                or not all(isinstance(k, str) and re.fullmatch(r"[a-z][a-z0-9_]{0,31}", k) for k in cursors)):
            raise NativeFailure("native_source_shape")
        targets, states = {}, {}
        for key, cursor in cursors.items():
            assurance = assurances[key]
            if (type(cursor).__name__ != "LogCursor" or type(assurance).__name__ != "FileSourceAssurance"
                    or _pin(Path(inspect.getfile(type(cursor))).resolve(), self.pins["cursor"]) is None
                    or _pin(Path(inspect.getfile(type(assurance))).resolve(), self.pins["assurance"]) is None
                    or assurance.adapter_sha256 != self.pins["adapter"]
                    or assurance.boot_identity != self.boot_identity or cursor._closed):
                raise NativeFailure("native_source_shape")
            current = os.stat(cursor._name, dir_fd=cursor._parent_fd, follow_symlinks=False)
            retained = []
            for identity, state in cursor._files.items():
                info = os.fstat(state.fd)
                if not stat.S_ISREG(info.st_mode) or _identity(info) != identity:
                    raise NativeFailure("native_writer_churn")
                retained.append({"identity": list(identity), "position": state.position, "size": info.st_size})
                targets[(os.getpid(), state.fd)] = identity
            if not retained or _identity(current) not in {tuple(x["identity"]) for x in retained}:
                raise NativeFailure("native_writer_churn")
            states[key] = {"current_identity": list(_identity(current)), "retained": retained}
        if len(targets) > self.max_files:
            raise NativeFailure("native_output_bound")
        return targets, states

    def capture(self, cursors, assurances, sequence):
        """Return actual reader receipts plus witnesses, or one finite failure."""
        started = time.monotonic_ns()
        readers = {}
        try:
            if os.geteuid() != 0:
                raise NativeFailure("native_root_visibility_required")
            if threading.active_count() != 1:
                raise NativeFailure("native_threaded_controller")
            if type(sequence) is not int or not 0 <= sequence < 2**64:
                raise NativeFailure("native_source_shape")
            if (_pin(Path(__file__).resolve(), self.pins["adapter"]) != self._own_identity
                    or _pin(self.binary, self.pins["lsof"], executable=True) != self._binary_identity
                    or scope_identity()[0] != self.capability["scope_identity"]):
                raise NativeFailure("native_code_pin_changed")
            for attempt in range(self.attempts):
                readers = {key: cursor.observe() for key, cursor in cursors.items()}
                targets, initial = self._cursors(cursors, assurances)
                selectors = [f"/proc/{pid}/fd/{fd}" for pid, fd in sorted(targets)]
                identities = set(targets.values())
                first = parse_lsof(self._run_lsof(selectors), identities, max_records=self.max_records)
                writers = tuple(x for x in first if x[3] in (b"w", b"u"))
                if len(writers) > 1024 or len({x[0] for x in writers}) > 256:
                    raise NativeFailure("native_output_bound")
                for (pid, fd), identity in targets.items():
                    if not any(x[0] == pid and x[1] == fd and x[2] == identity and x[3] == b"r" for x in first):
                        raise NativeFailure("native_scan_incomplete")
                try:
                    before = {pid: _start_ticks(pid) for pid in {x[0] for x in writers}}
                    second = parse_lsof(self._run_lsof(selectors), identities, max_records=self.max_records)
                    for (pid, fd), identity in targets.items():
                        if not any(x[0] == pid and x[1] == fd and x[2] == identity and x[3] == b"r" for x in second):
                            raise NativeFailure("native_scan_incomplete")
                    second_writers = tuple(x for x in second if x[3] in (b"w", b"u"))
                    if len(second_writers) > 1024 or len({x[0] for x in second_writers}) > 256:
                        raise NativeFailure("native_output_bound")
                    after = {pid: _start_ticks(pid) for pid in {x[0] for x in second_writers}}
                    readers = {key: cursor.observe() for key, cursor in cursors.items()}
                    final_targets, final = self._cursors(cursors, assurances)
                    if writers != second_writers or before != after or targets != final_targets:
                        raise NativeFailure("native_writer_churn")
                    if any(initial[k]["current_identity"] != final[k]["current_identity"] for k in initial):
                        raise NativeFailure("native_writer_churn")
                    break
                except (FileNotFoundError, ProcessLookupError):
                    if attempt + 1 == self.attempts:
                        raise NativeFailure("native_writer_churn") from None
                except NativeFailure as error:
                    if error.args != ("native_writer_churn",) or attempt + 1 == self.attempts:
                        raise
            else:
                raise NativeFailure("native_writer_churn")
            if (_pin(Path(__file__).resolve(), self.pins["adapter"]) != self._own_identity
                    or _pin(self.binary, self.pins["lsof"], executable=True) != self._binary_identity
                    or scope_identity()[0] != self.capability["scope_identity"]):
                raise NativeFailure("native_code_pin_changed")
            completed, wall = time.monotonic_ns(), time.time_ns()
            witnesses = {}
            for key, assurance in assurances.items():
                ids = {tuple(x["identity"]) for x in final[key]["retained"]}
                refs = [{"identity": list(x[2]), "process": [x[0], after[x[0]]]}
                        for x in second_writers if x[2] in ids]
                registry = sorted({tuple(x["process"]) for x in refs})
                witnesses[key] = {"protocol": "native_file_fd_scan_v1", "adapter_sha256": self.pins["adapter"],
                    "source_protocol": assurance.source_protocol, "pins": dict(assurance.pins),
                    "producer_identity": assurance.producer_identity, "boot_identity": self.boot_identity,
                    "sequence": sequence, "read_started_ns": started, "read_completed_ns": completed,
                    "wall_epoch_ns": wall, **final[key],
                    "registry_before": [list(x) for x in registry], "registry_after": [list(x) for x in registry],
                    "writer_refs": refs}
            return {"state": "observed", "readers": readers, "witnesses": witnesses,
                    "capability_receipt_sha256": self.capability["receipt_sha256"]}
        except (NativeFailure, OSError, ValueError, TypeError, KeyError, AttributeError):
            reason = sys.exc_info()[1]
            allowed = {"native_root_visibility_required", "native_threaded_controller", "native_source_shape", "native_output_shape",
                "native_access_flags_unknown", "native_nonappend_writer", "native_output_bound",
                "native_read_bound", "native_writer_churn", "native_scan_incomplete", "native_scan_timeout",
                "native_child_not_reaped", "native_code_pin_changed", "native_scope_changed"}
            code = reason.args[0] if (isinstance(reason, NativeFailure) and reason.args
                                     and isinstance(reason.args[0], str) and reason.args[0] in allowed) else "native_read_failed"
            return {"state": "failed", "reason": code, "readers": readers}

    def observe_sources(self, cursors, assurances, sequence, *, finalize=False):
        """Convenience composition; failures latch in every affected assurance."""
        result = self.capture(cursors, assurances, sequence)
        if result["state"] != "observed":
            for assurance in assurances.values():
                assurance.fail("native_read_failed")
            return result
        result["assurance"] = {}
        for key, assurance in assurances.items():
            method = assurance.finalize if finalize else assurance.observe
            result["assurance"][key] = method(result["readers"][key], result["witnesses"][key])
        return result
