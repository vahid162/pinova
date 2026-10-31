# Diagnostics upgrade U5: visible feedback and flow-bound WebOTP

Date: 2026-10-31. Scope: approved plan U5, requirements R1/R3/R10/R11 and AE4. Functional verification is accepted; canonical integration and final release gates are separate.

## Source and behavior

The thirteen owned UI/test files match the independently constructed verification snapshot `0ce78ead5c68ce81e34661818447ff59968bd331`, based on accepted U2 source `1db7932a9c2f09ef411a2b345c110fc0876c0fac`. The owned-file manifest SHA-256 is `31d3980b52cc4eee51a66ba96a9f1c46fe6b237f1286c398f28bfc746640aaf2`.

The shared Woodmart/Flatsome partial renders errors and the received support reference as text, with seven associated field labels, errors and focus targets. No response reference is invented when no server response exists. Password toggles and navigation use native labelled controls; OTP input uses the existing one-shot normalization path. Account assets and their exact cache-version assertions are synchronized.

Both controllers start the optional browser credential wait only after accepted signed state, binding it to the component, generation, form, opaque state and identifier. Replacement, edit, history, page hide and modal/theme close invalidate earlier callbacks. Standalone REST responses also carry request-generation checks. Stable component roots are captured because Alpine's invoking-element magic can otherwise point to a detached step button.

A current-flow credential arriving during ordinary failed verification is buffered until the request finishes; the previous rejected code cannot automatically repeat. A deliberate manual retry remains possible. Throttling ends the wait. No new server resend, OTP deadline, authentication endpoint or public account-disclosure behavior is introduced. Raw caught objects and credential contents are not sent to the console.

## Native theme boundaries and independent review

Read-only inspection of installed Woodmart source verified its native sidebar close event and the dropdown's hover/class visibility rules, including its 0.4-second visibility transition. The Magnific Popup implementation verified the actual configured event target and before-close ordering. These facts informed narrow capability checks; commercial theme source was neither copied nor installed in the public CI fixture.

The implementation binds the sidebar's native close event and the popup's actual event target only for the owned content. Dropdown observation is limited to its two owners and bounded class-history records; hover close is checked logically even during the visibility transition. Missing optional capabilities disable autofill without blocking manual authentication. Cleanup removes only owned handlers and observers.

An independent targeted review found and followed correction of three P2 cases: theme close/reopen retaining an old wait, ordinary failed verification losing the current wait, and a hover close hidden by the CSS transition. The separately identified stable-root issue was also corrected. No code-level blocker remained in the reviewed runtime. This focused review does not replace the final complete Compound Engineering review.

## Actual regression evidence

Before runtime edits, [proof run 41197161079](https://github.com/vahid162/pinova/actions/runs/41197161079), source `20bb1ecdc208e9de84fab03b63a5aa87d80d4ac1`, job `124525548504`, failed both real-controller assertions: a stale credential entered the replacement flow. These were expected behavior failures, not setup/import failures. The initial malformed proof workflow is retained separately as an infrastructure failure and is not counted as red evidence.

The first candidate [Quality run 41197481730](https://github.com/vahid162/pinova/actions/runs/41197481730), source `50b80adf96e002a62d8b3ebaf63a9aadcfd1a6fb`, passed JS syntax and 173 of 175 tests, including all seventeen new behavior regressions. Its two failures were old exact cache-version expectations, later synchronized without weakening them.

The second dedicated browser campaign, [41198368468](https://github.com/vahid162/pinova/actions/runs/41198368468), source `df2cdf9d40d11096916094a2fbe6b495cfcd473c`, passed all 183 JS tests and 22 of 26 browser cases. Four browser failures came from a fixture that repeatedly relied on the intentionally suppressed same-code automatic submission. The correction changes only that fixture: distinct synthetic codes exercise separate error phases and an explicit manual action verifies deliberate retry. The failed campaign is preserved.

[Final dedicated campaign 41198671436](https://github.com/vahid162/pinova/actions/runs/41198671436), exact source `0ce78ead5c68ce81e34661818447ff59968bd331`, job `124529919794`, passed:

- syntax for 42 first-party JavaScript files;
- all 183 JS tests, including 25 focused WebOTP/request lifecycle cases;
- all 26 actual browser executions: thirteen cases each on Chromium and WebKit, with no retries.

Browser cases cover wrong password/code, HTTP 429 and invalid response, network failure without a reference, labels/focus, valid current autofill, stale replacement, native close/reopen and rapid hover close during the real transition duration. The PHP fixture renders the actual integration adapters and uses real plugin assets. Native theme lifecycle APIs are explicitly modelled boundaries, not a claim that the full commercial themes ran in CI. Every Pinova REST route is intercepted; no provider or recipient is contacted.

The complete [Quality run 41198671412](https://github.com/vahid162/pinova/actions/runs/41198671412) also passed on the same exact source, including the repository's required compatibility, static, supply-chain, Chromium and FPM gates. This verifies the isolated U5 source; the integrated final upgrade still requires its own exact-head gates.

The final artifact is `13042265482`, ZIP SHA-256 `c35e200e6892dda7826544665d8a67f088819015b8af8acd564d752e5627217b`. The verification-only workflow and browser config exist only on the verification branch; canonical browser discovery/required CI wiring belongs to U6.

PHP syntax and owned whitespace checks passed. Local Node is absent, so no local installation or heavy suite was attempted. WebKit and mocked WebOTP do not establish physical iPhone/SMS acceptance. No live site, release, provider or SMS mutation was performed for U5.
