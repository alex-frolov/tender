#!/bin/sh
# Периодические задачи (scheduler). Замена `scheduler:run` (команды не
# существует — в проекте нет ScheduleProvider'ов, symfony/scheduler не
# используется напрямую). Здесь — минимальный cron-цикл для команд,
# которые обязаны выполняться регулярно:
#
#   auctions:start-scheduled   — старт назначенных торгов (SCHEDULED → TRADE).
#                                Интервал задаёт точность старта относительно
#                                scheduled_start_at.
#   auctions:heartbeat         — heartbeat TRADE-аукционов.
#                                Интервал ДОЛЖЕН быть < AUCTION_HEARTBEAT_TIMEOUT
#                                (по умолчанию 300 c), иначе аукционы авто-паузуются
#                                (heartbeat_timeout).
#   analytics:counters:snapshot — перенос Redis-счётчиков в analytics_counters
#                                (снапшот аналитики).
#   idempotency:cleanup        — удаление протухших idempotency-ключей.
#   notifications:digest:schedule — первый запуск ежедневного дайджеста (дальше
#                                самопланируется через Redis DelayStamp.
#
# Все команды идемпотентны/безопасны для повторного вызова.
set -eu

START_SCHEDULED_EVERY_SEC="${START_SCHEDULED_EVERY_SEC:-30}"  # точность старта торгов
HEARTBEAT_EVERY_SEC="${HEARTBEAT_EVERY_SEC:-30}"      # < AUCTION_HEARTBEAT_TIMEOUT
SNAPSHOT_EVERY_SEC="${SNAPSHOT_EVERY_SEC:-300}"
CLEANUP_EVERY_SEC="${CLEANUP_EVERY_SEC:-3600}"
DIGEST_EVERY_SEC="${DIGEST_EVERY_SEC:-86400}"

run_every() {
    every="$1"; shift
    last=0
    while true; do
        now=$(date +%s)
        if [ $((now - last)) -ge "$every" ]; then
            last="$now"
            echo "[scheduler] $*"
            php bin/console "$@" --no-interaction || echo "[scheduler] FAILED: $*"
        fi
        sleep 5
    done
}

# heartbeat обязателен в фоне (его отсутствие = авто-пауза торгов)
run_every "$HEARTBEAT_EVERY_SEC" auctions:heartbeat &
run_every "$START_SCHEDULED_EVERY_SEC" auctions:start-scheduled &
run_every "$SNAPSHOT_EVERY_SEC" analytics:counters:snapshot &
run_every "$CLEANUP_EVERY_SEC" idempotency:cleanup &
run_every "$DIGEST_EVERY_SEC" notifications:digest:schedule &

wait
