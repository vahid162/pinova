#!/usr/bin/env bash
set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
cd "${repo_root}"

skill_path='.agents/skills/pinova-development/SKILL.md'

if [[ ! -f "${skill_path}" ]]; then
	printf 'ERROR: required Pinova skill is missing: %s\n' "${skill_path}" >&2
	exit 1
fi

collect_changed_files() {
	case "${1:-}" in
		--working-tree)
			{
				git diff --name-only HEAD
				git diff --cached --name-only HEAD
				git ls-files --others --exclude-standard
			} | LC_ALL=C sort -u
			;;
		'')
			if git rev-parse --verify HEAD^ >/dev/null 2>&1; then
				git diff --name-only HEAD^ HEAD
			else
				git diff-tree --root --no-commit-id --name-only -r HEAD
			fi
			;;
		*)
			if [[ "${1}" =~ ^0+$ ]] || ! git cat-file -e "${1}^{commit}" 2>/dev/null; then
				if git rev-parse --verify HEAD^ >/dev/null 2>&1; then
					git diff --name-only HEAD^ HEAD
				else
					git diff-tree --root --no-commit-id --name-only -r HEAD
				fi
			else
				git diff --name-only "${1}" HEAD
			fi
			;;
	esac
}

mapfile -t changed_files < <(collect_changed_files "${1:-}")

plugin_changed=0
skill_changed=0

for path in "${changed_files[@]}"; do
	[[ "${path}" == "${skill_path}" ]] && skill_changed=1

	case "${path}" in
		pinova.php|readme.txt|src/*|utils/*|templates/*|assets/*|vendor/*|docs/*|composer.json|composer.lock|package.json|package-lock.json|.wp-env.json|phpunit*.xml.dist|phpstan.neon.dist|phpcs.xml.dist|tests/*|tools/*|.github/workflows/*|.agents/skills/pinova-development/references/*|.agents/skills/pinova-development/scripts/*)
			plugin_changed=1
			;;
	esac
done

if (( plugin_changed == 1 && skill_changed == 0 )); then
	printf 'ERROR: Pinova-affecting files changed without reviewing %s.\n' "${skill_path}" >&2
	printf 'Update the skill meaningfully in the same change set.\n' >&2
	exit 1
fi

if (( plugin_changed == 1 )); then
	printf 'Pinova skill synchronization check passed.\n'
else
	printf 'No Pinova-affecting files detected; skill synchronization not required.\n'
fi
