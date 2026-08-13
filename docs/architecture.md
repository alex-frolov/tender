# Architecture

This document describes the structure of the Tender Platform application as it exists today:
modules, layers, boundaries, the request lifecycle and the deployment topology.

## Overview

The application is a **modular monolith** on Symfony 8.1. Business and platform modules are vertical
slices (access + application + domain), and a small Shared kernel provides cross-cutting technical
capabilities. Boundaries between modules are enforced by PHPArkitect at CI time.

```
┌──────────────────────────────────────────────────────────────────────┐
│                          Modular monolith (Symfony)                  │
│                                                                      │
│  Business modules:  Iam · Tender · Bid · Auction · Contract ·        │
│                     Document · Notification · Favorite · SavedSearch  │
│                                                                      │
│  Platform modules:  Platform (api keys/webhooks) · Analytics · Export │
│                                                                      │
│  Shared kernel:     money · events/outbox · audit · idempotency ·    │
│                     trace-id · exception/API core                    │
│                                                                      │
│  Cross-cutting:     src/Controller (base + health) · src/Security    │
│                     (voters) · src/Infrastructure (framework glue)   │
└──────────────────────────────────────────────────────────────────────┘
```

## Source layout

```
src/
├── Iam/                    identity: users, companies, auth, permissions
├── Tender/                 tenders, lots, catalog, timeline
├── Bid/                    sealed bids, encryption, opening, qualification
├── Auction/                live auctions: trading, state, streaming, timers
├── Contract/               contracts, execution, claims, securities
├── Document/               documents, versions, storage
├── Notification/           notification subscriptions, matching, digest
├── Favorite/               favorites
├── SavedSearch/            saved searches
├── Platform/               api keys, webhooks, webhook delivery
├── Analytics/              counters, dashboard, stats
├── Export/                 background exports (xlsx/csv)
├── Shared/                 shared kernel (cross-cutting)
├── Security/               voters, access-denied handler
├── Infrastructure/         HTTP middleware, messenger, Redis, AMQP, console
└── Controller/             AbstractBaseController, HealthController
```

## Layers inside a module

Every module is a vertical slice with three layers:

```
HTTP request
    │
    ▼
┌─────────────────────────────────────────────┐
│ Access layer   Controller/                  │  thin adapter, one controller = one route,
│                (1 controller per route)     │  validation via forms → UseCase
├─────────────────────────────────────────────┤
│ Application    UseCase/                     │  one public execute(), strict types,
│                (public module contract)     │  accepts validated Input, returns Presenter
├─────────────────────────────────────────────┤
│ Domain         Service/ · Entity/ ·         │  domain rules, module-owned entities and
│                Repository/ · Form/ · Input/ │  repositories
└─────────────────────────────────────────────┘
```

- **Access layer** — `src/{Module}/Controller/`: thin HTTP adapters. One controller = one route.
  Common logic (JSON parsing, form input, current user) lives in
  `App\Controller\AbstractBaseController`.
- **Application layer** — `src/{Module}/UseCase/{Name}UseCase.php`: one class = one user action,
  `final`, with a marker interface and a single public `execute()`. Use cases are the public
  contract of a module: other modules may call them, but never reach into another module's
  internals.
- **Domain layer** — `src/{Module}/Service/`, `Entity/`, `Repository/`, `Form/`, `Input/`,
  `Presenter/`, `Exception/`. Each module **owns** its entities and repositories; there is no shared
  `src/Entity/`.

## Shared kernel

`src/Shared/` is cross-cutting and not a business module:

| Area | Contents |
|---|---|
| `Entity/` | technical entities only: `OutboxEvent`, `IdempotencyKey`, `AuditLog` |
| `Entity/Enum/` | technical enums (`OutboxEventStatusEnum`) |
| `Exception/` | API exception core: `ApiException`, `ValidationException` (422), `ConflictException` (409), `StateTransitionException` (409), `NotFoundException` (404), `UnauthorizedException` (401) |
| `Repository/` | shared technical repositories: `IdempotencyKey`, `OutboxEvent` |
| `Money/` | integer minor-unit money value object and service |
| `Audit/` | append-only audit service + trace context |
| `Events/` | outbox relayer, `EventMessage` envelope, JSON Schema validation |
| `Idempotency/` | idempotency key service |
| `Totp/` | RFC 6238 TOTP implementation |

Identity entities (`User`, `Company`, `Permission`, `RolePermission`, `RefreshToken`,
`EmailVerificationToken`, `PasswordResetToken`) live in `src/Iam/Entity/` and are consumed by all
modules as read-models.

## Module boundaries

PHPArkitect (`phparkitect.php`, 7 rules) enforces:

1. Controllers do not depend on `App\Infrastructure` directly.
2. Entities are isolated from controllers and infrastructure.
3. `App\Shared\Money` depends only on PHP itself.
4. Infrastructure is the bottom layer (nothing depends on it except itself).
5. Message envelopes (`EventMessage`, `TimelineMessage`) are isolated from layers.
6. A module does not reach into another module's internals (`Controller`, `Command`, `Entity`,
   `Repository`, `Form`, `Input`, `Presenter`, `Exception`, `Storage`, `Rules`, `State`, `Stream`,
   `Timer`, `Step`, `Timeline`, `Service`). Only public contracts, `App\Shared`, enum value-types,
   `App\Iam\Entity` read-models, and an explicit whitelist are allowed.
