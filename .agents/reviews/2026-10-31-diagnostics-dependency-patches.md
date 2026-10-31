# Diagnostics upgrade: constrained dependency security patches

Date: 2026-10-31. Scope: resolve the pre-existing security gate exposed while establishing the approved diagnostics baseline. No live-site installation is established by this source change.

## Evidence and selected repair

Quality run [41190672101](https://github.com/vahid162/pinova/actions/runs/41190672101), source `7a3c9357dd90598877d9fbaaa199eea3fb68d116`, exposed five production dependency advisories: four for PhpSpreadsheet 3.10.7 and one for Symfony Mime 5.4.52. Both the PHP 8.1 audit and Supply Chain audit failed; subsequent branch advancement cancelled the overall run. No audit was bypassed.

The temporary, read-only [hosted resolution run 41191375903](https://github.com/vahid162/pinova/actions/runs/41191375903) used source `2f5032acb5649e49b1a3d340fbf77fc3bc2cab1b`, PHP 8.1 and Composer 2.10.3. It resolved exactly two patch updates, without installation, scripts, plugins, unrelated package updates or root constraint changes:

| Package | Before | After | Upstream source |
|---|---|---|---|
| phpoffice/phpspreadsheet | 3.10.7 | 3.10.8 | `fee9c1ca8d9f1f676f007ac1ab134ce43624bd52` |
| symfony/mime | v5.4.52 | v5.4.55 | `aefc42a0233050ed08c20580137dd857352f214f` |

Strict lock validation and production audit passed on the isolated runner. The finite receipt confirmed that root constraints/content hash, installed development packages and every unrelated package remained unchanged. Artifact `13038298228` had SHA-256 `9355328acb016c9dc1893057e3f7a553d11fafae4f49a3989984df3f526d69eb`; its archive and both allowlisted members were independently downloaded, size-bounded and verified before applying the lockfile. The old lockfile SHA-256 was `df0ee2a33e896d6873fdb15f7d6ffbfa2d0628bdd0e6ca036edf6ce0712d80f9`; the generated replacement is `c66822aadc44ac1987c50927a4e475d19a74e3d4bb37a2f1033f39b07fb90c0d`.

## Review and remaining gates

An independent read-only review found no blockers. Official upstream comparisons confirm the source references, unchanged production requirements/autoload mappings and compatible PHP requirements. PhpSpreadsheet hardens readers/external images; Pinova's writer APIs remain unchanged. Mime tightens address/header handling and is a transitive dependency. The temporary resolver workflow was removed after consuming its validated output.

This is resolution and static-review evidence, not runtime acceptance. Before merge/release, require the normal exact-head compatibility matrix, Composer audit, spreadsheet security regressions, email-purpose tests and package/SBOM inventory checks. The unchanged RC21 U1 baseline remains a separate experiment. U6 must explicitly use the same dependency inventory in both source-revision arms and disclose normalization of RC21 code to the patched inventory.

Primary sources: [PhpSpreadsheet 3.10.8](https://github.com/PHPOffice/PhpSpreadsheet/releases/tag/3.10.8), [upstream spreadsheet comparison](https://github.com/PHPOffice/PhpSpreadsheet/compare/3.10.7...3.10.8), [Symfony security resolution](https://symfony.com/cve-2026-45070), [upstream Mime comparison](https://github.com/symfony/mime/compare/v5.4.52...v5.4.55).
