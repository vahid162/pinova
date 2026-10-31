#!/usr/bin/env python3
"""Disposable hosted diagnostic only. Never publishes cores or debugger text."""
import hashlib
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import re
import signal
import stat
import struct
import subprocess
import sys
import time

BUDGET = 1024 * 1024 * 1024
CORE_LIMIT = 512 * 1024 * 1024
FRAME_LIMIT = 64
ARMS = ("a1", "b1", "b2", "a2", "a3", "b3")


class CaptureError(Exception):
    def __init__(self, code):
        self.code = code


def require(value, code="ownership_unavailable"):
    if not value:
        raise CaptureError(code)


def read_bytes(path, limit):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        info = os.fstat(fd)
        require(stat.S_ISREG(info.st_mode) and info.st_size <= limit, "input_bound")
        data = os.read(fd, limit + 1)
        require(len(data) <= limit, "input_bound")
        return data
    finally:
        os.close(fd)


def write_json(path, value):
    data = (json.dumps(value, separators=(",", ":")) + "\n").encode()
    require(len(data) <= 131072, "receipt_bound")
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
    try:
        os.write(fd, data)
    finally:
        os.close(fd)


def run(args, timeout=30):
    # No raw stdout/stderr, error messages or command arguments escape this helper.
    try:
        result = subprocess.run(args, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                                stderr=subprocess.DEVNULL, timeout=timeout, check=False)
    except subprocess.TimeoutExpired:
        raise CaptureError("tool_timeout") from None
    require(result.returncode == 0, "tool_failed")


def elf_notes(path, expected_type=None):
    """Read only bounded ELF64 little-endian program headers and note segments."""
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        info = os.fstat(fd)
        require(stat.S_ISREG(info.st_mode) and info.st_size <= BUDGET, "elf_bound")
        header = os.pread(fd, 64, 0)
        require(len(header) == 64 and header[:6] == b"\x7fELF\x02\x01", "elf_unavailable")
        kind = struct.unpack_from("<H", header, 16)[0]
        require(struct.unpack_from("<H", header, 18)[0] == 62, "elf_architecture_unavailable")
        require(expected_type is None or kind == expected_type, "elf_identity_mismatch")
        offset = struct.unpack_from("<Q", header, 32)[0]
        size, count = struct.unpack_from("<HH", header, 54)
        require(size == 56 and 0 < count <= 4096 and offset + size * count <= info.st_size, "elf_bound")
        notes = []
        note_bytes = 0
        for index in range(count):
            entry = os.pread(fd, 56, offset + index * size)
            require(len(entry) == 56, "elf_truncated")
            if struct.unpack_from("<I", entry)[0] != 4:
                continue
            start = struct.unpack_from("<Q", entry, 8)[0]
            length = struct.unpack_from("<Q", entry, 32)[0]
            note_bytes += length
            require(note_bytes <= 8 * 1024 * 1024, "elf_bound")
            require(length <= 4 * 1024 * 1024 and start + length <= info.st_size, "elf_truncated")
            data = os.pread(fd, length, start)
            cursor = 0
            while cursor + 12 <= len(data):
                names, descs, note_type = struct.unpack_from("<III", data, cursor)
                cursor += 12
                require(names <= 4096 and descs <= 4 * 1024 * 1024, "elf_bound")
                name_end = cursor + names
                desc_start = (name_end + 3) & ~3
                desc_end = desc_start + descs
                require(desc_end <= len(data), "elf_truncated")
                notes.append((data[cursor:name_end].rstrip(b"\0"), note_type, data[desc_start:desc_end]))
                require(len(notes) <= 8192, "elf_bound")
                cursor = (desc_end + 3) & ~3
        return notes
    finally:
        os.close(fd)


def build_id(path):
    ids = [desc.hex() for name, kind, desc in elf_notes(path) if name == b"GNU" and kind == 3 and 4 <= len(desc) <= 64]
    require(len(ids) == 1, "build_identity_unavailable")
    return ids[0]


def core_identity(path, expected_pid, expected_signal, executable):
    notes = elf_notes(path, 4)
    statuses = [desc for name, kind, desc in notes if name == b"CORE" and kind == 1]
    require(any(len(desc) >= 36 and struct.unpack_from("<i", desc, 32)[0] == expected_pid
                and struct.unpack_from("<h", desc, 12)[0] == expected_signal for desc in statuses), "core_pid_signal_mismatch")
    paths, ranges = [], []
    for name, kind, desc in notes:
        if name != b"CORE" or kind != 0x46494C45:
            continue
        require(len(desc) >= 16, "elf_truncated")
        count = struct.unpack_from("<Q", desc)[0]
        require(count <= 4096 and 16 + count * 24 <= len(desc), "elf_bound")
        names = desc[16 + count * 24:].split(b"\0")
        require(len(names) == count + 1 and names[-1] == b'', 'core_mapping_invalid')
        paths.extend(names[:-1])
        for index, name in enumerate(names[:-1]):
            if name == os.fsencode(executable):
                start, end, unused_offset = struct.unpack_from('<QQQ', desc, 16 + index * 24)
                ranges.append([start, end])
    require(os.fsencode(executable) in paths, "core_executable_mismatch")
    require(valid_main_ranges(ranges), 'core_mapping_invalid')
    return ranges


def valid_main_ranges(value):
    return (isinstance(value, list) and 0 < len(value) <= 64
            and all(isinstance(row, list) and len(row) == 2
                    and all(type(bound) is int for bound in row) and 0 <= row[0] < row[1] < 2 ** 64 for row in value)
            and all(left[1] <= right[0] for left, right in zip(value, value[1:])))


