#!/usr/bin/env bash
# Браузерные e2e-тесты на изолированном стенде (порт 8081, база laravel_e2e).
# Рабочая база laravel не затрагивается. Браузер — в контейнере Playwright, на хост ничего не ставится.
# Использование: scripts/e2e.sh [аргументы playwright test]
set -euo pipefail
cd "$(dirname "$0")/.."

PLAYWRIGHT_IMAGE="mcr.microsoft.com/playwright:v1.56.1-noble"

echo "→ Сборка фронтенда"
npx vite build >/dev/null

echo "→ Стенд e2e"
docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "
  CREATE DATABASE IF NOT EXISTS laravel_e2e CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  GRANT ALL ON laravel_e2e.* TO \"laravel\"@\"%\";"' 2>/dev/null
docker compose --profile e2e up -d app-e2e nginx-e2e >/dev/null 2>&1

DB=$(docker compose exec -T app-e2e php -r 'echo getenv("DB_DATABASE");')
if [ "$DB" != "laravel_e2e" ]; then
  echo "Стенд e2e смотрит в базу «$DB», а не laravel_e2e — остановлено." >&2
  exit 1
fi
docker compose exec -T -u "$(id -u):$(id -g)" app-e2e php artisan migrate:fresh --seed --force -q
rm -rf storage/app/private/calls-e2e
docker compose exec -T app-e2e php artisan cache:clear -q

echo "→ Тесты"
docker run --rm --network host -u "$(id -u):$(id -g)" -e HOME=/tmp -e CI="${CI:-}" \
  -v "$PWD:/work" -w /work "$PLAYWRIGHT_IMAGE" npx playwright test "$@"
