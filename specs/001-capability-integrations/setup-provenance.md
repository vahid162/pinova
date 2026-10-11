# Spec Kit Setup Provenance

- Official upstream: GitHub Spec Kit, release `v1.1.3`.
- Verified source commit: `0282d03eb6fc3091b3deaa3a855d4030dc40cd6c`.
- `specify version --json`: CLI `1.1.3`, Python `3.11.16`.
- Official invocation executed in the selected worktree:
  `specify init --here --integration codex --script py --non-interactive --ignore-agent-tools --force`.
- Prior collision review found no existing `.specify/`, `specs/` or `speckit-*` skills.
  Existing AGENTS.md and the Pinova development skill were preserved.
- Initialization completed successfully; `specify integration status` reported Codex default,
  no missing/invalid managed files, no unchecked manifests and no modifications at initial check.
- The isolated temporary source CLI was used because PyPI download-host DNS/TLS access failed.
  Official source and pure-Python dependency source checkouts were used; no system Python,
  global DNS configuration or shared service was changed. This was not a global CLI installation.
- The optional MCP server dependencies/process were not provisioned or run; Codex workflow
  scaffolding and Python scripts were executed directly.
- Official Python scripts resolved the constitution/spec templates, initialized the plan,
  resolved the tasks template and verified feature prerequisites.
- Core templates, scripts and generated skill material retain upstream attribution;
  the official MIT notice is preserved at `.specify/LICENSE`.
- No optional extension, external message hook or automation was installed.
- Spec Kit material remains development-only and must be excluded from installable ZIPs.

Specification, plan and task generation are completed artifacts. Runtime implementation,
CI, release and staging completion must be recorded separately as actual evidence arrives.

## Local Development Compatibility Adjustments

Repository ShellCheck covers every tracked Bash script, including the shared Bash helpers
installed by the official Python-mode workflow. Local adjustments preserve behavior while
making source paths and eval-populated variable contracts explicit, separating declaration
from fallible assignments, and documenting one intentional literal Codex-command prefix.
The root lookup passes its current directory explicitly. Setup-plan and setup-tasks retain
default path-state persistence; their optional `--no-persist` argument is deliberately
omitted, with narrow `SC2119` annotations for older ShellCheck versions.
No script material was removed, and no broad lint suppression was added. Original manifest
hashes remain import-baseline evidence, so lifecycle inspection accurately recognizes these
project customizations rather than pretending they are unchanged upstream files.

The project-local ignore file also excludes Python workflow bytecode and isolated runtimes.
ShellCheck 0.11.0 passed for all 16 tracked shell scripts; Bash/Python prerequisite output and resolved
constitution templates agree. Setup-plan preserves the existing feature plan, and setup-tasks
resolves the expected feature and official tasks template.

The installed implement checklist gate passed all 14 requirements-quality items. All required
implementation context was loaded, and no extension hook or additional permission was needed.
Read-only analysis maps all 14 functional requirements and six measurable outcomes to the
29 original tasks; no conflicting requirement or constitution violation was identified.
This analysis does not claim executed native-plugin tests, a release or staging acceptance.
