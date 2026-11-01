# Carbon constructor semantic parity only

This never-merge proof branch is based on exact commit
`117e434bf65f093ad9d05f15cd180d34b4193ba9`. It does not build a plugin ZIP,
change application or logging files, run FPM/WordPress, send OTPs, or publish a release.

Five independent hosted jobs use PHP 8.1 through 8.5. Each installs the locked
production Composer dependency set once, with scripts and plugins disabled. The
reviewed generator consumes four hash-verified files from that actual installation.
Both semantic roots receive identical vendor and utils copies. A complete inventory
of regular-file paths, modes, sizes and SHA256 hashes independently requires that
the only vendor differences are the two pinned Carbon class edits. Symlinks and
special files are rejected. Every copied vendor/utils file is checked again after
the two processes; Composer metadata equality alone is not the inventory proof.

The seven reviewed source/contract files are retained byte for byte. The copied
constructor signature and body, unchanged Creator trait and license, and imported
names are checked by the reviewed PHP token checker before semantic execution.
Eight fixed PHP lint commands check the two fixtures and three Carbon files in each
root. CLI OPcache is disabled only for these disposable command invocations, and
each semantic process must independently observe inactive JIT. E_ALL diagnostics
are retained; unexpected stderr, including deprecations, rejects a command.

One baseline process and one candidate process each produce the same 64 synthetic
cases. Failure of the first process does not suppress the second. Shared setup or
source-check failure blocks both and leaves explicit finite receipts; no retry or
selective rerun is allowed. Both complete value receipts are compared using the
unchanged comparator. Equal values cannot overrule a command failure, diagnostic,
unknown runtime identity or changed inventory.

The private run directory is `/tmp/pinova-carbon-parity-<run-id>-1` (0700). Raw
stdout/stderr stay there or in private runner temporary files and are never uploaded.
Only bounded synthetic JSON receipts are artifacts. Each child-created working file,
including a Composer archive or raw stdout/stderr file, has a 64 MiB hard limit.
The separate acceptance limit for each captured stdout/stderr file remains 2 MiB;
oversized output rejects the command before parsing and is never uploaded. Receipt
output has a 128 KiB limit, each semantic process a 30-second limit, the driver a
420-second budget, and each hosted job a 15-minute outer limit. The hosted
runner's disposal ends the private workspace; this is not a server cleanup proof.

Passing this workflow establishes only the stated semantic parity cases for the
observed PHP versions. It does not prove the active-JIT crash fixed, resource
acceptance, deployment readiness, full ecosystem compatibility, or a PHP engine
root cause. Reflection filenames/lines and namespaced fallback resolution differ;
namespaced function/constant shadowing and comma-decimal locales are outside this
fixture. The earlier crash may still have been hidden by changed layout or compile
order. Active-JIT ordinary-application evidence remains a separate future gate.

The dedicated push branch is excluded from Quality. Existing diagnostics and
release workflow triggers do not match this branch. Do not merge this proof branch
or use its result as release authorization.
