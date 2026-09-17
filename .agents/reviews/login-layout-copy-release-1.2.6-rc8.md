# Pinova 1.2.6 Login Layout and Copy — RC8 Release Record

Date: 2026-09-17 (Asia/Tehran)

## Scope and operational boundary

- PR #23 centers the standalone-login logo independently of navigation, enlarges it only on desktop, keeps the mobile header at the top while centering the remaining content, and consolidates the identifier and password instructions into their bold labels.
- Password fields are LTR with right-side visibility toggles. The OTP-login and password-recovery alternatives use equal-width, normal-weight, visibly underlined actions with a pipe separator.
- Existing single-card, 320px-minimum, accessibility, request-locking, safe-area, 4/5/6-digit OTP, native-login activation, and security-integration behavior remains in scope and was covered by the unchanged quality matrix.
- No production plugin file, option, database row, cache, service, session, or native-login setting was changed during implementation, CI, merge, publication, independent verification, or this evidence update. PHP-FPM, Redis, host saturation, crawlers, and generic page latency were outside scope.

## Reviewed provenance

- Implementation branch: `fix/1.2.6-login-layout-copy`.
- Final reviewed implementation head: `485d089b3ac3aa3c37124475c4fcc8396542195d`.
- The first PR run exposed one stale browser spacing assertion after the requested UI checks themselves passed. The targeted assertion-only follow-up corrected that test without changing runtime behavior.
- PR #23 exact-head `pull_request` Quality run `35206496567` at `485d089b3ac3aa3c37124475c4fcc8396542195d` passed all 16 jobs, including Chromium, JavaScript, PHP 8.1–8.5, WordPress/WooCommerce compatibility, and HPOS on/off integration shards.
- The parallel branch `push` run `35206493527` at the same head is not presented as the PR gate: 15 jobs passed, while project-guidance compared only the assertion-only follow-up with its immediate parent and required a repeated Skill edit. The complete base-to-head PR run passed Skill synchronization, and no failed run was used to trigger publication.
- PR #23 was merged with the reviewed head pinned. Exact merged `main` commit `9d7f70b4b641daaf038f66cc993d7a8d99876e0d` then passed all 16 jobs in `push` Quality run `35206965454` before publication.

## Immutable publication evidence

- `publish/v1.2.6-rc8` was created from exact merged `main` commit `9d7f70b4b641daaf038f66cc993d7a8d99876e0d` only after the post-merge gate passed.
- Publish-branch `push` Quality run `35207765834` at `9d7f70b4b641daaf038f66cc993d7a8d99876e0d` passed all 16 jobs.
- `workflow_run` publisher run `35208028725` at `9d7f70b4b641daaf038f66cc993d7a8d99876e0d` passed release-identity validation, two-build reproducibility, annotated-tag creation, immutable pre-release publication, Release and asset-attestation verification, and its own published-asset redownload.
- Annotated tag object `1c77883e94fb9f3e4dea583c72c97332445b842e` targets exact commit `9d7f70b4b641daaf038f66cc993d7a8d99876e0d`.
- GitHub Release `390600592` is `draft: false`, `prerelease: true`, and reports native `immutable: true`.
- Installable asset `pinova-1.2.6.zip` is 5,025,054 bytes with SHA-256 `343d92e7e6919a045ba530cbe73910271bcc0dd7596a7d6a05f7ef6bf94c698d`.
- Checksum asset `pinova-1.2.6.zip.sha256` is 83 bytes with asset SHA-256 `d9b8694977c8c7a62cd455deef0d084dc2f285ec9295303d83b1170f46b441f4`.
- GitHub's public attestation API exposes the GitHub-initiated release attestation for tag-object digest `sha1:1c77883e94fb9f3e4dea583c72c97332445b842e` and for both published asset digests. The publisher's cryptographic `gh release verify` and `gh release verify-asset` checks passed for all three subjects.

## Independent handoff verification

- The ZIP and checksum were each downloaded twice afresh from GitHub after the publisher completed; both pairs were byte-identical and calculated to the API-reported SHA-256 values.
- Each checksum file validated its downloaded ZIP. Both ZIP integrity checks reported no compressed-data errors.
- Every packaged path is under the single top-level `pinova/` directory.
- The packaged plugin header and `PINOVA_VERSION` constant both report `1.2.6`.

## Handoff decision

The sole RC8 installation artifact is `pinova-1.2.6.zip` attached to `v1.2.6-rc8`; GitHub's automatic source archives are not installation packages. RC1–RC7 remain unchanged publication history, and RC6 remains the installed baseline. RC8 has passed implementation and publication-integrity gates and is the candidate for separately authorized isolated-staging acceptance. Publication does not authorize staging or production installation, database/configuration changes, or native-login gate activation. Exact production security-integration checks, physical Chrome Android and iOS Safari acceptance, the gate-off staging pass, later armed gate pass, and both production canaries remain outstanding authorization boundaries.
