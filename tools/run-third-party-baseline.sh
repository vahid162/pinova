#!/usr/bin/env bash
set -euo pipefail

# This suite creates synthetic accounts. Refuse every environment except the
# exact disposable GitHub run used by the existing scoped cleanup procedure.
expected="pinova-browser-${GITHUB_RUN_ID:-}-${GITHUB_RUN_ATTEMPT:-}"
if [[ ${CI:-} != true || ${GITHUB_ACTIONS:-} != true \
	|| ! ${GITHUB_RUN_ID:-} =~ ^[0-9]+$ || ! ${GITHUB_RUN_ATTEMPT:-} =~ ^[0-9]+$ \
	|| ${COMPOSE_PROJECT_NAME:-} != "$expected" || ${WP_ENV_HOME:-} != "/tmp/$expected" \
	|| ${PINOVA_TEST_PROFILE:-} != third-party ]]; then
	printf 'Third-party baseline requires its exact run-owned GitHub wp-env.\n' >&2
	exit 1
fi

repo_root="$(git rev-parse --show-toplevel)"
cd "$repo_root"
plugin_directory="$(basename "$repo_root")"
result_file="$(mktemp)"
trap 'rm -f -- "$result_file"' EXIT

for scenario in normal manual-approval; do
	npx --no-install wp-env run cli \
		--env-cwd="wp-content/plugins/$plugin_directory" \
		wp eval-file --use-include tests/Integration/third-party-baseline.php "$scenario" | tee "$result_file"
	# A successful process exit alone is insufficient: wpForo can call exit().
	grep -Fxq "PINOVA_BASELINE_COMPLETE $scenario" "$result_file"
done
