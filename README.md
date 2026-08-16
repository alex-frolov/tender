# Tender Platform

**API-first e-procurement engine** — tenders, sealed bids, real-time reverse auctions, contracts and
contract execution on a PHP highload stack. An event-driven modular monolith built on Symfony with
transactional outbox, idempotent mutations, live auction streaming over SSE, rate limiting, webhooks
with retries and integer-only money arithmetic.

[![CI](https://github.com/alex-frolov/tender/actions/workflows/ci.yml/badge.svg)](https://github.com/alex-frolov/tender/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.5-8892BF?logo=php&logoColor=white)](https://www.php.net/)
[![Symfony](https://img.shields.io/badge/Symfony-8.1-black?logo=symfony&logoColor=white)](https://symfony.com/)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-17-336791?logo=postgresql&logoColor=white)](https://www.postgresql.org/)
[![RabbitMQ](https://img.shields.io/badge/RabbitMQ-4-orange?logo=rabbitmq&logoColor=white)](https://www.rabbitmq.com/)
[![Redis](https://img.shields.io/badge/Redis-7-DC382D?logo=redis&logoColor=white)](https://redis.io/)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

<!-- GitHub topics (set on repo settings when publishing):
     php, symfony, highload, e-procurement, auction, rabbitmq, redis, postgresql,
     mercure, sse, webhooks, docker, modular-monolith, event-driven, api -->

---

## Table of contents

- [What is it](#what-is-it)
- [Features](#features)
- [Tech stack](#tech-stack)
- [Requirements](#requirements)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [Development commands](#development-commands)
- [Architecture](#architecture)
- [Deployment](#deployment)
- [Documentation](#documentation)
- [API](#api)
- [Quality & testing](#quality--testing)
- [Observability (dev)](#observability-dev)
- [Contributing](#contributing)
- [Security](#security)
- [License](#license)

---

## What is it

Tender Platform is a SaaS core for running competitive procurement: buyers publish **tenders with
lots**, suppliers submit **sealed bids**, prices drop in **live reverse auctions**, and the outcome
flows into **contracts** with signed execution and claims. The domain is inspired by Russian public
procurement (44-ФЗ / 223-ФЗ); jurisdiction-specific rules are **not** part of the core and live in
a policy plugin contract.

```
              ┌─────────────────────────────────────────────────────────────┐
              │                    Modular monolith (Symfony)               │
              │                                                             │
              │  Iam   Tender   Bid   Auction   Contract   Document         │
              │  Notification   Platform   Analytics   Export               │
              │  Favorite   SavedSearch   RuStateProcurement (plugin)        │
              │                                                             │
              │  Shared kernel: money · events/outbox · audit · idempotency │
              └───────────┬─────────────────────────────────┬───────────────┘
                          │                                 │
            REST (JSON)   │          events (outbox)        │  live push
                          ▼                                 ▼
                  OpenAPI / controllers             RabbitMQ ──► consumers ──► Mercure (SSE)
                          │                              │
                  Redis: rate limit · live auction       ├─► webhooks (HMAC, retries)
                         state · counters · delayed msgs └─► notifications · analytics
```

## Features

**Tenders & lots** (`src/Tender/`)
- Multi-lot tenders with the invariant *Σ lot prices = НМЦК* enforced at publish time.
- Automatic timeline: `draft → published → accepting_bids → …` scheduled via Redis-delayed messages.
- Aggregated tender status computed from lot/auction statuses (a tender cannot close while a lot is open).
- Documents with versions, SHA-256 hashes, size/mime limits and public/private visibility.

**Bids** (`src/Bid/`)
- Two-part bids encrypted at rest (sodium XSalsa20-Poly1305) until the opening deadline — only
  metadata is visible before opening.
- Automatic opening, qualification/admission with a required reason, bid withdrawal and replacement.
- One bid per supplier per lot; sealed-tender access guarded by active framework contracts.

**Auctions** (`src/Auction/`) — the core
- Three auction types: **REDUCTION** (fixed step / free price), **FREE_PRICE**, **PRICE_REQUEST**.
- Full `symfony/workflow` state machine: 16 persisted statuses, 38 transitions.
- Anti-sniping: the timer extends when a bid lands in the last window, capped by `max_extensions`
  and the `trade_end_lead_hours` boundary.
- Steps computed from start price without floating-point drift (integer minor units).
- Live state in Redis + push to clients via **Mercure SSE** on private topics (`auction:{id}`).
- Crash recovery: paused timers persist remaining seconds in PostgreSQL; recovery commands rebuild
  state from the source of truth after a Redis loss.

**Contracts** (`src/Contract/`)
- Contract types, framework (multi-use) contracts, contract→tender binding.
- E-signing flow with dual-party signature, execution stages and claims.
- Bid/contract security computed from the canonical price basis.

**IAM & multi-tenancy** (`src/Iam/`)
- Company = tenant (1:1), RLS-style isolation; registration → email verification → super-admin approval.
- RBAC with a permission catalog (`role_permissions`), 2FA TOTP, refresh-token rotation, invites,
  soft-delete with email masking.

**Platform** (`src/Platform/`, `src/Analytics/`, `src/Export/`, `src/Notification/`, …)
- Webhooks: subscriptions, HMAC-SHA256 signatures, retries with backoff, dead-letter.
- Two-level rate limiting backed by Redis, RFC 6585-compliant `429` + `Retry-After`.
- API keys with scopes (SHA-256 hashed at rest) and Bearer auth.
- Analytics: Redis counters → periodic additive snapshots into PostgreSQL.
- Background exports (xlsx/csv) streamed row-by-row; notifications (email/digest), saved searches,
  favorites.

**Policy plugin** (`src/RuStateProcurement/`)
- Jurisdiction-specific rules (44-ФЗ/223-ФЗ) are **not** in the core: a trusted policy plugin implements
  the core rule contracts (`TimelineRules`, `AuctionRules`, `SecurityRules`) and is activated by the
  `PROCUREMENT_PLUGIN_ENABLED` feature flag (compiler pass swaps the aliases — no core changes).
- Rule values (deadlines 7/15 days by НМЦК, 4 working days for requests for quotes, auction step
  0.5–5%, 10-minute step + anti-sniping, bid/contract security 0.5–5% / 5–30%, `Europe/Moscow`) live
  in the external `config/ru_state_procurement.yaml` — no code redeploy to change them.
- **Auto-generated protocol documents** via the `DocumentGenerator` contract: on
  `tender.opened` and `auction.winner_chosen` the plugin creates system-owned
  (`owner_role=system`, `is_auto_generated=true`) protocol documents attached to the tender, rendered
  from event payloads.
- `php bin/console ru:procurement:install` registers the protocol document types and prints the active
  rule summary.

**Cross-cutting**
- Transactional **outbox** → RabbitMQ: events are never lost; consumers power webhooks,
  notifications, analytics and the SSE hub.
- **Idempotency keys** (`Idempotency-Key` header) on mutations — replay-safe bids and submissions.
- **Immutable audit log** with a trace-id across every mutation (append-only).
- **JSON Schema contracts** for all 63 events, validated at the outbox write boundary.
- **Money**: integer minor units only, canonical net/gross basis, HALF_UP/floor rounding.

## Tech stack

| Component | Role |
|---|---|
| PHP 8.5 · Symfony 8.1 | language, framework (console, messenger, workflow, validator, security) |
| PostgreSQL 17 | source of truth (Doctrine ORM 3.6 + Migrations 4.0) |
| Redis 7 | rate limiting, auction live state, counters, delayed messages |
| RabbitMQ 4 | event bus (outbox relay, emails, webhooks, exports) |
| Mercure v0.16.3 | SSE hub for live auctions |
| Mailpit | dev mail (UI at `:8025`) |
| PHPUnit + ParaTest · PHPStan (max) · PHPArkitect · PHP CS Fixer | quality pipeline |
| zenstruck/foundry | test factories & stories (no doctrine-fixtures) |
| k6 · node | load testing (bids / catalog / SSE / webhooks) |

## Requirements

- Docker with Compose v2 (recommended development path)
- Make (for the `Makefile` commands)
- Optional: PHP 8.5 + extensions listed in `composer.json` to run outside containers

## Quick start

```bash
# From this directory (repo root):

# 0. Prepare environment (first time): copy the template and set your secrets
cp .env.dist .env

# 1. Build & start the dev stack
#    (app, web:8080, db:54329, redis:56379, rabbitmq:55672/55673,
#     mercure:3008, mailpit:8025/1025, worker, webhooks, scheduler)
docker compose up -d

# 2. Apply migrations to the dev database
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

# 3. Apply migrations to the test database (used by the test suite)
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction --env=test
```

Service endpoints:

| Service | URL |
|---|---|
| API / web | `http://localhost:8080` |
| RabbitMQ management | `http://localhost:55672` (guest/guest, or `tender`/`tender`) |
| Mercure hub | `http://localhost:3008` |
| Mailpit UI | `http://localhost:8025` |
| PostgreSQL | `localhost:54329` (`tender`/`tender`) |
| Redis | `localhost:56379` |

Health probes: `GET /health/live` (app + db + redis) and `GET /health/ready`
(+ rabbitmq). See [`docs/architecture.md`](docs/architecture.md).

## Configuration

The committed template is `.env.dist` (safe defaults; copy to `.env` for local overrides and fill in
your secrets). `.env`, `.env.dev` and other local env files are `git-ignored — never commit secrets. Test environment: `.env.test` (committed, test-only values). Required variables and their
purpose:

| Variable | Purpose |
|---|---|
| `DATABASE_URL` | PostgreSQL (Doctrine) |
| `REDIS_URL` | Redis (rate limit, live auction state, counters) |
| `RABBITMQ_URL` / `MESSENGER_TRANSPORT_DSN` | AMQP (messenger, event queue `tender_events`) |
| `MESSENGER_EMAILS_DSN` | AMQP channel for mail (queue `tender_emails`) |
| `MERCURE_URL` / `MERCURE_PUBLIC_URL` / `MERCURE_PUBLISH_URL` | SSE hub endpoints |
| `MERCURE_JWT_SECRET_PUBLISH` / `MERCURE_JWT_SECRET_SUBSCRIBE` | Mercure publish/subscribe JWTs |
| `MAILER_DSN` / `MAILER_FROM` | mail transport and sender |
| `EMAIL_VERIFY_TTL` / `EMAIL_VERIFY_URL_TEMPLATE` | email verification token TTL and link template |
| `PASSWORD_RESET_TTL` / `PASSWORD_RESET_URL_TEMPLATE` | password reset token TTL and link template |
| `INVITE_URL_TEMPLATE` | user invite link template |
| `AUTH_JWT_SECRET` / `AUTH_ACCESS_TTL` / `AUTH_REFRESH_TTL` | JWT access/refresh tokens |
| `ENCRYPTION_KEY` | sealed-bid content encryption key |
| `DOMAIN_TIMEZONE` | domain timezone (Europe/Moscow) |
| `IDEMPOTENCY_TTL` | idempotency key retention (seconds) |
| `AUCTION_HEARTBEAT_TIMEOUT` | heartbeat idle threshold for auto-pause (seconds) |
| `WEBHOOK_MAX_ATTEMPTS` / `WEBHOOK_DELIVERY_TIMEOUT` | webhook delivery retries and timeout |
| `NOTIFICATION_DIGEST_INTERVAL` | digest scheduling interval (seconds) |
| `RATE_LIMIT_GLOBAL_PER_MIN` | global per-IP API rate limit |
| `DOCUMENT_MAX_FILE_BYTES` | max uploaded document size |
| `LOCK_DSN` | lock store (flock) |
| `PROCUREMENT_PLUGIN_ENABLED` | `1` — RU procurement rules active (default), `0` — core defaults |
| `APP_SECRET`, `FILES_STORAGE` / `FILES_LOCAL_DIR`, `APP_SHARE_DIR` | framework / files |

## Development commands

A Makefile wraps the common workflows (all commands run on the host, PHP runs inside the `app`
container):

```bash
make up               # start the dev stack
make migrate          # apply migrations to dev DB
make migrate-test     # apply migrations to test DB
make console CMD="..."# run bin/console
make composer ARGS="..."  # run composer
make lint             # php -l on src and tests
make cs-fixer-check   # PHP CS Fixer dry-run
make phpstan          # PHPStan level max
make arkitect         # PHPArkitect layers & module boundaries
make test             # PHPUnit (full suite, sequential)
make test-parallel    # PHPUnit via ParaTest (parallel)
make test-smoke       # smoke/load tests (sequential)
make test-coverage    # PHPUnit + coverage gate (≥80%)
make quality          # lint + phpstan + arkitect + test
make load             # load tests (all scenarios)
make cache-clear      # clear dev cache
```

Console commands defined by the application:

| Command | Purpose |
|---|---|
| `outbox:relay` | relay pending outbox events to RabbitMQ |
| `auctions:heartbeat` | refresh Redis heartbeat for trading auctions |
| `auctions:recover` | rebuild Redis live-state + auto-pause stale trading auctions |
| `auctions:state:rebuild` | rebuild Redis live-state snapshots from PostgreSQL |
| `analytics:counters:snapshot` | snapshot Redis counters into `analytics_counters` |
| `notifications:digest:schedule` | schedule the next notification digest run |
| `notifications:digest:send` | send pending notification digests now |
| `idempotency:cleanup` | delete expired idempotency keys |
| `ru:procurement:install` | register plugin protocol document types + print active RU rules |
| `app:load:prepare` | prepare k6 load-test state in the dev DB |

## Architecture

The application is a **modular monolith** on Symfony (see
[`docs/architecture.md`](docs/architecture.md)). Nine business modules and three platform/technical
modules share a small **Shared kernel**; module boundaries are enforced by PHPArkitect at CI time.

- **Business modules**: `Iam`, `Tender`, `Bid`, `Auction`, `Contract`, `Document`, `Notification`,
  `Favorite`, `SavedSearch`.
- **Platform modules**: `Platform` (API keys, webhooks), `Analytics`, `Export`.
- **Policy plugin**: `RuStateProcurement` — jurisdiction-specific rules (44-ФЗ/223-ФЗ) via the core
  rule contracts + auto-generated protocol documents (see the Policy plugin feature above).
- **Shared kernel** (`src/Shared/`): money, events/outbox, audit, idempotency, trace-id,
  exception/API core.
- **Cross-cutting**: `src/Controller/` (base + health), `src/Security/` (voters), `src/Infrastructure/`
  (framework glue).

Every module is a vertical slice (access + application + domain): controllers
(`src/{Module}/Controller/`, one controller per route), use cases (`src/{Module}/UseCase/`),
services, entities and repositories owned by the module. Cross-module access is allowed only through
public module contracts (root interfaces) and `App\Shared` — enforced by PHPArkitect rule 6.

```
Controller ──► UseCase ──► Service ──► Entity / Repository
   (thin)     (public      (domain)     (module-owned)
              contract)
      │
      ▼
Shared kernel (money · outbox · audit · idempotency)   ──   Infrastructure (Redis · AMQP · HTTP)
```

Key architectural decisions:

- **Modular monolith**: single Symfony app; atomic transactions across aggregates; boundaries ready
  to be split into services later. No module reads another module's tables.
- **Money as integer minor units**: no float for currency; canonical basis, floor steps.
- **Live auction via Mercure SSE**: one-directional push on private topics; php-fpm never holds
  connections; hub scales independently.
- **Analytics as Redis counters + periodic PG snapshots**: real-time without materialized views.
- **Company = tenant (1:1)**: `users.company_id`, RLS isolation, `platform_admin` outside tenants.

**State machines** are declared as `symfony/workflow` configs (`config/workflow/*.yaml`), referenced
by enum case values and driven only through `WorkflowInterface` (see
[`docs/state-machines.md`](docs/state-machines.md)):

| Workflow | Scope |
|---|---|
| `tender.yaml` | publish lifecycle + aggregated multi-lot status (bottleneck = the slowest lot) |
| `auction.yaml` | 16 persisted statuses / 38 transitions, `start_trade` guarded by the frozen rules snapshot |
| `contract.yaml` | draft → pending → signed → registered → execution / claim / cancelled |
| `company_verification.yaml` | pending → active / rejected / suspended |

**Auction hot path**: `POST /auctions/{id}/bids` → single transaction
(`INSERT auction_bids` + `UPDATE current_price` + outbox) under a row lock
(`SELECT … FOR UPDATE`), then Redis snapshot → Mercure publish. Two concurrent bids with the same
price: exactly one wins.

## Deployment

The **production profile** (`docker-compose.prod.yml`) runs the multi-stage prod
images with per-service healthchecks, persistent volumes and secrets from the
environment (never from git). One-shot migrations run before rollout; a deploy
smoke verifies liveness, readiness and the API error contract.

```bash
cp .env.prod.dist .env.prod   # fill real secrets
make deploy                   # build → migrate → up → wait healthy → smoke
```

| Command | What it does |
|---|---|
| `make deploy` | full pipeline: `build → migrate → up → healthchecks → smoke` |
| `make prod-up` / `prod-down` | start / stop the prod stack |
| `make prod-migrate` | run DB migrations (one-shot `migrate` service) |
| `make prod-smoke` | deploy smoke (`/health/live`, `/health/ready`, API contract) |

Health probes: `GET /health/live` (app + db + redis) and `GET /health/ready`
(+ rabbitmq). Full runbook — [`operations/deployment.md`](operations/deployment.md).

## Documentation

Detailed documentation lives in [`docs/`](docs/README.md):

| Document | Contents |
|---|---|
| [`docs/architecture.md`](docs/architecture.md) | module layout, layers, boundaries, request lifecycle |
| [`docs/authentication.md`](docs/authentication.md) | JWT auth, refresh, 2FA, API keys, RBAC permissions |
| [`docs/state-machines.md`](docs/state-machines.md) | tender / auction / contract / company workflows |
| [`docs/events.md`](docs/events.md) | outbox, event catalog, schema validation, transports |
| [`docs/tenders.md`](docs/tenders.md) | tender lifecycle, timeline scheduling, aggregation |
| [`docs/bids.md`](docs/bids.md) | sealed bids, encryption, opening, qualification |
| [`docs/auctions.md`](docs/auctions.md) | live trading, Redis state, SSE, timer, recovery |
| [`docs/contracts.md`](docs/contracts.md) | contract lifecycle, signing, execution, claims |
| [`docs/api.md`](docs/api.md) | API reference (endpoints, conventions, errors) |
| [`docs/openapi.yaml`](docs/openapi.yaml) | machine-readable OpenAPI 3.1 API specification (English) |
| [`docs/database.md`](docs/database.md) | entities, ER overview, enums |
| [`docs/integrations.md`](docs/integrations.md) | webhooks, notifications, analytics, exports |

## API

- REST JSON API under `/api/v1`, stateless, Bearer JWT auth (or `X-API-Key` with scopes).
- Errors follow **RFC 9457** (Problem Details) with machine-readable codes:
  `{title, code?, detail?}`.
- Idempotency: `Idempotency-Key` header on mutations (replay-safe).
- Rate limiting: `X-RateLimit-Limit/Remaining/Reset` headers, `429` + `Retry-After`.
- Money in API: integer minor units.
- Full endpoint reference: [`docs/api.md`](docs/api.md).
- OpenAPI 3.1 specification: [`docs/openapi.yaml`](docs/openapi.yaml) (English).

## Quality & testing

The CI pipeline (`.github/workflows/ci.yml`) runs on every push/PR against real
PostgreSQL/Redis/RabbitMQ services:

```
lint (php -l) → schema registry check → PHP CS Fixer → PHPStan (level max)
→ PHPArkitect (layers & modules) → PHPUnit (parallel + smoke)
→ code coverage gate (≥80%, pcov) → docker build
```

The same pipeline runs locally: `docker compose exec app composer quality`.

- Tests live in `tests/` across `Unit` / `Integration` / `Functional` levels.
- Test data via **zenstruck/foundry** factories and stories; database isolation via
  `dama/doctrine-test-bundle` (each test in a rollback transaction; `SkipDatabaseRollback` for
  commit-heavy load tests).
- **PHPStan level max** on `src` + `tests` (float/decimal banned for money).
- **PHPArkitect** (7 rules): layer order, module boundaries — a module cannot reach into another
  module's internals; only public contracts and `App\Shared` are visible.
- **Event schema registry**: all 63 events have JSON Schema contracts, validated at the outbox
  write boundary and cross-checked in CI (`composer schema:check`).
- **Code coverage gate ≥ 80%** lines: `composer test:coverage` runs the suite via
  paratest with a Clover report and enforces the threshold
  (`scripts/check-coverage.php`, driver **pcov**); CI fails below 80%.
- State machines are covered by data-provider transition tables.

```bash
docker compose up -d
docker compose exec app composer quality      # lint + phpstan + phparkitect + phpunit
docker compose exec app composer test:coverage  # PHPUnit + coverage gate (≥80%)
```

## Load tests

`load/` contains k6 (HTTP) + Node (SSE hub) scenarios against the dev stack, with an
`app:load:prepare` command that seeds companies, users, trading auctions, a catalog and webhook
subscriptions. See [`load/README.md`](load/README.md) for scenarios and SLOs.

```bash
./load/run.sh               # full suite
./load/run.sh bids          # one scenario: bids | catalog | sse | webhooks
```

## Observability (dev)

Prometheus + Grafana stack for local development. Metric, dashboard and alert
specification — [`operations/observability.md`](operations/observability.md).

```bash
make observability-up     # prometheus, grafana, postgres/redis/php-fpm exporters
make observability-down   # stop (volumes are preserved)
make observability-logs   # service logs
make observability-ps     # status
```

URLs:

| Service | URL |
|---|---|
| Prometheus | `http://localhost:9090` (targets/rules: `/targets`, `/rules`) |
| Grafana | `http://localhost:3000` (admin/admin, dev) |
| postgres-exporter | `http://localhost:9187/metrics` |
| redis-exporter | `http://localhost:9121/metrics` |
| php-fpm-exporter | `http://localhost:9253/metrics` |
| node-exporter | `http://localhost:9100/metrics` (host disk/CPU/RAM) |
| blackbox-exporter | `http://localhost:9115/metrics` (uptime probe `/health/ready`) |
| Loki | `http://localhost:3100` (logs) |
| php-fpm status (via nginx) | `http://localhost:8080/status` |
| RabbitMQ prometheus | `http://localhost:55672` (management UI; metrics — `rabbitmq:15692/metrics`) |
| app `/metrics` | `http://localhost:8080/metrics` |
| Mercure `/metrics` | `http://localhost:3008/metrics` (patched v0.16.3 image) |

**What is collected:** PostgreSQL (connections, slow queries, table/partition sizes
of `audit_log`/`auction_bids` via `docker/prometheus/pg-queries.yaml`), Redis
(memory/evictions), RabbitMQ (queues via `rabbitmq_prometheus`, port 15692),
php-fpm (`pm.status_path=/status`, `docker/php/zz-status.conf`; the exporter
connects directly over FastCGI `tcp://app:9000/status`; pool saturation alerts),
the application (`web:80/metrics` — `src/Infrastructure/Http/MetricsController`, contract metrics
`ops/observability.md` §1: `auction_*` incl. `auction_stalled_now`/`auction_stall_events_total`,
`http_requests_total`, `http_request_duration_seconds`, `rate_limit_exceeded_total`,
`webhook_deliveries_total`, `outbox_pending_seconds`, `php_opcache_*` via `OpcacheMetricsCollector`,
`console_commands_*` via `ConsoleMetricsSubscriber`), **Mercure** (`mercure:80/metrics`,
native hub metrics via the locally patched v0.16.3 image, see below), **node-exporter**
(host disk/CPU/RAM, alert `DiskSpaceLow`) and **blackbox-exporter** (external uptime
check on `/health/ready`, alert `HealthReadyDown`).

**Logs:** Loki + promtail collect structured JSON logs of the stack
containers (docker.sd, `app-*` only); datasource `Loki` in Grafana; search by
trace-id: `{service="app"} | json`.

**Mercure:** the image is built locally from the `v0.16.3` sources with a minimal
patch (`docker/mercure/Dockerfile` + `docker/mercure/caddy_metrics.patch`):
v0.16 already collects `mercure_subscribers_connected` / `mercure_subscribers_total` /
`mercure_updates_total`, but the Caddy module does not register `/metrics` (404 in
the official image). The patch serves the metrics at `/metrics` for Prometheus. The
version is **deliberately not upgraded** to 2.x (breaks symfony/mercure 0.7.x:
publish → 401, SSE broken). The recording rule `sse_connections = mercure_subscribers_connected`
(`docker/prometheus/rules.yml`) feeds the dashboard; `sse_delivery_latency` is
unavailable in v0.16 (the hub has no latency metrics) — the panel is kept for the
future and will light up after a Mercure 2.x upgrade.

**Dashboards:** **Auction live** (`tender-auction-live`), **Platform**
(`tender-platform`), **Growth** (`tender-growth`) — provisioned from
`docker/grafana/dashboards/*.json` (read-only).

**Alertmanager:** out of scope for the dev stack. To enable notifications, add the
`prom/alertmanager` service to compose, point `alerting:`/`alertmanagers:` in
`docker/prometheus/prometheus.yml` at it and add routing rules (see the Prometheus
Alertmanager documentation).

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md).

## Security

See [`SECURITY.md`](SECURITY.md).

## License

MIT — see [`LICENSE`](LICENSE). Copyright (c) 2026 Aleksander Frolov.