7. UseCase (application layer) does not depend on `App\Controller` (above) or
   `App\Infrastructure` (below).

```
Module A                 Module B
┌─────────────┐          ┌─────────────┐
│ Controller/ │          │ Controller/ │
│   UseCase/  │◄────────►│   UseCase/  │   cross-module: only public contracts
│  Service/   │          │  Service/   │   (root interfaces, UseCase)
│  Entity/    │          │  Entity/    │
└─────────────┘          └─────────────┘
      │                        │
      └──────────┬─────────────┘
                 ▼
         Shared kernel (App\Shared)
```

**Public-contract pattern**: any service consumed cross-module is declared as an interface in the
module root (`App\{Module}\X`), with the implementation in `App\{Module}\Service\X`
(`implements X`). Autowiring resolves the interface to its single implementation. Consumers type
the interface, never the implementation.

## Transport layer

- **HTTP controllers** — module access layer (`src/{Module}/Controller/`).
- **Console commands** — `src/{Module}/Command/` for module commands; `src/Infrastructure/Console/`
  for technical commands (outbox, idempotency, redis cleanup, load preparation).
- **Message handlers** — in the owning module (`src/Tender/Timeline/TimelineMessageHandler`) or
  `src/Infrastructure/Messenger/EventMessageHandler`.
- **Authorization** — cross-cutting `src/Security/` (voters + access-denied handler).
- **Framework glue** — `src/Infrastructure/`.

## Request lifecycle

A single authenticated request flows through middleware, authorization and the controller/use-case
chain:

```
HTTP request
   │
   ▼
RateLimitMiddleware          kernel.request priority 100  (global token bucket, 429 + headers)
   │
   ▼
ApiKeyAuthMiddleware         kernel.request priority 95   (X-API-Key / non-JWT Bearer)
   │
   ▼
AuthMiddleware               kernel.request priority 90   (JWT Bearer → AuthContext + token)
   │
   ▼
Security firewall + voters   #[IsGranted] → AccessDecisionManager (affirmative)
   │                            role voters / permission voters / object voters
   │   denied → ApiAccessDeniedHandler (401 or 403)
   ▼
Controller                   currentUser() · formInput() → validation (422) → UseCase
   │
   ▼
UseCase / Service            domain rules; throws ApiException on failure
   │
   ▼
JsonApiExceptionSubscriber   kernel.exception → {title, code?, detail?} + HTTP status
   │
   ▼
JSON response
```

A detailed description of authentication and authorization is in
[authentication.md](authentication.md).

## Domain event flow

Domain mutations write an `OutboxEvent` in the same database transaction as the entity change. A
relayer (`outbox:relay`) moves pending events to RabbitMQ, where a single handler
(`EventMessageHandler`) fans them out to analytics counters, the auction SSE publisher, webhooks
and notifications. See [events.md](events.md).

```
DB transaction                Worker                        Consumers
┌─────────────────┐   ┌───────────────────┐   ┌────────────────────────────┐
│ entity change   │   │ outbox:relay      │   │ EventMessageHandler        │
│ + OutboxEvent   │──►│  ─► RabbitMQ      │──►│  ├─ analytics counters      │
│ (same tx)       │   │  (async)          │   │  ├─ auction SSE (Mercure)   │
└─────────────────┘   └───────────────────┘   │  ├─ webhook deliveries      │
                                              │  └─ notifications           │
                                              └────────────────────────────┘
```

## Deployment topology

The dev stack (`docker-compose.yml`) runs the following containers:

```
┌────────────────────────────────────────────────────────────┐
│ docker compose (dev stack)                                 │
│                                                            │
│  web (nginx :8080) ─► app (php-fpm 8.5)                    │
│                           │                                │
│  worker  ── outbox relay + messenger consume               │
│           │               (async, emails, live, exports)   │
│  webhooks── messenger consume webhooks (separate worker)   │
│  scheduler── docker/scheduler-entrypoint.sh (periodic:     │
│           │   auction heartbeat · counters snapshot ·      │
│           │   idempotency cleanup · digest)                │
│                                                            │
│  db (postgres 17) · redis 7 · rabbitmq 4 · mercure ·       │
│  mailpit                                                   │
└────────────────────────────────────────────────────────────┘
```

| Service | Port |
|---|---|
| web / API | `8080` |
| PostgreSQL | `54329` |
| Redis | `56379` |
| RabbitMQ management / AMQP | `55672` / `55673` |
| Mercure hub | `3008` |
| Mailpit UI / SMTP | `8025` / `1025` |

`worker-entrypoint.sh` runs the outbox relayer in the background and the messenger consumers
(`async emails live exports`) in the foreground. Webhook delivery runs on a dedicated worker so
HTTP fan-out never blocks the domain event queue.

## Related documents

- [events.md](events.md) — outbox, event catalog, transports
- [state-machines.md](state-machines.md) — workflow definitions
- [database.md](database.md) — entities and relationships
- [api.md](api.md) — REST API reference
