# Tasks: Capability-Based Integrations

**Input**: Design documents from `specs/001-capability-integrations/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/integration-health.md

**Tests**: Required by FR-012 and the repository's authentication/lifecycle contract.

**Organization**: Tasks are grouped by independently testable forum, vendor and observability journeys.

## Format: `[ID] [P?] [Story] Description`

- `[P]` identifies work in different files that can run in parallel after prerequisites.
- `[US1]`, `[US2]`, `[US3]` map to the specification's user stories.
- Checkboxes distinguish completed work from pending verification and release gates. A cancelled
  task remains unchecked and carries an explicit cancellation note; it is not counted as executed.

## Phase 1: Setup (Shared Infrastructure)

- [x] T001 Initialize official Spec Kit Codex/Python workflow at `.specify/` and `.agents/skills/speckit-*/SKILL.md`, preserving existing Pinova instructions.
- [x] T002 Establish `.specify/memory/constitution.md` and `specs/001-capability-integrations/` specification, design and quality checklist.
- [x] T003 Record actual current/base native interfaces and lifecycle assumptions for `src/Integrations/IntegrationSettings.php` against verified third-party fixture sources.

## Phase 2: Foundational (Blocking Prerequisites)

- [x] T004 Replace shared version gates in `src/Integrations/IntegrationSettings.php` with saved-intent and fresh per-operation capability checks; version evidence is descriptive and at most 48 characters per value.
- [x] T005 Cover late initialization, unfamiliar versions, malformed versions, missing callables and incompatible signatures in `tests/Unit/CapabilitySnapshotTest.php` and `tests/Unit/CapabilitySnapshotFixture.php`.

**Checkpoint**: One shared readiness definition; no dependency construction, account mutation or stale early absence.

## Phase 3: User Story 1 - Forum Coordination Through Compatible Updates (Priority: P1)

**Goal**: Keep forum coordination independent of WooCommerce and preserve native membership decisions.

**Independent Test**: Enabled forum routing and mobile registration with WooCommerce absent or changed, plus missing-membership and pending-state cases.

### Tests for User Story 1

- [x] T006 [US1] Add forum-only, compatible-version and missing-membership regression cases in `tests/Integration/wpforo-integration.php`; assert administrator holds, bans and truthful email confirmation.

### Implementation for User Story 1

- [x] T007 [US1] Gate automatic owned-membership operations by membership capability in `src/Integrations/WpForo/Load.php`, preserving pending-state enforcement when routing is off or support disappears.
- [x] T008 [US1] Preserve native REST topic initialization and upstream authentication errors in `src/Integrations/WpForo/Load.php`, independent of routing switches and WooCommerce.
- [x] T009 [US1] Verify forum deactivation/reactivation and legacy-account decisions in `tests/Integration/wpforo-integration.php` without metadata deletion or permission widening.

**Checkpoint**: Forum journey works independently; missing membership support does not activate restricted accounts.

## Phase 4: User Story 2 - Safe Vendor Onboarding Through Compatible Updates (Priority: P1)

**Goal**: Keep routing available while native conversion requires mobile proof and a recognized safe boundary.

**Independent Test**: Native vendor conversion with verified/unverified synthetic accounts; preserve account, orders, approval and forum membership.

### Tests for User Story 2

- [x] T010 [US2] Add current-version, ambiguous/missing native handler, missing forum-role callback and invalid mobile-proof regressions in `tests/Integration/DokanIntegrationTest.php`, `tests/Unit/CapabilitySnapshotTest.php` and `tests/Unit/CapabilitySnapshotFixture.php`.

### Implementation for User Story 2

- [x] T011 [US2] Revalidate the native mutation boundary immediately before conversion in `src/Integrations/Dokan/Load.php`; ordinary public registration/login never assigns seller status.
- [x] T012 [US2] Scope only the recognized forum role callback during authorized conversion in `src/Integrations/Dokan/Load.php`, preserving pending restrictions and native selling/product approval.
- [x] T013 [US2] Characterize native vendor reports, parent-order restrictions and preserved orders under both stores in `tests/Integration/dokan-hpos-reports.php`.

**Checkpoint**: Routing and conversion degrade independently; account privileges remain governed by proof and native approval.

## Phase 5: User Story 3 - Actual Coordination Health (Priority: P2)

**Goal**: Show saved intent versus current readiness with bounded evidence and read-only reporting.

**Independent Test**: Capability-loss/recovery observations, unchanged repeats, report no-writes and logging-failure injection.

### Tests for User Story 3

- [x] T014 [US3] Test fixed observation slots, unchanged/no-write behavior and logging failure in `tests/Integration/IntegrationHealthIntegrationTest.php`.

### Implementation for User Story 3

- [x] T015 [US3] Implement status observation in `src/Integrations/IntegrationHealth.php` with at most one non-autoloaded option per integration containing only state and reason; wire safe lifecycle points in `src/Pinova.php`.
- [x] T016 [P] [US3] Add finite transition event/status/reason handling and bounded throttling in `src/Logging/EventEvidence.php` and `src/Logging/EventThrottle.php`.
- [x] T017 [P] [US3] Include fresh `integrations` snapshots separately from retained historical issues in `src/Logging/IssueMonitor.php` and `src/Admin/Logs.php`.
- [x] T018 [US3] Render escaped off/active/partial/unavailable state and actionable reasons in `src/Admin/Issues.php` and the existing integration settings description in `src/Integrations/IntegrationSettings.php`.
- [x] T019 [US3] Verify current health, redaction, sampling and read-only report behavior in `tests/Integration/IssueMonitorIntegrationTest.php`, `tests/Integration/EventThrottleIntegrationTest.php` and `tests/Integration/logs-command.php`.

**Checkpoint**: Reports expose health even without historical events and do not persist observations or alter account decisions.

## Phase 6: Polish and Delivery Gates

- [x] T020 [P] Add checksum-verified deployed combinations to `tests/fixtures/third-party-baseline.json`, fixture/runner Node tests, `tools/prepare-third-party-fixtures.mjs`, `tools/configure-wp-env.mjs`, `tools/run-third-party-baseline.sh` and `.github/workflows/quality.yml`.
- [x] T021 [P] Update stable public behavior in `README.md`, `readme.txt` and the Pinova skill references; synchronize prepared-release entries in `CHANGELOG.md` and `readme.txt`.
- [x] T022 Run lightweight syntax, governance, changelog, skill-sync and targeted Node checks; record exact outcomes under `.agents/reviews/`.
- [x] T023 Review and commit the focused change, open the authorized GitHub pull request and complete required exact-head CI for `.github/workflows/quality.yml`.
- [x] T024 Run isolated real-plugin/browser characterization for prior/current/mixed/deployed combinations and both order stores using `tools/run-third-party-baseline.sh`; record acceptance evidence under `.agents/reviews/`.
- [x] T025 After reviewed merge and green merged-main CI, prepare and publish an immutable prerelease through `.github/workflows/`; do not rewrite an existing tag or release.
- [x] T026 Independently download and verify the release ZIP, checksums, SBOM, attestations and package-content exclusion of `.specify/`, `.agents/` and `specs/`; record evidence under `.agents/reviews/`.
- [ ] T027 Create a verified scoped rollback backup and install only that published asset on the authorized test site using the quality-reference staging procedure; record installation evidence under `.agents/reviews/`. **Cancelled/superseded:** the user explicitly declined a fresh backup; backup creation was not executed. Replaced by T033, with current-database-preserving files-only rollback.
- [x] T028 Verify complete test-site login, mobile proof, forum membership/holds, native vendor conversion, orders and report surfaces; record the observation window and remaining limitations under `.agents/reviews/`. Acceptance used actual test-site CLI/public user-API OTP completion and native cookie hooks plus remote CI Chromium; it does not establish physical SMS receipt, an on-host browser run or a 24-hour window.
- [x] T029 Reconcile all completed tasks against actual CI/release/staging evidence in `specs/001-capability-integrations/tasks.md` and notify the user for their own production installation. The dated evidence includes final isolation/HTTP receipts and the actual bilingual user notification; production installation was not performed.

## Dependencies & Execution Order

### Phase Dependencies

Setup precedes shared capability design. US1, US2 and US3 depend on that shared definition;
source work can proceed in parallel with explicit file ownership. Native-boundary verification,
review, CI and staging remain mandatory even when local source work is complete.

### User Story Dependencies

US1 needs only forum capabilities and is independent of Dokan/WooCommerce. US2 is independently
testable without forum, but preserves forum groups when present. US3 consumes the shared
snapshot and does not mutate either journey's eligibility. Shared-file edits must remain serialized.

### Within Each User Story

Add meaningful regressions before accepting the final implementation; characterize the native
boundary, implement the smallest adapter change and run the actual isolated verification gate.
An unrun test, source inspection or synthetic provider path is not completed operational acceptance.

### Parallel Opportunities

T016 and T017 own different logging/report files after the shared snapshot exists. T020 and T021
can run alongside runtime work with independent file ownership. Forum and vendor adapters can
be implemented in parallel, but edits to `IntegrationSettings.php` require one owner.

## Parallel Examples

- US1: Forum lifecycle regression work and review of the existing REST initialization boundary.
- US2: Native fixture inspection and order-store characterization preparation in separate files.
- US3: Finite evidence/throttle changes and report rendering changes after the snapshot contract is fixed.

## Implementation Strategy

### MVP First

Deliver the shared readiness checks and the independently verified forum journey first, keeping
all existing restrictions. Complete vendor and health journeys before the requested release.

### Incremental Delivery

Record source completion separately from test results. A reviewed green repository change is
followed by immutable prerelease verification, authorized test-site installation, complete
acceptance and user notification. No task grants production installation authority.

### Parallel Team Strategy

Assign one owner to shared readiness, separate owners to vendor/forum runtime and logging/CI,
and one owner to Spec Kit artifacts. Reconcile contracts and tasks at final review.

## Notes

Source implementation, regression execution, CI, publication, independent download/attestation
verification and test-site acceptance are now recorded in
[`capability-integrations-rc15-2026-10-11.md`](../../.agents/reviews/capability-integrations-rc15-2026-10-11.md).
Checkmarks refer to that evidence and its stated limits. T027 was cancelled rather than executed;
T033 records the explicitly authorized replacement. Final isolation/HTTP receipts and actual
bilingual user notification were recorded separately from the functional tests.


## Phase 7: Convergence

The initial assessment consolidated the remaining evidence gates without adding runtime scope.
Their statuses below are reconciled against the actual RC15 source, CI, release and test-site
evidence; the backup exception is recorded separately rather than claiming T027 was executed.

- [x] T030 Complete actual isolated native-plugin, browser, order-store and health-report execution through `.github/workflows/quality.yml` and `tools/run-third-party-baseline.sh`; record evidence under `.agents/reviews/` and reconcile T009/T013/T019/T024 per FR-012 and SC-001 through SC-005.
- [x] T031 Complete reviewed GitHub history, exact-head and merged-main checks, immutable prerelease publication and independent asset/attestation verification through `.github/workflows/`; record evidence under `.agents/reviews/` and reconcile T023/T025/T026 per FR-014 and SC-006.
- [x] T032 Complete verified scoped rollback, published-asset installation only on the authorized test site, full acceptance and user notification; record evidence under `.agents/reviews/` and reconcile T027/T028/T029 in `specs/001-capability-integrations/tasks.md` per FR-014, SC-006 and US1/US2 acceptance scenarios. T033, functional acceptance, final isolation/HTTP receipts and actual bilingual user notification are complete. T027 is reconciled as cancelled, not completed.
- [x] T033 Verify existing rollback material and independently verified public RC14 files; install only the verified published RC15 package on the authorized test site and exercise files-only rollback preserving the current database. No fresh backup or database restore. Previously verified October 10 database material is retained as historical recovery material, not a current full-database recovery point.

## Evidence reconciliation — 2026-10-11

The [dated RC15 record](../../.agents/reviews/capability-integrations-rc15-2026-10-11.md) names the
reviewed PR, exact source/tree, distinct CI runs, immutable tag/release, all four asset digests,
independent signature/SBOM verification and actual RC14 → RC15 → RC14 → RC15 test-site cycle.
It separates current read-only health from best-effort historical events and remote CI browser
coverage from native test-site checks. Production installation remains the user's operation.

Final reconciliation: 33 tasks total; 32 completed and one cancelled/superseded (T027), with no
pending implementation/delivery tasks. This does not mean all 33 tasks were executed: the
fresh-backup task was explicitly declined and was not performed. Production installation,
physical SMS receipt and a later production observation window are outside this task completion.
