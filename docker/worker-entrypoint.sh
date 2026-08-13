#!/bin/sh
# Worker-контейнер: outbox-релизер + консьюмеры messenger.
#
# dev-стек: пока worker не гонял outbox:relay, доменные события (auction.bid,
# tender.*, …) не попадали из outbox в RabbitMQ, и webhook-доставка,
# уведомления и аналитические счётчики в dev не работали. Здесь релизер
# запускается в фоне, консьюмеры — на переднем плане (как основной процесс).
set -eu

# Релизер outbox (pending → RabbitMQ), бесконечный цикл с паузой 1 c.
php /var/www/bin/console outbox:relay --pause=1 &

# Консьюмеры: доменные события, почта, live (Redis), экспорт.
# webhook-доставка — на ОТДЕЛЬНОМ worker `webhooks` (сервис compose `webhooks`,
# HTTP к подписчикам не должен делить процесс с общим worker'ом —
# иначе пустые очереди async/emails/live тормозят выборку webhook-задач
# (~10 доставок/сек вместо ~1200+/мин на выделенном, см. load/README.md §5).
exec php /var/www/bin/console messenger:consume async emails live exports -vv
