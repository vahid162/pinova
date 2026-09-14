# Account and Blocked List UI — Phase 3 Blocked List Contract

Date: 2026-09-14 (Asia/Tehran)

## Scope and lineage

- Development branch: `fix/1.2.5-account-block-ui`.
- Development base: `main` commit `c54f442dff0b3edc3476a6587c883f16e59fc486`.
- Affected runtime release: immutable `v1.2.5-rc1`, commit `9b86398cfbae46072c4b26aba1588a3b40454efc`.
- This phase changes only the isolated development worktree and a newly created disposable wp-env. It does not change production, the active 48-hour canary, GitHub branches, tags, releases, packages, WordPress options, or production database rows.
- The separate 1.3 identity/migration line remains out of scope.

## Before evidence

The new Blocked List regression suites were first run against the unchanged 1.2.5 runtime. The integration file reported 5 errors and 5 failures across 16 tests/35 assertions, while all 6 initial administrator-page JavaScript scenarios failed. This reproduced the Phase 1 findings: the selected type was ignored, JSON `blocked_by` filtering was overwritten, filter response shapes disagreed, system rows could be changed through add, pagination/navigation were inconsistent, and a block added after OTP issuance did not stop verification.

## Implemented backend contract

- The add route now requires one of `mobile`, `email`, `username`, or `ip`; each value is validated and canonicalized according to the explicitly selected type.
- Type/value mismatches return a field-specific WordPress REST error and do not write a row.
- Empty `blocked_until` remains a permanent `NULL` block. Temporary blocks require a future date, and expiry cleanup explicitly targets only non-null expired dates.
- Re-adding a canonical identifier updates one row. A manual request cannot convert or delete a system-managed row.
- The filter route consumes its declared JSON parameter and returns a bounded stable list of `{id,name}` values. Index filtering uses the selected administrator ID directly.
- Pagination uses a ceiling count, clamps an out-of-range page, and represents an empty result as one stable UI page.
- Add/delete errors no longer expose exception text or raw identifiers. Audit events retain only type, keyed fingerprint, status, administrator User ID, and resource ID.
- A row remains renderable if its original administrator account has since been deleted; the UI uses the safe `کاربر حذف‌شده` label.

## Block-to-login behavior

- Active mobile, email, username, and canonical IPv4/IPv6 blocks are enforced through the public authentication validators.
- Active client-IP blocks stop public authentication before OTP or password processing.
- OTP verification rechecks both client IP and the OTP identifier. A block added after code issuance therefore prevents verification, session creation, and user creation.
- Safe blocked messages identify only the input type and do not reveal account existence or native-only role membership.
- No attacker-controlled denial event was added, avoiding persistent log amplification.

## Administrator UI behavior

- The add modal consistently defaults to a permanent mobile block and labels the selector as the identifier type.
- The selected type is sent in the add request, and field-specific server errors are attached to the corresponding modal field.
- Add and delete operations reload the current filtered page from the server.
- Page navigation loads blocks rather than the unrelated user filter, clamps page values, and follows server pagination metadata.
- Filter search consumes the stable response shape, and clearing filters clears the selected administrator state.
- The previously blank type-error element now renders its validation message.

## Verification

- PHP syntax lint: passed.
- Unit PHPUnit: 17 tests, 41 assertions, passed.
- PHPStan with a 512 MB analysis limit: passed with no errors.
- PHPCS: 10/10 files passed.
- JavaScript: 16 focused REST-helper and Blocked List scenarios passed.
- Full integration, HPOS off: 44 tests, 170 assertions, passed.
- Full integration, HPOS on: 44 tests, 170 assertions, passed.
- Changelog synchronization: passed for 13 releases.
- Pinova Skill synchronization: passed.
- Composer locked dependency audit: no known advisories.
- npm production dependency audit: no vulnerabilities. The task-local development graph still reports two high-severity `extract-zip` advisories through `@wordpress/env` 10.35.0; npm proposes a forced breaking downgrade/change, so no automatic forced mutation was made. This is a development-tooling risk and does not enter the installable plugin ZIP.
- `git diff --check`: passed.

## Environment disposition and remaining work

- The uniquely named Phase 3 wp-env is retained for the next explicitly authorized phase; it is isolated from the previously preserved hotfix environment and from production.
- Account-page semantic markup, RTL/accessibility, digit/state/timer/history behavior, no-JavaScript fallback, and visual responsive redesign remain deferred to Phase 4.
- No commit, push, pull request, ZIP, tag, release, deployment, or production mutation was performed.
