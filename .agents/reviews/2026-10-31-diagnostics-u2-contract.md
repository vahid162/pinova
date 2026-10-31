# Diagnostics upgrade U2: bounded stage contract

Date: 2026-10-31. Scope: approved plan unit U2, requirements R2/R3/R4/R8/R13. This records the foundation; producer instrumentation, incident views, UI, resource comparison and installation remain later units.

## Reviewed source

The foundation commit is `6bc549048c2fffd003676e236bf8c1f6d308c769`, tree `0234ab0856aeb0adc32cdc286f6016069188b4dd`. An actual Compound Engineering code review completed with ten collected independent lenses: correctness, standards, testing, maintainability, agent-native, security, performance, API contract, reliability and adversarial. It reported no introduced actionable findings; its original verdict awaited hosted checks. The immutable private review receipt SHA-256 is `23e8d389f4770e2eb94d412291d1d9b4c833ed23aaaf4e31568d55e6fec388cf`.

The cross-model availability check found no attestably different installed route; no source was sent to another external model service. The independent adversarial lens completed through the local review route.

## Resulting contract

- `DiagnosticContext` accepts a caller-authenticated flow and original absolute deadline. Construction and observation use bounded memory and clocks, without WordPress, options or database calls. It buffers at most twelve finite stage observations and drains once after all delivery leases end.
- One existing logger path admits `otp.diagnostic` only for an explicit matching operation or flow scope within an unexpired window. Ordinary PSR calls and administrative audit cannot bypass that admission. The normal logger threshold remains unchanged in this unit.
- The final sanitized context, including trusted package metadata, is bounded to 2 KiB. The same finite catalogue applies to historical output; storage time and whole-second occurrence time remain distinct.
- One fixed row in the existing rate-limit table jointly limits site admissions to 1,000 per 900 seconds and live flow admissions to twelve, using at most 1,024 conservative HMAC slots. A site reset preserves live flow counters. A stale, replayed, missing or corrupt state suppresses detail; no automatic retry or refund grants permission.
- Exhaustion has one coalesced coverage marker per window. Dropped observations do not each write a counter. Read snapshots never initialize or repair state.
- General cleanup preserves exactly this one constant budget key; other expired rows retain their prior behavior. No identity schema or encrypted queue payload changed.

## Pre-implementation evidence

A test-only proof branch added the finite historical/input catalogue regression before runtime edits. [Run 41193038489](https://github.com/vahid162/pinova/actions/runs/41193038489), source `2e3faa9025ea70ad8fd24ec13bd8b71931cbe69a`, failed the intended assertion with one test, one assertion and one failure. The old sanitizer discarded the new finite facts. This was a behavioral failure, not an import or infrastructure failure; the proof workflow never entered the feature branch.

## Separate existing privacy defect

Commit `54d1de5e94de475e97cfe4a49fd5781da356a04c` separately corrected pre-existing anonymous Throwable class metadata that could carry a NUL and source path. One pure ASCII symbol validator now handles objects and explicit class strings, preserving valid names and codes while rejecting source-bearing or malformed values without autoload/reflection. An independent targeted review and real unit regressions cover the correction.

## Actual verification and correction

[Run 41195863236](https://github.com/vahid162/pinova/actions/runs/41195863236) on `54d1de5` is retained as failed. Every runtime gate passed, including all WordPress/WooCommerce and real wpForo/Dokan variants, Chromium UI, both native FPM boundaries and sixteen full-stack FPM assertion groups. Its failures were PHPCS formatting/private-parameter naming and PHPStan treating the late clock/state check as pure.

Commit `1db7932a9c2f09ef411a2b345c110fc0876c0fac` corrected formatting and the accurate `@phpstan-impure` annotation. Both late expiry checks remain. A bounded token comparison established executable equivalence except for the private parameter rename; no test or quality rule was weakened.

[Final U2 Quality run 41196695157](https://github.com/vahid162/pinova/actions/runs/41196695157) passed on that exact commit: 37 successful jobs, one expected push-only Dependency Review skip and no failures. Required Quality Gate job `124525816131` passed. Evidence includes:

- 154 unit tests and 595 assertions on each PHP 8.1–8.5, plus PHPStan on each and required PHP 8.1 PHPCS;
- all twelve WordPress/WooCommerce integration variants; the examined WordPress 6.8 and PHP 8.5 jobs each passed 569 tests and 3,965 assertions;
- all twelve real third-party plugin variants, Chromium UI and PHP 8.3/8.4 response boundaries;
- full-stack FPM job `124524184685`, all sixteen groups passed on its first attempt. Artifact `13040844210`, ZIP SHA-256 `bee2ea00a5dc095913c44e0f80cbdbf3348f76c08decbc7811830dda16c6c5f0`.

The earlier full-stack artifact `13040696963` was independently downloaded, hash/member/size checked, and its exact source and sixteen passing groups verified. These OFF/ON fixture results are not the separate U6 three-pair revision comparison or aggregate PHP PSS acceptance.

No live site, provider or SMS was used for U2. This unit is complete; no new release or installation is implied.
