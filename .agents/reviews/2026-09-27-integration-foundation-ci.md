# Integration foundation CI evidence

Historical snapshot captured on 2026-09-27. The implementation remains in draft PR #54; this is not a completion or installation claim.

- Remote foundation commit: `b41bd27af45f1096073bea0a4ed3e354f7831654`.
- Quality run: https://github.com/vahid162/pinova/actions/runs/36330754579
- Twenty jobs passed: all eight WordPress/WooCommerce/HPOS integration combinations, all other PHP matrix jobs, real pinned wpForo/Dokan characterization, account Chromium acceptance, JavaScript, dependency and supply-chain checks, Plugin Check, workflow/shell lint, and project guidance.
- PHP 8.1 unit/static checks passed, but its later PHPCS step rejected two style violations in `AuthenticationPolicy.php`. Consequently the aggregate required gate failed. The candidate is not green until a subsequent exact-head run passes.
- New regression coverage includes integration setting normalization/capability/nonce checks, per-provider expiry and cancellation barriers, and thirteen pre-session policy cases. The settings form test was corrected to accept WordPress's valid single-quoted attributes and strengthened to assert that both real checkboxes render.

## Additional native vendor characterization

The dedicated test-site normal fixture was extended and rerun successfully with role synchronization enabled only in the process's wpForo settings object. A synthetic secondary forum group was set through `Members::set_secondary_groupids()`, and native Dokan conversion then cleared it through wpForo's role synchronization hook. The completion marker confirms the scenario and account cleanup. This establishes the membership-loss regression that the Pinova Dokan adapter must cover. No saved role mapping, third-party source, shared service, or other site was changed.

## Pending boundaries

Shared mobile proof, authenticated number verification, runtime wpForo/Dokan adapters, combined browser acceptance, candidate release, and test-site upgrade are still required. Existing baseline tests deliberately describe behavior with the adapters off.
