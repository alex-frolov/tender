#!/usr/bin/env bash
# Деплой-процесс (задача 7.6): миграции → rollout → healthchecks → smoke.
#
# Использование:
#   bash scripts/deploy.sh          # полный деплой против docker-compose.prod.yml
#   bash scripts/deploy.sh --no-build   # пропустить пересборку образов
#
# Переменные окружения: PROD_COMPOSE (по умолчанию docker-compose.prod.yml),
# APP_VERSION, HTTP_PORT и остальные секреты — из окружения / .env.prod.
#
# Этапы:
#   1. build prod-образов (multi-stage);
#   2. миграции БД (NFR-18: up/down проверены в CI, здесь — up, идемпотентно);
#   3. rollout стека (app, web, worker, webhooks, scheduler + инфраструктура);
#   4. ожидание здорового стека (docker compose healthchecks);
#   5. deploy-smoke (health/live, health/ready, контракт ошибок API);
#   6. при фейле — подсказка про откат (operations/deployment.md §8).

set -euo pipefail
cd "$(dirname "$0")/.."

COMPOSE_FILE="${PROD_COMPOSE:-docker-compose.prod.yml}"
COMPOSE=(docker compose --env-file .env.prod -f "${COMPOSE_FILE}")
# HTTP_PORT берём из .env.prod (см. .env.prod.dist), иначе shell не увидит его
HTTP_PORT="${HTTP_PORT:-$(grep -E '^HTTP_PORT=' .env.prod 2>/dev/null | cut -d= -f2)}"
BASE_URL="${BASE_URL:-http://localhost:${HTTP_PORT:-8080}}"

echo "== Deploy: ${COMPOSE_FILE} → ${BASE_URL} =="

if [ "${1:-}" != "--no-build" ]; then
    echo "[1/5] build prod images (target prod-minimal / web)..."
    "${COMPOSE[@]}" build
else
    echo "[1/5] build skipped (--no-build)"
fi

echo "[2/5] migrations + cache warmup..."
"${COMPOSE[@]}" --profile deploy run --rm migrate
# В образе кэш собран с --no-warmup (на сборке Redis недоступен). Тёплый кэш
# нужен до первого трафика — собираем здесь, когда сервисы доступны.
"${COMPOSE[@]}" --profile deploy run --rm app php bin/console cache:warmup --env=prod

echo "[3/5] rollout..."
"${COMPOSE[@]}" up -d

echo "[4/5] wait for healthy stack..."
# compose waits for service_healthy dependencies; здесь дополнительно ждём web
deadline=$(( $(date +%s) + 180 ))
until [ "$(date +%s)" -ge "$deadline" ]; do
    if curl -fsS "${BASE_URL}/health/live" >/dev/null 2>&1; then
        break
    fi
    sleep 3
done

echo "[5/5] smoke..."
bash scripts/deploy-smoke.sh "${BASE_URL}"

echo "== DEPLOY OK =="
