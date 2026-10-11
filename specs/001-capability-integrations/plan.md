# Implementation Plan: Capability-Based Integrations

**Branch**: `feat/capability-integrations` | **Date**: 2026-10-11 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/001-capability-integrations/spec.md`

## Summary

Replace exact WooCommerce/wpForo/Dokan version gates with a small read-only capability snapshot
shared by routing, operation guards and administrator reports. Forum routing and membership
must be independent of WooCommerce. Dokan routing can remain active while an unsafe native
conversion boundary is unavailable. Preserve all existing proof, pending-state, approval and
scoped role-hook rules. Versions remain diagnostic evidence; semantic compatibility is tested
with real plugins rather than inferred from callable existence.

## Technical Context

**Language/Version**: PHP 8.1+ runtime; Node and npm versions declared by package.json; Python 3.11+ for Spec Kit development scripts.

**Primary Dependencies**: WordPress, native wpForo services and hooks; Dokan Lite and WooCommerce for vendor/account operations. Reuse existing Pinova services and logging.

**Storage**: Existing `pinova_integrations` option, existing account metadata and log tables; at most one fixed bounded health-observation option per integration. No schema or identity migration.

**Testing**: Targeted PHP integration regressions; Node fixture/runner tests; isolated GitHub Actions real-plugin and browser characterization with classic order storage and HPOS.

**Target Platform**: WordPress single-site plugin runtime and existing administrator/CLI reporting surfaces.

**Project Type**: Existing WordPress authentication plugin; no new service or monitoring framework.

**Performance Goals**: Fresh inspection uses loaded objects and a fixed number of interfaces/hooks; no external request, dependency construction, account scan or per-user cache. Unchanged observations must produce no write or event.

**Constraints**: Only Pinova implementation changes; read-only CLI/reporting; private data remains redacted; shared host limited to bounded source checks. Production installation is performed by the user.

**Scale/Scope**: Two independently requested integrations; forum routing/membership/REST readiness, vendor routing/conversion/forum-role preservation/report readiness, and one shared administrator health view.

## Constitution Check

*Gate evaluated before research and after design.*

| Principle | Design evidence | Result |
| --- | --- | --- |
| Canonical repository policy | Work in dedicated branch; repository policy and specialist contracts remain authoritative | Pass |
| Mobile identity and native decisions | Existing mobile proof, pending guards, native conversion and approval restrictions retained | Pass |
| Small supported integration | One shared capability snapshot; no replacement framework, external service or version registry | Pass |
| Actual-boundary verification | Missing-capability regression cases and real-plugin combinations in both order stores | Required verification gate |
| Observational evidence | Read-only current snapshot plus bounded status-transition logs using finite evidence | Pass |

No constitution violation is justified or introduced. A passing design check is not a claim
that implementation, real-plugin CI, package verification or staging acceptance has completed.

## Project Structure

### Documentation (this feature)

```text
specs/001-capability-integrations/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── checklists/requirements.md
├── contracts/integration-health.md
└── tasks.md
```

### Source Code (repository root)

```text
src/Integrations/IntegrationSettings.php  Shared capability/readiness decisions
src/Integrations/IntegrationHealth.php    Bounded status observations
src/Integrations/WpForo/Load.php           Independent forum operations and pending guards
src/Integrations/Dokan/Load.php            Native onboarding and scoped role callback
src/Logging/                             Finite events, evidence and issue reporting
src/Admin/Issues.php                      Current health in existing Logs and Issues
src/Admin/Logs.php                        Existing read-only incident report
src/Pinova.php                            Existing lifecycle wiring
tests/Integration/                       Authentication/lifecycle/health regressions
tests/js/                                Fixture and isolated runner checks
tests/fixtures/third-party-baseline.json  Characterization archives and checksums
tools/                                   Existing isolated compatibility runner
.github/workflows/quality.yml            Real-plugin CI profiles
```

**Structure Decision**: Extend existing Pinova integration and logging surfaces. Maintain a
single runtime readiness definition; leave real-package versions pinned only in test fixtures
for repeatable characterization, not as runtime authorization gates.

## Complexity Tracking

No exceptions. Preserve the existing architecture, native interfaces and release pipeline.
