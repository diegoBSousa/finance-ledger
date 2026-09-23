#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

docker compose exec -T app php artisan app:check-infrastructure --timeout=20
docker compose exec -T frontend node --input-type=module -e '
const origin = process.env.FRONTEND_ORIGIN;
const headers = { Accept: "application/json", Origin: origin };
const response = await fetch("http://web/api/v1/health", { headers });
const body = await response.json();
if (!response.ok || body.status !== "ok" || body.service !== "finance-ledger-api") throw new Error("API health response failed");
if (response.headers.get("Access-Control-Allow-Origin") !== origin) throw new Error("Frontend origin is not allowed by CORS");
console.log("HTTP API contract and frontend CORS origin: OK");
'
