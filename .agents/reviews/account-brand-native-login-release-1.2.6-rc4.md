# Pinova 1.2.6 Account Brand and Native Login — RC4 Release Record

Date: 2026-09-16 (Asia/Tehran)

## Scope and operational boundary

- PR #15 delivers the orange/cream GPANTE account experience, responsive desktop/mobile layout corrections, full-width store return control, button semantics and styling, plain-text bidirectional OTP delivery messaging, and removal of the public native-login link.
- Native WordPress login blocking is staged and disabled by default. Its private administrator route uses the real WordPress login pipeline so Wordfence, 2FA, passkeys, recovery, and protected core actions remain available. Production activation is a separate operational action with an active administrator session, private-route verification, backup, rollback, and explicit authorization.
- No production plugin file, option, database row, cache, service, login session, or running observation was changed during merge, publication, or this evidence update.

## Reviewed provenance

- Implementation branch: `fix/1.2.6-rc4-account-brand-native-login`.
- Final implementation commit: `beede136a6fac230f5881a688ea27ed470a426be`.
- Branch push Quality run `35073061949` and PR #15 Quality run `35073065752` each passed all 15 jobs; the final automated review reported no major findings.
- PR #15 was merged with the reviewed head pinned, producing exact `main` commit `0b894854faf3d5433ac6b636b80bd65d3af423f0`.
- The independent `main` Quality run `35073882571` passed all 15 jobs before any publish branch was created.

## Immutable publication evidence

- `publish/v1.2.6-rc4` was created from exact merged `main` commit `0b894854faf3d5433ac6b636b80bd65d3af423f0` only after the `main` gate passed.
- Publish-branch Quality run `35074206989` passed all 15 jobs.
- Publisher run `35074420994` passed release-identity validation, two-build reproducibility, annotated-tag creation, immutable pre-release publication, Release and both asset-attestation checks, and its own published-asset redownload.
- Annotated tag object `393591212ce7f062358492e19c1b4f009a65fc88` targets exact commit `0b894854faf3d5433ac6b636b80bd65d3af423f0`.
- GitHub Release `389750791` is `draft: false`, `prerelease: true`, and reports native `immutable: true`.
- Installable asset `pinova-1.2.6.zip` is 5,019,657 bytes with SHA-256 `9d2e8c8c7cfee0e2491551b1806a8328cf99f2b7261e5da7326221f6c7fe45b6`.
- Checksum asset `pinova-1.2.6.zip.sha256` is 83 bytes with asset SHA-256 `68496de3b5db0f493fc0af7b415671cc7f2ddb1bcb0f7189322b6efeeecf2039`.

## Independent handoff verification

- Both published assets were downloaded afresh from GitHub after the publisher completed.
- `sha256sum -c` passed and the calculated ZIP digest matched both the checksum file and GitHub's asset digest.
- ZIP integrity passed with no compressed-data errors. All 3,685 entries are under the single top-level `pinova/` directory.
- The packaged plugin header reports version `1.2.6`, WordPress requirement `6.8`, and PHP requirement `8.1`; the account stylesheet, bootstrap, settings, structured logger, and main runtime class are present.

## Handoff decision

The sole RC4 installation artifact is `pinova-1.2.6.zip` attached to `v1.2.6-rc4`; GitHub's automatic source archives are not installation packages. RC1–RC3 remain unchanged as immutable history and are superseded for a new 1.2.6 installation. RC4 is ready for the separately authorized staging or controlled production canary gate, but it is not stable/latest and its publication does not authorize installation or activation of the native-login block.
