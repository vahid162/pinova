# Security Policy

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability, exposed credential, authentication bypass, privacy leak, or production incident.

Use [GitHub Private Vulnerability Reporting](https://github.com/vahid162/pinova/security/advisories/new). Include:

- affected Pinova and WordPress/WooCommerce versions;
- the affected authentication method, role policy, and HPOS mode when relevant;
- minimal reproduction steps and expected versus actual behavior;
- impact and prerequisite access;
- logs or screenshots with OTPs, passwords, tokens, cookies, nonces, private routes, mobile numbers, email addresses, and site-specific secrets removed.

If Private Vulnerability Reporting is unavailable, contact the repository owner through their GitHub profile and request a private reporting channel. Do not send a working exploit or secret through a public issue or discussion.

## Response and disclosure

Maintainers will acknowledge a complete report as capacity permits, validate it against a supported release, and coordinate remediation and disclosure with the reporter. Please allow time for a tested fix and immutable release before public disclosure.

Security releases follow the same review, CI, reproducible-package, attestation, and immutable-release requirements as other releases. Publication does not authorize production installation; operators must retain backup and rollback controls.

## Supported versions

Security fixes target the supported stable line and, when applicable, its newest immutable prerelease. Older prereleases and superseded artifacts remain immutable history and may not receive backports. Check the WordPress plugin page, GitHub Releases, and release evidence for the exact supported artifact rather than relying on a copied status statement.

## Scope

Reports involving Pinova authentication, authorization, OTP purpose isolation, account enumeration, native-login routing, rate limiting, blocked identifiers, exports, privacy erasure, structured logging, dependency integrity, or release provenance are in scope.

Provider outages, unsupported third-party modifications, social engineering without a product defect, and findings that require already-compromised administrator access without increasing impact may be out of scope. Maintainers will still review credible reports.
