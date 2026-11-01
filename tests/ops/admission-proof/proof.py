"""NEVER MERGE: seven fixed native admission cases on a disposable hosted runner."""
import ctypes
import fcntl
import hashlib
import http.client
import http.server
import json
import os
from pathlib import Path
import pwd
import re
import select
import signal
import socket
import stat
import subprocess
import sys
import threading
import time

import admission

HERE = Path(__file__).resolve().parent
BRANCH = "refs/heads/proof/native-admission-20261101"
ROUTES = ("main", "mcp", "chat", "named")
NAMES = ("exact_open", "persistent_close_cache", "invalid_permissions_tokens",
         "waiter_after_lock", "parent_death", "verified_reopen_refusals", "unrelated_isolation")


class Check(Exception):
    pass


def require(value):
    if not value:
        raise Check()


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def write_new(path, value, mode=0o600):
    fd = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY | os.O_NOFOLLOW, mode)
    try:
        os.write(fd, value)
        os.fsync(fd)
    finally:
        os.close(fd)


def identity(pid):
    def fields():
        raw = Path(f"/proc/{pid}/stat").read_text().rsplit(")", 1)[1].split()
        return {"pid": pid, "start_ticks": int(raw[19]), "pgid": int(raw[2]), "session": int(raw[3])}
    before = fields()
    try:
        exe = os.stat(f"/proc/{pid}/exe")
        executable = {"exe_device": exe.st_dev, "exe_inode": exe.st_ino}
    except FileNotFoundError:
        # A launched child can already be a zombie; its unreaped stat identity still exists.
        executable = {"exe_device": None, "exe_inode": None}
    require(fields() == before)
    return before | executable


def core_identity(ident):
    return {key: ident[key] for key in ("pid", "start_ticks", "pgid", "session")}


def same_process(ident):
    try:
        return core_identity(identity(ident["pid"])) == core_identity(ident)
    except (FileNotFoundError, ProcessLookupError):
        return False


def direct_children(pid):
    path = Path(f"/proc/{pid}/task/{pid}/children")
    try:
        with path.open() as handle:
            raw = handle.read(8193)
        require(len(raw) <= 8192)
        values = raw.split()
        require(len(values) <= 128 and all(value.isdigit() for value in values))
        return [int(value) for value in values]
    except FileNotFoundError:
        return []


def hosted_scope():
    run = os.environ.get("GITHUB_RUN_ID", "")
    require(os.getuid() == 0 and run.isdigit() and os.environ.get("GITHUB_RUN_ATTEMPT") == "1")
    require(os.environ.get("GITHUB_ACTIONS") == "true"
            and os.environ.get("RUNNER_ENVIRONMENT") == "github-hosted"
            and os.environ.get("GITHUB_REF") == BRANCH)
    root = Path(os.environ.get("PINOVA_ADMISSION_PROOF_ROOT", ""))
    require(str(root) == f"/tmp/pinova-admission-{run}-1" and root.resolve() == root)
    require(root.stat().st_uid == 0 and stat.S_IMODE(root.stat().st_mode) == 0o755)
    contract = json.loads((HERE / "contract.json").read_text())
    require(contract["branch"] == BRANCH and contract["fixed_cases"] == list(NAMES))
    for name, expected in contract["sources"].items():
        require(re.fullmatch(r"[a-zA-Z0-9_.-]+", name) and not (HERE / name).is_symlink())
        require(digest(HERE / name) == expected)
    head = subprocess.check_output(["git", "-c", "safe.directory=" + str(HERE.parents[2]), "rev-parse", "HEAD"], cwd=HERE,
                                   stderr=subprocess.DEVNULL, timeout=2).decode().strip()
    require(re.fullmatch("[a-f0-9]{40}", head) and head == os.environ.get("GITHUB_SHA"))
    return root, contract, head


