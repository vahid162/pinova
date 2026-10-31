"""Bounded Linux log observations; import performs no I/O.

This is a reader, not a daemon or a site-specific command. Receipts contain
finite counters and reasons, never paths or raw lines. The caller owns target
authorization and scheduling. Retained descriptors preserve readable renamed
inodes, but inotify cannot distinguish every truncate/regrow from an append.
Consequently this generic reader NEVER certifies complete source history.
An independent loss-detecting producer/source protocol is a separate gate.
"""
import copy
import ctypes
import hashlib
import os
from pathlib import Path
import re
import stat
import struct
import sys


IN_MODIFY = 0x00000002
IN_ATTRIB = 0x00000004
IN_MOVED_FROM = 0x00000040
IN_MOVED_TO = 0x00000080
IN_CREATE = 0x00000100
IN_DELETE = 0x00000200
IN_DELETE_SELF = 0x00000400
IN_MOVE_SELF = 0x00000800
IN_UNMOUNT = 0x00002000
IN_Q_OVERFLOW = 0x00004000
IN_IGNORED = 0x00008000
IN_ONLYDIR = 0x01000000
IN_DONT_FOLLOW = 0x02000000
IN_ISDIR = 0x40000000
EVENT = struct.Struct("iIII")
PATTERNS = {
    "php_fatal": re.compile(rb"PHP Fatal error|Uncaught (?:Error|Exception|TypeError)", re.I),
    "php_warning": re.compile(rb"PHP Warning", re.I),
    "php_deprecated": re.compile(rb"PHP Deprecated", re.I),
    "pool_capacity": re.compile(rb"server reached pm\.max_children", re.I),
    "worker_timeout": re.compile(rb"execution timed out|execution time[^\n]{0,256}exceeded", re.I),
    "worker_signal": re.compile(rb"child \d+ exited on signal|SIGSEGV|SIGKILL", re.I),
}


def _bounded(value, maximum):
    return type(value) is int and 0 < value <= maximum


def _identity(info):
    return info.st_dev, info.st_ino


def _policy(info):
    # Link count changes normally when a retained, rotated inode is unlinked.
    return info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)


class _File:
    def __init__(self, fd, info, baseline):
        self.fd = fd
        self.identity = _identity(info)
        self.policy = _policy(info)
        self.position = info.st_size if baseline else 0
        self.anchor = os.pread(fd, min(256, self.position), max(0, self.position - 256))
        self.pending = b""
        self.discarding = bool(baseline and self.anchor and not self.anchor.endswith(b"\n"))


