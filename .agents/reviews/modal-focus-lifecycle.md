# Modal focus lifecycle: post-merge release blocker

## Scope and provenance

Recorded on 2026-09-19 while preparing the next immutable release after RC9. This follows [PR27](https://github.com/vahid162/pinova/pull/27), not a rewrite of its green-head evidence.

PR27's documentation head `46b72fd7ce3fb5c7a42ad941a9ad878d5d62b7be` passed all 16 jobs in exact-head PR run `35426578052` and review comment `5739926812` reported no major issues. It merged as `04535ac308e85497f453305c2133facf244d4a54`; the merged tree is identical to the reviewed tree.

The separate main-push run [35426974523](https://github.com/vahid162/pinova/actions/runs/35426974523) then exposed a real timing defect. Fifteen jobs passed. Browser job [105854520453](https://github.com/vahid162/pinova/actions/runs/35426974523/job/105854520453) passed 13 of 14 tests, but pending-request dismissal failed restored opener focus at `tests/browser/account-ui.spec.mjs:564`. Both corner-control tests passed. Sensitivity was skipped after normal acceptance failed; run-scoped cleanup succeeded.

The downloaded failure artifact `10579257124` matched SHA-256 `a1ecd9810a4a89e0192bcdbbe36b3e8bbe344cea195fd020cce89473c061dbdf`. The modal controller blob `b63bc9db65a56692273569383dd0aa9f69ca0f69` was unchanged from RC9. No package was published from that failing main commit.

## Reproduction and cause

An isolated Chrome diagnostic used the actual modal template, CSS, Alpine, and controller, with a mock pending REST response and controlled rapid-dismissal timing. One of 24 scheduling cases reproduced lost opener focus. Passive logs showed:

1. `closeModal` returned focus to the opener.
2. The previously scheduled 100ms heading callback ran after close while reactive DOM hiding had not yet completed.
3. It moved focus back to the heading; hiding that element then lost the intended focus target.

The source callback did not verify current modal state, requested step, or request identity. The original CI trace did not itself record timer execution; the source and separate passive-log reproduction establish this mechanism, rather than a fabricated timer event in that trace. Counts from a small scheduling diagnostic are not a production failure-rate estimate.

## Bounded correction

A monotonically increasing `focusSequence` identifies pending heading/return-focus tasks. A heading callback runs only for the latest request while the modal is open on the requested step. Closing invalidates prior tasks. The close-return callback also checks that it has not been superseded by reopening. No arbitrary sleep, test retry, or disabled assertion is used as a repair.

Only UI focus coordination changes in `assets/js/pages/login-modal.js`. Authentication endpoints, credentials, request cancellation, OTP rules, forms, logo, CSS layout, and PHP logic remain unchanged. The shared `.5` asset revision is still unpublished and remains the next package's cache revision; no new version or dependency change is needed. Published RC9 remains untouched.

## Regression evidence before CI

`tests/js/modal-focus-lifecycle.test.mjs` executes the shipped controller with deferred callback scheduling, not immediate fake timeouts. It checks normal heading/return focus, busy dismissal before heading delivery, superseded steps, repeated requests for the same step, and rapid close/reopen. The old runtime fails the adverse-order assertions. The candidate passes all five new tests and the full 105-test JavaScript suite.

Repeating the same 24-case isolated Chrome diagnostic with the candidate produced no lost opener focus. This is supplemental browser evidence, not full WordPress integration or physical-device acceptance. The original real-browser pending-dismissal assertion is unchanged, so CI must still pass that behavior without masking the race.

The exact final PR head, merged-main head, publish-branch head, and release each require their own applicable checks. A subsequent release evidence record must provide actual IDs and outcomes; this pre-CI report does not predeclare them.

## Release and rollback boundary

The operator authorized a ready-to-install corrected candidate, not production installation. Use a reviewed follow-up branch `fix/modal-focus-lifecycle`, green exact-head PR and merged-main checks, and the existing immutable publisher. Do not rerun an unchanged failure merely to obtain a green badge. Preserve the failed run and prior release history.

If rollback is needed before release, revert the focused follow-up commit through normal review. Never move RC9 or another published tag. Physical mobile/Firefox, actual safe areas, assistive technology, and staging/canary acceptance remain separate from package verification and production installation.
