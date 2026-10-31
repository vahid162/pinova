# Diagnostics upgrade U3: OTP timing, ownership and maintenance health

Date: 2026-10-31. Scope: approved U3, requirements R1/R4/R5/R6/R9/R13 and AE1/AE2/AE5. The isolated source candidate is `9890de3cfca0b0368a90b7767fda8fa8175071ed`, tree `c2b773a3941685c9ce3cac869b8887c77d740b8b`, based on accepted U2. Its twenty-one owned PHP files have manifest SHA-256 `59958e9154e9860ef1d91aff15fa31799a5dee47cb7aa25fbf2b273f274ac44e`. Temporary proof workflows and synthetic salt/bootstrap overlays do not enter canonical source.

## Resulting behavior

OTP stage observations remain bounded in memory until queue claims and fast leases are positively observed released. The diagnostic gate also covers native lock acquisition before claim registration, reentrant callbacks and unknown release outcomes. Nested contexts restore the previous context without borrowing provider authority.

Immediate arming retains the original returned persisted deadline on flow reuse. Request age uses its actual whole-second origin; provider duration uses a valid same-process monotonic clock after the final guard. HTTP completion is distinct from adapter acceptance and phone receipt. Unavailable or discontinuous clocks omit timings. Verification detail requires a matching signed record and purpose; legacy or invalid state cannot invent trusted flow context.

Both cron and immediate delivery now revalidate original connection, exact flow lock, claim and expiry at provider handoff. Internal decisions distinguish unknown activity from known cancellation/expiry. Never-attempted unavailable work defers: only the original still-owning worker can restore the exact READY reservation. Losing ownership cannot finish, restore or consume it. Prior accepted or uncertain handoffs remain authoritative; explicit provider false retains deliberate retry. Existing public bool and immediate-exception compatibility remain.

Queue health reads a bounded 1,001 rows and reports at most 1,000 observations without decryption or repair. Pending age and processing claim age have different origins; unknown reads/times/lock ownership remain explicit. A separate finite, non-autoloaded OTP cleanup observation exposes last result, backlog and continuation alongside read-only schedules. Seven-day post-expiry retention, null-expiry preservation, 5,000-row batches and five-minute continuation remain unchanged.

## Actual pre-correction failures

| Proof source / run | Observed behavior before correction |
|---|---|
| `20bb1ec`, [41197161079](https://github.com/vahid162/pinova/actions/runs/41197161079), job `124525548389` | One test, eight assertions, one intended failure: an ordinary logger reconnect after a failed native Bale handoff allowed a second provider without the original connection-owned lock. |
| `c51b9ef`, [41199826761](https://github.com/vahid162/pinova/actions/runs/41199826761), job `124533259180` | Three tests, twenty-eight assertions, three intended failures: two early lost-lease paths incorrectly finished/released work; reentry during native acquisition performed new diagnostic work under the lock. |
| `86b4cd3`, [41201084543](https://github.com/vahid162/pinova/actions/runs/41201084543), job `124536855561` | Three tests, twenty-four assertions, three intended failures: reconnect, silent read and throwing read in the channel loop still collapsed unknown activity into terminal false. |

The initial malformed proof YAML was an infrastructure failure, not behavioral red evidence. All failures above reached their actual assertions before the corresponding correction.

## Test isolation correction

The first complete candidate run [41199046991](https://github.com/vahid162/pinova/actions/runs/41199046991), source `541a133`, failed static checks and an old exact seven-key queue-health consumer expectation; runtime evidence is retained. The second [41200503082](https://github.com/vahid162/pinova/actions/runs/41200503082), source `71febde`, passed all units/PHPStan, eleven integrations, all real-plugin variants, UI and FPM, but retained PHPCS findings and one missing sampled native-policy log assertion.

Read-only diagnosis found committed legacy verification throttle rows left by the new independent-connection fixture, plus incomplete setup in the existing reset-logging test. The original runner's precise colliding slot was not observed. A separate test-only synthetic auth-salt collision made the route deterministic: [41201475772](https://github.com/vahid162/pinova/actions/runs/41201475772), source `15a4fcd`, job `124537970493`, reached the collision marker and failed the same native-policy log expectation with four tests and forty-nine assertions. Production throttle behavior was not changed.

The correction restores the fixture's bounded preexisting verification throttle rows in `finally`, and clears the verification event's scope in reset-test setup. With the same forced-collision bootstrap, [41202355837](https://github.com/vahid162/pinova/actions/runs/41202355837), source `cd355d6`, job `124540494124`, passed four tests and sixty-three assertions on attempt one. Both actual logs contain `PINOVA_LOG_COLLISION_PROOF_READY=1`; this is controlled red/green evidence, not a random rerun.

## Final acceptance

[Final Quality run 41202328755](https://github.com/vahid162/pinova/actions/runs/41202328755) passed on exact source `9890de3cfca0b0368a90b7767fda8fa8175071ed`, attempt one: thirty-seven successful jobs, one expected push-only Dependency Review skip and no failures. Required aggregate job `124542661701` passed. Actual evidence includes 155 unit tests / 615 assertions on PHP 8.1–8.5, PHPStan and required PHPCS, all twelve WordPress/WooCommerce variants (examined result: 612 tests / 5,615 assertions), all twelve real-plugin variants, Chromium UI and both PHP 8.3/8.4 response boundaries.

Full-stack FPM job `124540415958` passed all sixteen groups. Artifact `13043720324`, ZIP SHA-256 `c8df3b9d80b49b54ba94307e72156003885d7cf4695e0f1939a39c7b35048ea7`, was independently downloaded, checksum/member/size checked and its `state=passed`, exact source and sixteen groups verified. No reruns were used. This existing OFF/ON fixture does not replace U6's separate three-pair revision comparison, aggregate PSS coverage or improved race coordination.

An independent final targeted source review found no remaining blocker in unavailable-ownership deferral, prior provider outcomes, known inactivity, exact-owner restoration or the preclaim diagnostic gate. This is narrower than the final full Compound Engineering review required by U7. New cases include preceding accepted/uncertain outcomes, cancellation/expiry, silent/throwing reads and reentry. No quality rule was suppressed and no sensitive boundary was removed to pass checks.

No live site, provider or SMS was used for U3. Resource comparison, integrated final checks, release and deployment remain later gates.
