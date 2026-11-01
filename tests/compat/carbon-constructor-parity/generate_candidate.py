"""Private source generator only; never invokes PHP or edits an installed vendor tree."""
from pathlib import Path
import difflib
import hashlib
import json

HERE = Path(__file__).resolve().parent
REF = "9228ce90e1035ff2f0db84b40ec2e023ed802075"
PINS = {
    "src/Carbon/Carbon.php": "66d8915a111f9c771e8c4cefadcc13892f4474213062f8eb2ca50811f2c6192b",
    "src/Carbon/CarbonImmutable.php": "7a414adf4b7d639b2b8e01863e69e5ce12ae9405774e29f049391def17568d66",
    "src/Carbon/Traits/Creator.php": "b5f00b16f74ba0f0f86f86645ae6a0a10445b63e0ca75939298afb173da4af0a",
    "LICENSE": "57a20d90f47d71dbfbb681c33f909bdc9e3ba2b82eeb187be1ed19a4910a23c5",
}
METHOD = b"    public function __construct($time = null, $tz = null)\n"
DOC = b"    /**\n     * Create a new Carbon instance.\n"
FOLLOWING = b"    /**\n     * Get timezone from a datetime instance.\n"


def require(value, reason):
    if not value:
        raise RuntimeError(reason)


def digest(value):
    return hashlib.sha256(value).hexdigest()


def main():
    source = json.loads((HERE / "source-origin.json").read_text())
    require(source["source_reference"] == REF and source["version"] == "2.73.0"
            and source["upstream_sha256"] == PINS, "source_identity_changed")
    upstream = HERE / "upstream"
    before = {}
    for name, expected in PINS.items():
        path = upstream / name
        require(path.is_file() and not path.is_symlink() and path.stat().st_size <= 131072, "source_shape_changed")
        before[name] = path.read_bytes()
        require(digest(before[name]) == expected, "source_hash_changed")
    creator = before["src/Carbon/Traits/Creator.php"]
    require(creator.count(DOC) == creator.count(METHOD) == creator.count(FOLLOWING) == 1, "constructor_anchor_changed")
    copied = creator[creator.index(DOC):creator.index(FOLLOWING)]
    method = copied[copied.index(METHOD):].rstrip(b"\n") + b"\n"
    require(method.endswith(b"    }\n"), "constructor_end_changed")
    after = dict(before)
    for name, anchor in (
        ("src/Carbon/Carbon.php", b"    use Date;\n\n"),
        ("src/Carbon/CarbonImmutable.php", b"    use Date {\n        __clone as dateTraitClone;\n    }\n\n"),
    ):
        value = before[name]
        require(value.count(anchor) == 1 and METHOD not in value, "class_shape_changed")
        require(value.count(b"use Carbon\\Traits\\Date;\n") == 1
                and value.count(b"use DateTimeZone;\n") == 1
                and b"use Exception;" not in value and b"use Carbon\\Exceptions\\InvalidFormatException;" not in value,
                "class_imports_changed")
        value = value.replace(b"use Carbon\\Traits\\Date;\n", b"use Carbon\\Exceptions\\InvalidFormatException;\nuse Carbon\\Traits\\Date;\n", 1)
        value = value.replace(b"use DateTimeZone;\n", b"use DateTimeZone;\nuse Exception;\n", 1)
        after[name] = value.replace(anchor, anchor + copied, 1)
        require(after[name].count(method) == 1, "constructor_byte_copy_changed")
    require(after["src/Carbon/Traits/Creator.php"] == creator and after["LICENSE"] == before["LICENSE"], "upstream_retention_changed")
    require(all(b"declare(strict_types" not in value for name, value in after.items() if name.endswith(".php")), "strict_types_changed")
    target = HERE / "candidate"
    require(not target.exists(), "candidate_already_exists")
    for name, value in after.items():
        path = target / name
        path.parent.mkdir(parents=True, exist_ok=True)
        with path.open("xb") as handle:
            handle.write(value)
    changes = [name for name in PINS if after[name] != before[name]]
    require(changes == ["src/Carbon/Carbon.php", "src/Carbon/CarbonImmutable.php"], "patch_scope_changed")
    patch = "".join("".join(difflib.unified_diff(before[name].decode().splitlines(True),
        after[name].decode().splitlines(True), fromfile="a/" + name, tofile="b/" + name)) for name in changes)
    (HERE / "vendor-constructor.patch").write_text(patch)
    contract = {"schema": "pinova.private-carbon-constructor-source.v1", "package": "nesbot/carbon", "version": "2.73.0",
        "source_reference": REF, "upstream_sha256": PINS,
        "candidate_sha256": {name: digest(value) for name, value in after.items()},
        "constructor_signature_and_body_sha256": digest(method), "copied_doc_and_method_sha256": digest(copied),
        "patch_sha256": digest(patch.encode()), "changed_files": changes,
        "strict_types": "absent_in_all_three_upstream_and_candidate_files",
        "constructor_copies_byte_equal": True, "creator_trait_byte_unchanged": True, "license_byte_unchanged": True,
        "application_logging_changed": False, "native_executed": False, "semantic_parity_proven": False,
        "JIT_fix_proven": False, "build_integration": False, "shipping_fix": False}
    (HERE / "candidate-contract.json").write_text(json.dumps(contract, indent=2) + "\n")
    print(json.dumps({"state": "source_generated_only", "changed_files": changes,
                      "patch_sha256": contract["patch_sha256"], "native_executed": False}))


if __name__ == "__main__":
    main()
