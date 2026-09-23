#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

command -v docker >/dev/null || { echo 'Docker Engine and the Compose plugin are required.' >&2; exit 1; }
docker compose version >/dev/null
docker info >/dev/null

if [[ ! -f .env ]]; then
    umask 077
    cp .env.example .env
    app_key="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
    db_password="$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')"
    root_password="$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')"
    sed -i \
        -e "s|^LOCAL_UID=.*|LOCAL_UID=$(id -u)|" \
        -e "s|^LOCAL_GID=.*|LOCAL_GID=$(id -g)|" \
        -e "s|^APP_KEY=.*|APP_KEY=${app_key}|" \
        -e "s|^DB_PASSWORD=.*|DB_PASSWORD=${db_password}|" \
        -e "s|^MYSQL_ROOT_PASSWORD=.*|MYSQL_ROOT_PASSWORD=${root_password}|" .env
    umask 022
fi

if grep -Eq '^(APP_KEY|DB_PASSWORD|MYSQL_ROOT_PASSWORD)=$' .env; then
    echo 'APP_KEY, DB_PASSWORD and MYSQL_ROOT_PASSWORD must be set in .env.' >&2
    exit 1
fi

# Upgrading an earlier increment preserves existing secrets and adds a demo password once.
if ! grep -q '^DEMO_USER_EMAIL=' .env; then
    printf '\nDEMO_USER_EMAIL=demo@example.test\n' >> .env
fi
if ! grep -q '^DEMO_USER_PASSWORD=.' .env; then
    demo_password="$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')"
    if grep -q '^DEMO_USER_PASSWORD=' .env; then
        sed -i "s|^DEMO_USER_PASSWORD=.*|DEMO_USER_PASSWORD=${demo_password}|" .env
    else
        printf 'DEMO_USER_PASSWORD=%s\n' "$demo_password" >> .env
    fi
fi

docker compose config --quiet
mkdir -p backend/bootstrap/cache backend/storage/framework/{cache/data,sessions,views,testing} backend/storage/logs
docker compose build app
docker compose run --rm --no-deps --user 0:0 app sh -c \
    'mkdir -p storage/app/private/uploads && chown "$LOCAL_UID:$LOCAL_GID" storage/app/private/uploads'
docker compose run --rm --no-deps app composer install --no-interaction --prefer-dist
docker compose run --rm --no-deps frontend npm ci
docker compose up -d --wait --wait-timeout 180 mysql redis
docker compose run --rm --no-deps app php artisan migrate --force
docker compose run --rm --no-deps app php artisan db:seed --force
docker compose up -d --wait --wait-timeout 120
bash scripts/smoke.sh

echo 'Ready: frontend http://localhost:5173 | API http://localhost:8080/api/v1/health (default ports).'
