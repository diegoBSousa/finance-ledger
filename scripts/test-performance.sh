#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
if (( $# > 1 )) || { (( $# == 1 )) && [[ "$1" != --quick ]]; }; then
    echo 'Usage: bash scripts/test-performance.sh [--quick]' >&2
    exit 2
fi
[[ -f backend/vendor/autoload.php ]] || { echo 'Run scripts/bootstrap.sh first.' >&2; exit 1; }
export LEDGER_PERFORMANCE_UID="$(id -u)" LEDGER_PERFORMANCE_GID="$(id -g)"
export LEDGER_PERFORMANCE_MODE=full
if [[ "${1:-}" == --quick ]]; then export LEDGER_PERFORMANCE_MODE=quick; fi
performance_project="finance-ledger-performance-${LEDGER_PERFORMANCE_UID}-$$"
export LEDGER_PERFORMANCE_ARTIFACTS="$PWD/artifacts/performance/$(date -u +%Y%m%dT%H%M%SZ)-$$"
mkdir -p "$LEDGER_PERFORMANCE_ARTIFACTS"
performance_compose=(docker compose --env-file /dev/null --project-name "$performance_project" -f compose.performance.yaml)
cleanup() {
    result=$?
    "${performance_compose[@]}" logs --no-color > "$LEDGER_PERFORMANCE_ARTIFACTS/services.log" 2>&1 || true
    "${performance_compose[@]}" down --volumes --remove-orphans >/dev/null || true
    echo "Performance artifacts: $LEDGER_PERFORMANCE_ARTIFACTS"
    exit "$result"
}
trap cleanup EXIT
"${performance_compose[@]}" config --quiet
"${performance_compose[@]}" build runner
"${performance_compose[@]}" up -d --wait --wait-timeout 180 mysql redis
"${performance_compose[@]}" run --rm --no-deps -T runner | tee "$LEDGER_PERFORMANCE_ARTIFACTS/run.log"
