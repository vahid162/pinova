# Feature Specification: Capability-Based Integrations

**Feature Branch**: `feat/capability-integrations`

**Created**: 2026-10-11

**Status**: Ready for implementation

**Input**: User description: "Keep WooCommerce, Dokan and wpForo coordination working through compatible updates without a setup tied to specific versions. Check required capabilities, keep the forum independent, degrade only affected features safely, and expose actual integration health. Implement inside Pinova; release through GitHub and install only on the test site for verification."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Keep Forum Coordination Through Compatible Updates (Priority: P1)

A site owner enables wpForo coordination once. Forum login and registration continue through
Pinova after compatible updates, and users can verify their mobile identity without changing
forum bans, email confirmation or administrator approval.

**Why this priority**: An unrelated store update must not silently disable forum mobile identity.

**Independent Test**: Enable only the forum coordination and exercise registration, mobile proof,
forum approval and authenticated forum rendering with WooCommerce absent or changed.

**Acceptance Scenarios**:

1. **Given** forum coordination is enabled and its required capabilities are available, **When**
   a compatible plugin update changes the version, **Then** forum coordination stays active
   without resaving settings.
2. **Given** WooCommerce is absent, **When** a user uses forum login and mobile registration,
   **Then** forum coordination works through Pinova with the same WordPress account.
3. **Given** a forum member is banned, manually held, or requires administrator approval, **When**
   the user verifies their mobile, **Then** the existing restriction remains enforced and email
   verification is not fabricated.
4. **Given** only automatic membership support becomes unavailable, **When** forum routing is
   still available, **Then** routing remains available and pending membership is not activated.

---

### User Story 2 - Keep Vendor Onboarding Safe Through Compatible Updates (Priority: P1)

A user logs in or registers with Pinova, proves ownership of the mobile number for that account,
and becomes a vendor using Dokan's native form. Compatible updates do not require new setup.

**Why this priority**: Login routing must not silently disappear or grant a seller role prematurely.

**Independent Test**: Enable only Dokan coordination and complete native onboarding with synthetic
accounts while checking account identity, orders, seller approval and forum groups.

**Acceptance Scenarios**:

1. **Given** Dokan coordination is enabled and required capabilities are available, **When**
   WooCommerce or Dokan changes to a compatible version, **Then** login routing and native
   onboarding remain available without resaving settings.
2. **Given** mobile proof is absent or invalid, **When** a user submits vendor conversion,
   **Then** conversion is blocked and neither seller role nor selling permissions are granted.
3. **Given** a verified customer completes native conversion, **When** Dokan creates the vendor
   state, **Then** WordPress User ID, orders, forum membership and approval decisions are preserved.
4. **Given** the safe conversion boundary or forum-role preservation support is missing,
   **When** routing still works, **Then** routing continues but conversion is blocked safely
   and the administrator sees the unavailable operation.

---

### User Story 3 - See Actual Coordination Health (Priority: P2)

The administrator and an AI inspecting Pinova reports can distinguish saved intent from
current readiness and see a clear, actionable reason for partial or unavailable functionality.

**Why this priority**: Successful core-login logs alone cannot establish working forum/vendor routing.

**Independent Test**: Change one synthetic dependency capability, inspect the integration page
and read-only issue report, and verify that repeated unchanged observations stay bounded.

**Acceptance Scenarios**:

1. **Given** a coordination switch is enabled, **When** required capabilities are missing,
   **Then** the settings page and issue report distinguish partial/unavailable coordination
   from active coordination and name the affected operation.
2. **Given** a dependency changes and readiness remains the same, **When** health is inspected
   repeatedly, **Then** version evidence updates without generating a log on every request.
3. **Given** the administrator views or exports a read-only report, **When** current health is
   calculated, **Then** no integration setting, account state or historical issue is changed.
4. **Given** logging fails, **When** status is observed or a user authenticates,
   **Then** the user operation and eligibility decisions remain unaffected.

