#!/usr/bin/env bash
# Deploy smoke (см. operations/deployment.md).
#
# Проверяет, что прод-стек отвечает по HTTP и прошёл миграции:
#   - GET /health/live   → 200 {status: ok}       (app + db + redis)
#   - GET /health/ready  → 200 {status: ready}    (+ rabbitmq)
#   - контракт ошибок API (RFC 9457) жив: POST /api/v1/auth/register с
#     пустым телом → 422 {title, detail}
#
# Использование:
#   bash scripts/deploy-smoke.sh [BASE_URL]   # по умолчанию http://localhost:8080
#
# Exit code: 0 — smoke прошёл; 1 — стек не готов/контракт нарушен.

set -euo pipefail

BASE_URL="${1:-http://localhost:8080}"
WAIT_SECONDS="${SMOKE_WAIT_SECONDS:-120}"

echo "== Tender deploy smoke =="
echo "Base URL: ${BASE_URL}"

# Ждём готовности web (healthcheck через nginx может занимать стартовые секунды)
deadline=$(( $(date +%s) + WAIT_SECONDS ))
until [ "$(date +%s)" -ge "$deadline" ]; do
    if curl -fsS "${BASE_URL}/health/live" >/dev/null 2>&1; then
        break
    fi
    sleep 2
done

live=$(curl -fsS "${BASE_URL}/health/live") || {
    echo "FAIL: /health/live не отвечает (${WAIT_SECONDS}s timeout)"; exit 1
}
ready=$(curl -fsS "${BASE_URL}/health/ready") || {
    echo "FAIL: /health/ready не отвечает"; exit 1
}

echo "health/live : ${live}"
echo "health/ready: ${ready}"

# Проверка JSON-контрактов (jq не обязателен — сверяем подстроки)
echo "${live}"  | grep -q '"status":"ok"'    || { echo "FAIL: live status != ok";    exit 1; }
echo "${ready}" | grep -q '"status":"ready"' || { echo "FAIL: ready status != ready"; exit 1; }
echo "OK: liveness/readiness"

# Контракт ошибок API (RFC 9457): невалидное тело регистрации → 422
code=$(curl -s -o /tmp/tender-smoke-err.json -w '%{http_code}' \
    -X POST "${BASE_URL}/api/v1/auth/register" \
    -H 'Content-Type: application/json' -d '{}')
if [ "$code" != "422" ]; then
    echo "FAIL: register empty body → ${code}, ожидали 422"
    cat /tmp/tender-smoke-err.json 2>/dev/null || true
    exit 1
fi
grep -q '"title"' /tmp/tender-smoke-err.json || {
    echo "FAIL: 422 не соответствует RFC 9457 (нет title)"; exit 1
}
echo "OK: API error contract (422 + RFC 9457)"

echo "== SMOKE PASSED =="
