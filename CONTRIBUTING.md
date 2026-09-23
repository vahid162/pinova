# Contributing to Pinova

## Required reading

Read root [`AGENTS.md`](AGENTS.md) completely before using an AI adapter, repository skill, or development command. Then read the Pinova development skill and the references it routes to for your task.

## Development workflow

1. Start from the current intended remote base in a dedicated branch and worktree.
2. Keep the change focused. Do not mix mechanical normalization, documentation restructuring, runtime behavior, schema migration, and release preparation.
3. Add a regression test when practical and preserve the security and identity invariants in `AGENTS.md`.
4. Run targeted checks locally. Run resource-intensive dependency, integration, browser, compatibility, and package matrices on GitHub Actions or an isolated development host.
5. Update durable documentation only when its contract changes. Store dated CI, release, staging, or deployment evidence under `.agents/reviews/`, never in durable entrypoints.
6. Open a pull request and complete every applicable section of the template.

## Documentation ownership

- `AGENTS.md`: repository-wide rules and routing
- `README.md`: stable public overview
- `readme.txt`: WordPress distribution metadata and user-facing release notes
- `.agents/skills/pinova-development/`: specialist contracts and procedures
- `CHANGELOG.md`: released behavior
- `.agents/reviews/`: immutable evidence snapshots

Do not copy a rule into provider-specific AI files. Those files may only direct the provider to root `AGENTS.md`.

## Required lightweight checks

```bash
php tools/check-ai-governance.php
php tools/check-changelog-sync.php
php tools/check-plugin-metadata.php
bash .agents/skills/pinova-development/scripts/check-skill-sync.sh --working-tree
npm run lint:js
npm run test:js
```

Use the quality reference for the proportional PHP, integration, browser, HPOS, and packaging gates. Never run heavy suites on a host that shares resources with production.

## Locked dependencies and supply chain

Git does not track `vendor/`. On an isolated development host, recreate PHP dependencies from `composer.lock` with `composer install --no-interaction --prefer-dist`. Use the Node and npm versions declared by `package.json`, and recreate JavaScript dependencies from `package-lock.json` with `npm ci --ignore-scripts`; do not use `npm install` in CI or release preparation.

The required supply-chain gate validates and audits both lockfiles, compares a production-only Composer install with `composer.lock`, rejects unapproved production licenses, and runs Dependency Review for pull requests. GitHub Actions and the actionlint container must use immutable revisions. The release workflow builds from the Composer lockfile and publishes a checksummed SPDX SBOM with a signed SBOM attestation alongside the ZIP and ZIP checksum.

## Pull requests

Describe the requirement, implementation, tests, unresolved risk, and rollback implications. Explicitly classify impacts on runtime code, database schema, authentication, privacy, logging, dependencies, packaging, public documentation, and AI instructions.

Published tags and releases are immutable. Fix a defect through a new reviewed commit and release rather than rewriting history.

Repository administrators must keep GitHub metadata and branch/tag rules aligned with [`.github/repository-governance.md`](.github/repository-governance.md).
