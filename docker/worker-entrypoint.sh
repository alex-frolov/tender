#!/bin/sh
# Worker-контейнер: outbox-релизер + консьюмеры messenger.
#
# dev-стек: пока worker не гонял outbox:relay, доменные события (auction.bid,
# tender.*, …) не попадали из outbox в RabbitMQ, и webhook-доставка,
# уведомления и аналитические счётчики в dev не работали. Здесь релизер
# запускается в фоне, консьюмеры — на переднем плане (как основной процесс).
#
# Релизер запускается ПОД СУПЕРВИЗИЕЙ (relay_forever): без неё его падение
# проходило молча — контейнер оставался Up на messenger:consume, а события
# копились в outbox со статусом pending. Так, в частности, тихо умирали
# live-события аукциона: ставка коммитилась, а SSE-подписчики (Mercure)
# не получали ничего, потому что auction.bid не доезжал до RabbitMQ.
# Типовая причина падения в dev — протухший кэш контейнера Symfony
# (`require(var/cache/dev/Container…/…php): Failed to open stream`) после
# пересборки кэша соседним процессом: перезапуск команды лечит.
set -eu

RELAY_PAUSE_SEC="${RELAY_PAUSE_SEC:-1}"          # пауза релизера на пустом outbox
RELAY_RESTART_DELAY_SEC="${RELAY_RESTART_DELAY_SEC:-5}"  # backoff перед перезапуском
RELAY_HEARTBEAT="${RELAY_HEARTBEAT:-/var/www/var/run/outbox-relay.heartbeat}"  # для healthcheck

relay_forever() {
    while true; do
        echo "[worker] outbox:relay starting"
        php /var/www/bin/console outbox:relay \
            --pause="$RELAY_PAUSE_SEC" --heartbeat="$RELAY_HEARTBEAT" \
            || echo "[worker] outbox:relay exited (code $?), restarting in ${RELAY_RESTART_DELAY_SEC}s"
        sleep "$RELAY_RESTART_DELAY_SEC"
    done
}

# Релизер outbox (pending → RabbitMQ) — в фоне, с автоперезапуском.
relay_forever &

# Консьюмеры: доменные события, почта, live (Redis), экспорт.
# webhook-доставка — на ОТДЕЛЬНОМ worker `webhooks` (сервис compose `webhooks`,
# HTTP к подписчикам не должен делить процесс с общим worker'ом —
# иначе пустые очереди async/emails/live тормозят выборку webhook-задач
# (~10 доставок/сек вместо ~1200+/мин на выделенном, см. load/README.md §5).
exec php /var/www/bin/console messenger:consume async emails live exports -vv