class LogCursor:
    """Read-only retained-inode cursor with permanently latched uncertainty.

    Construction installs the parent watch before opening the baseline file.
    Call observe() for finite incremental observations, then finalize() for a
    bounded last drain. close() only releases owned descriptors. No receipt has
    a flag that promotes an unverified file stream to loss-free history.
    """

    def __init__(self, path, *, max_read=2 * 1024 * 1024, max_open_files=8,
                 max_events=4096, max_line=65536, max_pending_moves=64,
                 max_final_passes=8):
        limits = ((max_read, 2 * 1024 * 1024), (max_open_files, 64),
                  (max_events, 16384), (max_line, 65536),
                  (max_pending_moves, 1024), (max_final_passes, 32))
        if any(not _bounded(value, maximum) for value, maximum in limits):
            raise ValueError("invalid_observer_bound")
        candidate = Path(path)
        if (not candidate.is_absolute() or str(candidate) != os.path.normpath(str(candidate))
                or candidate.parent.resolve(strict=True) != candidate.parent
                or not 0 < len(os.fsencode(candidate.name)) <= 255):
            raise ValueError("invalid_log_path")
        if sys.platform != "linux":
            raise RuntimeError("linux_log_watcher_required")
        self.max_read, self.max_open_files, self.max_events = max_read, max_open_files, max_events
        self.max_line, self.max_pending_moves = max_line, max_pending_moves
        self.max_final_passes = max_final_passes
        self._name = os.fsencode(candidate.name)
        self._parent = candidate.parent
        self._parent_fd = self._inotify_fd = -1
        self._parent_watch = -1
        self._parent_valid = True
        self._files = {}
        self._generations = {0: None}
        self._names = {self._name: 0}
        self._moves = {}
        self._file_watches = {}
        self._retired_watches = set()
        self._gaps = set()
        self._source_policy = None
        self._totals = {key: 0 for key in PATTERNS}
        self._closed = False
        self._final_receipt = None
        self._current_available = False
        self._read_backlog = False
        try:
            self._parent_fd = os.open(self._parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
            parent_info = os.fstat(self._parent_fd)
            self._parent_identity = _identity(parent_info)
            self._parent_policy = _policy(parent_info)
            self._libc = ctypes.CDLL(None, use_errno=True)
            self._libc.inotify_init1.argtypes = [ctypes.c_int]
            self._libc.inotify_init1.restype = ctypes.c_int
            self._libc.inotify_add_watch.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_uint32]
            self._libc.inotify_add_watch.restype = ctypes.c_int
            self._inotify_fd = self._libc.inotify_init1(os.O_NONBLOCK | os.O_CLOEXEC)
            if self._inotify_fd < 0:
                raise OSError()
            mask = (IN_MODIFY | IN_ATTRIB | IN_MOVED_FROM | IN_MOVED_TO | IN_CREATE
                    | IN_DELETE | IN_DELETE_SELF | IN_MOVE_SELF | IN_UNMOUNT)
            self._parent_watch = self._libc.inotify_add_watch(
                self._inotify_fd, os.fsencode(self._parent), mask | IN_ONLYDIR | IN_DONT_FOLLOW)
            if (self._parent_watch < 0
                    or _identity(os.stat(self._parent, follow_symlinks=False)) != self._parent_identity):
                raise OSError()
            self._open_generation(0, self._name, baseline=True)
            if self._events():
                self._gap("baseline_race")
        except (OSError, ValueError):
            self.close()
            raise RuntimeError("log_watcher_unavailable") from None

    def _gap(self, reason):
        self._gaps.add(reason)

    def _new_generation(self):
        # Logical generation bookkeeping is bounded as well as actual FDs.
        if len(self._generations) >= self.max_open_files:
            self._gap("file_descriptor_bound")
            return None
        generation = len(self._generations)
        self._generations[generation] = None
        return generation

    def _open_generation(self, generation, name, baseline=False):
        fd = -1
        try:
            expected = os.stat(name, dir_fd=self._parent_fd, follow_symlinks=False)
            fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC,
                         dir_fd=self._parent_fd)
            info = os.fstat(fd)
            if not stat.S_ISREG(info.st_mode):
                raise OSError()
            identity = _identity(info)
            matches = stat.S_ISREG(expected.st_mode) and identity == _identity(expected)
            if not matches:
                # Keep the actual inode readable without assigning a replacement
                # to an earlier event generation. A later poll can recover both.
                self._gap("generation_identity_changed")
            if identity in self._files:
                if matches:
                    self._generations[generation] = identity
                return
            if len(self._files) >= self.max_open_files:
                self._gap("file_descriptor_bound")
                return
            state = _File(fd, info, baseline)
            self._files[identity] = state
            if matches:
                self._generations[generation] = identity
            fd = -1  # The state owns this descriptor until close/finalize.
            if self._source_policy is None:
                self._source_policy = state.policy
            elif state.policy != self._source_policy:
                self._gap("log_owner_or_mode_changed")
            if state.discarding:
                self._gap("baseline_partial_line")
            mask = IN_MODIFY | IN_ATTRIB | IN_MOVE_SELF | IN_DELETE_SELF | IN_UNMOUNT
            wd = self._libc.inotify_add_watch(
                self._inotify_fd, os.fsencode(f"/proc/self/fd/{state.fd}"), mask)
            if wd < 0:
                self._gap("file_watch_unavailable")
            elif wd in self._retired_watches or wd in self._file_watches:
                self._gap("watch_identity_ambiguous")
            else:
                self._file_watches[wd] = identity
        except OSError:
            self._gap("source_path_unavailable" if name == self._name else "generation_unavailable")
        finally:
            if fd >= 0:
                os.close(fd)

    def _handle_event(self, wd, mask, cookie, name):
        if mask & IN_Q_OVERFLOW:
            self._gap("event_queue_overflow")
            return
        if wd == self._parent_watch:
            if mask & (IN_IGNORED | IN_UNMOUNT | IN_DELETE_SELF | IN_MOVE_SELF):
                self._parent_valid = False
                self._gap("parent_watch_lost")
            if mask & IN_ISDIR or not name:
                return
            if mask & IN_MOVED_FROM:
                generation = self._names.pop(name, None)
                if generation is not None:
                    if not cookie or len(self._moves) >= self.max_pending_moves:
                        self._gap("rename_bound")
                    else:
                        self._moves[cookie] = generation
                return
            if mask & IN_MOVED_TO:
                generation = self._moves.pop(cookie, None)
                if generation is None and name == self._name:
                    generation = self._new_generation()
                    self._gap("external_source_generation")
                if generation is not None:
                    self._names[name] = generation
                return
            if mask & IN_CREATE and name == self._name:
                generation = self._new_generation()
                if generation is not None:
                    self._names[name] = generation
            if mask & IN_DELETE:
                self._names.pop(name, None)
        elif wd in self._file_watches:
            if mask & (IN_IGNORED | IN_UNMOUNT):
                self._file_watches.pop(wd, None)
                self._retired_watches.add(wd)
                self._gap("file_watch_lost")
        else:
            self._gap("unknown_watch")

    def _events(self):
        processed = 0
        while True:
            try:
                data = os.read(self._inotify_fd, 65536)
            except BlockingIOError:
                return processed
            except OSError:
                self._gap("event_read_failed")
                return processed
            if not data:
                self._gap("event_read_failed")
                return processed
            position = 0
            while position < len(data):
                if processed >= self.max_events:
                    self._gap("event_bound")
                    return processed
                if len(data) - position < EVENT.size:
                    self._gap("invalid_event")
                    return processed
                wd, mask, cookie, length = EVENT.unpack_from(data, position)
                position += EVENT.size
                if length > 256 or length > len(data) - position:
                    self._gap("invalid_event")
                    return processed
                raw_name = data[position:position + length]
                position += length
                if length and b"\0" not in raw_name:
                    self._gap("invalid_event")
                    return processed
                name = raw_name.split(b"\0", 1)[0]
                processed += 1
                self._handle_event(wd, mask, cookie, name)

    def _reconcile(self):
        self._current_available = False
        try:
            if _policy(os.fstat(self._parent_fd)) != self._parent_policy:
                self._gap("parent_owner_or_mode_changed")
            current_parent = os.stat(self._parent, follow_symlinks=False)
            if (not stat.S_ISDIR(current_parent.st_mode)
                    or _identity(current_parent) != self._parent_identity):
                self._parent_valid = False
                self._gap("parent_identity_changed")
        except OSError:
            self._parent_valid = False
            self._gap("parent_unavailable")
        if not self._parent_valid:
            return
        for generation, identity in self._generations.items():
            names = [name for name, owner in self._names.items() if owner == generation]
            if identity is not None:
                for name in names:
                    try:
                        info = os.stat(name, dir_fd=self._parent_fd, follow_symlinks=False)
                        if not stat.S_ISREG(info.st_mode) or _identity(info) != identity:
                            self._gap("generation_identity_changed")
                    except OSError:
                        self._gap("generation_unavailable")
                continue
            if names:
                self._open_generation(generation, names[0])
            elif generation not in self._moves.values():
                self._gap("generation_unavailable")
        try:
            info = os.stat(self._name, dir_fd=self._parent_fd, follow_symlinks=False)
            generation = self._names.get(self._name)
            expected = self._generations.get(generation)
            self._current_available = stat.S_ISREG(info.st_mode) and _identity(info) == expected
            if not self._current_available:
                self._gap("source_path_unavailable")
        except OSError:
            self._gap("source_path_unavailable")

    def _lines(self, state, data, counts):
        parts = (state.pending + data).split(b"\n")
        state.pending = b""
        for line in parts[:-1]:
            if state.discarding:
                state.discarding = False
            elif len(line) > self.max_line:
                self._gap("line_bound")
            else:
                for key, pattern in PATTERNS.items():
                    count = len(pattern.findall(line))
                    counts[key] += count
                    self._totals[key] += count
        if state.discarding:
            return
        state.pending = parts[-1]
        if len(state.pending) > self.max_line:
            self._gap("line_bound")
            state.pending = b""
            state.discarding = True

    def _read_files(self, counts):
        remaining = self.max_read
        payload_bytes = 0
        self._read_backlog = False
        for state in self._files.values():
            try:
                before = os.fstat(state.fd)
                if _policy(before) != state.policy:
                    self._gap("log_owner_or_mode_changed")
                reset = before.st_size < state.position
                if reset:
                    self._gap("truncated")
                elif state.anchor:
                    # Reserve payload progress even under a deliberately tiny
                    # I/O bound. A bounded suffix remains a damage detector,
                    # never a proof that old bytes were not overwritten.
                    verify_bytes = min(len(state.anchor), remaining // 2)
                    if not verify_bytes:
                        self._gap("read_bound")
                    anchor = os.pread(state.fd, verify_bytes, state.position - verify_bytes)
                    remaining -= len(anchor)
                    if verify_bytes and hashlib.sha256(anchor).digest() != hashlib.sha256(state.anchor[-verify_bytes:]).digest():
                        self._gap("rewritten_or_truncated")
                        reset = True
                if reset:
                    state.position = 0
                    state.anchor = state.pending = b""
                    state.discarding = False
                while remaining:
                    data = os.pread(state.fd, min(65536, remaining), state.position)
                    if not data:
                        break
                    remaining -= len(data)
                    payload_bytes += len(data)
                    state.position += len(data)
                    state.anchor = (state.anchor + data)[-256:]
                    self._lines(state, data, counts)
                after = os.fstat(state.fd)
                if after.st_size < state.position:
                    self._gap("changed_during_read")
                elif after.st_size > state.position:
                    self._gap("read_bound")
                    self._read_backlog = True
            except OSError:
                self._gap("file_read_failed")
                self._read_backlog = True
        return payload_bytes, self.max_read - remaining

    def _receipt(self, counts, new_bytes, io_bytes, events, *, finalized=False, drain_observed=None):
        gaps = sorted(self._gaps)
        return {
            "available": self._parent_valid and self._current_available,
            "current_path_available": self._current_available,
            "history_assurance": "incomplete" if gaps else "unknown",
            "coverage_complete": False,
            "needs_review": True,
            "coverage_reasons": ["source_history_unverified", *gaps],
            "detected_gaps": gaps,
            "counts": dict(counts),
            "totals": dict(self._totals),
            "new_bytes": new_bytes,
            "io_bytes": io_bytes,
            "events": events,
            "retained_files": len(self._files),
            "pending_line": any(state.pending or state.discarding for state in self._files.values()),
            "read_backlog": self._read_backlog,
            "finalized": finalized,
            "drain_observed": drain_observed,
        }

    def observe(self):
        if self._final_receipt is not None:
            return copy.deepcopy(self._final_receipt)
        counts = {key: 0 for key in PATTERNS}
        if self._closed:
            self._gap("observer_closed")
            return self._receipt(counts, 0, 0, 0)
        events = self._events()
        self._reconcile()
        new_bytes, io_bytes = self._read_files(counts)
        return self._receipt(counts, new_bytes, io_bytes, events)

    def finalize(self):
        if self._final_receipt is not None:
            return copy.deepcopy(self._final_receipt)
        counts = {key: 0 for key in PATTERNS}
        new_bytes = io_bytes = event_count = 0
        drained = False
        if self._closed:
            self._gap("observer_closed")
            self._final_receipt = self._receipt(counts, 0, 0, 0, finalized=True, drain_observed=False)
            return copy.deepcopy(self._final_receipt)
        for _ in range(self.max_final_passes):
            result = self.observe()
            for key in counts:
                counts[key] += result["counts"][key]
            new_bytes += result["new_bytes"]
            io_bytes += result["io_bytes"]
            event_count += result["events"]
            if result["new_bytes"] == 0 and result["events"] == 0 and not result["read_backlog"]:
                drained = not any(identity is None for identity in self._generations.values())
                break
        if not drained:
            self._gap("final_drain_bound")
        if any(state.pending or state.discarding for state in self._files.values()):
            self._gap("final_partial_line")
        self._final_receipt = self._receipt(counts, new_bytes, io_bytes, event_count,
                                            finalized=True, drain_observed=drained)
        self.close()
        return copy.deepcopy(self._final_receipt)

    def close(self):
        if self._closed:
            return
        self._closed = True
        for fd in [*(state.fd for state in self._files.values()), self._inotify_fd, self._parent_fd]:
            if fd >= 0:
                try:
                    os.close(fd)
                except OSError:
                    pass
        for state in self._files.values():
            state.pending = state.anchor = b""
            state.discarding = False

    def __enter__(self):
        return self

    def __exit__(self, *_):
        self.close()
