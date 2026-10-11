# Capability integrations RC15 — release and test-site evidence, 2026-10-11

This dated record covers the reviewed capability-based coordination change, immutable GitHub
prerelease and installation on the authorized dedicated test site. The user retains responsibility
for production installation. No production package, theme, dependency-plugin source, configuration,
scheduler or shared service was changed. Private infrastructure coordinates, identifiers,
credentials, signed download URLs and raw operational payloads are excluded.

## Reviewed source and CI

- Implementation: [PR75](https://github.com/vahid162/pinova/pull/75), final reviewed head
  `e7715e01fd878054abd4b1eb8e873cb883eaed5d`.
- Exact-head [PR Quality 38120083839](https://github.com/vahid162/pinova/actions/runs/38120083839)
  and [branch Quality 38120080435](https://github.com/vahid162/pinova/actions/runs/38120080435)
  passed.
- Merge/source `adec9803b2e2a97f8a6bbe57bf99549f33f9e04d`, tree
  `d4596042bd593960c7f496ceb8c9914e7182b388`.
- Independent [merged-main Quality 38120845601](https://github.com/vahid162/pinova/actions/runs/38120845601)
  passed: 30 successful jobs and one event-inapplicable Dependency Review skip. The required gate
  succeeded. All ten native-plugin matrix jobs and eight WordPress integration matrix jobs passed.
- [Publish-branch Quality 38121364457](https://github.com/vahid162/pinova/actions/runs/38121364457),
  [dispatcher 38122002233](https://github.com/vahid162/pinova/actions/runs/38122002233) and
  [publisher 38122006634](https://github.com/vahid162/pinova/actions/runs/38122006634) succeeded
  for `publish/v1.3.0-rc15` at that exact merged source.

Representative merged-main job logs were inspected independently of the publisher. The deployed
profile uses WooCommerce 11.2.1, Dokan 5.3.0 and wpForo 3.2.2. Both HPOS states emitted all 16
required native completion markers, including forum normal/manual-approval scenarios with
WooCommerce and Dokan skipped. Each selected deployed job passed eight real-plugin Chromium
tests. The separate account UI Chromium job passed 16 tests. A selected integration job completed
376 tests and 2,471 assertions, including integration-health, issue-monitor and event-throttle
coverage. These are remote CI browser results, not a browser run on the shared test-site server.

Earlier failed runs remain part of GitHub history. Lightweight syntax, targeted Node, governance,
changelog, skill-sync and tracked-shell checks passed before acceptance. The CI correction to
Spec Kit shell helpers preserves their intended default working-directory and persistence
semantics; all 16 tracked shell scripts passed ShellCheck. Full matrices and package builds ran
on GitHub Actions, rather than on the shared host.

## Resulting behavior and limits

Saved coordination intent is separate from observed readiness. Dependency versions are bounded
diagnostic evidence; changing a version number alone no longer disables the integration. Loaded
callable signatures, native lifecycle contracts and guarded mutation boundaries determine whether
each operation is available. Forum coordination does not depend on WooCommerce or Dokan.
Routing can remain active when automatic membership or native vendor conversion is unavailable.

The same WordPress account remains the identity anchor. Purpose-bound mobile proof remains
necessary for guarded onboarding. Ordinary public registration and login do not grant seller
status. Native forum holds, bans, groups, truthful email confirmation, selling permission and
vendor/product approval remain authoritative. Pending-member safeguards survive routing switches
and missing capabilities. Unknown or ambiguous native mutation behavior fails closed for that
operation; it does not become approval.

The existing integration settings tab and Logs and Issues show off, active, partial or unavailable
state, finite reasons and observed versions. Page/JSON/CLI reports calculate fresh current health
without persisting an observation or changing account eligibility. Historical transition events
are separate best-effort evidence: configured levels, sampling, retention and logging failure can
leave gaps, and a current active snapshot does not prove a historical issue recovered. Observation
uses at most two fixed non-autoloaded options containing state/reason only. This release adds no
schema migration, scheduler or external monitoring service and performs no automatic code repair.

Capability inspection and characterized native tests support compatible updates; they cannot
guarantee all future upstream semantic changes or uncharacterized extensions. Genuine API loss
or changed native behavior can still require a future Pinova adapter correction.

## Immutable release and independent asset verification

[Release v1.3.0-rc15](https://github.com/vahid162/pinova/releases/tag/v1.3.0-rc15): ID 409507614,
native immutable=true, draft=false, prerelease=true. Annotated tag object
`8e7e7104553ec1ffd0596bf50b8239339e6682a9` targets
`adec9803b2e2a97f8a6bbe57bf99549f33f9e04d`. The plugin/readme version remains 1.3.0;
`build-info.json` identifies RC15, the exact source and release-package identity. The release
was not promoted to stable/latest.

| Asset | Bytes | SHA-256 |
|---|---:|---|
| pinova-1.3.0.zip | 5,038,062 | 3520ce22685396490507882e798f1092282342493823d1646e3979f98b39a3e0 |
| pinova-1.3.0.zip.sha256 | 83 | 470eaef7ab872bc2b5be6bf6a47e68d47b9974c54cd894ced4d378384b574e73 |
| pinova-1.3.0.spdx.json | 97,939 | 5eeb7028725d7447dbb2984a9f70a242424f0816663e2c820bc9899402b74eb0 |
| pinova-1.3.0.spdx.json.sha256 | 89 | 04c289898ff59be4d16cbe6e27de4496ab0b5d7a22dadf54eceac604a255a689 |

An independent read of the public Release API confirmed the state, exact target, sizes and digests
above. The publisher built twice, checked reproducibility and verified release/all-four-asset attestations
and the signed SBOM. A separate fresh download of all four published assets passed native API
digests, exact companion checksum basenames, bounded ZIP integrity and path checks, runtime
allowlist, exact build identity and SPDX content validation. The ZIP contains 3,626 files and the
SBOM matches all 44 production Composer packages exactly once. Agent instructions, Spec Kit
artifacts, specifications, tests, tools and private data are excluded from the installable package.

Independent host verification with the native GitHub CLI also validated the ZIP's SBOM attestation,
requiring the exact repository, source commit, publish-branch ref and signing-workflow identity,
and rejecting self-hosted runners. Its verified signed predicate matched the separately downloaded
SPDX JSON exactly and bound the published ZIP digest.

Two transport/environment problems were resolved without changing Pinova or weakening verification.
GitHub served the public attestation bundle as raw Snappy-compressed JSON, matching the
[official CLI decoder](https://github.com/cli/cli/blob/v2.102.0/pkg/cmd/attestation/api/client.go#L237-L244).
A private bounded transport decoder was independently checked with ten valid and 22 malformed
fixtures; authenticity remained the native CLI's responsibility. The default Sigstore endpoint
returned HTTP 403 for root 15. The [official Sigstore Pages TUF repository](https://github.com/sigstore/root-signing/blob/main/README.md#publishing-and-automated-testing), used by Sigstore for preproduction testing,
was used with the native root-14 bootstrap. Native TUF authenticated the update and fresh signed
metadata before producing the trusted-root input for verification. Signature thresholds, expiry,
target integrity and attestation policy were retained; no system dependency or service changed.

## Authorized test-site installation and rollback

The user explicitly declined a fresh backup. No new backup was created and no database was
restored. Previously verified October 10 database material was retained; it is historical recovery
material, not a current full-database recovery point. Independently downloaded public RC14 files
matched the original installed plugin exactly and supplied the files-only rollback. This scoped
exception did not change the durable backup requirement for future installations.

The actual package cycle RC14 → RC15 → RC14 → RC15 passed on the authorized test site.
The initial upgrade changed 15 files and removed none. Physical rollback restored 14 changed
files and removed only the candidate-added integration-health file, returning the plugin manifest
and original measured state exactly to RC14. It preserved the current database. The final RC15
installation matched all 3,626 packaged files, including bytes and metadata, and both requested
integrations were active at `2026-10-11T07:54:12Z`.

Three native identity scenarios passed: normal forum, manual approval and administrator-held
membership. Actual public user-API OTP completion exercised native cookie hooks and retained the
same WordPress User ID. The checks verified mobile proof, truthful email confirmation, preserved
forum groups and native selling/vendor approval. Synthetic OTP setup did not send a real message
or establish phone receipt. All owned fixture data was cleaned up.

Two monitoring scenarios passed: current read-only report/CLI behavior and deduplicated
observations bounded to the two fixed option slots. Report execution emitted no stderr, and fixture
cleanup restored the original observer rows. Native identity execution emitted four expected
WP-CLI redirect diagnostics from the native handler; independent inspection of the bounded
receipt found no PHP or SQL error. These diagnostics are not claimed as successful browser
redirects.

Bounded pre/post comparisons matched original accounts and metadata, posts/postmeta, forum
state, both CPT and HPOS order stores, measured settings, all 31 original log records and schema
version 3. These comparisons verify the measured state, not every possible concurrent or
uninspected server datum. No unintended removal or account/permission widening was observed.

## Final isolation, HTTP checks and remaining boundaries

Final isolation checks preserved 12,523 measured files across test-site configuration and five
other plugin installations in both bytes and ownership/modes. This is the scoped manifest,
not a claim that every site or server file was inspected. No maintenance state remained.
The final login HTTP response was 200 with the identifier field rendered and no fatal error;
the scoped Pinova REST namespace response was 200 and exposed the expected OTP route metadata.
An earlier broad REST-index probe exceeded its 2 MB response-size bound. The subsequent probe
used the specific namespace and passed; the size-bound rejection was a transport-check limit,
not evidence of a product failure. The two single-request timings had no comparison baseline
and are not a performance acceptance claim.

The observed acceptance window was `2026-10-11T07:48:53Z` to `07:59:49Z` (10 minutes 56 seconds),
including the packaged-file check at `07:54:12Z` and public HTTP checks near `07:58Z`. The final
post-HTTP handoff check at `07:59:49Z` again matched all original bounded data/settings, schema 3
and all 31 original logs; both integrations were active, maintenance was absent and stderr was
empty. The user was notified in English and Persian that the test-site installation and rollback
passed and that production installation remains their own operation. No production installation
was performed.

- No new Pinova defect was found within the measured acceptance scope.
- Acceptance is point-in-time CLI/native-hook testing plus remote CI Chromium coverage. It is
  not physical SMS receipt, an on-host browser run, a 24-hour observation window, a production
  canary or a new real shopper payment.
- A pre-existing external Elementor deprecation under CLI PHP 8.4 remains outside this Pinova-only
  change. Separate environment findings are retained privately for the user's follow-up; public
  evidence omits security-sensitive configuration detail. Neither Elementor nor the environment
  configuration was changed to suppress those findings.

The release, authorized test-site installation, rollback, functional acceptance, final measured
isolation/HTTP checks and user-notification gates are complete within these limits. Stable promotion,
production installation and later production observation are not part of this evidence.

## گزارش فارسی

نسخهٔ RC15 پس از بازبینی PR75، موفقیت آزمون‌های نسخهٔ نهایی و شاخهٔ اصلی، ساخت تکرارپذیر و
بررسی گواهی‌های انتشار، به‌صورت پیش‌انتشار تغییرناپذیر منتشر شد. دانلود مستقل هر چهار فایل،
چک‌سام‌ها، محتوای ۳٬۶۲۶ فایل و فهرست ۴۴ وابستگی تأیید شد. بررسی مستقل امضای SBOM نیز منبع،
شاخه، گردش‌کار انتشار و هش بسته را تأیید کرد. مشکلات انتقال Snappy و دسترسی به منبع TUF با
روش‌های رسمی و حفظ کنترل‌های امضا و اعتبار فراداده حل شدند.

هماهنگی دکان و wpForo اکنون بر قابلیت‌های واقعی و مرزهای امن عملیات تکیه دارد؛ شمارهٔ نسخه فقط
اطلاعات محیط است. انجمن به ووکامرس وابسته نیست. فقدان یک قابلیت تنها عملیات مربوط را متوقف
می‌کند و محدودیت‌های عضویت، تأیید موبایل، تصمیم مدیر، گروه‌های انجمن و مجوز فروش حفظ می‌شوند.
صفحهٔ تنظیمات و «لاگ‌ها و مشکلات» وضعیت فعلی را از رخدادهای تاریخی جدا نشان می‌دهند. گزارش‌گیری
فقط‌خواندنی است و این قابلیت کد یا تنظیمات را خودکار تعمیر نمی‌کند.

با توجه به رد صریح پشتیبان تازه، پشتیبان جدید یا بازگردانی دیتابیس انجام نشد. فایل‌های عمومی
RC14 با نسخهٔ نصب‌شده تطبیق داده شدند و چرخهٔ واقعی نصب، بازگشت فایل‌ها و نصب دوباره موفق بود؛
دیتابیس فعلی حفظ شد. سه سناریوی هویت و دو سناریوی پایش موفق بودند و داده‌های موقت پاک شدند.
دریافت پیامک واقعی، مرورگر روی این سرور، مشاهدهٔ ۲۴ساعته یا نصب روی سایت اصلی با این بررسی‌ها
اثبات نمی‌شوند. بررسی نهایی حفظ فایل‌های افزونه‌های دیگر و تنظیمات سایت آزمایشی و پاسخ HTTP نیز موفق بود و
نتیجه به فارسی و انگلیسی به کاربر اطلاع داده شد. در محدودهٔ بررسی، ایراد تازه‌ای در پینوا یافت
نشد؛ نصب روی سایت اصلی و بررسی بعدی آن همچنان در اختیار کاربر است.
