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

npx --no-install wp-env run cli wp pinova otp run | tee "$result_file"
grep -Fq 'Processed 0 scheduled Pinova OTP jobs; consult delivery logs for provider results.' "$result_file"

npx --no-install wp-env run cli \
	--env-cwd="wp-content/plugins/$plugin_directory" \
	wp eval-file --use-include tests/Integration/logs-command.php | tee "$result_file"
grep -Fxq 'PINOVA_LOGS_COMMAND_COMPLETE' "$result_file"

npx --no-install wp-env run cli \
	--env-cwd="wp-content/plugins/$plugin_directory" \
	wp eval-file --use-include tests/Integration/wpforo-rest.php | tee "$result_file"
grep -Fxq 'PINOVA_REST_COMPLETE' "$result_file"

npx --no-install wp-env run cli \
	--env-cwd="wp-content/plugins/$plugin_directory" \
	wp eval-file --use-include tests/Integration/dokan-hpos-reports.php | tee "$result_file"
grep -Fxq 'PINOVA_DOKAN_REPORTS_COMPLETE' "$result_file"

npx --no-install wp-env run cli \
	--env-cwd="wp-content/plugins/$plugin_directory" \
	wp eval-file --use-include tests/Integration/wallet-hpos-reports.php | tee "$result_file"
grep -Fxq 'PINOVA_WALLET_REPORTS_COMPLETE' "$result_file"

for phase in seed upgrade rollback cleanup; do
	options=()
	if [[ "$phase" == seed || "$phase" == rollback ]]; then
		options=("--skip-plugins=$plugin_directory" "--require=tests/Integration/load-installed-baseline.php")
	fi
	npx --no-install wp-env run cli \
		--env-cwd="wp-content/plugins/$plugin_directory" \
		wp eval-file --use-include "${options[@]}" tests/Integration/installed-upgrade.php "$phase" | tee "$result_file"
	grep -Fxq "PINOVA_UPGRADE_COMPLETE $phase" "$result_file"
done

for suite in third-party-baseline wpforo-integration dokan-integration; do
	case "$suite" in
		third-party-baseline) marker=PINOVA_BASELINE_COMPLETE ;;
		wpforo-integration) marker=PINOVA_WPFORO_COMPLETE ;;
		dokan-integration) marker=PINOVA_DOKAN_COMPLETE ;;
	esac
	for scenario in normal manual-approval; do
		npx --no-install wp-env run cli \
			--env-cwd="wp-content/plugins/$plugin_directory" \
			wp eval-file --use-include "tests/Integration/$suite.php" "$scenario" | tee "$result_file"
		# A successful process exit alone is insufficient: wpForo can call exit().
		grep -Fxq "$marker $scenario" "$result_file"
	done
done
