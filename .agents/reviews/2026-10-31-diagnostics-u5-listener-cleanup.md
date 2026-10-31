# Diagnostics upgrade U5 follow-up: owned browser listener cleanup

Date: 2026-10-31. Scope: same-unit U5 follow-up identified by the actual Compound Engineering simplification pass. Canonical integration and release are separate gates.

Each initialized controller previously added anonymous page-hide/page-show handlers. Existing form history/before-unload and modal before-unload callbacks also retained destroyed state. Removing only the new handlers would leave those references alive.

The existing actual-controller lifecycle test was strengthened before runtime edits. [Quality run 41203889198](https://github.com/vahid162/pinova/actions/runs/41203889198), exact source `1f3b75ed3a823fa3957e49564449f4c074501f28`, JavaScript job `124544949082`, passed 183 existing tests and failed exactly two new destruction cases: both controllers retained three page-hide listeners where two were expected. The shared event harness models independent callback sets rather than replacing callbacks by name.

The three runtime files now retain per-instance cleanup closures. Initialization removes previous owned hooks; destruction removes only that component's window/theme listeners, cancels pending request/credential/timer work and invalidates late callbacks. Modal destruction also invalidates focus and releases its owned background interaction. Existing callback bodies and ordering are preserved. Controller cancellation stays in the controllers. Unreleased asset revision 9 remains synchronized; no package using that revision has been deployed.

[Dedicated verification 41205046758](https://github.com/vahid162/pinova/actions/runs/41205046758), exact source `87e4cb4b1768630724ee7cc831e9585b5088e112`, job `124548310340`, passed all 185 JavaScript tests and all 26 actual adapter/browser executions: thirteen each on Chromium and WebKit, no retries, failures or skips. Coverage includes repeated initialization/destruction, inert detached state and preservation of live mounts/unrelated handlers. These fixtures use real plugin controllers/adapter HTML and stub all provider-facing REST; they do not establish physical-device or SMS acceptance.

Artifact `13043942246`, ZIP SHA-256 `90d6bc98df012a859cc1a499297afd124cc901742a53ab5521a6b153a5808f99`, was independently downloaded and checked. Its embedded report confirms total/expected 26, unexpected/flaky/skipped zero, both named engines and `ok=true`. The four owned UI/test files have manifest SHA-256 `b86bb1377c7769a5a1832440a703a44b0fde3ae53dccd84d019912a861744d77`. Those bytes are unchanged in the subsequent combined candidate; only U4 formatting/tests changed there.

No live site, provider, SMS, release or installation was used for this follow-up. Temporary browser workflow/config files never enter canonical source.
