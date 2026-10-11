# Capability integrations implementation preflight — 2026-10-11

## Scope and base

Base: `1573a9c3687ddf197b6f3d561c5fc911f26c78ef`, independently reconciled with remote main before implementation. The baseline main Quality run was successful. Existing unrelated pull requests and immutable releases were preserved.

Runtime acceptance now uses per-feature callable contracts and guarded native mutation boundaries, not exact WooCommerce/Dokan/wpForo versions. Forum coordination is independent of commerce. Current capability observations are distinct from retained historical issues. Mobile proof, administrator holds, genuine email confirmation, native vendor approval and account/order/group continuity remain required.

Spec Kit 1.1.3 source and generated development workflows are recorded in the feature's setup provenance. Requirements checklist passed 14/14. All fourteen functional requirements and six success criteria map to the task list. Source completion is distinct from executed acceptance.

## Review findings and corrections

- Requested seller coordination still guards public signup when routing/conversion is unavailable.
- Requested new forum accounts acquire owned pending state before a missing membership capability can interrupt enrollment.
- Declared but uninitialized forum services block group-sensitive conversion and are inspected afresh after initialization.
- Changed email reset addresses remain unconfirmed when native status retrieval fails.
- REST initialization that returns without creating the topic service emits bounded operational failure evidence without replacing the upstream authentication result.
- Native profile writes reject uncharacterized formatting, target aliases and retries before execution. Conditional zero-row results cannot fall through to an unconditional retry. The known successful completion hook releases the scoped guard for independent later hooks; exception cleanup preserves an outer lock.
- Existing integration-state assertions use the new finite states and distinguish default-off from requested-but-missing dependencies.

An independent read-only reviewer reproduced the email-binding and query-boundary failures with standalone behavioral fixtures and confirmed the corrections, including late initialization, zero-row retries, comma-delimited targets and scoped lock cleanup. This source review is not real-plugin or browser acceptance.

## Lightweight verification

Passed:

- PHP syntax for all 23 changed PHP files.
- WordPress coding-standard check for the eleven changed runtime files with existing temporary tooling; exact locked CI remains authoritative.
- Targeted Node fixture/configuration and runner tests; each missing completion-marker guard remains enforced.
- Package-content policy tests.
- Workflow actionlint; third-party runner syntax; six imported Spec Kit helpers with verified temporary ShellCheck 0.11.0.
- Metadata/readme validation, synchronized changelog, durable-document governance, Pinova skill synchronization and `git diff --check`.
- Spec Kit Bash/Python prerequisite/template parity, existing-plan preservation and ignored Python-cache behavior.

Official deployed fixture archives were independently downloaded and checked: WooCommerce 11.2.1 and Dokan Lite 5.3.0. The manifest retains prior/current/mixed profiles and adds the deployed combination. The remote matrix has ten order-storage/profile cases and normal/manual forum-only invocations with commerce plugins skipped.

## Pending acceptance

Full PHP, static-analysis, WordPress, native integration, HPOS, browser, dependency, security and package checks run on isolated GitHub Actions. Exact-head review/CI, merged-main CI, immutable prerelease publication, independent asset verification, scoped rollback backup, published-package test-site installation/rollback and acceptance are still pending. No production installation or shared-service change is authorized by this evidence. Physical SMS receipt is not established by synthetic fixtures.

Status-transition history is best effort and threshold-dependent. Atomic state claims precede logging; an omitted event is not backfilled on an unchanged observation. Current read-only snapshots remain available but do not prove recovery or universal future compatibility.
