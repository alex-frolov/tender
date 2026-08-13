# Deployment — Tender Platform (operations/deployment.md)

> Prod profile, migrations, healthchecks and deploy smoke.

This document describes how the application is built, deployed and verified in
production. The **prod profile** is `docker-compose.prod.yml` (standalone, does not
reuse the dev compose). All commands below run from the repository root (`app/`).

---

## 1. Environments

| Profile | Compose file | Purpose | Notes |
|---|---|---|---|
| `dev` | `docker-compose.yml` | local development | bind-mounted sources, Mailpit, hot reload |
| `test` | `docker-compose.yml` + `docker-compose.load.yml` | load / CI | rate limits raised, no debug |
| `prod` | `docker-compose.prod.yml` | production | multi-stage prod images, healthchecks, secrets from env |

## 2. Prod topology

| Service | Image | Role |
|---|---|---|
| `app` | `tender-platform/app` (`prod-minimal` target) | Symfony php-fpm (:9000 FastCGI) |
| `web` | `tender-platform/web` (nginx, `docker/web.Dockerfile`) | HTTP edge, proxies PHP to `app:9000` |
| `db` | `postgres:17-alpine` | source of truth, persistent volume |
| `redis` | `redis:7-alpine` | rate limit, live auction state, counters |
| `rabbitmq` | `rabbitmq:4-management-alpine` | events/emails/webhooks/exports queues |
| `mercure` | `dunglas/mercure:v0.16.3` | SSE hub for live auctions |
| `worker` | `tender-platform/app` | outbox relay + consumers (async, emails, live, exports) |
| `webhooks` | `tender-platform/app` | dedicated webhook delivery consumer |
| `scheduler` | `tender-platform/app` | `scheduler:run` (timeline, digests) |
| `migrate` | `tender-platform/app` | one-shot migrations (`--profile deploy`) |

Only `web` and `mercure` publish host ports (`HTTP_PORT`, `MERCURE_PORT`).
Data lives in named volumes (`db_data`, `redis_data`, `rabbitmq_data`, `app_var`).

## 3. Configure

```bash
cp .env.prod.dist .env.prod
$EDITOR .env.prod     # fill real secrets (never commit)
```

Required variables are enforced by compose (`${VAR:?...}`) and the env contract
(`.env.dist` / `config/services.yaml`): `APP_SECRET`, `DATABASE_URL`-parts,
`MAILER_DSN`, `MERCURE_*`, `AUTH_JWT_SECRET`, `ENCRYPTION_KEY`, URL templates.
`docker compose --env-file .env.prod config` validates before touching anything.

## 4. Build

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml build
```

- `app` image: `docker/Dockerfile` target `prod-minimal` (multi-stage: `dev` base →
  `prod` → `prod-minimal`). `composer install --no-dev`, prod cache built in-image,
  `USER www-data`, runtime helpers (composer/git) removed.
- `web` image: `docker/web.Dockerfile` — nginx + `public/` + prod nginx config.
- Reproducible: `composer.lock` is part of the build context (see `.dockerignore`).

CI validates both targets (`.github/workflows/ci.yml` → `docker build` job).

## 5. Migrations

Doctrine Migrations, versioned and up/down-tested in CI. Seed dictionaries
(`document_types`, `permissions`, `contract_types`) are **idempotent data
migrations** — running them repeatedly is safe.

```bash
# one-shot, run before rollout
docker compose --env-file .env.prod -f docker-compose.prod.yml --profile deploy run --rm migrate

# or via Makefile
make prod-migrate
```

Deploy order: **migrate → cache (built in-image) → rollout workers/app →
smoke**. Migrations run against the old app version; they must be backward
compatible (expand, then contract). Rollback: see §8.

## 6. Rollout

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d
make prod-up
```

Dependencies gate on healthchecks (`condition: service_healthy`), so `app` waits
for `db/redis/rabbitmq/mercure`, and `web` waits for `app`. Restart policy
`unless-stopped`. Workers get graceful shutdown via signal.

## 7. Healthchecks

Container healthchecks are declared **per service** in `docker-compose.prod.yml`
(php-fpm has no HTTP server, so the image itself does not carry a HEALTHCHECK):

| Probe | Check | Verifies |
|---|---|---|
| `GET /health/live` | `{status: ok}` | app boots, DB + Redis reachable |
| `GET /health/ready` | `{status: ready}` | + RabbitMQ (readiness) |
| `web` container | `wget /health/live` via nginx | full HTTP path |
| `app` container | FastCGI TCP :9000 | php-fpm alive |
| `db/redis/rabbitmq` | native healthcheck | infra alive |
| `mercure` | `pgrep caddy` | hub process alive |

Health endpoints are **excluded from rate limiting** (RL-4) and do not require
auth. Orchestrator (or `deploy.sh`) uses `service_healthy` before routing traffic.

## 8. Deploy smoke & rollback

Smoke script — `scripts/deploy-smoke.sh` (also wired into `scripts/deploy.sh`):

```bash
bash scripts/deploy-smoke.sh http://localhost:${HTTP_PORT:-8080}
# 1. /health/live  → {status: ok}
# 2. /health/ready → {status: ready}
# 3. POST /api/v1/auth/register {} → 422 RFC 9457 {title, detail}
make prod-smoke
```

Full pipeline:

```bash
make deploy        # build → migrate → up → wait healthy → smoke
```

On smoke failure:

1. Roll back the previous image tag: `APP_VERSION=<prev>` for `app`/`web`/workers.
2. If a migration was applied and the new version is incompatible, apply the
   corresponding `down` migration (`doctrine:migrations:execute 'DoctrineMigrations\X' --down`)
   — only when it is safe (expand/contract discipline makes most rollbacks
   reversible without `down`).
3. Re-run `make prod-smoke`.

## 9. Backups & recovery

- **PostgreSQL**: PITR with WAL archiving (RPO ≤ 15 min, RTO ≤ 1 h). Production
  `POSTGRES_*` and WAL destination live outside the compose stack.
- **Redis**: no critical data — live auction state rebuilds from PostgreSQL
  (`auctions:state:rebuild`, `auctions:recover`). Counters are additive snapshots
  into `analytics_counters`.
- **Files** (`FILES_LOCAL_DIR`): set `FILES_STORAGE` to object storage (S3-compatible)
  for durable document retention; `local` only for single-node evaluation.
- **Archive**: `audit_log` / `webhook_deliveries` are partition-candidates;
  archive to object storage beyond retention, purge with `archive=true` queries.

## 10. Observability

See [`observability.md`](observability.md): Prometheus metrics,
structured JSON logs with trace-id, Grafana dashboards and the
auction alerting set (stalled trade, bid p95, webhook dead-letter, outbox lag).

## 11. Hardening checklist

- [ ] `.env.prod` is gitignored; secrets never in the repo
- [ ] `HTTP_PORT`/`MERCURE_PORT` behind TLS-terminating reverse proxy (or Mercure TLS)
- [ ] `MAILER_DSN` uses authenticated SMTP; SPF/DKIM for `MAILER_FROM`
- [ ] Rate limits tuned for the tenant set (`RATE_LIMIT_GLOBAL_PER_MIN`)
- [ ] Backup job runs and is restore-tested
- [ ] `MERCURE_JWT_SECRET_*` / `AUTH_JWT_SECRET` rotated, unique per env
- [ ] CI green on the release tag: lint → schema registry → cs-fixer → PHPStan →
      PHPArkitect → PHPUnit → docker build (both targets)
