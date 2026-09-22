# Pinova repository instructions

These instructions apply to the entire repository. User instructions take precedence. Repository guidance never grants permission to mutate production, databases, GitHub settings, releases, accounts, or preserved development environments.

## Mandatory first read

Every human or AI contributor must read this root `AGENTS.md` completely before reading a provider-specific instruction file, a repository skill, or task-specific documentation. Provider adapters may point here, but must not duplicate these rules.

After this file, read `.agents/skills/pinova-development/SKILL.md` and only the references it routes to for the task. If another instruction conflicts with this file, stop and resolve the conflict before changing the repository.

## Start every task

1. Classify the request as review, diagnosis, implementation, migration, packaging/release, or production deployment. Authority for one class does not imply authority for another.
2. Inspect the clean/dirty state, active branch, worktrees, remotes, and exact base ref. Preserve unrelated changes and every worktree or environment owned by another task.
3. For implementation, branch from the intended remote ref in a dedicated worktree. Never develop in an installed plugin directory.
4. Establish the compute boundary before dependency installation, static analysis, packaging, containers, or browser tests. A separate path or container does not isolate CPU, RAM, swap, I/O, or Docker.
5. Read the public `README.md` and WordPress `readme.txt` when behavior, installation, compatibility, privacy, or packaging is involved.

## Durable documentation boundaries

- `AGENTS.md` contains durable repository-wide rules and source routing only.
- `README.md` contains stable public product, installation, and contribution information only.
- `.agents/skills/pinova-development/` contains durable specialist contracts and procedures.
- `CONTRIBUTING.md`, `SECURITY.md`, and repository templates contain durable collaboration policies.
- `CHANGELOG.md` and the WordPress changelog contain released behavior.
- `.agents/reviews/` contains dated and immutable audit, pull-request, CI, release, staging, and deployment evidence.

Do not put the current release candidate, branch, commit SHA, pull-request number, workflow/run ID, checksum, date-based snapshot, installed-site state, active milestone, or next-release status in `AGENTS.md`, `README.md`, the skill entrypoint, or stable references. Discover live state from Git, GitHub, and the authorized environment. Store nonsensitive historical evidence under `.agents/reviews/`.

## Non-negotiable product invariants

- WordPress User ID is the account anchor. OTP login, profile edits, fallback resolution, and migrations must not rewrite `wp_users.user_login`.
- Public authentication and recovery responses must not reveal account existence, password availability, or native-only role policy.
- Password authentication must use the native WordPress pipeline so security plugins, 2FA, passkeys, and standard hooks remain effective.
- OTP verification is purpose-bound. Login/registration and password-recovery records must not cross flows.
- Ambiguous legacy identity resolution fails closed. Multi-account merge requires its own audited migration workflow.
- Core WordPress tables are never altered destructively. Plugin schema changes are additive, idempotent, retryable, and rollback-tolerant.
- Logs never contain raw identifiers, OTPs, passwords, reset keys, tokens, nonces, cookies, provider payloads, exception messages, file paths, or traces. Context is deny-by-default and attacker-controlled events are bounded.
- A logging failure must never interrupt authentication, OTP, REST, export, or another user request.
- Redirects are validated, empty destinations receive an explicit same-site fallback, and failed redirects return a controlled nonblank response.
- Role allowlists are revalidated after filters; administrative or commerce-management capabilities cannot be admitted to public authentication policy accidentally.
- Spreadsheet formatting is restricted to populated ranges. Permanent blocks use a null expiry and must survive expired-row cleanup.
- Only `REMOTE_ADDR` is trusted by default. Proxy headers require an explicitly configured trusted CIDR.

Detailed authentication, UI, WooCommerce, native-login, logging, and release contracts live in the Pinova skill and its routed references.

## Source routing

- Bootstrap and upgrades: `pinova.php`, `src/Install.php`, `src/Version.php`, `utils/`
- REST and authentication: `src/API/`, `src/Services/`, `src/Objects/`
- Structured logging: `src/Logging/`, `src/Admin/Logs.php`
- WordPress integrations and privacy: `src/Integrations/Wordpress/`
- WooCommerce: `src/Integrations/Woocommerce/`
- User interface: `templates/`, `assets/`
- Automated coverage: `tests/`
- Build and repository checks: `tools/`, `.github/workflows/`
- Historical evidence: `.agents/reviews/`

## Change workflow

1. Establish the requirement from source, tests, logs, or an isolated reproduction.
2. Add or update a regression test when practical.
3. Implement the smallest coherent change while preserving the product invariants.
4. Run lightweight targeted checks locally. Run resource-intensive matrices and release builds only on GitHub Actions or an isolated development host.
5. Update the skill or a reference only when the change alters an instruction contract, invariant, command, interface, or development/release procedure. Ordinary code changes do not require a ceremonial skill edit.
6. Update public documentation when user-visible behavior, requirements, installation, privacy, or operations change. Update changelogs only for released behavior or an explicitly prepared release.
7. Run `php tools/check-ai-governance.php`, `php tools/check-changelog-sync.php`, and `bash .agents/skills/pinova-development/scripts/check-skill-sync.sh --working-tree` before handoff.
8. Report exact refs, files, checks, residual risks, external effects, and the next outstanding gate.

## Production and external-state boundary

Treat every installed Pinova directory and its database as read-only unless the user explicitly authorizes the exact operation and its target. Before an installation, migration, purge, settings change, or gate activation, identify the target, create a verified backup, define rollback, and use maintenance controls appropriate to the risk.

Never run automated development tests against production. Never stop, update, prune, or reuse another task's containers, volumes, databases, ports, or worktrees.

GitHub publication is not installation authorization. A release candidate remains a prerelease until its required staging and canary gates pass. Published tags and releases are immutable history: never move, overwrite, or delete them to correct a defect.

## Definition of done

- Code/documentation: the intended diff is focused, tests and policy checks pass, and failures or untested boundaries are stated accurately.
- Installable package: additionally requires reviewed GitHub history, green exact-head and merged-main checks, a reproducible release ZIP, immutable release metadata, attestations, and independent redownload verification.
- Installation: additionally requires an authorized target, verified backup and rollback, staging acceptance, post-install checks, and the agreed observation window.

Every handoff states the achieved boundary, unchanged external or production state, and the next gate.
