"""Proof-only exact extracted filesystem helper; no production paths or import I/O."""
import fcntl
import hashlib
import os
from pathlib import Path
import re
import stat

ADMISSION_DIRECTORY = os.environ.get("PINOVA_ADMISSION_PROOF_ROOT", "") + "/admission"
ADMISSION_TOKEN = b"pinova-deploy-open-v1\n"

class Failure(Exception):
    pass


def need(value, reason):
    if not value:
        raise Failure(reason)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def signature(info):
    return (info.st_dev, info.st_ino, info.st_uid, stat.S_IMODE(info.st_mode),
            info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def retain_parent_fd(fd):
    try:
        return fcntl.fcntl(fd, fcntl.F_DUPFD_CLOEXEC, 10)
    finally:
        os.close(fd)


def admission_identity(info):
    return [info.st_dev, info.st_ino, info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)]


def rename_admission(directory_fd, before, after):
    """One same-directory, no-replace rename; never overwrite an unknown entry."""
    import ctypes
    library = ctypes.CDLL(None, use_errno=True)
    function = library.renameat2
    function.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
    function.restype = ctypes.c_int
    if function(directory_fd, before.encode("ascii"), directory_fd, after.encode("ascii"), 1):
        raise OSError(ctypes.get_errno(), "admission_rename_failed")


class PersistentAdmission:
    """Root-owned persistent absence of open contains failure after lease release."""
    def __init__(self, config):
        self.spec = config["admission"]
        self.path = Path(self.spec["path"])
        self.held_name = ".held-" + config["operation_id"]
        self.fd = None
        need(str(self.path) == ADMISSION_DIRECTORY and self.path.resolve() == self.path
             and re.fullmatch("[a-f0-9]{32}", config["operation_id"])
             and self.spec["token_sha256"] == sha(ADMISSION_TOKEN), "admission_scope")
        try:
            self.fd = retain_parent_fd(os.open(self.path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW))
            self.verify_directory()
        except BaseException:
            self.close_fd()
            raise

    def verify_directory(self):
        before = os.fstat(self.fd)
        current = os.stat(self.path, follow_symlinks=False)
        need(stat.S_ISDIR(before.st_mode) and stat.S_ISDIR(current.st_mode)
             and admission_identity(before) == self.spec["directory_identity"]
             and admission_identity(current) == self.spec["directory_identity"]
             and before.st_uid == before.st_gid == 0 and stat.S_IMODE(before.st_mode) == 0o755,
             "admission_directory_changed")

    def absent(self, name):
        try:
            os.stat(name, dir_fd=self.fd, follow_symlinks=False)
        except FileNotFoundError:
            return True
        return False

    def verify_token(self, name):
        fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=self.fd)
        try:
            before = os.fstat(fd)
            need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1
                 and admission_identity(before) == self.spec["open_identity"]
                 and before.st_uid == before.st_gid == 0 and stat.S_IMODE(before.st_mode) == 0o644
                 and before.st_size == len(ADMISSION_TOKEN), "admission_token_changed")
            value = os.read(fd, len(ADMISSION_TOKEN) + 1)
            after = os.fstat(fd)
            named = os.stat(name, dir_fd=self.fd, follow_symlinks=False)
            need(value == ADMISSION_TOKEN and signature(before) == signature(after)
                 and signature(after) == signature(named), "admission_token_changed")
        finally:
            os.close(fd)

    def close_admission(self):
        self.verify_directory()
        self.verify_token("open")
        need(self.absent(self.held_name), "admission_foreign_hold")
        rename_admission(self.fd, "open", self.held_name)
        # If fsync/verification fails, open stays absent; never reopen in cleanup.
        os.fsync(self.fd)
        self.verify_hold()

    def verify_hold(self):
        self.verify_directory()
        need(self.absent("open"), "admission_reopened_or_foreign")
        self.verify_token(self.held_name)

    def binding(self):
        self.verify_hold()
        return {"directory_identity": self.spec["directory_identity"],
                "open_identity": self.spec["open_identity"], "held_name": self.held_name,
                "token_sha256": self.spec["token_sha256"]}

    def verify_binding(self, binding):
        need(binding == self.binding(), "admission_lease_mismatch")

    def reopen_verified(self):
        """Caller has verified reap, package, cache, ownership and native-marker cleanup."""
        self.verify_hold()
        rename_admission(self.fd, self.held_name, "open")
        try:
            os.fsync(self.fd)
            self.verify_directory()
            self.verify_token("open")
            need(self.absent(self.held_name), "admission_hold_reappeared")
        except BaseException:
            # A filesystem failing during this final commit cannot be guaranteed safe.
            # Reclose only our exact token; never rename a foreign replacement.
            try:
                self.verify_directory()
                self.verify_token("open")
                need(self.absent(self.held_name), "admission_foreign_hold")
                rename_admission(self.fd, "open", self.held_name)
                os.fsync(self.fd)
                self.verify_hold()
            except BaseException:
                raise Failure("operator_required_admission_containment_unknown") from None
            raise Failure("admission_reopen_failed_reclosed") from None

    def close_fd(self):
        if self.fd is not None:
            os.close(self.fd)
            self.fd = None


def verify_admission_binding(config, binding):
    guard = PersistentAdmission(config)
    try:
        guard.verify_binding(binding)
    finally:
        guard.close_fd()
