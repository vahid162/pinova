#!/usr/bin/env python3
"""Disposable hosted diagnostic only. Never publishes cores or debugger text."""
import hashlib
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

BUDGET = 512 * 1024 * 1024
CORE_LIMIT = 256 * 1024 * 1024
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
    paths = []
    for name, kind, desc in notes:
        if name != b"CORE" or kind != 0x46494C45:
            continue
        require(len(desc) >= 16, "elf_truncated")
        count = struct.unpack_from("<Q", desc)[0]
        require(count <= 4096 and 16 + count * 24 <= len(desc), "elf_bound")
        paths.extend(desc[16 + count * 24:].split(b"\0"))
    require(os.fsencode(executable) in paths, "core_executable_mismatch")


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


def finite_environment(value):
    native_identity(value)
    extensions = value.get("extensions")
    require(isinstance(extensions, dict) and len(extensions) <= 128, "extensions_shape_invalid")
    clean = {}
    for name, version in extensions.items():
        require(isinstance(name, str) and re.fullmatch(r"[A-Za-z][A-Za-z0-9_ ]{0,63}", name), "extension_name_invalid")
        require(version is None or isinstance(version, str) and (re.fullmatch(r"[0-9][A-Za-z0-9.+_-]{0,47}", version)
                or name == 'mysqlnd' and version == 'mysqlnd ' + value['php_version']), "extension_version_invalid")
        clean[name] = version
    settings = value.get("settings")
    keys = ("opcache.enable", "opcache.enable_cli", "opcache.optimization_level", "opcache.jit", "opcache.jit_buffer_size",
            "opcache.protect_memory", "opcache.validate_timestamps", "opcache.file_update_protection", "opcache.revalidate_freq")
    require(isinstance(settings, dict) and set(settings) == set(keys), "settings_keys_invalid")
    require(all(isinstance(v, str) and re.fullmatch(r"[A-Za-z0-9_xX.+-]{0,48}", v) for v in settings.values()), "settings_value_invalid")
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


def read_native_environment(arm_root):
    return finite_environment(read_native_environment_payload(arm_root))


def preflight(campaign, arm_root, base):
    state = state_load(base)
    require(mount_matches(base, state), 'mount_identity_mismatch')
    require(arm_root.resolve() == arm_root and read_bytes(arm_root / 'fixture-owned', 128).decode().strip() == state['owner'])
    value = read_native_environment_payload(arm_root)
    identity = native_identity(value)
    try:
        inventory = finite_environment(value)
    except CaptureError as error:
        if error.code not in OPTIONAL_METADATA_REASONS:
            raise
        return {'state': 'version_recorded', 'resource_acceptance': False, 'identity': identity, 'inventory': None,
                'inventory_status': {'state': 'needs_review', 'reason': error.code}}
    return {'state': 'recorded', 'resource_acceptance': False, 'identity': identity, 'inventory': inventory,
            'inventory_status': {'state': 'recorded', 'reason': None}}


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
    return {"state": "ready", "resource_acceptance": False, "storage_budget_bytes": BUDGET, "per_core_limit_bytes": CORE_LIMIT}


def process_identity(pid, executable):
    require(isinstance(pid, int) and 1 < pid < 2147483648, "process_identity_unavailable")
    proc = Path(f"/proc/{pid}")
    fields = (proc / "stat").read_text().rsplit(")", 1)[1].split()
    start = int(fields[19])
    target = os.readlink(proc / "exe")
    require(target == executable, "process_identity_mismatch")
    command = (proc / "cmdline").read_bytes()
    pool = "fixture_status" if command.startswith(b"php-fpm: pool fixture_status\0") else "fixture" if command.startswith(b"php-fpm: pool fixture\0") else None
    require(pool is not None, "process_identity_mismatch")
    info = os.stat(proc / "exe")
    return {"pid": pid, "start_ticks": start, "pool": pool, "exe_identity": [info.st_dev, info.st_ino]}


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
    for row in workers:
        row['started_epoch'] = boot + row['start_ticks'] / ticks
    value = {"arm": arm, "executable": executable, "build_id": build_id(executable),
             "executable_sha256": hashlib.sha256(read_bytes(executable, 32 * 1024 * 1024)).hexdigest(), "workers": workers,
             "started_at": int(time.time()), "resource_acceptance": False}
    write_json(base / f"inventory-{arm}.json", value)
    return {"state": "recorded", "workers": len(workers), "resource_acceptance": False}


