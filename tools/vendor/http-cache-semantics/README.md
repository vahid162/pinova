Development-only security patch for GHSA-ch52-4w7c-c8xp. This directory is excluded from the installable Pinova package.

Source: the proposed upstream fix https://github.com/kornelski/http-cache-semantics/pull/58 at 14a8c2ad51740dc39bf3e8f1a11c845a5003f217. Upstream has no patched npm release as of 2026-10-03. Version 4.2.1-pinova.1 identifies this private patched snapshot, not an upstream release. index.js and LICENSE are unchanged from that exact commit; only package metadata removes install/test scripts and development dependencies.

index.js SHA-256: fc7b3f0265b7a7d0fee83bafa47186a66495720d3179801c2be3083de6d0cf76

The patch blocks max-stale reuse of security-restricted cache entries while preserving ordinary stale reuse and explicit public/immutable cookie opt-ins. The dependency override prevents the vulnerable registry implementation from being installed. Regression coverage must pass together with npm audit and the full locked CI suite; do not suppress the advisory or lower the audit threshold. Replace the private snapshot with a reviewed official fixed release when available.
