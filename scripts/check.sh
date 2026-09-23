#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

docker compose config --quiet
docker compose exec -T app composer validate --strict
docker compose exec -T app composer lint
docker compose exec -T app composer analyse
docker compose exec -T app composer test
docker compose exec -T frontend npm run lint
docker compose exec -T frontend npm run test
docker compose exec -T frontend npm run build
bash scripts/smoke.sh