def core_has_missing_loads(path):
    """Identity notes must already be complete; absent load bytes mean partial evidence."""
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        info = os.fstat(fd)
        header = os.pread(fd, 64, 0)
        require(len(header) == 64 and header[:6] == b'\x7fELF\x02\x01', 'elf_unavailable')
        require(struct.unpack_from('<HH', header, 16) == (4, 62), 'elf_identity_mismatch')
        offset = struct.unpack_from('<Q', header, 32)[0]
        size, count = struct.unpack_from('<HH', header, 54)
        require(size == 56 and 0 < count <= 4096 and offset + size * count <= info.st_size, 'elf_bound')
        for index in range(count):
            entry = os.pread(fd, 56, offset + index * size)
            require(len(entry) == 56, 'elf_truncated')
            if struct.unpack_from('<I', entry)[0] == 1:
                start = struct.unpack_from('<Q', entry, 8)[0]
                length = struct.unpack_from('<Q', entry, 32)[0]
                if start + length > info.st_size:
                    return True
        return False
    finally:
        os.close(fd)


def verified_debug_symbols(campaign, expected, *, staged=False):
    require(isinstance(expected, str) and re.fullmatch(r'[a-f0-9]{8,128}', expected) and len(expected) % 2 == 0,
            'debug_build_identity_mismatch')
    private = campaign / 'private'
    root = campaign / 'debug-symbols-staged' if staged else private / 'debug-symbols'
    directory = root / 'usr/lib/debug'
    target = directory / '.build-id' / expected[:2] / (expected[2:] + '.debug')
    for parent in (private, root):
        info = parent.lstat()
        require(parent.resolve() == parent and stat.S_ISDIR(info.st_mode) and info.st_uid == 0
                and stat.S_IMODE(info.st_mode) & 0o077 == 0, 'debug_path_invalid')
    require(directory.resolve() == directory and target.resolve() == target, 'debug_path_invalid')
    info = target.lstat()
    require(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and not stat.S_IMODE(info.st_mode) & 0o022,
            'debug_path_invalid')
    require(build_id(target) == expected, 'debug_build_identity_mismatch')
    return directory, target


def native_symbol(value):
    if not isinstance(value, str) or not re.fullmatch(r"[A-Za-z_][A-Za-z0-9_.$:@~<>-]{0,159}", value):
        return "unknown"
    prefixes = ("zend", "_zend", "ZEND", "php", "zif_", "zim_", "pdo_", "mysqlnd_", "curl_", "xml", "pcre", "gd_", "__", "pthread_", "SSL_", "CRYPTO_", "EVP_")
    return value if value.startswith(prefixes) or value in ("main", "execute_ex", "execute_internal", "malloc", "free", "calloc", "realloc", "abort", "raise", "mmap", "munmap", "_start") else "unknown"


def native_module(path):
    if not isinstance(path, str) or not path.startswith(("/usr/lib/", "/lib/", "/usr/sbin/", "/usr/bin/")):
        return "unknown"
    name = Path(path).name
    return name if re.fullmatch(r"(?:php-fpm8\.4|[A-Za-z0-9_+.-]{1,96}\.so(?:\.[0-9.]{1,24})?)", name) else "unknown"


OPTIONAL_METADATA_REASONS = frozenset(('extensions_shape_invalid', 'extension_name_invalid', 'extension_version_invalid',
                                     'settings_keys_invalid', 'settings_value_invalid', 'zend_version_invalid'))


def native_identity(value):
    require(isinstance(value, dict) and value.get("php_version") == "8.4.27" and value.get("sapi") == "fpm-fcgi", "native_version_mismatch")
    return {'php_version': '8.4.27', 'sapi': 'fpm-fcgi'}


def finite_settings(value):
    native_identity(value)
    settings = value.get('settings')
    keys = ("opcache.enable", "opcache.enable_cli", "opcache.optimization_level", "opcache.jit", "opcache.jit_buffer_size",
            "opcache.protect_memory", "opcache.validate_timestamps", "opcache.file_update_protection", "opcache.revalidate_freq")
    require(isinstance(settings, dict) and set(settings) == set(keys), "settings_keys_invalid")
    require(all(isinstance(v, str) and re.fullmatch(r"[A-Za-z0-9_xX.+-]{0,48}", v) for v in settings.values()), "settings_value_invalid")
    return {key: settings[key] for key in keys}


def settings_observation(value):
    try:
        return {'actual_settings': finite_settings(value), 'actual_settings_status': {'state': 'recorded', 'reason': None}}
    except CaptureError as error:
        return {'actual_settings': None, 'actual_settings_status': {'state': 'needs_review', 'reason': error.code}}


def finite_environment(value):
    native_identity(value)
    extensions = value.get("extensions")
    require(isinstance(extensions, dict) and len(extensions) <= 128, "extensions_shape_invalid")
    clean = {}
    for name, version in extensions.items():
        require(isinstance(name, str) and (re.fullmatch(r"[A-Za-z][A-Za-z0-9_ ]{0,63}", name) or name == 'cgi-fcgi'), "extension_name_invalid")
        require(version is None or isinstance(version, str) and (re.fullmatch(r"[0-9][A-Za-z0-9.+_-]{0,47}", version)
                or name == 'mysqlnd' and version == 'mysqlnd ' + value['php_version']), "extension_version_invalid")
        clean[name] = version
    settings = finite_settings(value)
    zend = value.get("zend_version")
    require(isinstance(zend, str) and re.fullmatch(r"[0-9][A-Za-z0-9.+_-]{0,47}", zend), "zend_version_invalid")
    return {"php_version": "8.4.27", "sapi": "fpm-fcgi", "zend_version": zend, "extensions": clean, "settings": settings,
            "resource_acceptance": False}


def read_native_environment_payload(arm_root):
    try:
        return json.loads(read_bytes(arm_root / 'private/native-environment.json', 32768))
    except (json.JSONDecodeError, UnicodeDecodeError, RecursionError):
        raise CaptureError('inventory_invalid') from None
    except FileNotFoundError:
        raise CaptureError('inventory_missing') from None
    except OSError:
        raise CaptureError('inventory_unavailable') from None


