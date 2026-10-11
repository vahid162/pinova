# Pinova Constitution

## Core Principles

### I. Repository Policy Is Canonical

Contributors MUST read and follow [AGENTS.md](../../AGENTS.md) before a workflow stage.
This constitution routes to existing policy rather than redefining or duplicating it.
Explicit user instructions take precedence; Spec Kit does not grant additional external authority.

### II. Preserve Mobile Identity and Native Decisions

Feature plans MUST demonstrate preservation of the identity and authentication invariants in
[AGENTS.md](../../AGENTS.md) and the [Pinova development skill](../../.agents/skills/pinova-development/SKILL.md).
Integration convenience MUST NOT widen account privileges or falsify email verification.

### III. Prefer the Smallest Supported Integration

New coordination behavior MUST use existing platform interfaces and narrowly scoped adapters.
Readiness MUST follow the interfaces an operation consumes; a dependency version is evidence,
not an authorization or compatibility decision by itself. Separate operations MUST degrade
independently where account restrictions can still be enforced safely.

### IV. Verify Behavior at the Actual Boundary

Authentication, membership and vendor-conversion changes MUST have meaningful regression
coverage and real-plugin characterization. A callable inspection is a runtime prerequisite,
not proof of semantic compatibility. Required isolated CI and release gates are defined in
the [quality reference](../../.agents/skills/pinova-development/references/quality-and-release.md).

### V. Report Evidence Without Affecting the Request

Operational visibility MUST follow the [logging contract](../../.agents/skills/pinova-development/references/logging.md).
Reports MUST distinguish current readiness from historical events and confirmed observations
from missing evidence. Status observation MUST remain bounded and incapable of interrupting
authentication or changing account eligibility.

## Additional Constraints

All integration implementation remains inside Pinova. Plans MUST identify the actual resources,
dependencies and side effects required by each operation. The shared host compute boundary,
privacy rules, documentation ownership and preservation requirements in AGENTS.md apply to
Spec Kit commands and generated artifacts as well as runtime code.

## Development Workflow and Quality Gates

Each feature records specification, design, tasks and verification evidence without claiming a
test or deployment that has not run. Apply the repository's definition of done for the requested
delivery boundary. Publication and installation are separate gates; this feature's approved
procedure installs a verified GitHub prerelease only on the dedicated test site, with production
installation performed by the user.

## Governance

Amendments MUST describe the changed principle, rationale and affected feature plans.
Use semantic versioning for governance changes, retain the original ratification date, and
review each plan against this constitution before and after design. Repository policy remains
the authoritative source when workflow scaffolding and project rules differ. No template,
hook, generated task or constitution grants authority beyond the user's requested scope.

**Version**: 1.0.0 | **Ratified**: 2026-10-11 | **Last Amended**: 2026-10-11
