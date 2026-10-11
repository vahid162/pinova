# Integration Health Contract

## Shared Runtime Interface

`IntegrationSettings::requested(slug)` reports normalized saved intent.
`IntegrationSettings::capable(slug, feature)` inspects the required loaded native interfaces.
`IntegrationSettings::enabled(slug)` combines requested intent with routing readiness.
`IntegrationSettings::snapshot()` supplies the fixed readiness structure in [data-model.md](../data-model.md).
Versions are descriptive evidence; no equality or range comparison decides activation.

No inspector initializes a native plugin, resolves a container service, changes settings or
writes account data. Native conversion must additionally revalidate the mutation boundary at
the point of submission. Operation guards preserve the existing pending and proof contract.

## Administrator Settings

The existing integration tab retains two independent checkboxes and shows current state,
controlled reason and observed versions. Saving intent does not imply healthy readiness.
No new menu, dependency setting write, identity migration or automatic upgrade is introduced.

## Logs and Issues, JSON and CLI

Reports include a top-level `integrations` readiness snapshot alongside historical issues.
The observation timestamp is distinct from the report's event window. Current readiness is
not a historical event count and does not close or mark an issue recovered. Administrator
rendering escapes all evidence; export/CLI retain the existing capability and redaction rules.

`wp pinova logs report` remains read-only. Viewing/exporting a report must not persist a health
observation, change an integration switch, mark an issue reviewed or execute queued work.

## Transition Events

- Event names: `integration.wpforo_status_changed`, `integration.dokan_status_changed`.
- Context: controlled `status` and `reason` values only; no customer/account/provider data.
- Partial/unavailable transitions are operational evidence, not automatically confirmed code bugs.
- One fixed observation slot per integration deduplicates identical state/reason pairs; each safe observation emits at most two events. Operation failures use the existing finite throttle.
- Logging failure cannot change authentication, proof or onboarding eligibility.
- Logging may be incomplete or suppressed by the configured level. Claims precede best-effort logging, so unchanged observations do not retry missing events; the fresh snapshot must still show current health.

## Degradation Guarantees

Forum routing uses no WooCommerce version prerequisite. Missing membership support prevents
activation of owned pending members while safe login routing can continue. Vendor conversion
requires a recognized native callback boundary and safe forum-role preservation whenever the
forum is present. Ordinary OTP login never grants seller status. Turning routing off cannot
clear a pending restriction or imply approval.
