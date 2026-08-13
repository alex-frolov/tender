# Наблюдаемость (operations/observability.md)

- **Дата:** 2026-08-08

---

## 1. Метрики (Prometheus)

### Домен аукциона (критичные)
- `auction_bids_total` — ставки/сек (rate);
- `auction_bid_latency_seconds` (histogram) — p95 записи ставки < 100 мс;
- `auction_extensions_total` — продления (антиснайпинг);
- `auction_pauses_total` — паузы/возобновления;
- `auction_active_trades` — активные аукционы в TRADE;
- `auction_no_bids_alert` — **alerting: активный TRADE без ставок N минут (возможный сбой)** — настраиваемый порог (напр. 15 мин при step_duration=10 мин).

### Платформа
- `http_requests_total` / `http_request_duration` (по маршрутам, статусам);
- `rate_limit_exceeded_total` — срабатывания 429;
- `webhook_deliveries_total{status}` — доставки/фейлы/дед;
- `rabbitmq_*` — глубина очередей, dead-letter;
- `outbox_pending` — задержка публикации outbox (лаг);
- `sse_connections` — коннекты Mercure (hub-метрики), `sse_delivery_latency` (p95 < 1 сек).

### Инфраструктура
- PG: соединения, slow queries, размер партиций; Redis: память, evictions; диск.

## 2. Логи (structured JSON)

- Формат: JSON (app), request-id/trace-id во всех записях;
- Без ПДн в логах (email, ИНН — маскировать/не логировать);
- Уровни: debug (dev), info (бизнес-события), warning (429, ретраи), error (исключения), critical (dead-letter, сбой аукциона).

## 3. Трейсинг

- trace-id через заголовки (X-Request-Id / traceparent), сквозной: API → домен → outbox → RabbitMQ → консьюмер → webhook/Mercure;
- Ключевые цепочки: ставка → событие → webhook; ставка → Redis → Mercure.

## 4. Дашборды (Grafana)

1. **Аукцион live** — ставки/сек, p95 записи, продления, паузы, активные TRADE, alert no_bids;
2. **Платформа** — RPS, ошибки, rate limit, webhooks, очереди;
3. **Рост** — размер партиций (audit/auction_bids), latency каталога (p95), retention-прогресс.

## 5. Алерты (базовые)

| Алерт | Условие | Критичность |
|---|---|---|
| Auction stalled | TRADE без ставок > порога | high |
| Bid latency | p95 записи > 150 мс (5 мин) | high |
| Dead-letter webhook | webhook_deliveries dead > 0 | medium |
| Outbox lag | outbox_pending возраст > 1 мин | medium |
| 429 storm | rate_limit_exceeded > порога | medium |
| Disk/partition | заполнение > 80% | medium |

## 6. Места сбора

- Prometheus (pull) + exporters (php-fpm, PG, Redis, RabbitMQ); Mercure — свои метрики;
- Loki (логи) / Sentry (исключения) — упрощённо в dev; prod — по выбору (Grafana Cloud / self-hosted).
