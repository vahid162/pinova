# Pinova project map

Read this reference when locating code, reviewing identity behavior, changing persistence, or assessing integration impact.

## Entry points and major modules

- `pinova.php`: plugin metadata, constants, service loading, integration hooks, activation marker, and HPOS declaration.
- `src/API/`: REST controllers and the compatibility response envelope.
- `src/Services/`: OTP, user, validation, rate-limit, firewall, SMS, channel, and API orchestration.
- `src/Identity/`: normalization, identity repository, resolver, registration, audit, conflicts, migration, merge journal, rollback, and admin UI.
- `src/CLI/IdentityCommand.php`: `wp pinova identity` audit, migrate, conflicts, merge, and rollback commands.
- `src/Integrations/Wordpress/`: user profile, list table, export authorization, Excel, and VCF.
- `src/Integrations/Woocommerce/`: login/account/checkout redirects, customer creation, and supported ownership transfer.
- `src/Helpers/IP.php`: client address resolution and trusted-proxy handling.
- `src/Install.php` and `src/Version.php`: additive schema creation, upgrades, and administrator capability assignment.
- `utils/`: shared database, settings, installation, and version helpers retained for compatibility.
- `templates/` and `assets/`: login UI and browser assets.

The source tree contains `Autoptimize` and `LiteSpeedCache` compatibility loaders, but the 1.3.0 RC1 bootstrap does not instantiate them. Treat those two integrations as unverified until bootstrap coverage and integration tests prove that their filters are registered; documentation must not imply active compatibility merely because the classes exist.

## Identity model

`{$wpdb->prefix}pinova_identities` is the canonical identity index. `(type, normalized_value)` is globally unique; no foreign key points to WordPress core tables.

Resolution order:

1. active canonical identity;
2. legacy `user_login` or `user_email`;
3. supported Pinova and WooCommerce mobile metadata.

Normalization rules:

- mobile: E.164;
- email: lowercase canonical form;
- username: WordPress-compatible normalized form;
- empty values: never identities.

Legacy fallback is read compatibility, not permission to rewrite `user_login`. Backfill may happen only after successful authentication or an explicit migration apply; discovery endpoints stay read-only.

## Merge ownership boundary

Version 1.3 supports WordPress Core and WooCommerce ownership records, including posts, comments, orders/HPOS customer references, and download permissions. Profile, billing, and shipping values copy only when the canonical destination is empty.

The source account is not deleted. It receives `pinova_merged_into`, its login is blocked, and active sessions are invalidated. Journal tables record only objects changed by a run so rollback does not overwrite later activity.

Do not extend merge coverage by guessing third-party schemas. Add an adapter and preflight coverage for each supported integration.

## Database tables

- `pinova_otp`: OTP attempts, channels, expiry, and verification state.
- `pinova_blocks`: blocked identifiers and expiry.
- `pinova_rate_limits`: atomic hashed rate-limit buckets.
- `pinova_identities`: canonical normalized identifiers.
- `pinova_identity_conflicts`: masked deterministic conflicts.
- `pinova_identity_merge_runs`: merge/rollback run headers.
- `pinova_identity_merge_items`: per-object journal and rollback state.

Schema changes must be additive and compatible with WordPress `dbDelta()`. Never remove or repurpose WordPress core columns or indexes.

## Security-sensitive boundaries

- Exports require `list_users`, nonce validation, safe strings, VCF escaping, batching, and the XLSX limit.
- WooCommerce customer creation accepts only approved billing/shipping fields and forces role `customer`.
- Redirects must pass `wp_validate_redirect()` and an allowed-host policy.
- Public password/forgot responses must not expose account or password existence.
- Administrator password login remains available through native `wp-login.php` according to the role policy.
- Rate-limit keys store hashed identifiers; proxy chains are evaluated only behind trusted CIDRs.
