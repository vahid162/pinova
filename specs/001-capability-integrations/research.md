# Research: Capability-Based Integrations

## Runtime compatibility decision

**Decision**: Replace exact version allowlists with inspection of the interfaces each operation
will call, including callable signatures and the recognized native mutation callback boundary.

**Rationale**: The prior shared status check rejected both enabled integrations when WooCommerce
changed outside a single admitted version. That gate mixed saved intent with runtime readiness
and made an unrelated commerce release disable forum coordination. Version equality does not
establish or disprove compatible behavior.

**Alternatives considered**: Expanding the version list repeats maintenance after each update;
accepting an open version range still guesses semantics; enabling unconditionally can bypass
safe conversion or membership prerequisites. A new adapter framework adds unnecessary scope.

## Independent features and account restrictions

**Decision**: Separate routing from membership, REST initialization, vendor conversion and forum
role preservation. Missing dependencies must not erase owned pending states. Revalidate the
current capability and native callback boundary immediately before account mutation.

**Rationale**: Pinova already contains scoped native lifecycle adapters and purpose-bound mobile
proof. An interface check is useful only when the actual operation still enforces its account,
approval and callback invariants. Retain explicit native extension restrictions where behavior
has not been characterized, including Dokan Pro conversion.

**Alternatives considered**: A single integration-wide switch unnecessarily removes functioning
routes; restoring forum groups after conversion can fail after a privilege mutation. Continue
scoping only the identified incompatible role callback during the authorized native transition.

## Fresh read-only observations

**Decision**: Inspect already initialized plugin objects on demand without constructing native
services, initializing dependencies, making network calls or caching early missing services.
Evaluate native hooks at the actual operation boundary.

**Rationale**: Bootstrap order and REST lifecycle differ. A cached early absence or container
resolution during observation can change behavior before native initialization is complete.

**Alternatives considered**: Persistent readiness caches require invalidation and can go stale;
bootstrapping missing dependencies inside inspection makes read-only reporting mutate state.

## Issue visibility and event volume

**Decision**: Add current readiness to existing settings, Logs and Issues, JSON and read-only
CLI reports. Coalesce finite status/reason changes through one fixed observation slot per plugin,
with at most two events per safe observation, outside read-only report/CLI paths and public
authentication requests. Runtime operation failures use the existing finite throttle.

**Rationale**: Event-only reports cannot expose an enabled integration whose version gate kept
it inactive without throwing an error. Current health must remain visible even when logging or
retention misses a historical transition. Diagnostic version changes are not incidents by themselves.

**Alternatives considered**: Logging on every request floods the table; mirroring server logs
introduces privacy/volume risks; adding autonomous repairs exceeds the user's monitoring scope.

## Verification and delivery

**Decision**: Reuse the isolated compatibility runner with verified official archives for the
previously characterized releases, mixed pairs and the reported installed combination. Cover
both order stores and capability-loss mutations; preserve the GitHub release and staging gates.

**Rationale**: Loaded symbols cannot prove all native membership/vendor semantics. Real-plugin
characterization is required before claiming compatibility. Pinned archive fixtures create
repeatable evidence without imposing those pins on runtime users.

**Alternatives considered**: Source-only tests would miss native callbacks and lifecycle changes;
testing on production would violate the approved delivery and shared-resource boundaries.

## Source evidence and remaining gates

Decisions derive from `IntegrationSettings`, the Dokan/wpForo adapters, mobile verification,
Logs and Issues, and the repository's project-map/logging/quality references. Concurrent
implementation owns exact interface details and test evidence. There are no unresolved scope
questions; successful CI, immutable prerelease validation and test-site acceptance remain gates,
not assumptions or completed results.