def preflight(campaign, arm_root, base):
    state = state_load(base)
    require(mount_matches(base, state), 'mount_identity_mismatch')
    require(arm_root.resolve() == arm_root and read_bytes(arm_root / 'fixture-owned', 128).decode().strip() == state['owner'])
    value = read_native_environment_payload(arm_root)
    identity = native_identity(value)
    partial = settings_observation(value)
    try:
        inventory = finite_environment(value)
    except CaptureError as error:
        if error.code not in OPTIONAL_METADATA_REASONS:
            raise
        return {'state': 'version_recorded', 'resource_acceptance': False, 'identity': identity, 'inventory': None,
                'inventory_status': {'state': 'needs_review', 'reason': error.code}, **partial}
    return {'state': 'recorded', 'resource_acceptance': False, 'identity': identity, 'inventory': inventory,
            'inventory_status': {'state': 'recorded', 'reason': None}, **partial}


def context():
    env = os.environ
    run_id, attempt = env.get("GITHUB_RUN_ID", ""), env.get("GITHUB_RUN_ATTEMPT", "")
    require(env.get("GITHUB_ACTIONS") == "true" and env.get("RUNNER_ENVIRONMENT") == "github-hosted" and os.geteuid() == 0
            and re.fullmatch(r"[0-9]+", run_id) and re.fullmatch(r"[0-9]+", attempt)
            and env.get("PINOVA_FPM_NATIVE_CAPTURE") == "1" and env.get("PINOVA_FPM_PHP_VERSION") == "8.4"
            and env.get("PINOVA_FPM_LOGGING_PROFILE") == "normal")
    campaign = Path(f"/tmp/pinova-fpm-revisions-{run_id}-{attempt}")
    arm_root = Path(f"/tmp/pinova-fpm-full-{run_id}-{attempt}")
    require(env.get("PINOVA_FPM_CAMPAIGN_ROOT") == str(campaign) and env.get("PINOVA_FPM_FULL_STACK_ROOT") == str(arm_root))
    require(campaign.resolve() == campaign and campaign.stat().st_uid == 0
            and read_bytes(campaign / "fixture-owned", 128).decode().strip() == f"{run_id}:{attempt}")
    base = campaign / "private/native-capture"
    require((campaign / "private").resolve() == campaign / "private" and (campaign / "private").stat().st_uid == 0)
    return campaign, arm_root, base


def state_load(base):
    require(base.resolve() == base and base.stat().st_uid == 0 and stat.S_IMODE(base.stat().st_mode) == 0o700)
    value = json.loads(read_bytes(base / "state.json", 16384))
    require(value.get("owner") == f"{os.environ['GITHUB_RUN_ID']}:{os.environ['GITHUB_RUN_ATTEMPT']}")
    return value


def mount_matches(base, state):
    point = base / "cores"
    if not os.path.ismount(point):
        return False
    require(point.resolve() == point, "mount_identity_mismatch")
    info = point.stat()
    backing = Path(f"/sys/dev/block/{os.major(info.st_dev)}:{os.minor(info.st_dev)}/loop/backing_file")
    require(backing.is_file(), "mount_identity_mismatch")
    source = backing.read_text().strip()
    require(Path("/" + source.lstrip("/")).resolve() == base / "cores.img", "mount_identity_mismatch")
    image = (base / "cores.img").lstat()
    require([image.st_dev, image.st_ino] == state["image_identity"] and stat.S_ISREG(image.st_mode)
            and image.st_size == BUDGET and image.st_uid == 0, "mount_identity_mismatch")
    matches = []
    for line in Path("/proc/self/mountinfo").read_text().splitlines():
        left, right = line.split(" - ", 1)
        fields = left.split()
        if fields[4] == str(point):
            matches.append((set(fields[5].split(",")), right.split()[0]))
    require(len(matches) == 1 and {"nosuid", "nodev", "noexec"} <= matches[0][0] and matches[0][1] == "ext4", "mount_identity_mismatch")
    return True


