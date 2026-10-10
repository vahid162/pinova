# Elementor document warning characterization — 2026-10-10

## Scope and source

PR #73 adds a Pinova-only compatibility guard for the characterized Elementor Core 4.3.3 / Pro 4.3.0 pair. It removes only priority-11 `enqueue_scripts` callbacks owned by exact Product or Product_Archive objects whose `get_post()` is null. Other versions, classes, methods, priorities, non-null post values, real documents and their preview scripts are preserved. There is no schema, identity, logging, dependency or third-party source change.

Reviewed source head: `40feed1921c9e44738d22b21998d923a49074c47`.
Merge: `080b58aae867a734850192b44d4cfeafaf4c6441`.
Both trees: `24af4cac5927c882f6ec006e104b2e28b0058485`.

## Reproduction and review

The active Elementor kit was present and valid. Installed Pro's template type inspection constructs documents with no post; the two WooCommerce constructors register frontend enqueue callbacks anyway. These call Core `get_main_id()` before a post check and cause the null-ID warning.

A bounded, outbound-blocked native frontend lifecycle produced two warning occurrences. Loading the candidate in that CLI process reduced them to zero. Native Product and Product_Archive callbacks bound to an existing published post remained at priority 11; through the native enqueue lifecycle they enqueued `wc-single-product` and `woocommerce`, with zero warnings. Merely invoking callbacks before native script registration is not a valid enqueue test; the final characterization uses the native lifecycle.

Three simplification reviewers and four code reviewers returned no actionable findings. A follow-up reviewed the behavior-preserving allowlist formatting and optional PHPStan signatures added after the first CI attempt. The missing analysis declarations and formatting failures were corrected without suppressions or changed runtime behavior.

Automated regression uses original behavioral doubles for the two Pro classes, with real WordPress hook dispatch. No proprietary plugin source is bundled. It covers repeated invocation, exact class/method/priority boundaries, real documents, unrelated callbacks, and absent/uncharacterized version pairs. Native Pro behavior is separately characterized on authorized staging.

Exact-head PR Quality: `38046374779`, success.
Exact-head branch Quality: `38046371795`, success.
Merged-main Quality: `38046990959`, success.
Integration profiles: 366 tests, 2395 assertions; complete suite includes PHP 8.1–8.5, HPOS on/off, real wpForo/Dokan combinations, browser acceptance, plugin checks and package/security gates.

## Production read-only observation

The production installation had independently advanced to Core 4.3.4 / Pro 4.3.1. Both implicated Pro methods already contain a missing-post check. The Pinova adapter deliberately does not apply to that pair. A bounded 1 MiB server-log scan contained no records for the specific document warning; this is not a complete site-health assessment or proof about unretained events.

Production files, data and services were not changed by this task.

## Immutable release and independent verification

Release: https://github.com/vahid162/pinova/releases/tag/v1.3.0-rc14

- Release ID: `408896182`; immutable, prerelease, not draft.
- Release-branch Quality: `38047483355`, success.
- Publisher: `38047904321`, success, including fresh-download asset and source-bound SBOM attestation verification.
- Annotated tag object: `cd5a356fbb2810886c44a6d75be441bf4ed1ab66`, targeting the merge above.
- ZIP SHA-256: `0abae9d12714970982b77be8fccb4c1b8541e34fdb8516b631d755ab2708f0cc`.
- SBOM SHA-256: `7483d187cfdf703d5bd5fb89b6038e409a7490ea1e0a2d3f464b64ba180a5b21`.

An independent host download verified all four API digests, both checksum files, ZIP integrity, package allowlists, source build identity and SBOM/ZIP agreement for 44 Composer packages. Signature verification ran successfully in the publisher; no additional local signature verification was claimed. The ZIP has 3625 files, adds only the Elementor document adapter relative to RC13, and removes no files. Other changed files are the bootstrap hook, readme, build identity and generated Composer class maps.

## Authorized staging acceptance

Only Pinova on the authorized test installation was replaced. A private database/Pinova-file backup was verified before installation. The published ZIP passed RC13 → RC14 → RC13 → RC14 installation and rollback. Each installed tree matched its release archive; file ownership was preserved and maintenance ended. The restored predecessor also passed its native-login/empty-worker smoke check. No database restore was required.

Final RC14 acceptance:

- Native frontend reproduction: zero document warnings.
- Native Product and Product_Archive preview: valid callbacks retained and both native script handles enqueued; postless callbacks removed, zero warnings.
- Repeated WooCommerce saves, stale synthetic metadata cache, duplicate-safe OTP login, single-use rejection, password recovery, technical failure evidence, late policy/proof rejection and conflicting identity: passed.
- All three fixture-owned accounts and their metadata, OTP records and synthetic log evidence were removed; original and concurrent records were preserved. Outbound test messages: zero. These checks do not establish physical SMS receipt.
- Empty OTP CLI command processed zero jobs and preserved the cron array. Native login GET returned HTTP 200 with an empty username field.
- Public home/login/forum returned HTTP 200; forum sign-in and vendor dashboard returned same-site Pinova login redirects.
- Original account rows, all original user metadata, settings, order count, original 31 log rows through ID 43, schema 3 and logging-cleanup schedule matched pre-install hashes/state. The native-login gate remained off.
- Neighboring plugin trees matched their pre-install file hashes: Elementor Core 3429 files, Pro 1326, wpForo 682, Dokan Lite 1223.
- Final Pinova installation matched all 3625 release files; maintenance was absent.

The post-install server and WooCommerce fatal-log observation began at 11:21:31 UTC. The initial acceptance scan showed zero appended bytes and zero new PHP warnings/notices/deprecations, fatal/uncaught errors or database errors. Pinova's current-day report was database-backed and readable, with cleanup scheduled and no issues. Its zero examined rows, warning threshold, sampling and retention remain coverage limits; absence of rows is not proof of universal health. Physical delivery was not tested.

The separate private installation-provenance observation is outside this Pinova compatibility correction and is not included in public evidence. Production installation remains the site owner's decision; no production or shared-service change was performed.

## Evidence PR browser-fixture correction

The first documentation PR run (`38048404905`, job `114202466188`) passed 15 browser cases but failed before the Logs and Issues case could authenticate. Its trace recorded no login POST, and the screenshot showed the synthetic password in the username field with the password field empty. WordPress's native 200 ms autofocus ran during automated field entry. The same documentation head's branch run passed, as had the release-source runs above.

The browser fixture now waits for native username focus before entering credentials and asserts both field values before submission. It does not disable autofocus, alter authentication, add retries, or relax the login assertion. This test-only correction changes no released files and does not require replacing the immutable RC14 package. CI must pass on the corrected evidence PR head before merge.
