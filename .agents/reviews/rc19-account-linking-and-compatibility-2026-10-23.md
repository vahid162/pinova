# Account linking and compatibility release evidence

Date: 23 October 2026. Evidence timestamps use UTC. Publication and staging acceptance completed as recorded below. This historical evidence does not authorize production installation or financial migration.

خلاصه: RC19 منتشر و امضاها و فایل‌های آن مستقل بررسی شدند. هفت سناریوی سایت آزمایشی، پاک‌سازی محدود، بازگشت واقعی به RC17 و نصب دوباره موفق بودند. سایت اصلی، حساب‌های واقعی و کیف پول تغییر نکردند. دریافت واقعی پیامک، آزمون دستگاه واقعی و پایش ۲۴ساعته تأیید نشده‌اند.

## Changes and scope

- Keep contact phone numbers outside login identity, including filtered legacy aliases. Existing customers use their original authentication before independently verified account linking.
- Add privileged, bounded, default-read-only linking for an empty mobile-only account. Preserve immutable usernames, orders and download permissions; require fresh OTP proof. Source-owned content, commerce, wallet or forum history requires separate review.
- Include exact non-owned aliases in versioned rollback evidence. Changed identities block undo; older journals remain readable but cannot authorize writes with missing evidence.
- Remove only the characterized legacy checkout callbacks at runtime, preserving authenticated order ownership and optional billing email.
- Replace exact dependency version gates in the wallet and Elementor document adapters with callable API, callback order and affected query/document checks. Unknown shapes remain untouched. Native TeraWallet 1.7.3 report tests cover HPOS and legacy storage, opt-out, recharge exclusions and both Dokan callback orders.
- Separate fresh JSON sensitivity reports from process diagnostics, reject missing/stale reports, and retain every existing product assertion and no-retry requirement. See the [official JSON reporter interface](https://playwright.dev/docs/test-reporters#json-reporter).
- Include the security patch to the existing HTTP dependency; add no production dependency.

## Reviewed source and CI

| Boundary | Exact evidence |
| --- | --- |
| Initial implementation | [PR #82](https://github.com/vahid162/pinova/pull/82), merge `5f817acc71a71506789907c363c9c9a72cc3ae68`; head `1c60390e130dee7c61eecb0e6c9679dc55e1c348`; PR Quality `39926664482`, push Quality `39926660194` |
| Browser synchronization | [PR #83](https://github.com/vahid162/pinova/pull/83), merge `86309cdcdaf65012c2370f0f973ef204334bed4e`; head `0afcf9c229fc5c886d99ad13384208948bbed62d`; PR Quality `39952535829`, push Quality `39952527002` |
| Rollback and capability corrections | [PR #84](https://github.com/vahid162/pinova/pull/84), branch `fix/account-link-alias-safety`; reviewed head `0ecb813ad695f2ae01c1236960c10a0edbc7de63` |
| Final PR checks | [Quality 39990911461](https://github.com/vahid162/pinova/actions/runs/39990911461), attempt 2: 33 successful checks; [push Quality 39990905005](https://github.com/vahid162/pinova/actions/runs/39990905005): 32 successful, dependency review inapplicable |
| Merge/source | `4949ff131bd92b1f094936ac91a883da8c38c244`; tree `af28775a8fb54e96c6cf9580daaa0de75776f841`, identical to the reviewed head tree |
| Merged-main checks | [Quality 39995428631](https://github.com/vahid162/pinova/actions/runs/39995428631): successful, 32 applicable checks |
| Controlled publish ref | `refs/heads/publish/v1.3.0-rc19` at the exact merge/source above |
| Publish-ref checks | [Quality 39998180085](https://github.com/vahid162/pinova/actions/runs/39998180085): successful |
| Publisher | [Publish pre-release 39999807117](https://github.com/vahid162/pinova/actions/runs/39999807117): successful, exact source and publish ref above |

PR #84 records author self-review, not independent approval. Normal GitHub merge requirements were enforced. One job failed before tests when npm returned ECONNRESET; only the failed job and dependent gate were rerun. Browser assertion retries, audit thresholds and required gates were not weakened.

The PR Quality events are `pull_request`: runs 39926664482, 39952535829 and 39990911461 certify heads `1c60390e130dee7c61eecb0e6c9679dc55e1c348`, `0afcf9c229fc5c886d99ad13384208948bbed62d` and `0ecb813ad695f2ae01c1236960c10a0edbc7de63` respectively. Their separate `push` runs are 39926660194, 39952527002 and 39990905005 at those same respective heads. Merged-main Quality 39995428631 and publish-ref Quality 39998180085 are `push` events at `4949ff131bd92b1f094936ac91a883da8c38c244`; publisher 39999807117 is `workflow_dispatch` at that same source. These source runs do not certify the later evidence-only documentation commit.

The native wallet archive is `https://downloads.wordpress.org/plugin/woo-wallet.1.7.3.zip`, SHA-256 `aeb9e17c593e6f207003812eb2642719d95cb2ec337e61d56de069ff888e74b7`. This archive pin makes CI reproducible; runtime eligibility does not compare dependency version strings.

## Publication and independent verification

[RC19](https://github.com/vahid162/pinova/releases/tag/v1.3.0-rc19) is Release `422154704`, published by github-actions[bot], with `immutable: true`, `prerelease: true` and `draft: false`. Annotated tag object `2b8c7886cc4dcb1222448d2f041676e83ac9357e` targets source `4949ff131bd92b1f094936ac91a883da8c38c244`.

| Asset | Bytes | Independently verified SHA-256 |
| --- | ---: | --- |
| pinova-1.3.0.zip | 5058394 | `97fabeacf0e5603c101a8990baaaedb1b5115205878c7b150ded19a86bbe3627` |
| pinova-1.3.0.zip.sha256 | 83 | `645f75059f3fe3ec33a7a520455d0cf3f1e0ed4b758d79e020dba19ccaba7786` |
| pinova-1.3.0.spdx.json | 97939 | `32b211f7189c72059753c9a2152444b8d7b29ba3d7b4f36ef1cd907866f31fd9` |
| pinova-1.3.0.spdx.json.sha256 | 89 | `74c145bdbc2d1a0614b090a61fe96ab9d6220afc4c32f22bc1e5f74a18701a09` |

All four assets were downloaded independently from the published Release. ZIP CRC, runtime allowlist, build identity, checksums and SPDX inventory passed: 3631 files, 17021686 uncompressed bytes and 44 Composer packages. No site files were used to build the package.

The checksum-pinned, unmodified native GitHub verifier accepted the Release and all four assets. A deliberately altered checksum copy was rejected; the original assets remained unchanged. Signed public trust metadata was refreshed through its verified TUF chain and checked for expiry. Verification used public GET requests, no real credentials and no shared configuration changes.

One native-verified SPDX attestation matched the downloaded SBOM exactly. Its source/signer digest is the release source above, its source ref is refs/heads/publish/v1.3.0-rc19, its runner is GitHub-hosted, and its certificate identity is `https://github.com/vahid162/pinova/.github/workflows/publish-prerelease.yml@refs/heads/publish/v1.3.0-rc19`. This records SPDX verification, not an unrelated SLSA-predicate claim.

The preceding immutable [RC18](https://github.com/vahid162/pinova/releases/tag/v1.3.0-rc18), Release `421879601`, targeted `86309cdcdaf65012c2370f0f973ef204334bed4e`. Its publisher `39961868253` succeeded, but later rollback review found incomplete alias safety evidence. RC18 was withheld before staging installation and independent acceptance. Its tag and Release remain unchanged; no independent RC18 installation approval is asserted here.

## Published-package staging acceptance

Only the independently verified published ZIP was installed on the dedicated test site. Schema 3, existing settings and ownership were preserved. The files-only predecessor is immutable RC17, source `1a249ee980f915aedfc443080db7f65ad1d66120`, ZIP SHA-256 `906d0ed76543e29e2f89f65f6f2f3747d6d8f3d56a53c80968cdeaa8b508d0af`.

The fixed-site installer passed 18 isolated synthetic safety cases, including concurrent changes, foreign maintenance markers, symlinks, interrupted replacement and rollback recovery. Installer SHA-256: `97260eaebef9ac33295ee9dd0120c8c0129446087a7ca4ae3432bed72dfbba62`.

Seven synthetic scenarios passed against the published package:

1. Contact aliases never authorize identity, including case/trailing-space/accent comparison cases.
2. Privileged preview is read-only and preserves the contact/identity boundary.
3. Linking is idempotent, preserves usernames, denies retired sessions and requires fresh mobile proof.
4. Fresh purpose-bound seeded OTP proof preserves native order data, raw download permissions and native available-download results.
5. Guarded identity rollback preserves commerce records and does not restore old proof.
6. Plugin-owned classic checkout compatibility preserves optional billing email and excludes the characterized legacy callbacks.
7. The original canonical account still authenticates through the native password pipeline.

Mail and outbound HTTP were suppressed during the fixture; no real message was sent. The backend fixture skipped themes; separate final health checks loaded the actual active theme and plugins. These are distinct from CI browser acceptance and physical delivery.

Cleanup removed only owned synthetic records. All seven native table counts matched before/after: users, usermeta, posts, comments, HPOS orders, approved download directories and downloadable permissions. Recorded synthetic user IDs had zero remaining user/meta rows. Bounded observational counters expire naturally; shared counters were not rewound.

Actual code rollback then restored all 3628 predecessor files: 31 changed files and removal of only the three candidate-added files. No retirement/journal records remained before loading the older reader. The rolled-back login page returned HTTP 200, showed the old template without candidate guidance, and retained no-store headers. Reinstall restored the same verified RC19 ZIP, changing 34 files with no removals. Final inventory matched all 3631 candidate files exactly.

Final full-theme health passed: exact release source, schema 3, unchanged settings, no maintenance marker, all five queue categories empty, no native OTP jobs and a 60-second watchdog. Logging was database-backed/readable with scheduled cleanup and zero recorded issues at the configured warning level. An empty invocation of the installed OTP CLI processed zero jobs; this is not proof of provider delivery.

Anonymous home GET, login GET and authentication OPTIONS each returned HTTP 200; the candidate guidance reappeared and login retained no-store headers. Observed request times were 3317, 2495 and 2852 ms respectively; three observations are not a comparative performance benchmark.

The available target server-error stream had zero new bytes, no rotation/truncation and no new fatal, database or Elementor document-warning entries through 18:56 UTC. This is bounded target-stream evidence, not a review of every shared-server log. Production Pinova files, both configuration files and the production child-theme source matched their private pre-work fingerprints; those files were not copied or modified.

### Operational exceptions

The first fixture completed all seven functional scenarios but its cleanup assertion failed: native HPOS deletion retained three notes for its synthetic order. The test harness was corrected to remove only its own order notes through the native API, with an ID/type/order check and a 20-note bound. The three retained notes from the first run were removed with exact identity/time checks; fingerprints confirmed that every older note and all user metadata were preserved. The complete fixture was rerun successfully with explicit owned IDs and per-table counts. This correction changed only the private operational fixture; the immutable published package was unchanged.

A read-only diagnostic initially used incorrect column names, then was corrected; it made no database writes. Other orphan revocation metadata for absent staging accounts was observed and preserved rather than swept. The recorded acceptance accounts left no orphan metadata. Unrelated historical-data cleanup is outside this release procedure.

استثنای عملیاتی: ابزار آزمون ابتدا سه یادداشت سفارشِ ساختگی را جا گذاشت. پاک‌سازی همان آثار با API بومی اصلاح و سپس تمام سناریوها و شمارنده‌های جدول‌ها با موفقیت بررسی شدند. یادداشت‌های قدیمی و متادیتای نامرتبط حذف نشدند؛ بستهٔ منتشرشده تغییر نکرد.

No database export or configuration copy is part of this files-only procedure. Native fixture cleanup and code rollback are distinct from a database restore.

## Remaining boundaries

Real customer accounts and financial records remain on hold by the owner's instruction; no wallet migration, order reassignment or permission regeneration is authorized by this release. A separate private wallet report covers that case.

Production installation belongs to the site owner. Physical SMS receipt, physical-device acceptance and a 24-hour canary are not established by CI, seeded OTP verification or the staging smoke tests. Keep the release a prerelease; future upstream API/query changes still require characterization.
