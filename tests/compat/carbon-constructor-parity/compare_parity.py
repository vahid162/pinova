"""Compare two future receipts only; never launches PHP or retries a case."""
from pathlib import Path
import hashlib
import json
import sys

HERE = Path(__file__).resolve().parent


def require(value):
    if not value:
        raise ValueError("parity_incomplete_or_different")


def read_receipt(path):
    path = Path(path)
    require(path.is_file() and not path.is_symlink() and path.stat().st_size <= 131072)
    return json.loads(path.read_bytes())


def main():
    require(len(sys.argv) == 3 and Path(sys.argv[1]).resolve() != Path(sys.argv[2]).resolve())
    baseline, candidate = (read_receipt(path) for path in sys.argv[1:])
    contract_bytes = (HERE / "candidate-contract.json").read_bytes()
    parity_bytes = (HERE / "parity-contract.json").read_bytes()
    contract, parity = json.loads(contract_bytes), json.loads(parity_bytes)
    keys = [(name, case) for name in parity["classes"] for case in parity["cases"]]
    for expected, receipt in (("baseline", baseline), ("candidate", candidate)):
        require(receipt["schema"] == "pinova.carbon-constructor-parity.v1" and receipt["state"] == "complete"
                and receipt["variant"] == expected and receipt["JIT_active"] is False
                and receipt["JIT_evidence"] in ("extension_absent", "CLI_OPcache_disabled", "native_status_inactive")
                and receipt["shipping_fix"] is False and receipt["active_JIT_fix_proven"] is False
                and receipt["resource_acceptance"] is False
                and receipt["source_contract_sha256"] == hashlib.sha256(contract_bytes).hexdigest()
                and receipt["parity_contract_sha256"] == hashlib.sha256(parity_bytes).hexdigest())
        require(type(receipt["pid"]) is int and receipt["pid"] > 1)
        require([(row["class"], row["case"]) for row in receipt["cases"]] == keys)
        pins = contract[("upstream" if expected == "baseline" else "candidate") + "_sha256"]
        require(set(receipt["loaded_sources"]) == set(parity["classes"]))
        for name, source in receipt["loaded_sources"].items():
            file = "src/Carbon/" + name.split("\\")[-1] + ".php"
            require(source == {"class_source_sha256": pins[file],
                "constructor_origin": "Creator_trait_source" if expected == "baseline" else "concrete_class_source",
                "exact_expected_source_loaded": True})
        for row in receipt["cases"]:
            require(row["outcome"] == ("exception" if row["case"] in parity["exception_cases"] else "value"))
            if row["case"] in ("subclass_parent_constructor", "subclass_numeric_late_static"):
                require(row["value"]["parent_calls"] == 1 and row["value"]["object_id_initialized"] is True
                        and row["value"]["clone_id_initialized"] is True and row["value"]["distinct_objects"] is True)
    require(baseline["pid"] != candidate["pid"])
    for field in ("php_version", "php_binary_sha256", "timezone_db_version", "numeric_locale", "default_timezone",
                  "JIT_evidence", "normalized_inventory_sha256"):
        require(baseline[field] == candidate[field])
    require(baseline["numeric_locale"] == "C" and baseline["default_timezone"] == "UTC")
    differences = [i for i, (a, b) in enumerate(zip(baseline["cases"], candidate["cases"])) if a != b]
    result = {"schema": "pinova.carbon-constructor-parity-comparison.v1",
        "state": "equal" if not differences else "different", "compared_cases": len(keys),
        "different_case_keys": [{"class": keys[i][0], "case": keys[i][1]} for i in differences],
        "baseline_receipt_sha256": hashlib.sha256(Path(sys.argv[1]).read_bytes()).hexdigest(),
        "candidate_receipt_sha256": hashlib.sha256(Path(sys.argv[2]).read_bytes()).hexdigest(),
        "namespace_shadowing_equivalence_proven": False, "reflection_location_equivalence": False,
        "active_JIT_fix_proven": False, "shipping_fix": False, "resource_acceptance": False}
    print(json.dumps(result, sort_keys=True))
    return 0 if not differences else 1


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (ValueError, OSError, KeyError, TypeError):
        print(json.dumps({"schema": "pinova.carbon-constructor-parity-comparison.v1", "state": "incomplete",
                          "reason": "unverified_receipt_or_runtime_identity", "shipping_fix": False}))
        raise SystemExit(1)
