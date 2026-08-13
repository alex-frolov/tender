#!/usr/bin/env bash
# Нагрузочные тесты (задача 7.2, NFR-1/22): ставки, каталог, SSE, webhooks.
#
# Требования:
#   - docker compose (стек dev), node (SSE-сценарий), k6 (bin/k6 или $K6_BIN);
#   - к6: https://k6.io (обычный бинарь; SSE-часть — node, т.к. stock k6 не
#     стримит SSE).
#
# Использование:
#   ./load/run.sh                  # все сценарии
#   ./load/run.sh bids             # один сценарий: bids|catalog|sse|webhooks
#
# env: LOAD_SUPPLIERS=20 LOAD_CATALOG=5000 LOAD_WEBHOOK_RATE=1200
#      LOAD_WEBHOOK_DURATION=30 K6_BIN=k6
set -euo pipefail

cd "$(dirname "$0")/.."

K6_BIN="${K6_BIN:-}"
if [[ -z "$K6_BIN" ]]; then
    for candidate in k6 "$HOME/go/bin/k6" "$(go env GOPATH 2>/dev/null)/bin/k6"; do
        if command -v "$candidate" >/dev/null 2>&1 || [[ -x "$candidate" ]]; then
            K6_BIN="$candidate"
            break
        fi
    done
fi
K6_BIN="${K6_BIN:-k6}"
SUPPLIERS="${LOAD_SUPPLIERS:-20}"
CATALOG="${LOAD_CATALOG:-5000}"
WEBHOOK_RATE="${LOAD_WEBHOOK_RATE:-1200}"
WEBHOOK_DURATION="${LOAD_WEBHOOK_DURATION:-30}"
WEBHOOK_TOTAL=$(( WEBHOOK_RATE * WEBHOOK_DURATION / 60 ))

COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.load.yml)

require_k6() {
    if ! command -v "$K6_BIN" >/dev/null 2>&1; then
        echo "ERROR: k6 not found (K6_BIN=$K6_BIN). Install: https://k6.io/docs/getting-started/installation/" >&2
        exit 1
    fi
}

step() { echo; echo "==> $*"; }

start_load_stack() {
    step "Starting load stack (app + worker + webhooks + loadreceiver, APP_DEBUG=0, rate limit high)"
    "${COMPOSE[@]}" up -d app worker webhooks loadreceiver
    sleep 3
}

prepare_state() {
    step "Preparing load state (suppliers=$SUPPLIERS, catalog=$CATALOG)"
    "${COMPOSE[@]}" exec -T app php bin/console app:load:prepare --suppliers="$SUPPLIERS" --catalog="$CATALOG"

    # Warm-up: после пересоздания контейнера opcache/container-cache холодные,
    # первые запросы распарсивают весь код (+секунды на p95). Прогреваем, чтобы
    # замеры отражали установившийся профиль, а не холодный старт.
    step "Warming up app (opcache / container cache)"
    local token
    token="$("${COMPOSE[@]}" exec -T app php -r 'echo json_decode(file_get_contents("/var/www/load/state.json"),true)["customer"]["token"];')"
    local auction
    auction="$("${COMPOSE[@]}" exec -T app php -r 'echo json_decode(file_get_contents("/var/www/load/state.json"),true)["auction"]["id"];')"
    for _ in 1 2 3; do
        curl -s -o /dev/null "http://localhost:8080/health" || true
        curl -s -o /dev/null -H "Authorization: Bearer $token" "http://localhost:8080/api/v1/tenders?status=published" || true
        curl -s -o /dev/null -H "Authorization: Bearer $token" "http://localhost:8080/api/v1/auctions/$auction/state" || true
    done
}

run_bids() {
    require_k6
    step "Scenario: bids (NFR-1)"
    "$K6_BIN" run load/k6/bids.js
}

run_catalog() {
    require_k6
    step "Scenario: catalog (NFR-22)"
    "$K6_BIN" run load/k6/catalog.js
}

run_sse() {
    require_k6
    step "Scenario: SSE discovery (app-side, NFR-22)"
    "$K6_BIN" run load/k6/sse-discovery.js
    step "Scenario: SSE hub (Mercure, NFR-22/R9) — node-скрипт (k6 не стримит SSE)"
    node load/sse/load.mjs
}

run_webhooks() {
    require_k6
    step "Scenario: webhooks (NFR-3/WH-2..6)"

    # Очистка: очередь RabbitMQ + журнал доставок + статистика receiver,
    # чтобы замер был с чистого состояния.
    "${COMPOSE[@]}" exec -T rabbitmq rabbitmqctl purge_queue -p /tender tender_webhooks >/dev/null 2>&1 || true
    "${COMPOSE[@]}" exec -T rabbitmq rabbitmqctl purge_queue -p /tender tender_events >/dev/null 2>&1 || true
    "${COMPOSE[@]}" exec -T app php -r '$c=new PDO("pgsql:host=db;dbname=tender","tender","tender");$c->exec("DELETE FROM webhook_deliveries");' 2>/dev/null || true
    curl -s "http://localhost:8787/reset" >/dev/null 2>&1 || true

    # Отдельный worker только на webhooks уже поднят как сервис compose
    # `webhooks` (см. docker-compose.yml); purge-очередь ниже — чтобы замер
    # шёл с чистого состояния, а не с хвоста предыдущего прогона.

    # Steady-state эмиссия auction.bid (rate/мин в течение duration).
    AUCTION="$("${COMPOSE[@]}" exec -T app php -r 'echo json_decode(file_get_contents("/var/www/load/state.json"),true)["auction"]["id"];')"
    TENANT="$("${COMPOSE[@]}" exec -T app php -r 'echo json_decode(file_get_contents("/var/www/load/state.json"),true)["customer"]["company_id"];')"
    step "Emitting auction.bid events ($WEBHOOK_RATE/min, ${WEBHOOK_DURATION}s → ${WEBHOOK_TOTAL})"
    "${COMPOSE[@]}" exec -d app php bin/console app:load:emit-events --auction="$AUCTION" --tenant="$TENANT" --rate="$WEBHOOK_RATE" --duration="$WEBHOOK_DURATION" >/tmp/tender-load-emitter.log 2>&1

    step "Running webhooks k6 scenario"
    LOAD_WEBHOOK_TOTAL="$WEBHOOK_TOTAL" "$K6_BIN" run load/k6/webhooks.js
}

main() {
    start_load_stack
    prepare_state

    local scenario="${1:-all}"
    case "$scenario" in
        all)     run_bids; run_catalog; run_sse; run_webhooks ;;
        bids)    run_bids ;;
        catalog) run_catalog ;;
        sse)     run_sse ;;
        webhooks) run_webhooks ;;
        *)       echo "unknown scenario: $scenario (all|bids|catalog|sse|webhooks)" >&2; exit 1 ;;
    esac

    echo
    echo "Load run finished. See results above (k6 thresholds == SLO)."
}

main "$@"
