# Pinova identity migration runbook

Pinova 1.3 maps normalized usernames, emails, and mobile numbers to one immutable WordPress user ID. It never changes `wp_users.user_login` during migration and never merges two user IDs automatically.

## Safety rules

- Run all audit and migration commands on a fresh staging database copy first.
- Take a full database backup immediately before every production `--apply`.
- Select the canonical target user ID manually for every merge conflict.
- Keep the target user's password, roles, and capabilities unchanged.
- Treat source-user session invalidation as irreversible; data rollback cannot recreate sessions.
- Do not apply migrations or merges on Multisite.
- If wpForo or an unsupported membership integration is detected, merge apply stops until an adapter handles that data.

## Staging sequence

```bash
wp pinova identity audit
wp pinova identity migrate --dry-run
wp pinova identity conflicts
wp pinova identity migrate --apply --yes
wp pinova identity conflicts
```

Inspect every deterministic conflict. For each manually approved canonical target:

```bash
wp pinova identity merge <source-user-id> --into=<target-user-id> --dry-run
wp pinova identity merge <source-user-id> --into=<target-user-id> --apply --yes
```

To resume a failed journaled merge, use the same source and target plus the run ID:

```bash
wp pinova identity merge <source-user-id> --into=<target-user-id> --apply --yes --resume=<run-id>
```

Preview and apply a rollback:

```bash
wp pinova identity rollback <run-id> --dry-run
wp pinova identity rollback <run-id> --apply --yes
```

Rollback changes only objects that are still in the exact target state recorded by that run. New activity after a merge remains on the target account. Invalidated sessions are reported but cannot be restored.

## gpante canary

1. Verify both `/login` and `/wp-login.php` for an anonymous browser at the Nginx/WordPress boundary.
2. Install 1.2.3 on staging, run the security regression suite, then monitor the gpante canary for 48 hours.
3. Refresh staging from production, install 1.3.0, run audit and dry-run, and manually approve conflicts.
4. Back up the production database and execute the approved migration and merges during a maintenance window.
5. Monitor login failures, identity conflicts, WooCommerce orders, and rollback journals for seven days before public release.

Operational logs must contain only user IDs, event types, masked identifiers, and correlation IDs. Never log raw identifiers, OTPs, passwords, reset keys, application passwords, or tokens.
