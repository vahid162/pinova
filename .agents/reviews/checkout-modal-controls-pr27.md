# PR27 checkout-modal controls and test evidence

## Snapshot and authority

This is a pre-publication evidence snapshot recorded on 2026-09-19. It describes the verified implementation/test head below. It does not claim that a later documentation commit, merged main, new release, or installation has passed its gates.

The operator authorized the focused control fix, test hardening, and then documentation synchronization plus a reviewed merge and a new installable release. Production installation, settings changes, native-login gate activation, and database operations remain outside that authority. Published RC9 must not be rewritten; use the next unused RC after the release gates pass.

| Identity | Verified value |
| --- | --- |
| Repository | `vahid162/pinova` |
| Pull request | [#27](https://github.com/vahid162/pinova/pull/27) |
| Branch | `fix/checkout-modal-corner-controls` |
| Base / RC9 target | `1d39ec04d5f6293d4ea3511480980fbc8912938d` |
| Verified implementation/test head | `1423de55639ec3a50bfb50119e0a44ecc7926d6a` |
| Exact-head Quality run | [35424813233](https://github.com/vahid162/pinova/actions/runs/35424813233), event `pull_request`, attempt 1 |
| Synthetic PR test merge | `328335baaa4656046201e8fa46d61b774d665e82` — not a merge into main |
| Browser job | [105848808330](https://github.com/vahid162/pinova/actions/runs/35424813233/job/105848808330) |
| Automated review | [comment 5739749252](https://github.com/vahid162/pinova/pull/27#issuecomment-5739749252), exact head `1423de5563`, no major issues reported |

At the snapshot, main and the published RC9 both target the base above. Release API metadata for [RC9](https://github.com/vahid162/pinova/releases/tag/v1.2.6-rc9) identifies Release `391638137` as non-draft, pre-release, and immutable. Its reported ZIP SHA-256 is `4c34353127cfed47cc0dfd42f042debe67727279370afbfe34e756e6f176ed5d`. This is an API metadata observation, not a fresh independent RC9 asset/attestation audit. The installed site version was not determined by this task. RC8 deployment notes elsewhere are historical.

## Defect and bounded correction

A generic Woodmart selector `:is(.btn,.button,button,[type=submit],[type=button])` has the same specificity as the former single-class Pinova control selector. When its rule follows Pinova's CSS it can replace `position:absolute` with `relative`, putting the control into the centered flex header. The original UI investigation confirmed the close-control conflict on checkout; later browser tests reproduce the conflict without requiring or redistributing the commercial theme.

The correction moves the unchanged close button to a direct child of `.pinova-auth-card`, outside the header, loader, and inert task content. Its 44×44 target uses safe-area-aware 12px top-left insets. Close-control rules and the modal back-control position are scoped under `.pinova-auth-modal`. The logo, forms, handlers, standalone layout rules, and authentication JavaScript/endpoints remain unchanged. Both account asset revisions move from `.4` to `.5`; the plugin version remains `1.2.6`.

## Failure history and test repair

Initial implementation head `00601dc89aaaa998436e9ce55690edf309e3ced1` failed browser run [35397900385](https://github.com/vahid162/pinova/actions/runs/35397900385). The 120 geometry checks passed, but two keyboard assertions raced the existing delayed heading-focus transfer. Tests now observe actual heading focus after opening/changing each step, avoid a duplicate initial-state transition, and only then send keyboard input. They do not focus the heading themselves, add fixed waits or whole-test retries, skip tests, or remove product assertions.

The pinned wp-env `10.35.0` also prompted on `destroy --force`; that version does not implement the flag. The browser job now invokes `tools/cleanup-browser-env.sh`, guarded to the exact current GitHub run/project/home, and fails if cleanup or verification fails. It does not prune global Docker resources or remove shared images. The script is not a general desktop cleanup command.

Head `dbcfda53277e4e310fe9286932d638d30dd8c6fc` introduced the synchronization, mutation audit, cleanup, and tooling regressions. Final verified head `1423de55...` additionally made mutation-result classification use the raw failing assertion headline. Marker text in adjacent formatted source snippets cannot certify an unrelated failure.

## Observed results for the verified head

| Gate | Result |
| --- | --- |
| Quality jobs | 16/16 successful |
| JavaScript unit/structural/tooling tests | 100 passed; 0 failed, cancelled, or skipped |
| Full Stage 1 Chromium acceptance | 14 passed; 0 failed, skipped, or flaky |
| Modal geometry matrix | 120 cases: 10 viewports × 6 presentation states × 2 stylesheet orders |
| Healthy sensitivity controls | 3 repetitions before + 1 after passed, no retries |
| Targeted independent mutations | 5/5 rejected at the intended product assertion |
| PHP jobs | 8.1, 8.2, 8.3, 8.4, 8.5 passed |
| Integration jobs | All 8 configured WordPress/WooCommerce/HPOS combinations passed |
| Guidance | Changelog and Skill synchronization passed |
| Browser environment cleanup | Verified zero run-owned containers, volumes, networks, and work directory |

PHP 8.1 also passed the configured PHPCS, dependency audit, and CI build checks. Their success is not an immutable-release publication or independent package verification.

The geometry matrix checks layout and focus by selecting presentation states; it is not 120 real logins. The complete browser suite separately tests existing login/checkout behavior. Backend and physical-device acceptance have distinct evidence and limits.

### Fault-detection evidence

| Response-only mutation | Required failing assertion |
| --- | --- |
| Remove product heading focus | `[modal:heading-focus]` |
| Break reverse focus trap | `[modal:reverse-trap]` |
| Break forward focus trap | `[modal:forward-trap]` |
| Change close position to relative | `[modal:close-position]` |
| Change back position to relative | `[modal:back-position]` |

Mutations operate only on browser asset responses from the loopback test origin, not plugin or server files. The ordinary suite does not declare these product defects expected failures. The separate audit accepts only the exact targeted assertion failure and rejects runner/setup errors, unrelated failures, missing tests, skips, retries, whole-test timeouts, and undetected mutations. The raw-headline verifier itself has regression coverage for misleading adjacent source text.

## Artifact and retention

- Artifact name: `account-ui-browser-35424813233-1`.
- Artifact ID: `10578968510`.
- Downloaded ZIP SHA-256: `a73a22f07b66e5f3c27a07b621ca55b9ee54959874cfa84426a73a14807910de`, matching the GitHub API digest.
- Inspected: ordinary acceptance report, seven sensitivity JSON reports (healthy-before, five mutants, healthy-after), and cleanup log.
- The exact final verifier accepted the downloaded sensitivity evidence. The cleanup log explicitly recorded absence of run-owned resources and work directory.
- Workflow artifact retention: seven days; reported expiry `2026-09-26T05:52:41Z`. A remote link is not permanent retention. This small summary preserves provenance after expiry; raw traces and credentials must not be committed.

## Remaining gates and rollback

A documentation follow-up needs exact-head CI and review. A later authorized merge needs a green merged-main run. The next unused pre-release must be built twice by the existing publisher, report `immutable:true`, pass release and asset attestations, and have its ZIP/checksum independently redownloaded and checked. Record that separate publication chain without moving RC9 or relabeling this snapshot.

Five mutations do not establish complete plugin defect coverage. Physical Firefox/Android/iOS Safari, actual safe areas, virtual keyboards, assistive technology, and staging/canary acceptance remain outstanding before production use. A working package never grants installation authority.

Before merge, leave the PR unmerged or close it. After an authorized merge, revert the focused change set if required, including coordinated asset revisions. Never rewrite published tags. Installation rollback must use an independently verified prior package and the agreed backup/rollback plan, under separate production authorization.
