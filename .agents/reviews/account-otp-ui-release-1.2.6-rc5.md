# Pinova 1.2.6 Account OTP UI — RC5 Release Record

Date: 2026-09-16 (Asia/Tehran)

## Scope and operational boundary

- PR #17 gives every full-page OTP flow one clear hierarchy: submitted identifier and explicit edit action, code field with configured-length guidance, primary verification, resend availability, then any alternate login method.
- The resend cooldown is plain status text rather than a disabled full-width control. The store return remains full width but is visually tertiary, and the duplicate first-step header exit is removed.
- This release changes no REST contract, authentication policy, schema, logging behavior, Blocked List rule, native-login gate default, or production setting. No production plugin file, option, database row, cache, session, or running observation was changed during merge, publication, or this evidence update.

## Reviewed provenance

- Implementation branch: `fix/1.2.6-rc5-otp-ui`.
- Final implementation commit: `2469c7b92eb286c56c8fc7b0453c66d7fb5bb1e7`.
- PR #17 Quality run `35084748629` passed all 15 jobs, the PR had no unresolved review threads, and it was merged with the reviewed head pinned.
- Exact merged `main` commit: `b65370bf591ab6837e9fd16ab5c0252c8a2cfb3f`.
- Independent `main` Quality run `35085373875` passed all 15 jobs before the publish branch was created.

## Immutable publication evidence

- `publish/v1.2.6-rc5` was created from exact merged `main` commit `b65370bf591ab6837e9fd16ab5c0252c8a2cfb3f` only after the `main` gate passed.
- Publish-branch Quality run `35085672774` passed all 15 jobs.
- Publisher run `35085930703` passed release-identity validation, two-build reproducibility, annotated-tag creation, immutable pre-release publication, Release and asset-attestation checks, and its own published-asset redownload.
- Annotated tag object `cd1b0e1a16f17969f230140f1494642c3a2f610a` targets exact commit `b65370bf591ab6837e9fd16ab5c0252c8a2cfb3f`.
- GitHub Release `389832419` is `draft: false`, `prerelease: true`, and reports native `immutable: true`.
- Installable asset `pinova-1.2.6.zip` is 5,020,216 bytes with SHA-256 `1998a8c4c783f2624f16a650fcd3952a987e02ff9106fb585c7a9a96db3bfd36`.
- Checksum asset `pinova-1.2.6.zip.sha256` is 83 bytes with asset SHA-256 `5d5fcb5619f9b881b6a12482f034f1ea37c124b6441c8642822c3b2cc105300d`.
- The GitHub release attestation names the annotated tag object and both published asset digests for repository `vahid162/pinova` and tag `v1.2.6-rc5`.

## Independent handoff verification

- Both published assets were downloaded afresh from GitHub after the publisher completed.
- `sha256sum -c` passed, and the calculated ZIP and checksum-asset digests matched both the checksum file and GitHub's reported asset digests.
- ZIP integrity passed with no compressed-data errors. All 3,685 entries are under the single top-level `pinova/` directory.
- The packaged plugin header reports version `1.2.6`, WordPress requirement `6.8`, and PHP requirement `8.1`; the account CSS, account JavaScript, full-page login template, WordPress readme, and eager Composer autoload file are present.

## Handoff decision

The sole RC5 installation artifact is `pinova-1.2.6.zip` attached to `v1.2.6-rc5`; GitHub's automatic source archives are not installation packages. RC1–RC4 remain unchanged as publication history and are superseded for a new 1.2.6 installation. RC5 is ready for the separately authorized staging or controlled production canary gate, but it is not stable/latest. Publication does not authorize installation, native-login gate activation, or database/configuration changes. Because RC5 is an account-interface release, desktop/mobile interaction acceptance remains a required post-install gate even though all structural and compatibility tests passed.
