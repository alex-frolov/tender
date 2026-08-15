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
- `auction_no_bids_alert` — **alerting: active TRADE with no bids for N minutes (possible failure)** — configurable threshold (e.g. 15 min at step_duration=10 min).

### Platform
- `http_requests_total` / `http_request_duration_seconds` (by route, status);
- `rate_limit_exceeded_total` — 429 hits;
- `webhook_deliveries_total{status}` — delivered/failed/dead;
- `rabbitmq_*` — queue depth, dead-letter;
- `outbox_pending` — outbox publish lag;
- `sse_connections` — Mercure hub connections (hub metrics), `sse_delivery_latency` (p95 < 1 sec).
- **Mercure /metrics:** the `mercure` job scrapes the hub at `mercure:80/metrics` (local patch `docker/mercure/caddy_metrics.patch`; native metrics `mercure_subscribers_connected` / `mercure_subscribers_total` / `mercure_updates_total`). ⚠️ The compose file sets `SERVER_NAME: "http://localhost http://mercure"` for the hub — the Caddy module serves /metrics only for the matching hostname (without `mercure` the scrape would return an empty body), see docker-compose.yml.

### Infrastructure
- PG: connections, slow queries, partition sizes; Redis: memory, evictions; disk.

## 2. Logs (structured JSON)

- Format: JSON (app), request-id/trace-id in every record;
- No PII in logs (email, INN — mask or do not log);
- Levels: debug (dev), info (business events), warning (429, retries), error (exceptions), critical (dead-letter, auction failure).

## 3. Tracing

- trace-id via headers (X-Request-Id / traceparent), end-to-end: API → domain → outbox → RabbitMQ → consumer → webhook/Mercure;
- Key chains: bid → event → webhook; bid → Redis → Mercure.

## 4. Dashboards (Grafana)

1. **Auction live** — bids/sec, write p95, extensions, pauses, active TRADE, no_bids alert;
2. **Platform** — RPS, errors, rate limit, webhooks, queues;
3. **Growth** — partition sizes (audit/auction_bids), catalog latency (p95), retention progress.

## 5. Alerts (baseline)

| Alert | Condition | Severity |
|---|---|---|
| Auction stalled | TRADE without bids > threshold | high |
| Bid latency | write p95 > 150 ms (5 min) | high |
| Dead-letter webhook | webhook_deliveries dead > 0 | medium |
| Outbox lag | outbox_pending age > 1 min | medium |
| 429 storm | rate_limit_exceeded > threshold | medium |
| Disk/partition | usage > 80% | medium |

## 6. Collection points

- Prometheus (pull) + exporters (php-fpm, PG, Redis, RabbitMQ); Mercure — its own metrics;
- Loki (logs) / Sentry (exceptions) — simplified in dev; prod — optional (Grafana Cloud / self-hosted).
