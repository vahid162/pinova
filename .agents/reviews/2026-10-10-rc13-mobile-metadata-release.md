# Mobile metadata and proof-policy repair release evidence

Recorded on 2026-10-10. This is evidence of the scoped repository and authorized staging work, not permission to deploy elsewhere.

## Problem and change

A WooCommerce customer read appended a synthetic, unsaved `pinova_mobile` row. Later native customer saves could insert it repeatedly. Pinova's proof reader rejected identical physical duplicates after consuming a valid OTP, so the public response incorrectly blamed the code and the issue report missed the completion failure.

The adapter now uses WooCommerce's view getter. Its read filter also removes only unsaved mobile rows if an extension enables raw metadata caching. The proof reader accepts byte-identical physical mobile values without deleting them; different values and duplicated proof/generation/binding rows remain rejected. Operational completion and session exceptions receive bounded evidence and a generic temporary-failure response that asks for a fresh code. Deliberate late policy denials retain the expected-rejection classification.

No account merge, mobile-row deletion, schema migration or username rewrite is part of this fix.

## Reviewed history and validation

- PR: https://github.com/vahid162/pinova/pull/69
- Reviewed source head: `d9b20cc5d519ef729d93cff4d5049372972966cd`.
- Exact reviewed tree: `41f5e9853c5b3d55cf0267b85f2a3f467c98c2e3`.
- Exact-head PR Quality `38037283195` passed all 29 jobs; branch Quality `38037278687` passed all applicable jobs.
- Nine local source-review lenses completed. Two findings (late-policy classification and retry copy) were independently validated and corrected. No outstanding actionable source finding.
- Separate-provider review was unavailable; a local adversarial review was completed. This is not a claim of external-model review.
- An initial CI run caught a cache fixture that assumed native customer caching was enabled and two WPCS formatting violations. The fixture now explicitly enables the optional cache through a test-only native customer subclass; no checks were removed or relaxed.
- One green integration matrix job reported 354 tests and 2,338 assertions. Coverage includes repeated native saves, preserved identical metadata, OTP login/replay, recovery without proof escalation, distinct-value rejection, proof-write failure and late-policy denials.
- Nonblocking coverage opportunities remain: direct tests of the two new authentication-event reason sampling/site cap combinations, and a proof-only API storage-failure response test. Generic throttle limits and login completion failures already have coverage.

## Follow-up before deployment

The first fix was merged as `2daed7eb83bdfee0dc9d1b7fb6532118d18c6192`; merged-main Quality run `38038141768` passed. After its RC11 publication pipeline had started, a further source check confirmed that a changing policy at the authenticated proof-only completion boundary still became an operational 503. This remains fail-closed but is inaccurate issue evidence. RC11 is therefore superseded for installation and was never installed by this task; published history is preserved.

PR https://github.com/vahid162/pinova/pull/70 corrects that one remaining exception type and adds an authenticated endpoint regression. Source head `c3233719b07f6c35112217824c90eb148149fad8`, tree `34e05f5449ff7edb73588e045f39a62bde8ce825`, passed focused independent review with no findings. All 29 PR Quality jobs passed in run `38038890004`; branch run `38038884745` also passed its applicable jobs. A checked integration job reported 355 tests and 2,351 assertions. It merged as `aa667e1ec0a25eb5409c08607532f69fd5bc9018`.

Merged-main Quality run `38039756479` passed all 28 applicable jobs (the PR-only dependency review was skipped). The unused publish branch `publish/v1.3.0-rc12` was created from that exact green merge commit.

## Deployment safeguards

A bounded independent review of the task-owned staging helpers caught partial-install recovery, missing completion-marker enforcement and cleanup verification defects before any installation. These were corrected and re-reviewed with no outstanding findings. Six isolated filesystem/control-flow recovery scenarios passed; a separate wrapper check rejected exit-zero without its completion marker. This is helper validation, not a claim that the real staging upgrade has already passed.

## RC12 publication and staging finding

RC12 was published from `aa667e1ec0a25eb5409c08607532f69fd5bc9018` after publish-branch Quality `38040157677` and publisher `38040585117` passed. Release `408831002` was immutable and prerelease; annotated tag object `7cd34ff93eb10664876fabff53643e92206009c6` targets that source. Independent downloads verified all four asset digests, package allowlists, 3,624 files and the SBOM inventory of 44 production Composer packages. ZIP SHA-256: `fa943d2d92babf9b48b714d3adf315a5311292516a622e9bf94e334d9d88b7dd`.

The authorized test site passed an RC10 → RC12 → RC10 → RC12 installation sequence, exact package verification, focused synthetic login/recovery/WooCommerce/policy tests, native-login rendering and empty-queue CLI checks. The subsequent full metadata comparison detected two orphan epoch rows belonging only to deleted synthetic accounts. The fixture had checked account removal but had not checked all metadata rows; its success marker was insufficient to establish complete cleanup. Original account records and original metadata rows were unchanged. Exact owned-row cleanup restored the metadata to the original backup. The final candidate fixture now requires zero metadata rows for every removed synthetic account before reporting completion.

WordPress snapshots metadata IDs during account deletion. Pinova's ordinary mobile revocation can recreate an already deleted epoch outside that snapshot. PR https://github.com/vahid162/pinova/pull/71 adds a bounded final `deleted_user` cleanup after an error-free direct lookup proves the account is absent. An existing account, including one removed only from a multisite site, keeps its evidence. No global orphan purge is included. Four regression cases cover earlier/missing epochs, metadata-ID revocation, and existing-account proof preservation; the last is a guard test, not a full multisite lifecycle test. Focused independent source review found no actionable issues.

