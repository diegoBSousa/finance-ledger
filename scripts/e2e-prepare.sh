#!/usr/bin/env bash
set -euo pipefail
# Destructive setup is accepted only for the dedicated, private E2E database.
[[ "${APP_ENV:-}" == testing && "${DB_DATABASE:-}" == finance_ledger_e2e && "${DB_HOST:-}" == mysql ]] || {
    echo 'Refusing to prepare a database outside the disposable E2E stack.' >&2
    exit 1
}
mkdir -p storage/framework/{cache/data,sessions,views,testing} storage/logs /e2e-uploads
php artisan migrate:fresh --force
php artisan db:seed --force
