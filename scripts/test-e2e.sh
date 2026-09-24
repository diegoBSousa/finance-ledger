#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
[[ -f backend/vendor/autoload.php ]] || { echo 'Run scripts/bootstrap.sh first.' >&2; exit 1; }
# A unique project name also isolates concurrent runs. Never use the development project.
export LEDGER_E2E_UID="$(id -u)" LEDGER_E2E_GID="$(id -g)"
e2e_project="finance-ledger-e2e-${LEDGER_E2E_UID}-$$"
e2e_compose=(docker compose --env-file /dev/null --project-name "$e2e_project" -f compose.e2e.yaml)
cleanup() {
    result=$?
    if (( result != 0 )); then "${e2e_compose[@]}" logs --no-color --tail=80 || true; fi
    "${e2e_compose[@]}" down --volumes --remove-orphans >/dev/null || true
    exit "$result"
}
trap cleanup EXIT
mkdir -p frontend/artifacts
"${e2e_compose[@]}" config --quiet
"${e2e_compose[@]}" build api frontend
"${e2e_compose[@]}" up -d --wait --wait-timeout 180 mysql redis
"${e2e_compose[@]}" run --rm --no-deps -T api bash /e2e-prepare.sh
"${e2e_compose[@]}" up -d --wait --wait-timeout 120 api frontend worker outbox-relay
"${e2e_compose[@]}" run --rm --no-deps -T browser
