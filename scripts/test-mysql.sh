#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

# Only the disposable test service is stopped; the development database is untouched.
trap 'docker compose --profile test stop mysql-test >/dev/null' EXIT
docker compose --profile test up -d --wait --wait-timeout 180 mysql-test
docker compose --profile test run --rm --no-deps -T test-runner