Source head: `8f5b209efb8bc036be789e443c8b33e83091c4f7`; tree: `835bc64ec39bb2c3fc2fd5b027ede8dae460ea88`. Local PHP syntax, governance, synchronized changelog, 10,026-byte WordPress readme and whitespace checks passed. A completed integration job reported 359 tests and 2,373 assertions. All 29 pull-request Quality checks passed in run `38041144023`; branch Quality `38041139236` passed all applicable checks. The reviewed correction merged as `4c7fdd99dc948e7955c3be36c6af2edfd39470c5`. RC12 is superseded for production installation, with its immutable publication preserved.

## Final publication and staging

Merged-main Quality `38041722303` passed all 28 applicable jobs; the PR-only dependency-review job was skipped. The unused `publish/v1.3.0-rc13` branch was created only after that result, from exact source `4c7fdd99dc948e7955c3be36c6af2edfd39470c5`.

- Final release: https://github.com/vahid162/pinova/releases/tag/v1.3.0-rc13
- Publish-branch Quality: `38042189731`, success, 28 applicable jobs; PR-only dependency review skipped.
- Publisher: `38042618107`, success, including two identical builds, SBOM creation, source-bound attestation and published-asset redownload verification.
- Annotated tag object: `1ba81dd378a71b900d3d5c10ad7b8e15f46e4579`; target: `4c7fdd99dc948e7955c3be36c6af2edfd39470c5`.
- Release ID: `408852084`; `immutable: true`, `prerelease: true`, `draft: false`. No stable/latest promotion.
- Independent host redownload: all four API asset digests, checksum files, ZIP integrity, safe paths, package allowlist, exact build provenance and 44-package Composer/SBOM inventory passed. The package contains 3,624 files, with no removed files versus RC10. Cryptographic attestation verification was performed by the successful publisher; no separate local signature-verification claim is made.

| Asset | SHA-256 |
| --- | --- |
| `pinova-1.3.0.spdx.json` | `25c976d7a7c0e1af531f579768cf2cf344ee0e1a4831c1ebefe61c86b551520f` |
| `pinova-1.3.0.spdx.json.sha256` | `a92d9806735388f7f94720d6edf3cb21b5fad94a1d2e0981068f7b4c673c6b86` |
| `pinova-1.3.0.zip` | `1cbe44b465013b339452b1ed566ed1189626060c8db3ea65a591b80e1a55b28e` |
| `pinova-1.3.0.zip.sha256` | `7a7fe87cbc724f2cb083a50cfcbdfe02f1959285ce5193be7f2cafed0aec9ad7` |

### Final installed-package acceptance

Only the authorized test site was updated. A fresh access-restricted database and Pinova backup was verified before installation; the package helper preserved the verified predecessor, checked existing files for drift, scoped maintenance and restored ownership. The RC12 → RC13 → RC12 → RC13 sequence passed exact file verification and real WordPress bootstrap at each boundary. RC13 remains installed and active; maintenance is absent and schema remains 3.

On WordPress 7.1.3, WooCommerce 11.1.2, wpForo 3.2.2, Dokan 5.2.1 and Elementor 4.3.3, the published ZIP passed:

- Repeated native WooCommerce saves and an explicitly enabled stale raw-metadata cache, without synthetic mobile persistence.
- Login with identical mobile rows, preserved username/rows, native cookie-hook observation, verified mobile proof and consumed-code replay rejection.
- Password recovery with identical rows, a verified password change and no mobile-proof escalation.
- A deliberately blocked proof write: temporary-failure status, fresh-code guidance and bounded operational evidence.
- Late login and authenticated proof-only policy denials: expected rejection, preserved account boundaries and no false operational-failure event.
- Conflicting mobile evidence rejected.
- Removal of all three synthetic accounts, their metadata, owned OTP records and owned test-log evidence. The strengthened fixture verified no metadata remained for any removed account. No real SMS or email was sent.
- Empty-queue OTP command: zero processed, unchanged schedule. Native administrator login GET: successful, empty username and no visible PHP error.
- Five bounded public HTTP checks: home/login/forum returned 200; vendor and sign-in routes redirected to same-site Pinova login. Observed response times were about 2.6–3.5 seconds; this is a smoke check, not a performance benchmark.

Original accounts, complete original user metadata, settings, activation list, native-login gate state, order count and all 31 original retained log rows matched their baseline hashes. No migration, merge or bulk deletion was performed. Auto-increment values, transient caches and bounded diagnostic counters are not claimed to be byte-identical database state. Byte-for-byte verification also preserved 682 wpForo, 1,223 Dokan and 3,429 Elementor files. No theme or shared service was edited.

### Observation and remaining limits

From 09:49:13 to approximately 09:52:12 UTC on 2026-10-10, the bounded test-site error scan found no PHP fatal/uncaught, database-error or Pinova entries and no truncation/rotation. Four pre-existing-type Elementor warnings recurred at `document.php:356`; the same warning was observed before this upgrade. They remain a separate finding and were not hidden or fixed by modifying Elementor.

The installed read-only issue report identified RC13 correctly, reported a readable database-backed log table and scheduled cleanup, and returned no matching issue rows for its current window. Its warning-level configuration examined zero retained rows in that window; this is not evidence that every operation was logged or that all defects are absent. Retention is 14 days. Original older evidence was preserved.

Production remains unchanged. The owner may install RC13 after reviewing this staging evidence and should have the affected user request a fresh OTP. Physical delivery and that customer's live login have not been retested. RC11/RC12 are superseded for production installation; their immutable releases remain preserved.


## Boundaries

No production installation, mutation of existing customer records, real SMS/email delivery, third-party plugin edit, theme edit or shared-service change is included. Synthetic staging credentials do not prove physical SMS delivery. Browser CI and staging callback/cookie-hook checks are distinct forms of evidence; no physical-device acceptance is claimed.
