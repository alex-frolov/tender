# Documentation

This directory describes the Tender Platform application: how it is structured, how the subsystems
work, and what the API looks like.

## Overview

```
                    ┌───────────────────────────────────────────────┐
                    │              Tender Platform (app/)           │
                    │                                               │
                    │  HTTP (REST) ──► Controllers ──► Use Cases    │
                    │                     │                         │
                    │                     ├─► domain services        │
                    │                     ├─► outbox ──► RabbitMQ    │
                    │                     └─► audit / idempotency    │
                    │                                               │
                    │  Workers: messenger consumers · outbox relay  │
                    │  Live push: Mercure SSE (auctions)            │
                    └───────────────────────────────────────────────┘
```

## Documents

| Document | Contents |
|---|---|
| [architecture.md](architecture.md) | module layout, layers, boundaries, request lifecycle, deployment |
| [authentication.md](authentication.md) | JWT auth, refresh, 2FA, API keys, RBAC permissions, company verification |
| [state-machines.md](state-machines.md) | tender / auction / contract / company verification workflows |
| [events.md](events.md) | outbox pattern, event catalog, schema validation, messenger transports |
| [tenders.md](tenders.md) | tender lifecycle, timeline scheduling, multi-lot aggregation |
| [bids.md](bids.md) | sealed bids, encryption, opening, qualification |
| [auctions.md](auctions.md) | live trading, Redis state, SSE streaming, timer, heartbeat/recovery |
| [contracts.md](contracts.md) | contract lifecycle, signing, execution, claims, security |
| [api.md](api.md) | REST API reference: endpoints, conventions, errors |
| [database.md](database.md) | entities, relationships, enums, table overview |
| [integrations.md](integrations.md) | webhooks, notifications, analytics counters, exports |
| [../operations/deployment.md](../operations/deployment.md) | prod profile, migrations, healthchecks, deploy smoke, rollback |

## Suggested reading order

1. [architecture.md](architecture.md) — understand the module layout and boundaries.
2. [events.md](events.md) — understand how domain events flow through the system.
3. [state-machines.md](state-machines.md) — understand the lifecycle of tenders, auctions and
   contracts.
4. Then dive into the subsystem you are working on: [tenders.md](tenders.md),
   [bids.md](bids.md), [auctions.md](auctions.md) or [contracts.md](contracts.md).
5. Use [api.md](api.md) and [database.md](database.md) as reference material.