def child_mode():
    root, _, _ = hosted_scope()
    require(len(sys.argv) == 3 and sys.argv[2].isdigit() and os.getppid() == int(sys.argv[2]))
    config = json.loads((root / "private/fixture.json").read_text())
    lock = os.open(root / "cron.lock", os.O_RDWR | os.O_NOFOLLOW)
    fcntl.flock(lock, fcntl.LOCK_EX)
    gate = admission.PersistentAdmission(config)
    gate.close_admission()
    value = {"ready": True, "identity": identity(os.getpid()), "binding": gate.binding()}
    os.write(1, json.dumps(value, separators=(",", ":")).encode() + b"\n")
    # Parent owns this process; only its exact identity may be terminated.
    signal.alarm(15)
    while True:
        signal.pause()


class Backend(http.server.ThreadingHTTPServer):
    daemon_threads = True
    block_on_close = False


class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def do_GET(self):
        route = self.path.removeprefix("/")
        if route not in (*ROUTES, "unrelated", "error503"):
            self.send_error(400)
            return
        with self.server.counter_lock:
            self.server.hits[route] += 1
        self.send_response(200)
        self.send_header("Content-Length", "2")
        self.end_headers()
        self.wfile.write(b"ok")

    def log_message(self, *_):
        pass


class Proof:
    def __init__(self, root, contract, head):
        self.root, self.contract = root, contract
        self.deadline = time.monotonic() + 180
        self.processes, self.owned, self.sessions, self.locks, self.connections = [], {}, {}, [], []
        self.identity_unknown = set()
        self.backend = self.thread = self.gate = None
        self.observations, self.isolation = [], []
        self.receipt = {"protocol": "native-admission-v1", "never_merge": True,
                        "shipping_fix": False, "resource_acceptance": False,
                        "deployment_ready": False, "drain_proven": False,
                        "production_reader_retirement_proven": False,
                        "reboot_durability_proven": False, "source_commit": head,
                        "base_commit": contract["base_commit"], "sources": contract["sources"],
                        "clock": {"utc_start": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                                  "monotonic_start_ns": time.monotonic_ns()},
                        "cases": [{"name": n, "outcome": "unexecuted"} for n in NAMES],
                        "cleanup": "unexecuted", "accepted": False}

    def budget(self):
        require(time.monotonic() < self.deadline)

    def spawn(self, args, **kwargs):
        self.budget()
        ticks = os.sysconf("SC_CLK_TCK")
        before = int(time.clock_gettime(time.CLOCK_BOOTTIME) * ticks)
        proc = subprocess.Popen(args, start_new_session=True, **kwargs)
        self.processes.append(proc)
        self.sessions[proc.pid] = {"leader_pid": proc.pid, "birth_ticks_lower": before,
                                  "birth_ticks_upper": int(time.clock_gettime(time.CLOCK_BOOTTIME) * ticks),
                                  "leader_start_ticks": None}
        try:
            ident = identity(proc.pid)
            launch = self.sessions[proc.pid]
            require(ident["session"] == ident["pgid"] == proc.pid
                    and launch["birth_ticks_lower"] <= ident["start_ticks"] <= launch["birth_ticks_upper"])
            launch["leader_start_ticks"] = ident["start_ticks"]
            self.owned[proc.pid] = ident
        except BaseException:
            self.identity_unknown.add(proc.pid)
            raise Check() from None
        return proc

    def bound_session(self, ident):
        launch = self.sessions.get(ident["session"])
        return (launch is not None and launch["leader_start_ticks"] is not None
                and ident["pgid"] == ident["session"]
                and ident["start_ticks"] >= launch["leader_start_ticks"])

    def inventory(self):
        """Read only our direct/adopted child tree, never the host process inventory."""
        queue, seen, direct = [(os.getpid(), 0)], set(), []
        while queue:
            parent, depth = queue.pop()
            require(depth <= 16 and len(seen) <= 128)
            if parent != os.getpid() and not same_process(self.owned[parent]):
                continue
            for pid in direct_children(parent):
                if parent == os.getpid():
                    direct.append(pid)
                if pid in seen:
                    continue
                seen.add(pid)
                try:
                    ident = identity(pid)
                    if not self.bound_session(ident):
                        self.identity_unknown.add(pid)
                        continue
                    self.owned[pid] = ident
                    queue.append((pid, depth + 1))
                except (FileNotFoundError, ProcessLookupError):
                    continue
                except BaseException:
                    self.identity_unknown.add(pid)
        return direct

    def signal_owned(self, ident, value):
        require(self.bound_session(ident) and ident["pid"] not in self.identity_unknown)
        try:
            fd = os.pidfd_open(ident["pid"], 0)
        except ProcessLookupError:
            return
        try:
            current = identity(ident["pid"])
            require(core_identity(current) == core_identity(ident) and self.bound_session(current))
            signal.pidfd_send_signal(fd, value, None, 0)
        except (FileNotFoundError, ProcessLookupError):
            pass
        finally:
            os.close(fd)

    def counts(self):
        with self.backend.counter_lock:
            return dict(self.backend.hits)

    def request(self, route, expected, unrelated=False, connection=None):
        self.budget()
        before = self.counts()
        conn = connection or http.client.HTTPConnection("127.0.0.1", 18081 if unrelated else 18080, timeout=2)
        if connection is None:
            self.connections.append(conn)
        conn.request("GET", "/" + route)
        response = conn.getresponse()
        body = response.read(257)
        after = self.counts()
        delta = {name: after[name] - before[name] for name in after}
        self.observations.append({"route": route, "status": response.status, "backend_delta": delta})
        require(response.status == expected and len(body) <= 256)
        require(delta == {name: int(name == route and expected == 200) for name in after})
        if connection is None:
            conn.close()
        return conn

    def unrelated(self, phase):
        self.request("unrelated", 200, unrelated=True)
        self.isolation.append(phase)

    def lock(self):
        fd = os.open(self.root / "cron.lock", os.O_RDWR | os.O_NOFOLLOW)
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        self.locks.append(fd)
        return fd

    def unlock(self, fd):
        fcntl.flock(fd, fcntl.LOCK_UN)
        os.close(fd)
        self.locks.remove(fd)

    def cron(self, name, expected, pending=False):
        number = len(self.processes)
        marker = self.root / f"private/work-{number}"
        ready = self.root / f"private/ready-{number}"
        script = ("#!/bin/bash\nset -eu\nexec 9>\"$PINOVA_ADMISSION_PROOF_ROOT/cron.lock\"\n"
                  "printf ready >\"$1\"\n/usr/bin/flock -x 9\n"
                  + (HERE / f"{name}-guard.sh").read_text()
                  + "printf work >\"$2\"\n")
        path = self.root / f"private/cron-{number}.sh"
        write_new(path, script.encode())
        proc = self.spawn(["/bin/bash", str(path), str(ready), str(marker)],
                          stdout=self.raw, stderr=self.raw)
        if pending:
            until = min(self.deadline, time.monotonic() + 2)
            while not ready.exists() and time.monotonic() < until:
                require(proc.poll() is None)
                time.sleep(0.01)
            require(ready.read_bytes() == b"ready" and proc.poll() is None and not marker.exists())
            self.inventory()
            return proc, marker
        self.finish_cron(proc, marker, expected)

    def finish_cron(self, proc, marker, expected):
        code = proc.wait(timeout=3)
        self.observations.append({"wrapper_exit": code, "work_marker": marker.exists()})
        require(code == expected and marker.exists() == (expected == 0))

    def all_cron(self, expected):
        for name in ("general", "email", "otp"):
            self.cron(name, expected)

    def setup(self):
        self.budget()
        os.umask(0o022)
        self.receipt["pidfd_prerequisite"] = "unavailable_or_unknown"
        require(hasattr(os, "pidfd_open") and hasattr(signal, "pidfd_send_signal"))
        probe = os.pidfd_open(os.getpid(), 0)
        os.close(probe)
        self.receipt["pidfd_prerequisite"] = "available"
        require(ctypes.CDLL(None).prctl(36, 1, 0, 0, 0) == 0)  # PR_SET_CHILD_SUBREAPER
        (self.root / "private").mkdir(mode=0o700)
        (self.root / "worker-tmp").mkdir(mode=0o755)
        worker = pwd.getpwnam("www-data")
        for name in ("client-body", "proxy", "fastcgi", "uwsgi", "scgi"):
            temporary = self.root / "worker-tmp" / name
            temporary.mkdir(mode=0o700)
            os.chown(temporary, worker.pw_uid, worker.pw_gid)
        self.raw = open(self.root / "private/runtime.log", "xb", buffering=0)
        (self.root / "admission").mkdir(mode=0o755)
        write_new(self.root / "admission/open", admission.ADMISSION_TOKEN, 0o644)
        write_new(self.root / "cron.lock", b"", 0o600)
        self.config = {"operation_id": "0123456789abcdef0123456789abcdef",
                       "admission": {"path": str(self.root / "admission"),
                       "directory_identity": admission.admission_identity((self.root / "admission").stat()),
                       "open_identity": admission.admission_identity((self.root / "admission/open").stat()),
                       "token_sha256": hashlib.sha256(admission.ADMISSION_TOKEN).hexdigest()}}
        write_new(self.root / "private/fixture.json", json.dumps(self.config).encode())
        self.gate = admission.PersistentAdmission(self.config)
        self.backend = Backend(("127.0.0.1", 18082), Handler)
        self.backend.hits = {r: 0 for r in (*ROUTES, "unrelated", "error503")}
        self.backend.counter_lock = threading.Lock()
        self.thread = threading.Thread(target=self.backend.serve_forever, kwargs={"poll_interval": 0.1}, daemon=True)
        self.thread.start()
        fragment = (HERE / "admission.conf.in").read_text()
        require(fragment.count("__ADMISSION_OPEN__") == 1 and fragment.count("open_file_cache off;") == 1)
        fragment = fragment.replace("__ADMISSION_OPEN__", str(self.root / "admission/open"))
        write_new(self.root / "private/admission.conf", fragment.encode())
        config = f'''user www-data;
worker_processes 1;
daemon off;
pid {self.root}/private/nginx.pid;
error_log {self.root}/private/nginx.log warn;
events {{ worker_connections 64; }}
http {{
  access_log off;
  client_body_temp_path {self.root}/worker-tmp/client-body;
  proxy_temp_path {self.root}/worker-tmp/proxy;
  fastcgi_temp_path {self.root}/worker-tmp/fastcgi;
  uwsgi_temp_path {self.root}/worker-tmp/uwsgi;
  scgi_temp_path {self.root}/worker-tmp/scgi;
  open_file_cache max=32 inactive=600s;
  open_file_cache_valid 600s;
  open_file_cache_errors on;
  error_page 503 = @error503;
  server {{
    listen 127.0.0.1:18080;
    server_name target.fixture;
    include {self.root}/private/admission.conf;
    location = /main {{ proxy_pass http://127.0.0.1:18082/main; }}
    location = /mcp {{ proxy_pass http://127.0.0.1:18082/mcp; }}
    location = /chat {{ proxy_pass http://127.0.0.1:18082/chat; }}
    location = /named {{ error_page 404 = @named; return 404; }}
    location @named {{ rewrite ^ /named break; proxy_pass http://127.0.0.1:18082; }}
    location @error503 {{ rewrite ^ /error503 break; proxy_pass http://127.0.0.1:18082; }}
  }}
  server {{
    listen 127.0.0.1:18081;
    server_name unrelated.fixture;
    location = /unrelated {{ proxy_pass http://127.0.0.1:18082/unrelated; }}
    location @error503 {{ rewrite ^ /error503 break; proxy_pass http://127.0.0.1:18082; }}
  }}
}}
'''
        write_new(self.root / "private/nginx.conf", config.encode())
        binary = Path(os.environ["PINOVA_NGINX_BINARY"])
        require(str(binary) == str(self.root / "package/root/usr/sbin/nginx") and binary.is_file())
        self.receipt["nginx_binary_sha256"] = digest(binary)
        packages = list((self.root / "package").glob("nginx_*.deb"))
        require(len(packages) == 1)
        self.receipt["deb_sha256"] = digest(packages[0])
        version = subprocess.run([str(binary), "-v"], capture_output=True, timeout=2)
        require(version.returncode == 0)
        matched = re.fullmatch(rb"nginx version: nginx/([0-9]+\.[0-9]+\.[0-9]+)(?: \(Ubuntu\))?\n", version.stderr)
        require(matched is not None)
        self.receipt["nginx_version"] = matched[1].decode("ascii")
        args = [str(binary), "-p", str(self.root) + "/", "-c", str(self.root / "private/nginx.conf")]
        subprocess.run([*args, "-t"], stdout=self.raw, stderr=self.raw, check=True, timeout=3)
        self.nginx = self.spawn(args, stdout=self.raw, stderr=self.raw)
        until = min(self.deadline, time.monotonic() + 5)
        ready = False
        worker_uid = pwd.getpwnam("www-data").pw_uid
        while time.monotonic() < until:
            require(self.nginx.poll() is None)
            try:
                with socket.create_connection(("127.0.0.1", 18080), timeout=0.1):
                    self.inventory()
                    children = direct_children(self.nginx.pid)
                    ready = any(os.stat(f"/proc/{pid}").st_uid == worker_uid for pid in children)
                    if ready:
                        break
            except OSError:
                pass
            time.sleep(0.02)
        require(ready)
        self.inventory()
        require(any(os.stat(f"/proc/{pid}").st_uid == worker_uid
                    for pid in direct_children(self.nginx.pid)))
        self.receipt["admission_identity"] = self.config["admission"] | {"path": "neutral-run-root/admission"}

    def case1(self):
        self.keepalive = http.client.HTTPConnection("127.0.0.1", 18080, timeout=2)
        self.connections.append(self.keepalive)
        self.request("main", 200, connection=self.keepalive)
        self.keepalive_socket = self.keepalive.sock
        require(self.keepalive_socket is not None)
        for route in ROUTES[1:]:
            self.request(route, 200)
        self.all_cron(0)
        self.unrelated("open")

    def case2(self):
        self.gate.close_admission()
        self.gate.verify_binding(self.gate.binding())
        require(self.keepalive.sock is self.keepalive_socket)
        self.request("main", 503, connection=self.keepalive)
        require(self.keepalive.sock is self.keepalive_socket)
        for route in ROUTES:  # Fixed cache-observation sequence; no acceptance retry.
            self.request(route, 503)
        self.all_cron(75)
        self.unrelated("hold")

    def case3(self):
        self.gate.reopen_verified()
        directory, token = self.root / "admission", self.root / "admission/open"
        os.chmod(directory, 0o700)
        self.request("main", 503)
        self.unrelated("traversal_denied")
        os.chmod(directory, 0o755)
        self.gate.verify_token("open")
        for bad in (b"x" * len(admission.ADMISSION_TOKEN), b"bad"):
            token.write_bytes(bad)
            self.all_cron(75)
            token.write_bytes(admission.ADMISSION_TOKEN)
            self.gate.verify_token("open")
        saved = directory / "saved-token"
        os.rename(token, saved)
        token.symlink_to("saved-token")
        self.all_cron(75)
        require(token.is_symlink() and os.readlink(token) == "saved-token")
        token.unlink()
        os.rename(saved, token)
        os.chown(token, pwd.getpwnam("www-data").pw_uid, 0)
        self.all_cron(75)
        os.chown(token, 0, 0)
        self.gate.verify_token("open")

    def case4(self):
        fd = self.lock()
        proc, marker = self.cron("general", 75, pending=True)
        self.gate.close_admission()
        self.unlock(fd)
        self.finish_cron(proc, marker, 75)
        self.gate.verify_hold()
        self.receipt["case4_boundary"] = {"parent_lock_held_before_hold": True,
                                         "wrapper_started_before_hold": True,
                                         "guard_after_final_lock_source_verified": True,
                                         "kernel_waiter_state_attested": False}

    def case5(self):
        self.gate.reopen_verified()
        proc = self.spawn([sys.executable, "-B", str(HERE / "proof.py"), "--hold-child", str(os.getpid())],
                          stdout=subprocess.PIPE, stderr=self.raw)
        require(select.select([proc.stdout], [], [], 4)[0])
        ready = json.loads(proc.stdout.readline(4097))
        require(ready["ready"] is True and core_identity(ready["identity"]) == core_identity(self.owned[proc.pid]))
        self.gate.verify_binding(ready["binding"])
        require(same_process(self.owned[proc.pid]))
        self.signal_owned(self.owned[proc.pid], signal.SIGKILL)
        require(proc.wait(timeout=3) == -signal.SIGKILL)
        fd = self.lock()  # Real lease became free, but the persistent admission stays closed.
        self.unlock(fd)
        self.gate.verify_hold()
        self.all_cron(75)
        self.request("main", 503)
        self.unrelated("parent_dead")
        self.receipt["killed_fixture_parent"] = ready["identity"]

    def refused(self, action, expected):
        try:
            action()
        except admission.Failure as exc:
            require(str(exc) == expected)
        else:
            raise Check()

    def case6(self):
        directory, token = self.root / "admission", self.root / "admission/open"
        self.gate.verify_hold()  # Unverified recovery deliberately does not call reopen.
        self.gate.close_fd()
        require(not token.exists())
        self.gate = admission.PersistentAdmission(self.config)
        self.gate.verify_hold()
        held = directory / self.gate.held_name
        foreign = b"foreign-state\n"
        write_new(token, foreign)
        foreign_id = admission.signature(token.stat())
        self.refused(self.gate.reopen_verified, "admission_reopened_or_foreign")
        require(admission.signature(token.stat()) == foreign_id and token.read_bytes() == foreign)
        try:
            admission.rename_admission(self.gate.fd, self.gate.held_name, "open")
        except FileExistsError:
            pass
        else:
            raise Check()
        require(admission.signature(token.stat()) == foreign_id and held.exists())
        token.unlink()  # Only the exact fixture-owned foreign entry just checked.
        self.gate.verify_hold()
        self.gate.reopen_verified()
        self.unrelated("reopen")
        for route in ROUTES:
            self.request(route, 200)
        self.all_cron(0)
        write_new(held, foreign)
        held_id = admission.signature(held.stat())
        self.refused(self.gate.close_admission, "admission_foreign_hold")
        require(admission.signature(held.stat()) == held_id and held.read_bytes() == foreign)
        held.unlink()
        saved = directory / "original-token"
        os.rename(token, saved)
        write_new(token, admission.ADMISSION_TOKEN, 0o644)
        replacement_id = admission.signature(token.stat())
        self.refused(self.gate.close_admission, "admission_token_changed")
        require(admission.signature(token.stat()) == replacement_id and not held.exists())
        token.unlink()
        os.rename(saved, token)
        self.gate.verify_token("open")
        self.receipt["unknown_syscall_fsync_policy"] = "unresolved_hold_or_explicit_containment_unknown"
        self.receipt["fault_injected_fsync_tested_here"] = False

    def case7(self):
        require(self.isolation == ["open", "hold", "traversal_denied", "parent_dead", "reopen"])
        self.unrelated("final")
        require(self.counts()["error503"] == 0)
        self.receipt["unrelated_phases"] = self.isolation

    def cleanup(self):
        signal.alarm(15)
        deadline = time.monotonic() + 12
        ok = True

        def attempt(action):
            nonlocal ok
            try:
                return action()
            except BaseException:
                ok = False
                return None

        def reap():
            # Popen retains responsibility for its direct children; adopted children are reaped explicitly.
            direct = attempt(self.inventory)
            if direct is None:
                return None
            launches = {proc.pid for proc in self.processes}
            for proc in self.processes:
                attempt(proc.poll)
            for pid in direct:
                if pid not in launches:
                    def wait_owned():
                        try:
                            os.waitpid(pid, os.WNOHANG)
                        except ChildProcessError:
                            pass
                    attempt(wait_owned)
            return attempt(self.inventory)

        if not self.receipt["accepted"] and self.gate is not None:
            def hold():
                if (self.root / "admission/open").exists():
                    self.gate.close_admission()
                self.gate.verify_hold()
                return True
            self.receipt["failure_containment"] = "held_verified" if attempt(hold) else "unknown"
        for conn in self.connections:
            attempt(conn.close)
        for fd in list(self.locks):
            attempt(lambda fd=fd: self.unlock(fd))
        attempt(self.inventory)
        # Graceful requests only address fresh PID/start/session bindings through a pidfd.
        for proc in reversed(self.processes):
            if attempt(proc.poll) is None:
                ident = self.owned.get(proc.pid)
                if ident is None or proc.pid in self.identity_unknown:
                    self.identity_unknown.add(proc.pid)
                    ok = False
                    continue
                value = signal.SIGQUIT if proc is getattr(self, "nginx", None) else signal.SIGTERM
                attempt(lambda ident=ident, value=value: self.signal_owned(ident, value))
        grace = min(deadline, time.monotonic() + 4)
        while time.monotonic() < grace:
            direct = reap()
            if direct == []:
                break
            time.sleep(0.02)
        # This inventory also captures children newly adopted after a launch leader exits.
        attempt(self.inventory)
        for ident in list(self.owned.values()):
            live = attempt(lambda ident=ident: same_process(ident))
            if ident["pid"] not in self.identity_unknown and live:
                attempt(lambda ident=ident: self.signal_owned(ident, signal.SIGKILL))
        while time.monotonic() < deadline:
            direct = reap()
            if direct == []:
                break
            # An adopted child's own descendants may only become direct after the preceding kill.
            for ident in list(self.owned.values()):
                live = attempt(lambda ident=ident: same_process(ident))
                if ident["pid"] not in self.identity_unknown and live:
                    attempt(lambda ident=ident: self.signal_owned(ident, signal.SIGKILL))
            time.sleep(0.02)
        for proc in self.processes:
            if proc.stdout:
                attempt(proc.stdout.close)
        if self.backend is not None:
            attempt(self.backend.shutdown)
            attempt(self.backend.server_close)
            if self.thread is not None:
                attempt(lambda: self.thread.join(timeout=1))
                ok = ok and not self.thread.is_alive()
        if self.gate is not None:
            attempt(self.gate.close_fd)
        if hasattr(self, "raw"):
            attempt(self.raw.close)
        remaining = attempt(self.inventory)
        live = [attempt(lambda ident=ident: same_process(ident)) for ident in self.owned.values()]
        ok = ok and remaining == [] and not self.identity_unknown and all(value is False for value in live)
        self.receipt["owned_processes"] = list(self.owned.values())
        self.receipt["launched_sessions"] = list(self.sessions.values())
        self.receipt["unknown_process_ids"] = sorted(self.identity_unknown)
        self.receipt["remaining_own_child_ids"] = remaining
        self.receipt["cleanup"] = "passed" if ok else "failed"
        self.receipt["accepted"] = self.receipt["accepted"] and ok
        signal.alarm(0)

    def run(self):
        def expired(*_):
            raise TimeoutError()
        signal.signal(signal.SIGALRM, expired)
        signal.alarm(180)
        active_case, observation_start = None, 0
        try:
            self.setup()
            for index in range(7):
                self.budget()
                before = len(self.observations)
                active_case, observation_start = index, before
                self.receipt["cases"][index]["outcome"] = "failed"
                getattr(self, f"case{index + 1}")()
                self.receipt["cases"][index].update(outcome="passed", observations=self.observations[before:])
            self.receipt["accepted"] = True
        except BaseException as exc:
            if active_case is not None:
                self.receipt["cases"][active_case]["observations"] = self.observations[observation_start:]
            self.receipt["failure_class"] = ("assertion" if isinstance(exc, Check) else
                "helper_refusal" if isinstance(exc, admission.Failure) else
                "deadline" if isinstance(exc, TimeoutError) else
                "os_error" if isinstance(exc, OSError) else "unexpected")
        finally:
            try:
                self.cleanup()
            except BaseException:
                self.receipt["cleanup"] = "failed"
                self.receipt["accepted"] = False
            self.receipt["clock"].update(utc_end=time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                                         monotonic_end_ns=time.monotonic_ns())
            signal.alarm(0)
            write_new(self.root / "receipt.json", (json.dumps(self.receipt, indent=2) + "\n").encode(), 0o644)
        return 0 if self.receipt["accepted"] else 1


if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == "--hold-child":
        child_mode()
    else:
        root, contract, head = hosted_scope()
        sys.exit(Proof(root, contract, head).run())
