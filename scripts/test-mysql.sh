#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

# Recreate disposable test containers to discard references to removed Compose networks.
# Startup and cleanup target only test services; development data stays in its own volumes.
trap 'docker compose --profile test stop mysql-test redis-test >/dev/null' EXIT
docker compose --profile test up -d --force-recreate --wait --wait-timeout 180 mysql-test redis-test
docker compose --profile test run --rm --no-deps -T test-runner
