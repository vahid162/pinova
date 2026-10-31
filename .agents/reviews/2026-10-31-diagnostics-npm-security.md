# Diagnostics upgrade: development-tool security gate

Date: 2026-10-31. Scope: the pre-existing npm audit failure exposed after the production Composer repair. These npm dependencies are development tools and are excluded from the plugin ZIP.

## Finding and constrained repair

Quality [41191788109](https://github.com/vahid162/pinova/actions/runs/41191788109), source `b4cd1044ee4fbda575c9556758e93ccb677d786d`, failed its unchanged low-severity npm audit threshold. The four moderate affected chain nodes led through wp-env 12, rimraf 5, glob 10.5.0 and minimatch 9.0.9. A forced audit fix proposed downgrading the environment; that proposal was not applied.

The scoped override retains wp-env 12.0.0, rimraf 5.0.10, every direct development pin and all existing overrides. Only wp-env's rimraf consumer receives glob 14.0.2 and minimatch 11.0.1. This is a reviewed transitive major override, not a claim of two patch-only package changes.

The generated lock has 27 changed package paths within the independently reconstructed old/new glob dependency closure: one addition, 20 removals and six modifications. The only affected consumer outside that closure is the intended rimraf-to-glob edge; unrelated consumers keep their dependencies. Added/modified packages are development-only, compatible with the pinned Node runtime, and retain official registry URLs and SHA-512 integrities. BlueOak-1.0.0 metadata applies to glob, minimatch, path-scurry, lru-cache and the added nonrecursive matcher; balanced-match and brace-expansion retain MIT metadata.

## Hosted and independent verification

Temporary read-only [resolution run 41192756930](https://github.com/vahid162/pinova/actions/runs/41192756930), exact source `e7775e599b3724a6f70792a8ad4a27fab854e6a6`, used Node 24.21.0 and npm 11.19.0. It generated the manifest/lock without running package scripts, rejected changes outside the allowed closure, then recreated the actual installed dependencies and passed:

- existing Git unsafe-VISUAL rejection, YAML API and vulnerable-formatter exclusion;
- actual wp-env-resolved CommonJS cleanup/glob/minimatch APIs;
- asynchronous/synchronous literal and glob deletion with sibling/symlink-target preservation;
- first-read cancellation with no background traversal after rejection;
- bounded minimatch preprocessing;
- the existing private cache-patch verification and unchanged npm audit threshold.

Artifact `13039602035` ZIP SHA-256: `d3e07cd750e1024451d15acc647d1b68ac74ba1d520d17e7df97e6d61adae5fc`. An independent download verified this digest, size limits and exactly three allowed members. The generated files were applied only after a separate read-only review confirmed registry metadata, dependency graphs and scope. The temporary resolver workflow was removed after consuming its output; the installed API checker remains an enforced Supply Chain check.

| File | Before SHA-256 | After SHA-256 |
|---|---|---|
| package.json | `d0d22b3cf4972c4f5315c71a802b5d24b90b89573604f59e3a80e06f28cde4a7` | `a23e2db35a6c6039157b3dacd801828f6804fe04208bbf9da00cbed20f08cab3` |
| package-lock.json | `3f87e84e2d0105048bb212ebd75b60d0e684be73f72730fb99336ab0e297a8c4` | `f3414a725f61ecd1ed2d96a8ae29801f049b89ac40ce17b19ac1c404cf82b348` |

Full exact-head compatibility, browser, environment cleanup, package and merged-main gates remain required. Successful resolution/API/audit checks alone do not establish that complete acceptance. No development dependencies were installed on the shared live host and no site was changed.

Primary sources: [wp-env package](https://github.com/WordPress/gutenberg/blob/trunk/packages/env/package.json), [rimraf API use](https://github.com/isaacs/rimraf/blob/v5.0.10/src/index.ts), [glob advisory](https://github.com/isaacs/node-glob/security/advisories/GHSA-3g3m-2fjr-6pw4), [glob fix](https://github.com/isaacs/node-glob/compare/v14.0.1...v14.0.2), [minimatch advisory](https://github.com/isaacs/minimatch/security/advisories/GHSA-g3hv-75wg-m249).
