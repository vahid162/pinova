# RC20 publication and staging evidence — 2026-10-24

This records the immutable published package and the separately authorized staging work. RC20 remains a prerelease. Publication, staging checks, provider acceptance, physical receipt and a completed observation window are separate claims.

## Source and publication

The engineering changes and their source tests are recorded in [the source verification](2026-10-24-engineering-hardening-source.md). [PR #93](https://github.com/vahid162/pinova/pull/93) synchronized the current release notes and preserved that source evidence without changing runtime code, dependencies or workflows.

| Gate | Exact source and result |
| --- | --- |
| Preparation PR | Head `558b647b731487bd07322ea4222c44d53bca7aa8`; [pull-request Quality 40115633883](https://github.com/vahid162/pinova/actions/runs/40115633883) succeeded with 35 successful jobs |
| Merged main | `push` at `9a4741a48889743d1cbf00579e37ad5d1d8c380f`; [Quality 40116994367](https://github.com/vahid162/pinova/actions/runs/40116994367) succeeded with 34 successful jobs and the expected non-PR Dependency Review skip |
| Controlled publish branch | `push` on `publish/v1.3.0-rc20` at the same source; [Quality 40118110825](https://github.com/vahid162/pinova/actions/runs/40118110825) succeeded with 34 successful jobs and the expected Dependency Review skip |
| Authorized dispatch | [40118759351](https://github.com/vahid162/pinova/actions/runs/40118759351) succeeded |
| Publisher | [40118765461](https://github.com/vahid162/pinova/actions/runs/40118765461) succeeded for the exact publish source |

The release source tree is `e96af2bb6955028e54119fa87f4f03467cf51674`. The annotated tag object is `360a1b7b3bf1c958f69333e66985f74cc051e9b3`, targeting the merged source above. [Release v1.3.0-rc20](https://github.com/vahid162/pinova/releases/tag/v1.3.0-rc20), ID `422894572`, was verified with native `immutable: true`, `prerelease: true`, and `draft: false`. The repository owner confirmed native Release Immutability before the publish branch was created. Package/plugin metadata remains `1.3.0`; its build identity supplies the RC tag and exact commit.

| Published asset | Bytes | SHA-256 |
| --- | ---: | --- |
| `pinova-1.3.0.zip` | 5,065,884 | `58035f36840cf7958f96a9c192829b0169c8cb93ec9967b59e83c282892ee64c` |
| `pinova-1.3.0.zip.sha256` | 83 | `161304fb2d487df8821235e95640cea7742eac71aa95ff3ef3574b71daab3add` |
| `pinova-1.3.0.spdx.json` | 97,939 | `d3eb50ff6d27c3a50c2bdc5422fcfd02b9e8754c958bce62617e6f5d3ce7de00` |
| `pinova-1.3.0.spdx.json.sha256` | 89 | `1f670d8457c75fde71a0f370121612f656519de42b4588b7e9b41870fd1ba3cf` |

## Independent package verification

A separate verification unit freshly downloaded all four published assets. The unmodified GitHub CLI 2.102.0 verified the native immutable Release and each of its four asset proofs. Its isolated trust stores were refreshed through signed TUF root rotations; the public Sigstore CDN required its official root-signing mirror. No real GitHub credentials or shared CLI configuration were used. An intentionally changed checksum file was rejected, with the original assets preserved.

The signed SPDX predicate was verified against the exact source digest, `refs/heads/publish/v1.3.0-rc20`, the publisher workflow certificate identity and a GitHub-hosted runner. The signed predicate equals the downloaded SPDX 2.3 SBOM, and its subject equals the downloaded ZIP digest.

ZIP integrity, the existing package allowlist, file modes and production inventory passed. The package contains 3,634 files and 17,042,762 uncompressed bytes, with 44 production Composer packages matching this source's `composer.lock`. All 168 first-party packaged files byte-match that exact source. The verification receipt digest is `9ac57e14908da643958a23215b433563c36e1fca8278d177c426a9b2733825ce`; the complete installed-file manifest digest is `04a9f7c07d454bb2e5343f2feebc66bcd4ee0f5509e2ab15f8160219b1bc8b43`.

A fresh independent RC19 download had ZIP digest `97fabeacf0e5603c101a8990baaaedb1b5115205878c7b150ded19a86bbe3627`, source `4949ff131bd92b1f094936ac91a883da8c38c244`, and 3,631 files. The per-entry comparison found 3,530 unchanged files, three additions, 101 changes and no removals. The two first-party additions are `assets/js/pages/auth-state.js` and `src/Services/OTPQueueStore.php`; 25 first-party files changed. The remaining additions/changes are build identity and Composer-generated metadata, plus the previously merged dependency updates `giggsey/libphonenumber-for-php-lite` 9.0.30 → 9.0.41 and `symfony/polyfill-mbstring` v1.38.2 → v1.43.0. The third added file is upstream `vendor/giggsey/libphonenumber-for-php-lite/qodana.yaml`, permitted by the current vendor policy. Both candidates' production inventories match their respective lockfiles; no unaccounted vendor changes were found.

## Authorized staging

The user confirmed the exact dedicated test-site target and authorized installation, additive migration, seven-day OTP retention and one real SMS to a privately supplied recipient. Host/site identity and the existing installed RC19 file inventory were checked before mutation. No recipient, account data, provider credential, private recovery coordinate or database backup is included in this public record.

Immediately before installation, a new private database and plugin backup was created and verified, preserving all older backups. The installer required the verified public predecessor and candidate digests, exact installed-file ownership/content checks, a backup no older than 900 seconds, and an owned maintenance marker with a 180-second drain. Its safety preparation included 90 isolated cases covering installer, backup/controller, first-migration and canary failure paths. This is operational-helper verification, separate from the source CI suite.

The first installed RC20 migration was explicitly measured before a full Pinova bootstrap: schema 3 → 4 took 108.543 ms; the idempotent second call took 0.015 ms. The expiry index is `(expires_at,id)`. Existing indexes and all four plugin-table row fingerprints were preserved by the migration; account, identity and configuration fingerprints also matched. The actual staging OTP table had only one row, so this timing does not establish a production-sized DDL bound.

A separate schema-only clone with 50,000 synthetic rows built the index in 405.141 ms and selected `expiry_cleanup` for the range plan. The clone and its owned controller state were removed. This is synthetic scale evidence, without copying real OTP data.

The bounded retention probe completed in 6.142 seconds. It verified at most 5,000 deletions per pass, a deduplicated 300-second continuation, deletion of eligible verified/legacy expired rows, and preservation of three live/recent/null-expiry cases. All owned synthetic OTP/log rows and continuation events were removed. One preexisting OTP more than seven days past expiry was deleted under the approved retention policy and remains present in the pre-install database backup. These probes made zero provider calls.

Actual code rollback restored the exact published RC19 files, including removal of the three RC20 additions. Its native runtime booted with additive schema 4 and the expiry index intact, with an empty queue. No database restore was performed. Code rollback cannot restore rows already deleted by retention; database recovery remains a separate operation using the verified backup.

The final RC20 reinstall completed in 186.659 seconds including its maintenance drain. All 3,634 installed files matched the independently verified candidate manifest; ownership/modes of all 3,631 retained files were preserved and the three additions received standard 0644 modes. Account, identity, application and provider configuration fingerprints matched the original baseline. Final health reported schema 4, no issues, an empty queue, correct daily maintenance/watchdog hooks and no remaining cleanup backlog or owned maintenance marker.

All 14 bounded public HTTP checks passed. The three served authentication modules matched the published ZIP byte-for-byte, including shared import revision 8 and standalone version 1.3.0.8. Foreign and opaque/null Origin requests received 403; malformed login/recovery array fields received 400; username-only method selection succeeded without requesting an OTP. Correlation headers were valid. The post-smoke snapshot confirmed no new accounts, OTPs, queue entries or delivery jobs, with protected fingerprints unchanged.

The CLI probe profile used WordPress 7.1.3 / PHP 8.3.36. The target web vhost uses active PHP-FPM 8.4.27. The observed components were WooCommerce 11.1.2 with HPOS enabled, wpForo 3.2.2, Dokan Lite 5.2.1, Elementor 4.3.3 / Pro 4.3.0 and Woodmart 8.6.2. The native-login gate remained disabled and a privacy page was configured. WP_CACHE was disabled and object/advanced-cache drop-ins were absent. Dynamic HTTP samples took approximately 2.7–3.6 seconds; no prior baseline was measured, so this does not establish a latency improvement or regression. These observations do not establish authenticated privacy/commerce actions or complete theme workflows.

A local interactive browser was unavailable because the connector was not connected. The user separately confirmed correct behavior when asked to check the mobile login page and opening/closing its login window without requesting another OTP; the browser/device was not specified. This limited manual confirmation, the live HTTP/module checks and existing Chromium CI do not establish a named Chrome Android/Safari iOS matrix or authenticated mobile workflows.

## One authorized SMS

Fresh runtime and raw read-only preflight checks confirmed the installed source, configured SMS-only selection and no preexisting live recipient flow. The initial private preflight helper incorrectly required three legitimately empty optional settings sections to be arrays; that helper was corrected without changing site options, and an independent reviewer reran 34 offline cases successfully.

Exactly one public OTP POST returned HTTP 200 with the expected generic success, opaque state, OTP method and bounded TTL; response latency was 3,357 ms. One ordinary same-site GET exercised normal WordPress cron. The user explicitly confirmed physical SMS receipt. No second initiation, OTP verification, account creation, role/configuration change or early history deletion was requested.

The sender helper then rejected response processing before saving its flow linkage. A fake-only reproduction demonstrates an overly strict helper assumption: a valid 180-second expiry begins after WordPress bootstrap and can exceed the client request-start plus 181 seconds. The observed candidate deadline was request-start plus 182.307 seconds. The response itself was discarded, so the exact live failing assertion and exact response-to-record ownership cannot be reconstructed. This demonstrates a helper problem; no product expiry defect was established. Original sender/attempt receipts and the consumed one-attempt budget were preserved.

A separate bounded read-only observation found one new recipient OTP with application-classified SMS acceptance, a delivered queue marker, no active worker lock or matching scheduled jobs, unchanged settings and no verification attempts. The record matched the queue scope and expected purpose/account binding, but attribution to the exact HTTPS response remains unconfirmed. It expired naturally and remains subject to the approved seven-day retention. Physical receipt is established by the user's separate confirmation, without requesting or retrieving the OTP code.

## Observation window and remaining acceptance

The first observer started at 2026-10-24 18:34:19 UTC and latched incomplete coverage because its policy required an inactive WordPress debug log. A read-only audit confirmed the active PHP-FPM 8.4.27 socket and target Nginx configuration; WordPress debugging is disabled and its default debug log is inactive. The existing target-vhost log is the configured in-request FastCGI stderr sink. No site logging/debug configuration was changed.

The private observer was corrected and independently verified with 64 isolated cases, PHP/Python syntax checks and a live read-only configuration-guard check. It pins 13 audited configuration files and two bounded include-directory memberships, checks the mapping at startup and each sample, and omits the optional WordPress sink only while both runtime debug flags remain explicitly false. Unknown or changed configuration, missing/unreadable required logs, rotation gaps, truncation and read caps latch incomplete coverage. Its full 86,400-second clock starts after initial configuration/log baselines.

The original observer was stopped after its exact root process identity, frozen files and absence of children were rechecked. Its three incomplete samples and original files were preserved with a separate stop receipt; they are not a completed successful window. A new private one-off observer started at **2026-10-24 19:01:08 UTC**, sampling every 600 seconds, with expected completion at **2026-10-25 19:01:08 UTC**. The new process and all five frozen artifacts were verified. Its first sample passed the configuration guard, required-vhost log coverage, three HTTP checks and database checks: the expected source/tag, schema 4, maintenance off, and no pending/expired delivery queue. The final completion record did not yet exist at this snapshot.

Coverage is limited to the configured target-vhost in-request diagnostics, HTTP probes and database snapshots. WordPress's normal reporting mask suppresses notices/deprecations; capture of all diagnostics after `fastcgi_finish_request()` and global FPM daemon/master logs has not been established. The observer does not send OTPs, verify login, delete history or change cron/services. Startup and healthy samples do not establish a completed 24-hour window, all-PHP diagnostic coverage, or stable-release readiness. Inspect the actual persisted completion timestamp, result and coverage before treating the observation as complete.

Live authenticated login/recovery and cross-purpose OTP verification, private administrator/security-plugin/logout acceptance, privacy export/erasure, and commerce/forum/seller account/order workflows were not performed on this installed site. Their isolated source CI is separate evidence. The user's basic manual mobile UI confirmation did not identify a browser/device and does not establish the named Android/iOS matrix. The full backup was integrity/readability checked; a full database restore was not executed. Production installation and stable/latest promotion remain separately authorized gates; neither was performed here.
