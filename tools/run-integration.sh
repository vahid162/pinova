#!/usr/bin/env bash
set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
plugin_directory="$(basename "${repo_root}")"
hpos="${PINOVA_TEST_HPOS:-no}"

case "${hpos}" in
	yes|no)
		;;
	*)
		printf 'PINOVA_TEST_HPOS must be yes or no, got: %s\n' "${hpos}" >&2
		exit 1
		;;
esac

wp-env run tests-cli \
	--env-cwd="wp-content/plugins/${plugin_directory}" \
	env "PINOVA_TEST_HPOS=${hpos}" \
	vendor/bin/phpunit -c phpunit.integration.xml.dist