### Edge Cases

- Dependencies may initialize after Pinova: an early absence must not become a permanent cached result.
- A function may exist with an incompatible signature; that operation remains unavailable.
- Native mutation callbacks may be duplicated, reordered or replaced; ambiguous transitions fail closed.
- Turning routing off or removing a dependency must not clear an integration-owned pending restriction.
- Dokan Pro introduces additional onboarding behavior: safe routing may remain available while
  uncharacterized conversion remains unavailable with an explicit reason.
- Unknown or malformed version strings are omitted from diagnostic evidence, not used as a gate.
- Legacy users without Pinova-owned pending state must retain native forum decisions.
- A log retention gap or sampled event is not proof of recovery or universal user success.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: A version-number change alone MUST NOT disable an integration whose required functionality remains available.
- **FR-002**: Forum coordination MUST NOT require WooCommerce solely to satisfy a compatibility check.
- **FR-003**: Each operation MUST verify the capabilities and invocation boundary it actually needs before acting.
- **FR-004**: Missing support MUST disable only the affected operation where the remaining operations can preserve account restrictions safely.
- **FR-005**: Mobile eligibility MUST use purpose-bound proof for the same WordPress account; public registration and ordinary login MUST NOT assign seller roles.
- **FR-006**: Native forum bans, administrator holds, email confirmation, vendor approval and selling permissions MUST remain authoritative.
- **FR-007**: Pending-state guards MUST survive routing switches and dependency loss.
- **FR-008**: The existing settings page MUST show off, active, partial or unavailable state with a controlled reason and observed versions.
- **FR-009**: Logs and Issues, JSON export and read-only CLI reports MUST include current integration readiness separately from historical issue evidence.
- **FR-010**: Status-transition records MUST be deduplicated and bounded; observation and logging failure MUST NOT interrupt user requests or change eligibility.
- **FR-011**: Logs MUST contain only approved finite status/reason evidence, without customer identifiers, credentials, raw payloads or traces.
- **FR-012**: Regression and real-plugin tests MUST cover compatible version changes, individual missing capabilities, mixed plugin combinations and both order stores.
- **FR-013**: Implementation MUST remain inside Pinova without modifying dependency plugins, the theme, production data or shared services.
- **FR-014**: Delivery MUST use a reviewed GitHub prerelease and test-site verification; the user installs on the main site.

### Key Entities *(include if feature involves data)*

- **Integration Intent**: The existing independent forum/vendor switches chosen by the administrator.
- **Readiness Snapshot**: Observation time, integration state, controlled reason, feature availability and safe version evidence; distinct from saved intent.
- **Status Observation**: Bounded state/reason transition evidence, independent of customer and account data.
- **Account Eligibility**: Existing mobile proof and integration-owned pending state governed by native approval decisions; not migrated or re-created by this feature.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: All characterized compatible update combinations keep enabled forum/vendor journeys available without reconfiguration.
- **SC-002**: Every simulated missing-capability case disables only its affected operation and preserves all existing account restrictions.
- **SC-003**: Zero test cases grant seller status, activate held forum membership or fabricate email verification solely because a user logs in or verifies a mobile.
- **SC-004**: Every requested integration has an accurate visible state and actionable reason in both administrator settings and read-only reports.
- **SC-005**: Repeated unchanged status observations produce no additional transition records; read-only reporting changes no stored state.
- **SC-006**: All required verification gates pass before test-site installation, and test-site acceptance is recorded before requesting the user's production installation.

## Assumptions

- Existing administrator switches and account records are retained; no settings reset or identity migration is required.
- Pinova's existing mobile identity workflow and native WordPress authentication remain authoritative.
- Routine compatible updates are supported by capability checks; a true behavioral change may require a future adapter fix.
- Runtime readiness inspections cannot prove every future plugin behavior; release verification includes actual supported plugin combinations.
- No new external monitoring service, automatic repair, server scheduler or production deployment is part of this feature.
