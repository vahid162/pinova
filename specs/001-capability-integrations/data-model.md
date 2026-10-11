# Data Model: Capability-Based Integrations

## Integration Intent

- Storage: existing `pinova_integrations` option.
- Fields: `wpforo_enabled`, `dokan_enabled`; canonical persisted values remain `"0"` or `"1"`.
- Existing normalization and administrator authorization apply; no automatic toggle or reset.
- Relationship: intent selects coordination; feature readiness determines whether a specific
  operation can currently be used.

## Readiness Snapshot

- `observed_at`: UTC ISO-8601 observation timestamp.
- `wpforo`, `dokan`: one fixed entry for each integration.
- Entry `requested`: boolean saved intent.
- Entry `state`: `off`, `active`, `partial` or `unavailable`.
- Entry `reason`: finite controlled code explaining readiness, never raw exception text.
- Entry `features`: fixed operation-to-boolean map. Forum operations include `routing`,
  `membership`, `rest`; vendor operations include `routing`, `conversion`, `forum_groups`, `reports`.
- Entry `versions`: recognized safe dependency versions, at most 48 characters per value;
  malformed or absent evidence is represented as an empty value. Versions never authorize an operation.
- Read-only and fresh; no account identifiers, paths, payloads or stored history.

## Status Observation

- Storage: at most one non-autoloaded fixed option per integration.
- Fields: the most recently claimed `state` and `reason` only.
- Unchanged observation: no write, no event.
- Changed observation: atomically claim the new pair before best-effort bounded logging.
- Historical transitions: existing Pinova log table and retention, with only approved finite
  status/reason context. The current snapshot remains independent of retained event completeness.
- No customer relationship; no schema migration or unbounded per-version/per-user keys.

## Account Eligibility

Existing account User ID, mobile proof and Pinova-owned pending metadata remain authoritative.
This feature adds no identity field, seller assignment or member-state migration. Missing
capability or a disabled routing switch does not clear pending metadata or administrator holds.
A conversion requires valid mobile proof and a recognized safe native transition; forum
activation requires the existing owned-state and approval policy in addition to readiness.

## State Transitions

1. Saved intent off produces `off`; existing pending restrictions still apply.
2. Requested integration with missing routing produces `unavailable`.
3. Requested routing ready with required membership/conversion unavailable produces `partial`.
4. Requested integration with required operation capabilities ready produces `active`.
5. Plugin load order or a dependency update may change the fresh snapshot without changing intent.
6. An inspection error yields controlled unavailable/partial evidence and cannot activate a mutation.

`active` means inspected capabilities are ready, not that every future behavior, customer
journey or external delivery has been proven successful.
