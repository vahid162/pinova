# Dedicated test-site baseline evidence

Captured 2026-09-27 before installing the integration candidate. This is a historical observation, not a release-readiness claim.

The user explicitly designated microbeauty.ir as a dedicated test site and authorized continuation of the integration scenario. They separately approved a private database, installed Pinova, and configuration backup. That backup was created with restricted permissions and verified with SHA-256 and archive readability before these scenarios. No backup payload is included in this repository.

The installed baseline is Pinova 1.3.0-rc5, build commit `7c309e6a747d9c0214c7416ffc586821cae3ab7d`, WordPress 7.1.2, WooCommerce 11.1.2, wpForo 3.2.1, and Dokan Lite 5.1.3. The site has its own database and plugin directory. It shares server resources, so probes used PHP 8.4, a 256 MB memory limit, serial execution, nice priority, and a 40-second timeout. No shared service, scheduler, or other site was changed. Test-site request-triggered WP-Cron is enabled.

## Characterization

The bounded CLI fixture passed both `normal` and `manual-approval` scenarios with the explicit `PINOVA_BASELINE_COMPLETE` markers. Mail and outbound HTTP were intercepted. Approval settings were altered only in the CLI process's wpForo settings object, not persisted.

Observed upstream behavior:

- The cookie hook runs before wpForo's login callback.
- Without manual approval, that callback activates an inactive forum profile.
- With manual approval, it redirects and exits after the cookie hook; the profile stays inactive.
- wpForo's password-reset callback marks email confirmed even for a fixture with no email.
- Native Dokan customer-to-vendor conversion replaces WordPress roles; the account ID and username remain unchanged.

WP-CLI's default eval-file mode conflicts with a leading strict_types declaration, so the runner now uses `--use-include`. wpForo's in-request member cache can return a stale email flag after reset; assertions now use its public fresh `get_is_email_confirmed()` accessor.

Shutdown cleanup removed each completed scenario's account. A separately identified abandoned synthetic subscriber from an earlier failed run was verified to have no email or posts and removed. A subsequent prefix-scoped user count was zero. Elementor emitted a PHP 8.4 nullable-parameter deprecation at shutdown; it did not fail the fixture and its source was not changed.

## Boundary

This proves the existing installed callbacks and failure modes. It does not prove the new mobile policy, real browser cookie receipt, prompt queue dispatch, an upgrade, or a release package. Integration routing has not been enabled on the test site. Those acceptance gates remain separate.