def gdb_extract():
    # GDB imports this same trusted helper. All debugger output is captured and discarded.
    import gdb
    campaign, arm_root, base = context()
    data = json.loads(read_bytes(base / "gdb-input.json", 16384))
    output = {"state": "needs_review", "reason": "gdb_unavailable", "frames": [], "resource_acceptance": False}
    try:
        exe, core = data["executable"], data["core"]
        require(exe == "/usr/sbin/php-fpm8.4" and Path(core).parent == base / "cores" and re.fullmatch(r"core\.[0-9]+\.(?:6|7|11)\.[0-9]+", Path(core).name))
        gdb.execute("file " + exe, to_string=True)
        gdb.execute("core-file " + core, to_string=True)
        require(gdb.selected_inferior().pid == data["pid"], "core_pid_signal_mismatch")
        frame = gdb.newest_frame()
        while frame is not None and len(output["frames"]) < FRAME_LIMIT:
            module_path = gdb.solib_name(frame.pc()) or exe
            module = native_module(module_path)
            identity = None
            if module != "unknown":
                try:
                    identity = build_id(str(Path(module_path).resolve()))
                except (CaptureError, OSError):
                    pass
            output["frames"].append({"index": len(output["frames"]), "symbol": native_symbol(frame.name()),
                                      "module": module, "build_id": identity})
            frame = frame.older()
        output.update({"state": "captured" if output["frames"] and output["frames"][0]["symbol"] != "unknown" else "needs_review",
                       "reason": "bounded_frames" if frame is not None else "symbol_unknown" if not output["frames"] or output["frames"][0]["symbol"] == "unknown" else None,
                       "frame_bound": frame is not None, "pid": data["pid"], "signal": data["signal"]})
    except BaseException:
        output.update({"state": "needs_review", "reason": "gdb_unwind_incomplete"})
    write_json(base / "gdb-output.json", output)


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
              "inventory_status": {'state': 'needs_review', 'reason': 'inventory_missing'}}
    try:
        result['inventory'] = read_native_environment(arm_root)
        result['inventory_status'] = {'state': 'recorded', 'reason': None}
    except CaptureError as error:
        # Optional metadata cannot suppress independently verified native cores.
        result['inventory_status'] = {'state': 'needs_review', 'reason': error.code}
    log = arm_root / 'private/fpm.log'
    fd = os.open(log, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        size = os.fstat(fd).st_size
        tail = os.pread(fd, min(65536, size), max(0, size - 65536)).decode('utf-8', errors='replace')
    finally:
        os.close(fd)
    for item in sorted(cores):
        match = re.fullmatch(r"core\.([0-9]+)\.(6|7|11)\.([0-9]+)", item.name)
        require(match and item.lstat().st_uid == 0 and stat.S_ISREG(item.lstat().st_mode)
                and not item.is_symlink() and item.stat().st_size <= CORE_LIMIT, "core_identity_mismatch")
        pid, signal, at = map(int, match.groups())
        require(pid in workers and facts["started_at"] - 2 <= at <= int(time.time()) + 2, "core_identity_mismatch")
        lifetimes = re.findall(r'\[pool fixture(?:_status)?\] child ' + str(pid) + r' exited on signal ' + str(signal)
                               + r' \([^\r\n)]{1,64}\) after ([0-9]+(?:\.[0-9]+)?) seconds from start', tail)
        require(len(lifetimes) == 1 and abs(at - (workers[pid]['started_epoch'] + float(lifetimes[0]))) <= 3,
                'core_process_lifetime_mismatch')
        receipt = {"pid": pid, "signal": signal, "state": "needs_review", "reason": "core_bound", "frames": [], "resource_acceptance": False}
        if len(result["cores"]) < 2:
            try:
                require(item.stat().st_size < CORE_LIMIT, "core_truncated_or_bound")
                core_identity(item, pid, signal, exe)
                write_json(base / "gdb-input.json", {"core": str(item), "executable": exe, "pid": pid, "signal": signal})
                (base / "gdb-output.json").unlink(missing_ok=True)
                command = ["gdb", "-q", "-nx", "-nh", "--batch", "-iex", "set auto-load off", "-iex", "set debuginfod enabled off",
                           "-ex", "python import gdb", "-ex", "source " + str(Path(__file__).resolve())]
                run(command, 30)
                receipt = json.loads(read_bytes(base / "gdb-output.json", 32768))
            except CaptureError as error:
                receipt["reason"] = error.code
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
    result["state"] = "needs_review" if alive or result["inventory"] is None or any(row["state"] != "captured" or row.get('frame_bound') for row in result["cores"]) else "crash_captured" if cores else "no_crash_observed"
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
        require(len(args) == 1 and args[0] in ("setup", "inventory", "preflight", "collect", "cleanup"), "command_unavailable")
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
    print(json.dumps(output, separators=(",", ":")))
    return 1 if output["state"] == "needs_review" else 0


if __name__ == "__main__":
    if "gdb" in sys.modules:
        try:
            gdb_extract()
        except BaseException:
            pass
    else:
        sys.exit(main())
