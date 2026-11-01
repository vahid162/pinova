"""One hosted, inactive-JIT semantic pair; no application/FPM or release action."""
from pathlib import Path
import hashlib
import json
import os
import re
import resource
import shutil
import signal
import stat
import subprocess
import sys
import time

HERE = Path(__file__).resolve().parent
CONTRACT = json.loads((HERE / "hosted-contract.json").read_text())
LIMITS = CONTRACT["limits"]
START = time.monotonic()
COMMANDS = []


def require(value, reason):
    if not value:
        raise RuntimeError(reason)


def sha(value):
    return hashlib.sha256(value).hexdigest()


def write_json(path, value):
    data = (json.dumps(value, sort_keys=True) + "\n").encode()
    require(len(data) <= LIMITS["receipt_bytes"], "receipt_bound")
    with path.open("xb") as handle:
        handle.write(data)


def inventory(root):
    require(root.is_dir() and root.resolve() == root, "inventory_root")
    rows, total = {}, 0
    for directory, dirs, files in os.walk(root, followlinks=False):
        require(time.monotonic() - START < LIMITS["driver_seconds"], "driver_deadline")
        require(len(dirs) + len(files) + len(rows) <= LIMITS["inventory_files"], "inventory_count")
        for name in dirs:
            require(stat.S_ISDIR((Path(directory) / name).lstat().st_mode), "inventory_directory")
        for name in sorted(files):
            path = Path(directory) / name
            before = path.lstat()
            require(stat.S_ISREG(before.st_mode) and before.st_size <= LIMITS["inventory_file_bytes"], "inventory_file")
            total += before.st_size
            require(total <= LIMITS["inventory_total_bytes"], "inventory_bytes")
            data = path.read_bytes()
            after = path.lstat()
            require((before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns) ==
                    (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns)
                    and len(data) == before.st_size, "inventory_changed_during_read")
            rows[path.relative_to(root).as_posix()] = {
                "mode": stat.S_IMODE(before.st_mode), "size": len(data), "sha256": sha(data)}
    require(rows, "empty_inventory")
    return rows


def inventory_summary(rows):
    return {"files": len(rows), "bytes": sum(row["size"] for row in rows.values()),
            "sha256": sha(json.dumps(rows, sort_keys=True, separators=(",", ":")).encode())}


