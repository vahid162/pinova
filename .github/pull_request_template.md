## Summary

Describe the requirement and the smallest coherent change that satisfies it.

## Verification

- [ ] Targeted regression tests added or the reason they are not practical is documented
- [ ] Lightweight governance and changelog checks pass
- [ ] Applicable PHP, JavaScript, integration, HPOS, browser, package, and security checks pass
- [ ] Remaining manual, staging, physical-device, or production acceptance is identified

## Impact classification

Check every affected area and explain it below.

- [ ] Runtime code or public interface
- [ ] Database schema, migration, cron, activation, deactivation, or uninstall
- [ ] Authentication, authorization, identity, redirect, or account recovery
- [ ] Privacy, personal data, retention, export, or erasure
- [ ] Logging, event catalogue, context allowlist, or incident export
- [ ] Dependencies, lockfiles, GitHub Actions, build, package, or release
- [ ] WordPress/WooCommerce compatibility or HPOS
- [ ] Public documentation or translation
- [ ] AI instructions, repository skill, or development procedure
- [ ] No impact in the categories above

## Security and privacy

Confirm that no raw identifier, OTP, password, reset key, token, nonce, cookie, provider payload, exception message, trace, private route, credential, or production coordinate is added to code, fixtures, logs, screenshots, or artifacts.

## Rollback and operations

Describe rollback behavior, additive-schema compatibility, backup requirements, operational monitoring, and any separately authorized deployment step. Use “not applicable” only with a short reason.

## AI-assisted work

- [ ] Root `AGENTS.md` was read before provider-specific instructions or repository skills
- [ ] AI-assisted changes were reviewed against source, tests, and repository invariants
- [ ] Durable documentation contains no live branch/release/run/deployment snapshot
