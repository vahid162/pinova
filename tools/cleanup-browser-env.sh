#!/usr/bin/env bash
# This destructive cleanup is restricted to the current disposable GitHub browser job.
set -euo pipefail

fail() { printf 'Browser cleanup refused: %s\n' "$*" >&2; exit 1; }
[[ ${CI:-} == true && ${GITHUB_ACTIONS:-} == true ]] || fail 'not a GitHub CI job'
[[ ${GITHUB_RUN_ID:-} =~ ^[0-9]+$ && ${GITHUB_RUN_ATTEMPT:-} =~ ^[0-9]+$ ]] || fail 'missing run identity'
expected="pinova-browser-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
[[ ${COMPOSE_PROJECT_NAME:-} == "$expected" ]] || fail 'project does not match this run'
[[ ${WP_ENV_HOME:-} == "/tmp/$expected" ]] || fail 'work directory does not match this run'
[[ ! -L "$WP_ENV_HOME" ]] || fail 'work directory is a symlink'
[[ ! -e "$WP_ENV_HOME" || -d "$WP_ENV_HOME" ]] || fail 'work directory is not a directory'

shopt -s nullglob
configs=("$WP_ENV_HOME"/*/docker-compose.yml)
(( ${#configs[@]} <= 1 )) || fail 'multiple Compose configurations'
if (( ${#configs[@]} == 1 )); then
    config=${configs[0]}
    parent=$(dirname "$config")
    [[ $(basename "$parent") =~ ^[[:xdigit:]]{32}$ ]] || fail 'unexpected wp-env directory'
    [[ ! -L "$parent" && ! -L "$config" && -f "$config" ]] || fail 'unsafe Compose configuration'
    # 10.35.0 has no destroy --force. Use the same run-scoped Compose project,
    # without an interactive prompt, global prune, or removal of shared images.
    docker compose --project-name "$expected" --file "$config" down --volumes --remove-orphans
fi

# Check each query's exit status. Missing configuration is not success if resources remain.
label="label=com.docker.compose.project=$expected"
containers=$(docker container ls --all --quiet --filter "$label")
volumes=$(docker volume ls --quiet --filter "$label")
networks=$(docker network ls --quiet --filter "$label")
[[ -z "$containers" && -z "$volumes" && -z "$networks" ]] || fail 'run-owned Docker resources remain'

# Never remove source checkouts or another task's wp-env home.
if [[ -d "$WP_ENV_HOME" ]]; then
    rm -rf -- "$WP_ENV_HOME"
fi
[[ ! -e "$WP_ENV_HOME" ]] || fail 'work directory removal did not complete'
printf 'Browser cleanup verified: %s has no containers, volumes, networks, or work directory.\n' "$expected"
