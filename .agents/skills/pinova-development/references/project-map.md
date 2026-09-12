# Pinova project map

Read this reference when locating code, reviewing authentication or identifiers, changing persistence, or assessing integration impact.

## Entry points and modules

- `pinova.php`: metadata, constants, bootstrap, integrations, and HPOS declaration.
- `src/API/`: REST controllers and response envelope.
- `src/Services/`: OTP, users, validation, rate limiting, firewall, SMS, channels, and API orchestration.
- `src/Objects/`: normalized request identifiers and mobile handling.
- `src/Integrations/Wordpress/`: profiles, users list, export authorization, Excel, and VCF.
- `src/Integrations/Woocommerce/`: login/account/checkout and constrained customer creation.
- `src/Helpers/IP.php`: client-address and trusted-proxy handling.
- `src/Install.php`, `src/Version.php`, and `utils/`: additive schema, upgrades, settings, and compatibility helpers.
- `templates/` and `assets/`: login interface.
- `tests/`: unit, plugin-load, and security integration coverage.

Identity-table resolver, migration, merge, rollback, and WP-CLI code live only on the separate 1.3 development line until explicitly merged. Do not document those features as shipped by 1.2.3.

## Identifier behavior in 1.2.3

`UserService::match()` resolves email through WordPress, username through WordPress, and mobile through legacy `user_login` plus configured metadata aliases. Queries use prepared placeholders.

A valid physical `pinova_mobile` value is an explicit login-mobile override. It is checked before legacy values when displaying a user's mobile. If an account later stores a different explicit override, the old value remains unchanged in a mobile-shaped WordPress username or legacy metadata but no longer authenticates as that user's Pinova mobile alias.

Resolution returns a User ID only when the candidate is unambiguous. Multiple legacy accounts matching one mobile return no user and fire `pinova/identity_conflict_detected`. Resolving that conflict and merging ownership is deferred to the reviewed 1.3 workflow.

## Data tables in 1.2.3

- `pinova_otp`: OTP attempts, channels, expiry, and verification.
- `pinova_blocks`: temporary or permanent blocked identifiers.
- `pinova_rate_limits`: atomic hashed rate-limit buckets.

Schema changes must be additive and `dbDelta()` compatible. Never alter/drop columns or indexes in `wp_users` or other core tables.

## Security boundaries

- Exports require `list_users`, nonce validation, safe strings, VCF escaping, batching, and XLSX row limits.
- WooCommerce customer creation accepts only allowed billing/shipping input and forces role `customer`.
- Redirects require `wp_validate_redirect()` and the allowed-host policy.
- Public password/forgot/OTP responses do not expose account existence or native-only role membership.
- Native administrator password/2FA login remains available through `wp-login.php` according to policy.
- Rate limits hash identifiers and return HTTP 429 with `Retry-After`.
- Proxy chains are considered only behind trusted CIDRs.
