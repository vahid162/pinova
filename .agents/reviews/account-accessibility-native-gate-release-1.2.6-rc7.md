# Pinova 1.2.6 Account Accessibility and Native-Login Gate — RC7 Release Record

Date: 2026-09-17 (Asia/Tehran)

## Scope and operational boundary

- PR #21 corrects the standalone account page's typography, paragraph spacing, control contrast, focus behavior, validation announcements, request locking, safe-area handling, and 4/5/6-digit OTP behavior while retaining the single-card layout and 320px minimum viewport.
- The private native-login route now creates a 30-minute, single-use administrator arm bound to the persisted slug fingerprint, User ID, and plugin version. Runtime blocking requires both the setting and a matching durable activation record; disabling the gate or changing the slug invalidates activation.
- Canonical blocking remains off by default, `PINOVA_BLOCK_NATIVE_LOGIN=false` remains the emergency override, native-role restrictions and the real WordPress authentication pipeline remain intact, and allowed core actions retain their exact handlers.
- No production plugin file, option, database row, cache, service, session, or native-login setting was changed during implementation, CI, merge, publication, independent verification, or this evidence update. PHP-FPM, Redis, host saturation, crawlers, and generic page latency were outside scope.

## Reviewed provenance

- Implementation branch: `fix/1.2.6-rc7-account-accessibility-native-login-arm`.
- Final reviewed implementation head: `f94b2230c4a96373643b094b6264baffd34616c4`.
- Exact-head push Quality run `35196513209` and PR #21 Quality run `35196516611` each passed all 16 jobs, including Chromium, PHP 8.1–8.5, WordPress/WooCommerce compatibility, and HPOS on/off integration shards.
- PR #21 was merged with the reviewed head pinned. Exact merged `main` commit `539f49c32e233201ce88b4b301848d741d491069` then passed all 16 jobs in Quality run `35196879813` before publication.

## Immutable publication evidence

- `publish/v1.2.6-rc7` was created from exact merged `main` commit `539f49c32e233201ce88b4b301848d741d491069` only after the post-merge gate passed.
- Publish-branch Quality run `35197407509` passed all 16 jobs.
- Publisher run `35197680055` passed release-identity validation, two-build reproducibility, annotated-tag creation, immutable pre-release publication, Release and asset-attestation verification, and its own published-asset redownload.
- Annotated tag object `b43fb25bc1eff7006dca9042cd94435d29018207` targets exact commit `539f49c32e233201ce88b4b301848d741d491069`.
- GitHub Release `390529306` is `draft: false`, `prerelease: true`, and reports native `immutable: true`.
- Installable asset `pinova-1.2.6.zip` is 5,024,704 bytes with SHA-256 `564e13f41d1e071be1975011633546aceb54e417a6adedea0e66c9803250dba3`.
- Checksum asset `pinova-1.2.6.zip.sha256` is 83 bytes with asset SHA-256 `e0c963d4dc316aa1a8a540e45a6f8fa30ffbc9134d2779e9995ae12e1cd3bd94`.
- GitHub's public attestation API exposes the GitHub-initiated release attestation for tag-object digest `sha1:b43fb25bc1eff7006dca9042cd94435d29018207` and for both published asset digests. The publisher's cryptographic `gh release verify` and `gh release verify-asset` checks passed for all three subjects.

## Independent handoff verification

- The ZIP was downloaded twice afresh from GitHub after the publisher completed; both downloads were byte-identical and calculated to the published SHA-256.
- The checksum file named the expected asset and matched GitHub's reported ZIP digest. ZIP integrity passed with no compressed-data errors.
- All 3,685 entries are under the single top-level `pinova/` directory. Repository-only top-level `.git`, `.github`, `.agents`, `node_modules`, `tests`, `tools`, `AGENTS.md`, `README.md`, and `CHANGELOG.md` paths are absent.
- The packaged plugin header, `PINOVA_VERSION` constant, and WordPress stable tag report `1.2.6`; the package requires WordPress 6.8 and PHP 8.1 and reports compatibility through WordPress 7.1.

## Handoff decision

The sole RC7 installation artifact is `pinova-1.2.6.zip` attached to `v1.2.6-rc7`; GitHub's automatic source archives are not installation packages. RC1–RC6 remain unchanged publication history, and RC6 remains the installed baseline. RC7 has passed implementation and publication-integrity gates and is the candidate for separately authorized isolated-staging acceptance. Publication does not authorize staging or production installation, database/configuration changes, or native-login gate activation. Exact production security-integration checks, physical Chrome Android and iOS Safari acceptance, the gate-off staging pass, later armed gate pass, and both production canaries remain outstanding authorization boundaries.