def run(root, name, command, seconds, cwd):
    # No shell and no retries. Capture only into the private 0700 run directory.
    remaining = LIMITS["driver_seconds"] - (time.monotonic() - START)
    if remaining <= 0:
        result = {"name": name, "state": "not_started_deadline", "exit_code": None,
                  "timed_out": False, "stdout_bytes": 0, "stderr_bytes": 0}
        COMMANDS.append(result)
        return result, None
    stdout, stderr = root / (name + ".stdout"), root / (name + ".stderr")
    def child_limits():
        resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
        resource.setrlimit(resource.RLIMIT_FSIZE, (LIMITS["working_file_bytes"],) * 2)
        resource.setrlimit(resource.RLIMIT_CPU, (seconds + 2, seconds + 2))
    result = {"name": name, "state": "launch_failed", "exit_code": None, "timed_out": False}
    with stdout.open("xb") as out, stderr.open("xb") as err:
        try:
            process = subprocess.Popen(command, cwd=cwd, stdin=subprocess.DEVNULL, stdout=out, stderr=err,
                                       start_new_session=True, preexec_fn=child_limits)
            try:
                process.wait(timeout=min(seconds, remaining))
            except subprocess.TimeoutExpired:
                result["timed_out"] = True
                # This child is still unreaped; its private session ID cannot be reused.
                try:
                    os.killpg(process.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
                process.wait(timeout=5)
            result["exit_code"] = process.returncode
            result["state"] = "passed" if process.returncode == 0 and not result["timed_out"] else "failed"
        except (OSError, subprocess.SubprocessError):
            result["state"] = "process_control_failed"
    for label, path in (("stdout", stdout), ("stderr", stderr)):
        size = path.stat().st_size
        result[label + "_bytes"] = size
        require(size <= LIMITS["command_output_bytes"], "command_output_bound")
        result[label + "_sha256"] = sha(path.read_bytes())
    if result["stderr_bytes"]:
        result["state"] = "unexpected_diagnostics"
    COMMANDS.append(result)
    return result, stdout


def public_receipt(raw, result, variant):
    failed = {"schema": "pinova.carbon-constructor-parity.v1", "state": "failed", "variant": variant,
              "reason": "command_or_receipt_unverified", "shipping_fix": False}
    if raw is None or result["stdout_bytes"] > LIMITS["receipt_bytes"]:
        return failed
    try:
        value = json.loads(raw.read_bytes())
        require(isinstance(value, dict) and value.get("schema") == failed["schema"]
                and value.get("state") in ("complete", "failed") and value.get("shipping_fix") is False,
                "semantic_receipt_shape")
        # Only output produced by the hash-verified synthetic fixture is eligible.
        # A command failure/diagnostic is separately retained and always rejects the pair.
        return value
    except (ValueError, RuntimeError, OSError):
        return failed


def main():
    os.umask(0o077)
    run_id = os.environ.get("GITHUB_RUN_ID", "")
    require(os.environ.get("GITHUB_ACTIONS") == "true"
            and os.environ.get("RUNNER_ENVIRONMENT") == "github-hosted"
            and re.fullmatch(r"[0-9]+", run_id) is not None
            and os.environ.get("GITHUB_RUN_ATTEMPT") == "1"
            and os.environ.get("GITHUB_REF") == "refs/heads/" + CONTRACT["branch"]
            and os.environ.get("PINOVA_PARITY_PHP") in CONTRACT["matrix"], "hosted_context")
    repo = Path(os.environ["GITHUB_WORKSPACE"]).resolve()
    require(HERE == repo / "tests/compat/carbon-constructor-parity", "checkout_path")
    results = repo / "parity-results"
    require(not results.exists(), "results_exist")
    results.mkdir(mode=0o700)
    root = Path("/tmp/pinova-carbon-parity-" + run_id + "-1")
    require(not root.exists(), "run_root_exists")
    root.mkdir(mode=0o700)
    os.environ["PINOVA_CARBON_PARITY_ROOT"] = str(root)
    os.environ["COMPOSER_NO_INTERACTION"] = "1"
    os.environ["COMPOSER_PROCESS_TIMEOUT"] = "120"
    os.environ["PYTHONDONTWRITEBYTECODE"] = "1"
    report = {"schema": "pinova.hosted-carbon-constructor-parity-result.v1", "state": "failed",
              "phase": "source", "matrix_php": os.environ["PINOVA_PARITY_PHP"],
              "source_bundle_manifest_sha256": CONTRACT["source_bundle_manifest_sha256"],
              "hosted_contract_sha256": sha((HERE / "hosted-contract.json").read_bytes()),
              "semantic_attempts": [], "active_JIT_fix_proven": False,
              "shipping_fix": False, "resource_acceptance": False, "build_integration": False}
    php = ["php", "-d", "opcache.enable_cli=0", "-d", "display_errors=stderr", "-d", "log_errors=0",
           "-d", "error_reporting=-1", "-d", "auto_prepend_file=", "-d", "auto_append_file=",
           "-d", "memory_limit=256M"]
    try:
        for name, expected in CONTRACT["source_sha256"].items():
            require(sha((HERE / name).read_bytes()) == expected, "fixture_hash")
        for name, expected in CONTRACT["base_source_sha256"].items():
            path = repo / name
            require(not path.is_symlink() and path.is_file() and path.stat().st_size == expected["size"]
                    and sha(path.read_bytes()) == expected["sha256"], "base_source_hash")
        original_utils = inventory(repo / "utils")
        require(set(original_utils) == {n[6:] for n in CONTRACT["base_source_sha256"] if n.startswith("utils/")},
                "utils_scope")
        report["phase"] = "composer"
        require(not (repo / "vendor").exists(), "vendor_already_exists")
        result, _ = run(root, "composer", ["composer", "install", "--no-dev", "--no-scripts", "--no-plugins",
                   "--no-interaction", "--no-progress", "--prefer-dist", "--optimize-autoloader", "--quiet"],
                   LIMITS["command_seconds"]["composer"], repo)
        require(result["state"] == "passed", "composer_failed")
        original_vendor = inventory(repo / "vendor")
        for name, expected in CONTRACT["base_source_sha256"].items():
            require(sha((repo / name).read_bytes()) == expected["sha256"], "composer_modified_input")
        source_contract = json.loads((HERE / "candidate-contract.json").read_text())
        harness = root / "harness"
        harness.mkdir()
        for name in CONTRACT["source_sha256"]:
            shutil.copy2(HERE / name, harness / name)
        for name, expected in source_contract["upstream_sha256"].items():
            source = repo / "vendor/nesbot/carbon" / name
            require(sha(source.read_bytes()) == expected, "installed_carbon_source_hash")
            target = harness / "upstream" / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, target)
        report["phase"] = "generator"
        result, _ = run(root, "generator", [sys.executable, str(harness / "generate_candidate.py")],
                        LIMITS["command_seconds"]["generator"], harness)
        require(result["state"] == "passed"
                and sha((harness / "candidate-contract.json").read_bytes()) == CONTRACT["source_sha256"]["candidate-contract.json"]
                and sha((harness / "vendor-constructor.patch").read_bytes()) == source_contract["patch_sha256"],
                "generator_unverified")
        for variant in ("baseline", "candidate"):
            (root / variant).mkdir()
            shutil.copytree(repo / "vendor", root / variant / "vendor", copy_function=shutil.copy2)
            shutil.copytree(repo / "utils", root / variant / "utils", copy_function=shutil.copy2)
        for name in source_contract["changed_files"]:
            shutil.copyfile(harness / "candidate" / name, root / "candidate/vendor/nesbot/carbon" / name)
        before = {variant: inventory(root / variant / "vendor") for variant in ("baseline", "candidate")}
        require(before["baseline"] == original_vendor and before["baseline"].keys() == before["candidate"].keys(),
                "vendor_copy_scope")
        different = sorted(n for n in before["baseline"] if before["baseline"][n] != before["candidate"][n])
        require(different == CONTRACT["allowed_vendor_differences"], "vendor_diff_scope")
        for name in different:
            relative = name[len("nesbot/carbon/"):]
            require(before["baseline"][name]["sha256"] == source_contract["upstream_sha256"][relative]
                    and before["candidate"][name]["sha256"] == source_contract["candidate_sha256"][relative]
                    and before["baseline"][name]["mode"] == before["candidate"][name]["mode"], "vendor_diff_hash")
        require(all(inventory(root / variant / "utils") == original_utils for variant in before), "utils_copy")
        report["vendor_inventory"] = {variant: inventory_summary(rows) for variant, rows in before.items()}
        report["vendor_inventory"]["only_differences"] = different
        report["utils_inventory"] = inventory_summary(original_utils)
        report["phase"] = "lint_and_token"
        lint_files = [harness / "check_constructor_source.php", harness / "constructor_parity.php"] + [
            root / variant / "vendor/nesbot/carbon" / name for variant in before
            for name in source_contract["upstream_sha256"] if name.endswith(".php")]
        lint_ok = True
        for index, path in enumerate(lint_files):
            result, _ = run(root, "lint_" + str(index), php + ["-l", str(path)],
                            LIMITS["command_seconds"]["lint"], harness)
            lint_ok = lint_ok and result["state"] == "passed"
        result, token_out = run(root, "token", php + [str(harness / "check_constructor_source.php"),
                    str(root / "baseline/vendor/nesbot/carbon"), str(root / "candidate/vendor/nesbot/carbon")],
                    LIMITS["command_seconds"]["token"], harness)
        require(lint_ok and result["state"] == "passed" and result["stdout_bytes"] <= LIMITS["receipt_bytes"],
                "source_checks_failed")
        token = json.loads(token_out.read_bytes())
        require(token.get("state") == "passed" and token.get("shipping_fix") is False, "token_receipt")
        write_json(results / "source-check.json", token)
        report["phase"] = "semantic_pair"
        pair_ok = True
        for variant in ("baseline", "candidate"):
            passed = False
            try:
                result, raw = run(root, variant, php + [str(harness / "constructor_parity.php"), variant],
                                  LIMITS["command_seconds"]["semantic"], harness)
                value = public_receipt(raw, result, variant)
                write_json(results / (variant + ".json"), value)
                passed = result["state"] == "passed" and value.get("state") == "complete"
                passed = passed and value.get("php_version", "").startswith(report["matrix_php"] + ".")
            except Exception:
                # A malformed first receipt must not suppress the second fixed process.
                pass
            report["semantic_attempts"].append({"variant": variant, "state": "passed" if passed else "failed"})
            pair_ok = pair_ok and passed
        report["phase"] = "comparison"
        result, raw = run(root, "compare", [sys.executable, str(harness / "compare_parity.py"),
                         str(results / "baseline.json"), str(results / "candidate.json")],
                         LIMITS["command_seconds"]["compare"], harness)
        require(raw is not None and result["stdout_bytes"] <= LIMITS["receipt_bytes"], "comparison_output")
        comparison = json.loads(raw.read_bytes())
        require(comparison.get("schema") == "pinova.carbon-constructor-parity-comparison.v1"
                and comparison.get("shipping_fix") is False, "comparison_receipt")
        write_json(results / "comparison.json", comparison)
        report["phase"] = "post_inventory"
        require(inventory(repo / "vendor") == original_vendor and inventory(repo / "utils") == original_utils,
                "installed_tree_mutated")
        require(all(inventory(root / variant / "vendor") == before[variant]
                    and inventory(root / variant / "utils") == original_utils for variant in before), "copied_tree_mutated")
        report["post_inventory_unchanged"] = True
        require(pair_ok and result["state"] == "passed" and comparison.get("state") == "equal", "parity_failed")
        report["state"], report["phase"] = "passed", "complete"
    except Exception:
        # Exception messages and traces remain private; phase is a fixed controller label.
        report["reason"] = "guard_or_command_failed"
    finally:
        for variant in ("baseline", "candidate"):
            if not (results / (variant + ".json")).exists():
                write_json(results / (variant + ".json"), {
                    "schema": "pinova.carbon-constructor-parity.v1", "variant": variant,
                    "state": "unverified" if any(r["name"] == variant for r in COMMANDS) else "not_executed",
                    "reason": "missing_verified_receipt", "shipping_fix": False})
        for name, schema in (("source-check", "pinova.carbon-constructor-source-check.v1"),
                             ("comparison", "pinova.carbon-constructor-parity-comparison.v1")):
            if not (results / (name + ".json")).exists():
                write_json(results / (name + ".json"), {
                    "schema": schema, "state": "unverified", "reason": "shared_prerequisite_or_output_failed",
                    "shipping_fix": False})
        report["commands"] = COMMANDS
        write_json(results / "controller.json", report)
    print(json.dumps({"state": report["state"], "phase": report["phase"],
                      "matrix_php": report["matrix_php"], "active_JIT_fix_proven": False, "shipping_fix": False}))
    return 0 if report["state"] == "passed" else 1


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception:
        print('{"state":"failed","phase":"controller_setup","shipping_fix":false}')
        raise SystemExit(1)
