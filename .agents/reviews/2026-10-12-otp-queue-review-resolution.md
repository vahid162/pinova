# OTP queue recovery: reviewed corrections, 2026-10-12

This dated record closes the findings from the completed source review in `2026-10-12-otp-queue-recovery-code-review.json`, run `20261012-074824-4bed127e`. It is source-review evidence, not release, staging or production acceptance.

The formal review completed ten selected lenses and one fresh validation batch. No different-provider CLI was available; adversarial review used the local fallback, with no cross-model corroboration claimed. A recovered performance-review capacity failure did not leave a missing lens.

## Applied findings

1. The post-provider-acceptance save-failure regression now installs a test-local dispatcher implementing the existing Illuminate contract. It reaches the existing-model saving event and restores an originally null dispatcher with `unsetEventDispatcher()`. No dependency was added.
2. Recovery excludes positively held native locks before consuming its finite candidate window. Final lock acquisition, compare-and-set, cancellation and deadline checks remain effective. A regression places three earlier held claims before an unlocked orphan.
3. Acknowledgement's initial read and CAS-loser readback use the same accepted-state predicate. Ready, processing and delivered states are accepted; cancelled, reserved, expired and database-error states remain rejected. Deterministic race cases cover both positive and negative outcomes.

The recovery-window gap was also addressed: native scheduled flow IDs are excluded through prepared placeholders before `LIMIT 32`, with a final native schedule check before dispatch. A fixture places 32 earlier future/recurring events before a missing-job orphan and verifies both progress and preservation. Worker dispatch remains limited to three jobs and new starts within twenty seconds. No persistent cursor or global repair option was introduced.

The queue-health boundary regression creates 1,001 synthetic rows in the disposable test database. It checks explicit truncation, 1,000 finite observed state counts, identity/payload exclusion, read-only behavior and agreement between administration, report and incident export. Current snapshot incompleteness remains separate from historical event coverage.

A completed bounded follow-up review found no outstanding functional finding. It identified and verified a narrow PHPCS annotation for the fixed-placeholder interpolation, consistent with the existing prepared-SQL pattern. All three relevant PHP files passed syntax checks and the diff passed whitespace checks.

## Simplification and verification boundary

The required simplification pass completed reuse, quality and efficiency reviews. No change was applied. Skipping the recovery read after a full native batch was rejected because it would suppress an existing database-read failure; that suggestion is not deferred work.

Root verification passed syntax for eighteen changed PHP files, governance, synchronized changelogs, metadata/readme size and instruction-contract synchronization. Full PHP/JavaScript, integration, browser, third-party, package and supply-chain execution remains a required GitHub Actions gate. No local dependency installation, unrestricted analysis or development suite ran on the shared host.

Public queue events are acknowledged only after scheduling and signed-response preparation; legacy internal callers retain their prior stored-payload observation. A recovery event records an attempt before claim, not provider acceptance or physical receipt. Current queue reads never repair or delete records.

## Operational boundary

Only a dedicated test-site installation is authorized after immutable publication and independent verification. Production remains unchanged. The operator declined a new database export; the same-schema files-only test path therefore uses the verified predecessor public package for file rollback and preserves ownership, modes, configuration and settings. Scoped maintenance must drain older workers before replacement. A rollback must first drain or expire new prefixed work, which predecessor workers cannot interpret. No expired or historically unacknowledged legacy orphan is automatically resent.
