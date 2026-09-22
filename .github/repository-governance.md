# GitHub repository governance

This file defines the durable desired GitHub configuration. It contains no live branch, run, release, or deployment status. Repository administrators must compare GitHub settings against this policy after workflow or ownership changes.

## Repository metadata

- Description: `Secure OTP, email, and password authentication for WordPress and WooCommerce.`
- Homepage: `https://wordpress.org/plugins/pinova/`
- Topics: `wordpress`, `woocommerce`, `otp`, `authentication`, `passwordless`, `sms`, `iran`
- Issues: enabled
- Private vulnerability reporting: enabled

## Default branch ruleset

Apply an active ruleset named `main-protection` to `refs/heads/main`:

- block deletion and non-fast-forward updates;
- require changes through a pull request;
- require one approval and CODEOWNER review;
- dismiss stale approvals after new commits;
- require approval of the most recent reviewable push;
- require all review conversations to be resolved;
- require the `Required Quality Gate` status check and require the branch to be current before merge;
- do not permit force pushes;
- permit bypass only for a narrowly assigned repository administrator in an emergency, with an audit record and follow-up pull request.

Do not make individual matrix job names required. The stable `Required Quality Gate` aggregates the complete workflow and avoids settings drift when matrix entries change.

## Release tag ruleset

Apply an active ruleset named `release-tags` to `refs/tags/v*`:

- block deletion and non-fast-forward updates;
- restrict tag creation to the GitHub Actions publisher used by the reviewed release workflow;
- keep administrator bypass limited to documented recovery from a GitHub platform failure; never use bypass to move or replace a published tag.

The publisher must still prove that its commit is reviewed, green, and reachable from `main`. Tag protection does not replace reproducible builds, Release immutability, attestations, or redownload verification.

## Settings audit

After a governance change, store a small nonsensitive evidence record under `.agents/reviews/` containing the actor, reviewed policy, resulting ruleset identifiers, and verification outcome. Do not copy that live state into `AGENTS.md`, `README.md`, the skill entrypoint, or this desired-state policy.
