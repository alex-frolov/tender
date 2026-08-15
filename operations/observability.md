# Observability (operations/observability.md)

- **Date:** 2026-08-08

---

## 1. Metrics (Prometheus)

### Auction domain (critical)
- `auction_bids_total` — bids/sec (rate);
- `auction_bid_latency_seconds` (histogram) — bid write p95 < 100 ms;
- `auction_extensions_total` — extensions (anti-sniping);
- `auction_pauses_total` — pauses/resumes;
- `auction_active_trades` — auctions currently in TRADE;
- `auction_stalled_now` / `auction_stall_events_total` — **alerting**: число TRADE-аукционов без ставок дольше порога (15 мин, `AuctionNoBidEvaluator`) и счётчик ПЕРЕХОДОВ в stalled (alert `AuctionStalled`). Без лейбла `auction_id` — кардинальность не растёт (roadmap #5, вариант A); дифф переходов — атомарно через Redis-SET `tender_metrics:gauges:stalled_set` (SADD возвращает число новых).

### Platform
- `http_requests_total` / `http_request_duration_seconds` (by route, status);
- `rate_limit_exceeded_total` — 429 hits;
- `webhook_deliveries_total{status}` — delivered/failed/dead;
- `rabbitmq_*` — queue depth, dead-letter;
- `outbox_pending` — outbox publish lag;
- `sse_connections` — Mercure hub connections (hub metrics), `sse_delivery_latency` (p95 < 1 sec).
- **Mercure /metrics:** the `mercure` job scrapes the hub at `mercure:80/metrics` (local patch `docker/mercure/caddy_metrics.patch`; native metrics `mercure_subscribers_connected` / `mercure_subscribers_total` / `mercure_updates_total`). ⚠️ The compose file sets `SERVER_NAME: "http://localhost http://mercure"` for the hub — the Caddy module serves /metrics only for the matching hostname (without `mercure` the scrape would return an empty body), see docker-compose.yml.

### Console (scheduler/worker/webhooks, roadmap #6)
- `console_commands_total{command}` — запуски; `console_commands_failed_total{command}` — падения (exit != 0 или исключение); `console_command_duration_seconds{command}` — гистограмма длительности;
- источник — `ConsoleMetricsSubscriber` (ConsoleEvents::COMMAND/TERMINATE/ERROR); агрегация через Redis (общий /metrics web-пула).

### Infrastructure
- PG: connections, slow queries, partition sizes; Redis: memory, evictions; disk.
- FPM pool (php-fpm-exporter): `phpfpm_active_processes`, `phpfpm_total_processes`, `phpfpm_listen_queue`, `phpfpm_slow_requests` — насыщение пула (alerts `PhpFpmPoolSaturation` / `PhpFpmListenQueue`);
- OPcache (app /metrics, `OpcacheMetricsCollector`): `php_opcache_hit_rate`, `php_opcache_memory_{used,free,wasted}_bytes`, `php_opcache_cached_scripts`, `php_opcache_cached_keys`, `php_opcache_manual_restarts`, `php_opcache_last_restart_time` (alert `OpcacheRestarted`). Источник — `opcache_get_status()` web-пула; у worker/scheduler свои инстансы (не экспортируются, см. observability-roadmap.md #1);
- External uptime (blackbox-exporter, job `blackbox`): `probe_success` на `http://web/health/ready` (alert `HealthReadyDown`).

## 2. Logs (structured JSON)

- Format: JSON (app), request-id/trace-id in every record;
- No PII in logs (email, INN — mask or do not log);
- Levels: debug (dev), info (business events), warning (429, retries), error (exceptions), critical (dead-letter, auction failure).

## 3. Tracing

- trace-id via headers (X-Request-Id / traceparent), end-to-end: API → domain → outbox → RabbitMQ → consumer → webhook/Mercure;
- Key chains: bid → event → webhook; bid → Redis → Mercure.

## 4. Dashboards (Grafana)

1. **Auction live** — bids/sec, write latency percentiles (p50/p90/p95/p99), extensions, pauses, active TRADE, no_bids alert;
2. **Platform** — RPS, errors, rate limit, webhooks, queues, HTTP latency percentiles (p50/p90/p95/p99), OPcache hit rate/memory;
3. **Growth** — partition sizes (audit/auction_bids), catalog latency percentiles (p50/p90/p95/p99), retention progress.

## 5. Alerts (baseline)

| Alert | Condition | Severity |
|---|---|---|
| Auction stalled | TRADE without bids > threshold | high |
| Bid latency | write p95 > 150 ms (5 min) | high |
| Dead-letter webhook | webhook_deliveries dead > 0 | medium |
| Outbox lag | outbox_pending age > 1 min | medium |
| 429 storm | rate_limit_exceeded > threshold | medium |
| Disk/partition | usage > 80% | medium |
| FPM pool saturation | phpfpm active/total > 0.8 (5 min) | high |
| FPM listen queue | phpfpm_listen_queue > 0 (2 min) | medium |
| OPcache restarted | last_restart_time < 5 min ago | medium |
| Console command failed | console_commands_failed_total за 15м > 0 | medium |
| Health /ready down | blackbox probe_success == 0 (5 min) | high |

Полный список правил — `docker/prometheus/alerts.yml` (проверяется promtool в CI).
SLO/burn-rate алерты — observability-roadmap.md #3 (запланированы).

## 6. Collection points

- Prometheus (pull) + exporters (php-fpm, PG, Redis, RabbitMQ, blackbox); Mercure — its own metrics;
- Loki (logs) / Sentry (exceptions) — simplified in dev; prod — optional (Grafana Cloud / self-hosted); Sentry wiring — observability-roadmap.md #7b;
- External uptime — blackbox-exporter внутри стека (dev); UptimeRobot для публичного URL — prod-шаг (roadmap #9).
