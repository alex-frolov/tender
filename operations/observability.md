# Observability (operations/observability.md)

- **Date:** 2026-08-15

---

## 1. Metrics (Prometheus)

### Auction domain (critical)
- `auction_bids_total` — accepted bids/sec (rate);
- `auction_bid_attempts_total{outcome}` — all bid placement attempts
  (accepted|rejected): the base for the acceptance/rejection ratio
  (RED, Prometheus practice). Replays (idempotent retries) are counted as
  neither accepted nor rejected;
- `auction_bid_rejections_total{reason}` — rejections by reason (the
  `BidRejectedException` error code: bid_rejected | auction_not_trade | duplicate_bid);
- `auction_bid_latency_seconds` (histogram) — bid write p95 < 100 ms;
  **accepted (non-replay) bids only** — the histogram feeds the bid-write SLI
  (slo-rules.yml); rejected attempts must not inflate the le="0.1" bucket;
- `auction_extensions_total` — extensions (anti-sniping);
- `auction_pauses_total` — pauses/resumes;
- `auction_active_trades` — auctions currently in TRADE;
- `auction_stalled_now` / `auction_stall_events_total` — **alerting**: the
  number of TRADE auctions without bids longer than the threshold (15 min,
  `AuctionNoBidEvaluator`) and a counter of TRANSITIONS into stalled (alert
  `AuctionStalled`). No `auction_id` label — cardinality does not grow
  (variant A); the transition diff is atomic via the Redis set
  `tender_metrics:gauges:stalled_set` (SADD returns the number of new elements).

### Platform
- `http_requests_total` / `http_request_duration_seconds` (by route, status);
- `rate_limit_exceeded_total` — 429 hits;
- `webhook_deliveries_total{status}` — delivered/failed/dead;
- `rabbitmq_*` — queue depth, dead-letter;
- `outbox_pending_seconds` — outbox publish lag (age of the oldest pending
  record, seconds);
- `sse_connections` — Mercure hub connections (hub metrics), `sse_delivery_latency` (p95 < 1 sec).
- **Mercure /metrics:** the `mercure` job scrapes the hub at `mercure:80/metrics` (local patch `docker/mercure/caddy_metrics.patch`; native metrics `mercure_subscribers_connected` / `mercure_subscribers_total` / `mercure_updates_total`). ⚠️ The compose file sets `SERVER_NAME: "http://localhost http://mercure"` for the hub — the Caddy module serves /metrics only for the matching hostname (without `mercure` the scrape would return an empty body), see docker-compose.yml.

### Console (scheduler/worker/webhooks)
- `console_commands_total{command}` — runs; `console_commands_failed_total{command}` — failures (exit code != 0 or exception); `console_command_duration_seconds{command}` — duration histogram;
- source — `ConsoleMetricsSubscriber` (ConsoleEvents::COMMAND/TERMINATE/ERROR); aggregation via Redis (shared /metrics of the web pool).

### Infrastructure
- PG: connections, slow queries, partition sizes; Redis: memory, evictions; disk.
- FPM pool (php-fpm-exporter): `phpfpm_active_processes`, `phpfpm_total_processes`, `phpfpm_listen_queue`, `phpfpm_slow_requests` — pool saturation (alerts `PhpFpmPoolSaturation` / `PhpFpmListenQueue`);
- OPcache (app /metrics, `OpcacheMetricsCollector`): `php_opcache_hit_rate`, `php_opcache_memory_{used,free,wasted}_bytes`, `php_opcache_cached_scripts`, `php_opcache_cached_keys`, `php_opcache_manual_restarts`, `php_opcache_last_restart_time` (alert `OpcacheRestarted`). Source — `opcache_get_status()` of the web pool; worker/scheduler have their own instances (not exported);
- Collector health (`InfrastructureMetricsCollector`): `metrics_gauge_refresh_errors_total` / `metrics_gauge_refresh_duration_seconds` — gauge refresh failures and duration (alert `MetricsGaugeRefreshFailed`): without them /metrics silently serves stale values;
- Build version: `app_build_info{version}` (pseudo-metric; source — `APP_VERSION`, default `dev`);
- External uptime (blackbox-exporter, job `blackbox`): `probe_success` on `http://web/health/ready` (alert `HealthReadyDown`).

## 2. Logs (structured JSON)

- Format: JSON (app), request-id/trace-id in every record;
- No PII in logs (email, INN — mask or do not log);
- Levels: debug (dev), info (business events), warning (429, retries), error (exceptions), critical (dead-letter, auction failure);
- **Collection:** Loki + promtail (docker.sd, app-* stack containers only), Loki datasource in Grafana (uid `loki`), logs panel on the Platform dashboard. Search by trace-id: `{service="app"} | json | line_format "{{.trace_id}} {{.__line__}}"`.

## 3. Tracing

- trace-id via headers (X-Request-Id / traceparent), end-to-end: API → domain → outbox → RabbitMQ → consumer → webhook/Mercure;
- Key chains: bid → event → webhook; bid → Redis → Mercure.

## 4. Dashboards (Grafana)

1. **Auction live** — bids/sec, acceptance/rejection (RED), write latency percentiles (p50/p90/p95/p99), extensions, pauses, active TRADE, no_bids alert;
2. **Platform** — RPS, errors, rate limit, webhooks, queues, HTTP latency percentiles (p50/p90/p95/p99), OPcache hit rate/memory;
3. **Growth** — partition sizes (audit/auction_bids), catalog latency percentiles (p50/p90/p95/p99), retention progress.

## 5. Alerts (baseline)

| Alert | Condition | Severity |
|---|---|---|
| Auction stalled | TRADE without bids > threshold | high |
| Bid latency | write p95 > 150 ms (5 min) | high |
| Dead-letter webhook | webhook_deliveries dead > 0 | medium |
| Outbox lag | outbox_pending_seconds > 60 | medium |
| 429 storm | rate_limit_exceeded > threshold | medium |
| Disk/partition | usage > 80% | medium |
| FPM pool saturation | phpfpm active/total > 0.8 (5 min) | high |
| FPM listen queue | phpfpm_listen_queue > 0 (2 min) | medium |
| OPcache restarted | last_restart_time < 5 min ago | medium |
| Console command failed | console_commands_failed_total > 0 over 15m | medium |
| Metrics gauge refresh failed | metrics_gauge_refresh_errors_total > 0 over 15m | medium |
| Health /ready down | blackbox probe_success == 0 (5 min) | high |
| SLO bid write fast/slow burn | error_ratio > 14.4x/6x (dual-window) | high/medium |
| SLO HTTP fast/slow burn | error_ratio > 14.4x/6x (dual-window) | high/medium |

The full rule set lives in `docker/prometheus/alerts.yml` (validated with
promtool in CI). SLO/burn-rate: targets 99% of bids written ≤ 100 ms and
99.9% of HTTP requests without 5xx (confirmed by the owner 2026-08-15);
recording rules — `docker/prometheus/slo-rules.yml`. Alerts do not fire on
NaN when there is no traffic.

## 6. Collection points

- Prometheus (pull) + exporters (php-fpm, PG, Redis, RabbitMQ, blackbox, node-exporter); Mercure — its own metrics;
- Loki (logs) — dev stack; Sentry (exceptions) — prod, the connection plan is documented, not implemented in code;
- External uptime — blackbox-exporter inside the stack (dev); UptimeRobot for the public URL — a prod step.