def setup(campaign, arm_root, base):
    require(not base.exists(), "capture_already_owned")
    available = os.statvfs(campaign / 'private')
    require(available.f_bavail * available.f_frsize >= 2 * 1024 * 1024 * 1024, 'disk_headroom_unavailable')
    executable = '/usr/sbin/php-fpm8.4'
    expected_build = build_id(executable)
    stage, destination = campaign / 'debug-symbols-staged', campaign / 'private/debug-symbols'
    require(not os.path.lexists(destination), 'debug_path_invalid')
    verified_debug_symbols(campaign, expected_build, staged=True)
    require(stage.stat().st_dev == destination.parent.stat().st_dev, 'debug_path_invalid')
    os.rename(stage, destination)
    verified_debug_symbols(campaign, expected_build)
    base.mkdir(mode=0o700)
    (base / "cores").mkdir(mode=0o700)
    image = base / "cores.img"
    fd = os.open(image, os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        os.posix_fallocate(fd, 0, BUDGET)
        info = os.fstat(fd)
    finally:
        os.close(fd)
    pattern_file = Path("/proc/sys/kernel/core_pattern")
    previous = pattern_file.read_text().rstrip("\n")
    # The fixture unshares networking only, so %p is the recorded host PID.
    pattern = str(base / "cores/core.%p.%s.%t")
    require(len(pattern.encode()) < 128, "core_route_bound")
    state = {"owner": f"{os.environ['GITHUB_RUN_ID']}:{os.environ['GITHUB_RUN_ATTEMPT']}",
             "image_identity": [info.st_dev, info.st_ino], "previous_pattern": previous, "pattern": pattern, "closed": False}
    write_json(base / "state.json", state)
    run(["mkfs.ext4", "-q", "-F", "-m", "0", str(image)])
    run(["mount", "-o", "loop,nosuid,nodev,noexec", str(image), str(base / "cores")])
    require(mount_matches(base, state), "mount_identity_mismatch")
    os.chmod(base / "cores", 0o700)
    (base / "cores/lost+found").rmdir()
    pattern_file.write_text(pattern + "\n")
    require(pattern_file.read_text().rstrip("\n") == pattern, "core_route_unavailable")
    return {"state": "ready", "resource_acceptance": False, "storage_budget_bytes": BUDGET,
            "per_core_limit_bytes": CORE_LIMIT, 'debug_build_id': expected_build}


def process_identity(pid, executable):
    require(isinstance(pid, int) and 1 < pid < 2147483648, "process_identity_unavailable")
    proc = Path(f"/proc/{pid}")
    fields = (proc / "stat").read_text().rsplit(")", 1)[1].split()
    start = int(fields[19])
    target = os.readlink(proc / "exe")
    require(target == executable, "process_identity_mismatch")
    command = (proc / "cmdline").read_bytes()
    pool = "fixture_status" if command.startswith(b"php-fpm: pool fixture_status\0") else "fixture" if command.startswith(b"php-fpm: pool fixture\0") else None
    if (pid == int(os.environ.get('PINOVA_FPM_MASTER_PID', '0'))
            and command.startswith(b'php-fpm: master process ')
            and os.environ.get('PINOVA_FPM_FULL_STACK_ROOT', '').encode() in command
            and os.environ.get('PINOVA_FPM_FULL_STACK_ROOT')):
        pool = 'master'
    require(pool is not None, "process_identity_mismatch")
    info = os.stat(proc / "exe")
    return {"pid": pid, "start_ticks": start, "pool": pool, "exe_identity": [info.st_dev, info.st_ino]}


NANO = 1000000000


def paired_clocks(ticks, utc_offset=None):
    before = time.clock_gettime_ns(time.CLOCK_REALTIME)
    boot = time.clock_gettime_ns(time.CLOCK_BOOTTIME)
    after = time.clock_gettime_ns(time.CLOCK_REALTIME)
    return {'realtime_before_ns': before, 'boottime_ns': boot, 'realtime_after_ns': after,
            'clock_ticks_per_second': ticks, 'utc_offset_seconds': time.localtime().tm_gmtoff if utc_offset is None else utc_offset}


def clock_offset(mapping):
    keys = ('realtime_before_ns', 'boottime_ns', 'realtime_after_ns', 'clock_ticks_per_second', 'utc_offset_seconds')
    require(isinstance(mapping, dict) and all(type(mapping.get(key)) is int for key in keys), 'clock_mapping_invalid')
    before, after, boot = (mapping[key] for key in ('realtime_before_ns', 'realtime_after_ns', 'boottime_ns'))
    require(1577836800 * NANO <= before <= after <= 4102444800 * NANO
            and after - before <= NANO and boot >= 0
            and 1 <= mapping['clock_ticks_per_second'] <= 1000000, 'clock_mapping_invalid')
    require(mapping['utc_offset_seconds'] == 0, 'clock_timezone_unverified')
    return before - boot, after - boot


def log_tail(arm_root):
    fd = os.open(arm_root / 'private/fpm.log', os.O_RDONLY | os.O_NOFOLLOW)
    try:
        info = os.fstat(fd)
        require(stat.S_ISREG(info.st_mode), 'exit_log_unavailable')
        return os.pread(fd, min(65536, info.st_size), max(0, info.st_size - 65536)).decode('utf-8', errors='replace')
    finally:
        os.close(fd)


def exit_records(text, pid, expected_signal=None):
    pattern = (r'^\[([^\r\n\]]{1,32})\] [A-Z]+: \[pool fixture(?:_status)?\] child ' + str(pid)
               + r' exited on signal (6|7|11) \([^\r\n)]{1,64}\) after ([0-9]{1,9})(?:\.([0-9]{1,9}))? seconds from start$')
    records = []
    for stamp, signum, whole, fraction in re.findall(pattern, text, re.MULTILINE):
        if expected_signal is not None and int(signum) != expected_signal:
            continue
        # fpm's prefix has local whole seconds; inventory must independently establish UTC.
        try:
            reap = int(datetime.strptime(stamp, '%d-%b-%Y %H:%M:%S').replace(tzinfo=timezone.utc).timestamp())
        except ValueError:
            continue
        records.append({'signal': int(signum), 'lifetime_ns': int(whole) * NANO + int(fraction.ljust(9, '0') or '0'),
                        'reap_epoch': reap})
        require(len(records) <= 64, 'exit_log_bound')
    return records


def drain(campaign, arm_root, base):
    """Read-only native-diagnostic wait while the owned master can still reap dumps."""
    state = state_load(base)
    require(mount_matches(base, state), 'mount_identity_mismatch')
    arm = os.environ.get('PINOVA_FPM_REVISION_ARM')
    require(arm in ARMS)
    facts = json.loads(read_bytes(base / f'inventory-{arm}.json', 16384))
    executable, master = facts['executable'], facts['master']
    require(executable == '/usr/sbin/php-fpm8.4' and master['pid'] == int(os.environ.get('PINOVA_FPM_MASTER_PID', '0')),
            'process_identity_mismatch')
    started = time.monotonic()
    result = {'state': 'needs_review', 'reason': 'drain_timeout', 'resource_acceptance': False,
              'master': master, 'master_verified': False, 'master_terminated': False,
              'started_realtime_ns': time.clock_gettime_ns(time.CLOCK_REALTIME),
              'core_dumping_remaining': 0, 'exit_match_count': 0}
    targets = set()
    try:
        while True:
            current = process_identity(master['pid'], executable)
            require(all(current[key] == master[key] for key in ('pid', 'start_ticks', 'exe_identity')),
                    'master_identity_changed')
            result['master_verified'] = True
            status = read_bytes(Path(f"/proc/{master['pid']}/status"), 65536).decode('ascii', errors='replace')
            master_dumping = re.findall(r'^CoreDumping:\s*([01])\s*$', status, re.MULTILINE)
            require(len(master_dumping) == 1, 'master_dump_status_unavailable')
            result['master_core_dumping'] = int(master_dumping[0])
            cores = list((base / 'cores').iterdir())
            require(len(cores) <= 64, 'core_count_bound')
            for item in cores:
                match = re.fullmatch(r'core\.([0-9]+)\.(?:6|7|11)\.[0-9]+', item.name)
                require(match is not None, 'core_identity_mismatch')
                if int(match[1]) != master['pid']:
                    targets.add(int(match[1]))
            dumping = result['master_core_dumping']
            known = {row['pid']: row for row in facts['workers']}
            require(targets <= known.keys(), 'core_identity_mismatch')
            for pid, expected in known.items():
                try:
                    current = process_identity(pid, executable)
                    require(all(current[key] == expected[key] for key in ('pid', 'start_ticks', 'exe_identity')),
                            'worker_identity_changed')
                    status = read_bytes(Path(f'/proc/{pid}/status'), 65536).decode('ascii', errors='replace')
                    values = re.findall(r'^CoreDumping:\s*([01])\s*$', status, re.MULTILINE)
                    require(len(values) == 1, 'dump_status_unavailable')
                    if values[0] == '1':
                        targets.add(pid)
                        dumping += 1
                except FileNotFoundError:
                    if expected['pool'] == 'fixture':
                        targets.add(pid)
            result['core_dumping_remaining'] = dumping
            tail = log_tail(arm_root)
            counts = [len(exit_records(tail, pid)) for pid in sorted(targets)]
            result['exit_match_count'] = sum(counts)
            require(all(count <= 1 for count in counts), 'exit_record_ambiguous')
            if dumping == 0 and all(count == 1 for count in counts):
                result.update({'state': 'drained', 'reason': None})
                break
            elapsed = time.monotonic() - started
            if elapsed >= 30:
                break
            time.sleep(min(0.1, 30 - elapsed))
    except CaptureError as error:
        result['reason'] = error.code
    except (OSError, ValueError, KeyError, TypeError):
        result['reason'] = 'drain_observation_unavailable'
    result['duration_seconds'] = max(0, time.monotonic() - started)
    if result['duration_seconds'] > 30:
        result.update({'state': 'needs_review', 'reason': 'drain_timeout'})
    result['completed_realtime_ns'] = time.clock_gettime_ns(time.CLOCK_REALTIME)
    result['clock_mapping'] = paired_clocks(facts['clock_mapping']['clock_ticks_per_second'])
    write_json(base / f'drain-{arm}.json', result)
    return result


def inventory(campaign, arm_root, base):
    state = state_load(base)
    require(mount_matches(base, state), "mount_identity_mismatch")
    arm = os.environ.get("PINOVA_FPM_REVISION_ARM")
    require(arm in ARMS and arm_root.resolve() == arm_root and read_bytes(arm_root / "fixture-owned", 128).decode().strip() == state["owner"])
    executable = str(Path(os.environ.get("PINOVA_FPM_BIN", "")).resolve())
    require(executable == "/usr/sbin/php-fpm8.4", "executable_unavailable")
    master = int(os.environ.get("PINOVA_FPM_MASTER_PID", "0"))
    require(master > 1 and str(arm_root).encode() in Path(f"/proc/{master}/cmdline").read_bytes(), "process_identity_mismatch")
    pids = [int(value) for value in Path(f"/proc/{master}/task/{master}/children").read_text().split()]
    require(10 <= len(pids) <= 11, "process_inventory_incomplete")
    workers = [process_identity(pid, executable) for pid in pids]
    require(sum(row["pool"] == "fixture" for row in workers) == 10, "process_inventory_incomplete")
    boot = int(next(line.split()[1] for line in Path('/proc/stat').read_text().splitlines() if line.startswith('btime ')))
    ticks = os.sysconf('SC_CLK_TCK')
    mapping = paired_clocks(ticks, time.localtime().tm_gmtoff)
    clock_offset(mapping)
    master_identity = process_identity(master, executable)
    for row in workers:
        row['started_epoch'] = boot + row['start_ticks'] / ticks
    value = {"arm": arm, "executable": executable, "build_id": build_id(executable),
             "executable_sha256": hashlib.sha256(read_bytes(executable, 32 * 1024 * 1024)).hexdigest(), "workers": workers,
             "started_at": int(time.time()), "resource_acceptance": False, 'master': master_identity,
             'clock_mapping': mapping, 'prewarm_wall_interval_ns': [mapping['realtime_before_ns'], mapping['realtime_after_ns']]}
    write_json(base / f"inventory-{arm}.json", value)
    return {"state": "recorded", "workers": len(workers), "resource_acceptance": False}


def gdb_extract():
    # GDB imports this same trusted helper. All debugger output is captured and discarded.
    import gdb
    campaign, arm_root, base = context()
    data = json.loads(read_bytes(base / "gdb-input.json", 16384))
    output = {"state": "needs_review", "reason": "gdb_unavailable", "frames": [], "resource_acceptance": False,
              'pid': None, 'signal': None, 'partial_core': data.get('partial_core') is True,
              'stack_complete': False, 'frame_bound': False, 'debug_objfile_attached': None}
    try:
        exe, core = data["executable"], data["core"]
        require(exe == "/usr/sbin/php-fpm8.4" and Path(core).parent == base / "cores" and re.fullmatch(r"core\.[0-9]+\.(?:6|7|11)\.[0-9]+", Path(core).name))
        require(isinstance(data.get('pid'), int) and 1 < data['pid'] < 2147483648 and data.get('signal') in (6, 7, 11),
                'core_pid_signal_mismatch')
        output.update({'pid': data['pid'], 'signal': data['signal']})
        ranges = data.get('main_ranges')
        require(valid_main_ranges(ranges), 'core_mapping_invalid')
        expected = data.get('build_id')
        require(build_id(exe) == expected, 'debug_build_identity_mismatch')
        canonical = campaign / 'private/debug-symbols/usr/lib/debug'
        target = canonical / '.build-id' / str(expected)[:2] / (str(expected)[2:] + '.debug')
        require(data.get('debug_directory', str(canonical)) == str(canonical) and data.get('debug_file', str(target)) == str(target),
                'debug_path_invalid')
        directory, target = verified_debug_symbols(campaign, expected)
        gdb.execute('set debug-file-directory ' + str(directory), to_string=True)
        gdb.execute("file " + exe, to_string=True)
        gdb.execute("core-file " + core, to_string=True)
        require(gdb.selected_inferior().pid == data["pid"], "core_pid_signal_mismatch")
        try:
            objects = list(gdb.objfiles())
            require(len(objects) <= 256, 'gdb_objfile_bound')
            main_loaded = any(obj.filename == exe and obj.build_id == expected and obj.owner is None for obj in objects)
            output['debug_objfile_attached'] = main_loaded and any(
                obj.filename == str(target) and obj.build_id == expected and obj.owner is not None
                and obj.owner.filename == exe and obj.owner.build_id == expected and obj.owner.owner is None
                for obj in objects)
        except BaseException:
            output['debug_objfile_attached'] = None
        frame = gdb.newest_frame()
        while frame is not None and len(output["frames"]) < FRAME_LIMIT:
            pc = frame.pc()
            module_path = gdb.solib_name(pc)
            if module_path is None and any(start <= pc < end for start, end in ranges):
                module_path = exe
            module = native_module(module_path)
            identity = None
            if module != "unknown":
                try:
                    identity = build_id(str(Path(module_path).resolve()))
                except (CaptureError, OSError):
                    pass
            try:
                name = frame.name()
                symbol = native_symbol(name)
                name_status = 'missing' if name is None else 'resolved' if symbol != 'unknown' else 'filtered'
            except BaseException:
                symbol, name_status = 'unknown', 'unavailable'
            output["frames"].append({"index": len(output["frames"]), "symbol": symbol, 'name_status': name_status,
                                      "module": module, "build_id": identity})
            frame = frame.older()
        known = bool(output['frames']) and all(row['symbol'] != 'unknown' and row['module'] != 'unknown' for row in output['frames'])
        unknown_reason = 'module_unknown' if any(row['module'] == 'unknown' for row in output['frames']) else 'symbol_unknown'
        output.update({"state": "captured" if known and not output['partial_core'] else "needs_review",
                       "reason": 'partial_core' if output['partial_core'] else "bounded_frames" if frame is not None else unknown_reason if not known else None,
                       "frame_bound": frame is not None, 'stack_complete': known and frame is None and not output['partial_core']})
    except CaptureError as error:
        output.update({'state': 'needs_review', 'reason': error.code, 'stack_complete': False})
    except BaseException:
        output.update({"state": "needs_review", "reason": "gdb_unwind_incomplete", 'stack_complete': False,
                       'frame_bound': len(output['frames']) >= FRAME_LIMIT})
    write_json(base / "gdb-output.json", output)


def core_timing(facts, worker, at, size, records, drained, observed):
    """Retain finite endpoints before enforcing the native lifetime fence."""
    timing = {'core_timestamp_epoch': at, 'core_size_bytes': size, 'worker_start_ticks': worker['start_ticks'],
              'exit_match_count': len(records), 'prewarm_wall_interval_ns': facts.get('prewarm_wall_interval_ns'),
              'birth_epoch_interval_ns': None, 'drain': drained, 'collection_clock_mapping': observed,
              'reported_lifetime_ns': records[0]['lifetime_ns'] if len(records) == 1 else None,
              'reap_log_interval_ns': [records[0]['reap_epoch'] * NANO, (records[0]['reap_epoch'] + 1) * NANO] if len(records) == 1 else None}
    try:
        lower, upper = clock_offset(facts['clock_mapping'])
        hz = facts['clock_mapping']['clock_ticks_per_second']
        ticks = worker['start_ticks']
        require(type(ticks) is int and ticks >= 0, 'clock_mapping_invalid')
        timing['birth_epoch_interval_ns'] = [lower + ticks * NANO // hz, upper + ((ticks + 1) * NANO + hz - 1) // hz]
    except (CaptureError, KeyError, TypeError):
        pass
    return timing


def verify_core_timing(facts, timing, drained, observed):
    require(timing['birth_epoch_interval_ns'] is not None, 'clock_mapping_invalid')
    offsets = [clock_offset(facts['clock_mapping']), clock_offset(drained['clock_mapping']), clock_offset(observed)]
    lower, upper = max(row[0] for row in offsets), min(row[1] for row in offsets)
    require(lower <= upper, 'clock_discontinuity')
    hz = facts['clock_mapping']['clock_ticks_per_second']
    require(all(mapping['clock_ticks_per_second'] == hz for mapping in (drained['clock_mapping'], observed)), 'clock_mapping_invalid')
    ticks = timing['worker_start_ticks']
    timing['birth_epoch_interval_ns'] = [lower + ticks * NANO // hz, upper + ((ticks + 1) * NANO + hz - 1) // hz]
    require(drained.get('state') == 'drained' and drained.get('master_verified') is True
            and drained.get('master_terminated') is False and drained.get('master') == facts['master']
            and drained.get('core_dumping_remaining') == 0, 'drain_incomplete')
    prewarm = timing['prewarm_wall_interval_ns']
    require(isinstance(prewarm, list) and len(prewarm) == 2 and all(type(value) is int for value in prewarm)
            and prewarm == [facts['clock_mapping']['realtime_before_ns'], facts['clock_mapping']['realtime_after_ns']], 'clock_mapping_invalid')
    end, start = drained['completed_realtime_ns'], drained['started_realtime_ns']
    require(type(start) is int and type(end) is int and prewarm[0] <= start <= end
            and end <= observed['realtime_after_ns'], 'drain_clock_invalid')
    require(timing['exit_match_count'] == 1, 'core_process_lifetime_mismatch')
    reap = timing['reap_log_interval_ns']
    require(reap[0] <= end, 'reap_after_drain')
    birth = timing['birth_epoch_interval_ns']
    lifetime = timing['reported_lifetime_ns']
    first_fork = max(reap[0] - lifetime, birth[0])
    require(lifetime >= 0 and first_fork <= prewarm[1] and first_fork + lifetime <= end
            and first_fork + lifetime < reap[1],
            'core_process_lifetime_mismatch')
    core_start = timing['core_timestamp_epoch'] * NANO
    require(core_start <= end and core_start < reap[1] and core_start <= prewarm[1] + lifetime
            and core_start + NANO > prewarm[0], 'core_process_lifetime_mismatch')


def collect(campaign, arm_root, base):
    state = state_load(base)
    require(mount_matches(base, state), "mount_identity_mismatch")
    arm = os.environ.get("PINOVA_FPM_REVISION_ARM")
    require(arm in ARMS)
    facts = json.loads(read_bytes(base / f"inventory-{arm}.json", 16384))
    exe = facts["executable"]
    require(exe == "/usr/sbin/php-fpm8.4" and build_id(exe) == facts["build_id"]
            and hashlib.sha256(read_bytes(exe, 32 * 1024 * 1024)).hexdigest() == facts["executable_sha256"], "build_identity_mismatch")
    workers = {row["pid"]: row for row in facts["workers"]}
    cores = list((base / "cores").iterdir())
    require(len(cores) <= 64, "core_count_bound")
    result = {"state": "no_crash_observed", "resource_acceptance": False, "cores": [], "core_count": len(cores),
              "worker_inventory": [{k: row[k] for k in ("pid", "start_ticks", "pool")} for row in facts["workers"]],
              "executable_build_id": facts["build_id"], "inventory": None, "cleanup_workers_alive": [],
              "inventory_status": {'state': 'needs_review', 'reason': 'inventory_missing'},
              'actual_settings': None, 'actual_settings_status': {'state': 'needs_review', 'reason': 'inventory_missing'}}
    try:
        value = read_native_environment_payload(arm_root)
    except CaptureError as error:
        result['inventory_status'] = {'state': 'needs_review', 'reason': error.code}
        result['actual_settings_status'] = {'state': 'needs_review', 'reason': error.code}
    else:
        result.update(settings_observation(value))
        try:
            result['inventory'] = finite_environment(value)
            result['inventory_status'] = {'state': 'recorded', 'reason': None}
        except CaptureError as error:
            # Optional metadata cannot suppress independently verified native cores.
            result['inventory_status'] = {'state': 'needs_review', 'reason': error.code}
    try:
        tail = log_tail(arm_root)
        result['exit_log_status'] = 'read'
    except (CaptureError, OSError):
        tail = ''
        result['exit_log_status'] = 'unavailable'
    try:
        drained = json.loads(read_bytes(base / f'drain-{arm}.json', 16384))
    except (OSError, ValueError):
        drained = {'state': 'needs_review', 'reason': 'drain_receipt_unavailable'}
    observed = paired_clocks(facts.get('clock_mapping', {}).get('clock_ticks_per_second', 0))
    for item in sorted(cores):
        match = re.fullmatch(r"core\.([0-9]+)\.(6|7|11)\.([0-9]+)", item.name)
        require(match and item.lstat().st_uid == 0 and stat.S_ISREG(item.lstat().st_mode)
                and not item.is_symlink() and item.stat().st_size <= CORE_LIMIT, "core_identity_mismatch")
        pid, signum, at = map(int, match.groups())
        require(pid in workers, "core_identity_mismatch")
        records = exit_records(tail, pid, signum)
        timing = core_timing(facts, workers[pid], at, item.stat().st_size, records, drained, observed)
        receipt = {"pid": pid, "signal": signum, "state": "needs_review", "reason": "core_bound", "frames": [], "resource_acceptance": False,
                   'timing': timing,
                   'partial_core': item.stat().st_size >= CORE_LIMIT, 'stack_complete': False}
        if len(result["cores"]) < 2:
            try:
                verify_core_timing(facts, timing, drained, observed)
                main_ranges = core_identity(item, pid, signum, exe)
                partial = item.stat().st_size >= CORE_LIMIT or core_has_missing_loads(item)
                directory = campaign / 'private/debug-symbols/usr/lib/debug'
                target = directory / '.build-id' / facts['build_id'][:2] / (facts['build_id'][2:] + '.debug')
                write_json(base / "gdb-input.json", {"core": str(item), "executable": exe, "pid": pid, "signal": signum,
                    'build_id': facts['build_id'], 'partial_core': partial, 'debug_directory': str(directory), 'debug_file': str(target),
                    'main_ranges': main_ranges})
                (base / "gdb-output.json").unlink(missing_ok=True)
                command = ["gdb", "-q", "-nx", "-nh", "--batch", "-iex", "set auto-load off", "-iex", "set debuginfod enabled off",
                           "-ex", "python import gdb", "-ex", "source " + str(Path(__file__).resolve())]
                run(command, 30)
                receipt = json.loads(read_bytes(base / "gdb-output.json", 32768))
                receipt['timing'] = timing
                receipt['partial_core'] = partial
                receipt['stack_complete'] = receipt.get('stack_complete') is True and not partial
                if partial:
                    receipt.update({'state': 'needs_review', 'reason': 'partial_core', 'stack_complete': False})
            except CaptureError as error:
                receipt["reason"] = error.code
            except (KeyError, ValueError, TypeError, OSError):
                receipt['reason'] = 'core_timing_unavailable'
        result["cores"].append(receipt)
    # Check the exact earlier PID/start/executable identity; never blame or kill an unrelated PID.
    end = time.monotonic() + 3
    while True:
        alive = []
        for pid, expected in workers.items():
            try:
                current = process_identity(pid, exe)
                if current["start_ticks"] == expected["start_ticks"] and current["exe_identity"] == expected["exe_identity"]:
                    alive.append(pid)
            except (OSError, CaptureError):
                pass
        if not alive or time.monotonic() >= end:
            result["cleanup_workers_alive"] = alive
            break
        time.sleep(0.05)
    result["state"] = "needs_review" if alive or result["inventory"] is None or any(row["state"] != "captured" or row.get('frame_bound') or row.get('partial_core') or row.get('stack_complete') is not True for row in result["cores"]) else "crash_captured" if cores else "no_crash_observed"
    return result


def clear_cores(campaign, arm_root, base):
    require(mount_matches(base, state_load(base)), "mount_identity_mismatch")
    items = list((base / "cores").iterdir())
    require(len(items) <= 64, "core_count_bound")
    for item in items:
        info = item.lstat()
        require(info.st_uid == 0 and stat.S_ISREG(info.st_mode), "core_cleanup_identity_mismatch")
        item.unlink()
    for name in ("gdb-input.json", "gdb-output.json"):
        (base / name).unlink(missing_ok=True)


def stop_known_workers(base):
    """Stop only PID-fd identities recorded by this diagnostic, never pool-name matches."""
    opened = []
    try:
        for arm in ARMS:
            source = base / f'inventory-{arm}.json'
            if not source.exists():
                continue
            facts = json.loads(read_bytes(source, 16384))
            require(facts['executable'] == '/usr/sbin/php-fpm8.4' and len(facts['workers']) <= 11)
            for expected in facts['workers']:
                try:
                    current = process_identity(expected['pid'], facts['executable'])
                    if (current['start_ticks'], current['exe_identity']) != (expected['start_ticks'], expected['exe_identity']):
                        continue
                    fd = os.pidfd_open(expected['pid'])
                    try:
                        again = process_identity(expected['pid'], facts['executable'])
                        require(again == current, 'worker_cleanup_identity_changed')
                        opened.append((fd, expected['pid']))
                    except BaseException:
                        os.close(fd)
                        raise
                except (FileNotFoundError, ProcessLookupError, CaptureError):
                    continue
        for fd, _ in opened:
            try:
                signal.pidfd_send_signal(fd, signal.SIGTERM)
            except ProcessLookupError:
                pass
        import select
        end = time.monotonic() + 3
        while opened and time.monotonic() < end and any(not select.select([fd], [], [], 0)[0] for fd, _ in opened):
            time.sleep(0.05)
        for fd, _ in opened:
            if not select.select([fd], [], [], 0)[0]:
                signal.pidfd_send_signal(fd, signal.SIGKILL)
        end = time.monotonic() + 3
        while opened and time.monotonic() < end and any(not select.select([fd], [], [], 0)[0] for fd, _ in opened):
            time.sleep(0.05)
        require(all(select.select([fd], [], [], 0)[0] for fd, _ in opened), 'worker_cleanup_incomplete')
        return [pid for _, pid in opened]
    finally:
        for fd, _ in opened:
            os.close(fd)


def cleanup(campaign, arm_root, base):
    if not base.exists():
        return {"state": "not_started", "resource_acceptance": False}
    state = state_load(base)
    if state.get("closed"):
        return {"state": "complete", "resource_acceptance": False}
    worker_cleanup_failed = False
    try:
        stopped = stop_known_workers(base)
    except BaseException:
        worker_cleanup_failed = True
    finally:
        pattern_file = Path("/proc/sys/kernel/core_pattern")
        current = pattern_file.read_text().rstrip("\n")
        require(current in (state["pattern"], state["previous_pattern"]), "core_route_restore_conflict")
        if current == state["pattern"]:
            pattern_file.write_text(state["previous_pattern"] + "\n")
        require(pattern_file.read_text().rstrip("\n") == state["previous_pattern"], "core_route_restore_failed")
    require(not worker_cleanup_failed, "worker_cleanup_unverified")
    if os.path.ismount(base / "cores"):
        require(mount_matches(base, state), "mount_identity_mismatch")
        run(["umount", str(base / "cores")])
    require(not os.path.ismount(base / "cores"), "mount_cleanup_failed")
    image = base / "cores.img"
    info = image.lstat()
    require([info.st_dev, info.st_ino] == state["image_identity"] and stat.S_ISREG(info.st_mode) and info.st_uid == 0)
    image.unlink()
    for item in base.glob("*.json"):
        if item.name == "state.json":
            continue
        require(item.lstat().st_uid == 0 and stat.S_ISREG(item.lstat().st_mode))
        item.unlink()
    state["closed"] = True
    write_json(base / "state.json", state)
    return {"state": "complete", "pattern_restored": True, "unmounted": True, "private_cores_deleted": True,
            "known_workers_stopped": stopped, "resource_acceptance": False}


def main():
    output = {"state": "needs_review", "reason": "capture_unavailable", "resource_acceptance": False}
    try:
        args = sys.argv[1:]
        require(len(args) == 1 and args[0] in ("setup", "inventory", "preflight", "drain", "collect", "cleanup"), "command_unavailable")
        owned = context()
        try:
            output = globals()[args[0]](*owned)
        finally:
            if args[0] == 'collect':
                clear_cores(*owned)
    except CaptureError as error:
        output["reason"] = error.code
    except BaseException:
        pass
    # The parent round-trips stdout through JavaScript; exact epoch nanoseconds
    # exceed its integer precision. Private clock receipts retain Python ints.
    def public(value):
        if type(value) is int and abs(value) > 9007199254740991:
            return str(value)
        if isinstance(value, dict):
            return {key: public(item) for key, item in value.items()}
        if isinstance(value, list):
            return [public(item) for item in value]
        return value
    print(json.dumps(public(output), separators=(",", ":")))
    return 1 if output["state"] == "needs_review" else 0


if __name__ == "__main__":
    if "gdb" in sys.modules:
        try:
            gdb_extract()
        except BaseException:
            pass
    else:
        sys.exit(main())
