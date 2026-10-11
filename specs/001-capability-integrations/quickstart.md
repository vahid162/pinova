# Validation Guide: Capability-Based Integrations

## Prerequisites and Compute Boundary

Read `AGENTS.md`, the Pinova skill and its quality/logging references. Use a clean disposable
checkout on GitHub Actions or a resource-isolated development host for dependency installation,
real-plugin environments and browser tests. Synthetic accounts only; production is not a test target.

The Spec Kit scripts require Python 3.11+. From a checkout with this feature selected, a read-only
artifact prerequisite check is:

```bash
python3 .specify/scripts/python/check_prerequisites.py --json --require-tasks --include-tasks
```

In another checkout, select this feature through `SPECIFY_FEATURE_DIRECTORY=specs/001-capability-integrations`
as supported by the initialized scripts; the local `.specify/feature.json` pointer is intentionally
not shared in Git.

## Lightweight Repository Checks

```bash
php tools/check-ai-governance.php
php tools/check-changelog-sync.php
bash .agents/skills/pinova-development/scripts/check-skill-sync.sh --working-tree
node --test tests/js/third-party-fixtures.test.mjs tests/js/third-party-runner.test.mjs
```

Run these only within the approved bounded local compute allowance. Passing them does not prove
native account, membership or vendor conversion behavior.

## Isolated Real-Plugin Characterization

Use the existing quality workflow and the real third-party profile documented in the quality
reference. The deployed characterization set uses `PINOVA_TEST_PROFILE=third-party` and
`PINOVA_TEST_PLUGIN_SET=deployed`; fixture preparation verifies archived plugin checksums before
loading code. Run the repository's `tools/run-third-party-baseline.sh` for every selected plugin
set and classic/HPOS order store, with locked dependencies and disposable databases.

Expected outcomes:

1. Enabled forum routing/member workflow works with WooCommerce absent and after a compatible
   WooCommerce change; bans, manual approval and truthful email confirmation remain unchanged.
2. Dokan public entry points use Pinova; missing mobile proof blocks native conversion;
   verified conversion preserves User ID, orders, forum groups and native selling approval.
3. Individual missing-capability mutations show partial/unavailable state and disable only
   affected operations; pending restrictions survive switching coordination off.
4. The existing browser configuration proves mobile verification and the native form journey;
   synthetic OTP verification is not proof of physical SMS receipt.
5. Read-only issue/admin export/CLI report paths produce no persistent health-state writes;
   unchanged observations do not add events; injected logging failure leaves auth unaffected.

## Release and Test-Site Acceptance

After reviewed CI and merged-main checks, publish through the existing immutable prerelease
pipeline. Verify the independently downloaded ZIP, checksum, SBOM and attestations. Installation
uses that GitHub asset and a verified scoped rollback backup on the authorized test site only.

Check the integration statuses, login and registration, mobile proof, forum restrictions,
existing-account vendor conversion, orders and report surfaces on the test site. Record any
limitation and observation window. Notify the user after passing acceptance; the user installs
on the production site. This document does not authorize or claim a production installation.
