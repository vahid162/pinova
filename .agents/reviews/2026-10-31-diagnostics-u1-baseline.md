# Diagnostics upgrade U1: operational reconciliation and baseline

Date: 2026-10-31. This evidence follows explicit approval of the diagnostics upgrade plan. The approved plan is preserved unchanged; subsequent human scope instructions govern installation authority.

## Source and scope

- Development branch: `feat/diagnostics-upgrade`, based on reviewed `main` at `f55692d2f80936729b198396e04431fad91423db`.
- Published reference: immutable prerelease `v1.3.0-rc21`, source `96386b9fdd803086e9ecfe2af421034976dc185d`.
- Initial U1 harness: `7a3c9357dd90598877d9fbaaa199eea3fb68d116`; accepted corrected harness: `12f3320d98c0247a0e21958969899084a6f794b7`. In both harness revisions, tracked runtime/archive inputs and dependency locks are byte-identical to the published reference.
- Shared live host performs only bounded source checks. Dependencies, native FPM workloads, browser tests and builds run on disposable GitHub-hosted compute.

## Prior operational evidence

Read-only reconciliation established that all eight selected production runtime files still match the published reference. Historical code/configuration/database backups and release archives retain the verified hashes recorded privately. This establishes historical backup integrity; it is neither a fresh installation backup nor a restore test. The operator confirmed current administrator access.

The previous observation window elapsed 86,401 seconds with 145 samples and maximum sample gap 602.669 seconds. Its result remains `needs_review`: a rotation gap affected OTP cron, email cron and resource streams starting at sample 88. Both coverage and observation-complete flags were false.

Four alert-bearing samples are not four distinct fatal events. Examined global errors belonged to other components or standalone core-file requests; no Pinova-owned fatal was established. Incomplete coverage prevents a claim that all Pinova failures were excluded. The final queue snapshot had no pending, expired, claimed or fast-slot work. Historical latency/resource summaries remain partial observations rather than acceptance proof.

The staging WordPress root was present at reconciliation, but the old installed Pinova directory was absent and configuration differed from the prior installation journal. The user subsequently reported that staging is unavailable and explicitly authorized a controlled production installation after development, review and immutable release gates. Live staging acceptance therefore remains unavailable and must be disclosed, not silently substituted with a passing result. No development/load suite may run on production.

## Acceptance gaps carried forward

- Fresh verified code/configuration/database backups and scoped rollback immediately before the authorized installation.
- Rotation-aware observer with tested bounded continuity; truncation or missing history must remain incomplete.
- Isolated three-pair RC21/candidate revision comparison with matched dependencies/workload and aggregate PHP PSS coverage. Existing immediate OFF/ON measurements are a different experiment.
- Physical-device/browser acceptance and human SMS receipt remain distinct from provider acceptance.
- A complete new 24-hour observation requires a final sample and verified coverage; starting a monitor is not completion.

## Newly exposed security gate

Quality run `41190672101` on the unchanged runtime failed Composer audit in PHP 8.1 and Supply Chain: four advisories affect PhpSpreadsheet 3.10.7 and one affects Symfony Mime 5.4.52. These are dependency findings, not regressions caused by new runtime code. Audit must not be skipped. Upstream patch releases 3.10.8 and 5.4.55 respectively are the constrained repair candidates; dependency resolution and compatibility evidence are required before acceptance.

Primary references: [PhpSpreadsheet patch release](https://github.com/PHPOffice/PhpSpreadsheet/releases/tag/3.10.8), [Symfony Mime security resolution](https://symfony.com/cve-2026-45070).

## Isolated baseline

The corrected complete [baseline campaign 41191669782](https://github.com/vahid162/pinova/actions/runs/41191669782), source `12f3320d98c0247a0e21958969899084a6f794b7`, passed both profiles. Each job passed 20 bounded evidence guards and all 16 existing native FPM safety groups. The two downloaded artifacts were independently hash-verified and contained only the three allowlisted sanitized JSON receipts. Full numeric observations, effective logging facts and interpretation limits are retained in `2026-10-31-diagnostics-baseline.json` beside this report.

| RC21 profile, immediate ON ordinary workload | Total measured PHP CPU (FPM + CLI) | Peak sampled PSS of one worker | Ordinary p95 | Whole-fixture final log rows/context bytes |
|---|---:|---:|---:|---:|
| Normal | 29.21 seconds | 51,989 KiB | 763.461 ms | 5 / 631 |
| Existing global debug | 23.45 seconds | 53,968 KiB | 592.471 ms | 537 / 69,072 |

These are whole WordPress/representative-plugin fixture observations, not Pinova-only consumption. Each profile used a separate disposable runner. Comparing these two numbers does not measure the cost of enabling debug; no cross-runner speedup is claimed. Peak single-worker PSS is not peak aggregate PHP PSS. The U6 three-pair source comparison, 100 ordinary responses per arm and aggregate PSS coverage remain separate required work.

The first complete campaign `41190672135` is retained as failed: normal passed, while diagnostic postprocessing correctly rejected a fixture that had not actually activated debug. The Settings API expects a duration and turns it into an absolute deadline; it can sanitize twice when creating an option. The fixture had supplied an absolute deadline. The correction first creates the ordinary option, then submits the accepted duration on the existing option; the real sanitizer remains in effect. Both profiles were rerun completely rather than selecting a passing observation from the failed campaign. Static guard diagnostics expose only code-owned reasons; parser, I/O and subprocess errors stay generic.

Passed artifact identities:

- Normal: `13039111797`, ZIP SHA-256 `752517f3405ed5dd22db0a602917a5c20cebe273c6a706e6f8acf535587f828b`.
- Diagnostic: `13039810073`, ZIP SHA-256 `0e30a64a4f11f585cc0107efc3289a31d927866595e9dc0416f764f80a3f8c42`.

The one-time baseline workflow/postprocessor are retired after capture because later candidate changes must not be mislabeled unchanged RC21. Their exact reproducible source remains in the linked campaign commit. The optional fixture logging profile remains for subsequent isolated comparison work.

## U1 outcome and carried-forward work

Operational reconciliation and the isolated baseline prerequisite are complete. This does not accept the new diagnostics implementation, resource overhead, live staging, production installation or final observation window.

The constrained Composer repair is recorded separately in `2026-10-31-diagnostics-dependency-patches.md`. Its production audit passes, and the compatibility matrix has progressed. The next Supply Chain step exposed a separate development-only npm chain: wp-env 12 / rimraf 5 / glob 10.5 / minimatch 9.0.9. Repair and regression checks for that chain remain open release gates; none of these npm packages enters the installable plugin ZIP. No audit threshold is lowered. U2 may build on the captured unchanged-runtime baseline while this independent development-tool gate is repaired.

No live-site writes, provider calls or SMS were performed for U1.
