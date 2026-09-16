# Pinova 1.2.6 Single-Card Account UI — RC6 Release Record

Date: 2026-09-16 (Asia/Tehran)

## Scope and operational boundary

- PR #19 replaces the rejected two-pane account presentation with one centered card using the GPANTE orange/cream visual system, while preserving all six authentication and password-recovery forms.
- OTP digits remain a dynamic visual layer over one real configured-length input, preserving paste, WebOTP/autofill, exact-length autosubmit, resend, identifier editing, and alternate password access.
- Final review identified and corrected a possible 320px logo/back-control overlap before merge. The header now reserves the complete 44px control target plus an 8px gap on both sides of the centered wordmark, with a regression assertion.
- This release changes no REST contract, authentication policy, schema, logging behavior, Blocked List rule, native-login gate default, or production setting. No production plugin file, option, database row, cache, session, or running observation was changed during merge, publication, or this evidence update.

## Reviewed provenance

- Implementation branch: `fix/1.2.6-rc6-simple-account-ui`.
- Final implementation commit: `c8d337f2bead3e53d24d3d792052281436dc889f`.
- PR #19 Quality run `35101731382` passed all 15 jobs, the sole review thread was resolved after its regression fix, and the PR was merged with the reviewed head pinned.
- Exact merged `main` commit: `c327c71596516a75f33ff80cbd5d7b0d8a99720b`.
- Independent `main` Quality run `35102214094` passed all 15 jobs before the publish branch was created.

## Immutable publication evidence

- `publish/v1.2.6-rc6` was created from exact merged `main` commit `c327c71596516a75f33ff80cbd5d7b0d8a99720b` only after the `main` gate passed.
- Publish-branch Quality run `35102571654` passed all 15 jobs.
- Publisher run `35102898151` passed release-identity validation, two-build reproducibility, annotated-tag creation, immutable pre-release publication, Release and asset-attestation checks, and its own published-asset redownload.
- Annotated tag object `2446301ab5f6cdb305c017e9568733099f9ebdc7` targets exact commit `c327c71596516a75f33ff80cbd5d7b0d8a99720b`.
- GitHub Release `389958951` is `draft: false`, `prerelease: true`, and reports native `immutable: true`.
- Installable asset `pinova-1.2.6.zip` is 5,020,419 bytes with SHA-256 `612a0ef051ea46e9a05b3f352c867b6e4704bc56bdc380c04df29ebdd7886d43`.
- Checksum asset `pinova-1.2.6.zip.sha256` is 83 bytes with asset SHA-256 `640d3f7240b9bd134325c03544454624d2e19f84ce01fd49a07af74dccb2fa52`.
- GitHub exposes an attestation for each asset digest. Its release statement names repository `vahid162/pinova`, tag `v1.2.6-rc6`, annotated tag object `2446301ab5f6cdb305c017e9568733099f9ebdc7`, and both published asset digests.

## Independent handoff verification

- Both published assets were downloaded afresh from GitHub after the publisher completed.
- `sha256sum -c` passed, and the calculated ZIP and checksum-asset digests matched both the checksum file and GitHub's reported asset digests.
- ZIP integrity passed with no compressed-data errors. All 3,685 entries are under the single top-level `pinova/` directory, and repository-only top-level development paths are absent.
- The packaged plugin header, constant, and WordPress stable tag report version `1.2.6`; the package requires WordPress 6.8 and PHP 8.1. The final mobile logo-space rule and required account/runtime files are present in the published ZIP.

## Handoff decision

The sole RC6 installation artifact is `pinova-1.2.6.zip` attached to `v1.2.6-rc6`; GitHub's automatic source archives are not installation packages. RC1–RC5 remain unchanged as publication history and are superseded for a new 1.2.6 installation. RC6 has passed the publication-integrity gates and is the current candidate for a separately authorized controlled production installation, but it is not stable/latest. Publication does not authorize installation, native-login gate activation, or database/configuration changes. Live desktop/mobile interaction and visual acceptance remain outstanding post-install gates.
