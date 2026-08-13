# Events & Messaging

This document describes the event system: the transactional outbox, the `EventMessage` envelope,
JSON Schema validation, and the messenger transports.

## Overview

Domain mutations persist an `OutboxEvent` in the same database transaction as the entity change.
A relayer moves pending events to RabbitMQ, where a single handler fans them out to consumers.

```
┌────────────────────┐   ┌──────────────────────┐   ┌──────────────────────┐
│  HTTP request      │   │  outbox:relay worker │   │  RabbitMQ            │
│  ──► service       │   │                      │   │                      │
│  ──► persist       │   │  SELECT pending      │   │  exchange tender_events (topic)
│      OutboxEvent   │──►│  (FIFO by createdAt) │──►│  queue tender_events │
│  ──► flush (same   │   │  dispatch EventMessage│  │                      │
│      transaction)  │   │  mark published      │   └──────────┬───────────┘
└────────────────────┘   └──────────────────────┘              ▼
                                                     EventMessageHandler
                                                       │  │  │  │
                                                       ▼  ▼  ▼  ▼
                                               analytics  SSE  webhooks  notifications
```

## Outbox entity

`App\Shared\Entity\OutboxEvent` maps to `outbox_events`:

| Column | Purpose |
|---|---|
| `id` | bigint identity |
| `tenant_id` | tenant id (nullable for platform events) |
| `event_type` | event type string, e.g. `auction.bid` |
| `payload` | JSON payload |
| `aggregate_type` / `aggregate_id` | aggregate reference |
| `status` | `pending` / `published` |
| `created_at` / `published_at` | timestamps |

Indexed by `(status, created_at)` for FIFO polling.

## EventMessage envelope

`App\Shared\Events\EventMessage` is the transport DTO:

```php
EventMessage(
    eventId: string,        // UUID v4 (minted at relay time)
    eventType: string,
    occurredAt: DateTimeImmutable,
    tenantId: ?string,
    aggregateType: string,
    aggregateId: string,
    payload: array,
)
```

## Relayer

`App\Shared\Events\OutboxRelayer::relay()` (driven by `outbox:relay` console command):

1. Select up to `batchSize` pending events ordered by `createdAt, id`.
2. For each: dispatch an `EventMessage` to the messenger bus, then mark the event `published`.
3. On failure the event is left `pending` (it will be retried); a failed event does not block the
   batch.

Guarantees:

- **at-least-once** — `published` is set only after a successful dispatch; a crash between dispatch
  and marking causes redelivery, so consumers must be idempotent.
- **idempotent** — `published` events are never re-selected.
- **FIFO** by `createdAt, id`.

## Schema validation

Every event type has a JSON Schema contract in `config/schemas/events/{event_type}.json`
(63 schemas). The envelope is validated at the outbox write boundary by
`OutboxEventSchemaListener` (Doctrine `prePersist`): a payload that does not match its schema
throws `EventSchemaViolationException` (HTTP 500, code `event_schema_violation`) and **rolls back
the whole transaction** — an invalid event never reaches the outbox.

```
envelope
┌──────────────────────────────────────────────┐
│ event_id (uuid)                              │
│ event_type (const, matches file name)        │
│ occurred_at (date-time UTC)                  │
│ tenant_id (uuid or null)                     │
│ aggregate_type (const)                       │
│ aggregate_id (uuid)                          │
│ payload (object, additionalProperties=false) │
└──────────────────────────────────────────────┘
```

Events without a registered schema are not validated (schemas are added incrementally). CI runs
`composer schema:check` (`scripts/check-event-schemas.php`) to verify that the schema registry is
consistent.

## Messenger transports

`config/packages/messenger.yaml` defines the transports:

| Transport | DSN | Purpose |
|---|---|---|
| `async` | RabbitMQ (`MESSENGER_TRANSPORT_DSN`) | domain `EventMessage` (exchange/queue `tender_events`), retry 3× delay 1000ms×2 |
| `emails` | RabbitMQ (`MESSENGER_EMAILS_DSN`) | mail (exchange/queue `tender_emails`), retry 3× |
| `live` | Redis (`MESSENGER_REDIS_DSN`) | delayed timeline messages, notification digest (TTL/DelayStamp) |
| `webhooks` | RabbitMQ (`RABBITMQ_URL`) | webhook delivery (queue `tender_webhooks`), retry 5× delay 1000ms×5 |
| `exports` | RabbitMQ (`RABBITMQ_URL`) | background exports (queue `tender_exports`), retry 3× |
| `failed` | Doctrine (`doctrine://default?queue_name=failed`) | dead-letter after retries are exhausted |

Routing:

```
App\Shared\Events\EventMessage                     → async
App\Tender\Timeline\TimelineMessage                → live
Symfony\Component\Mailer\Messenger\SendEmailMessage → emails
App\Notification\NotificationEmailMessage           → emails
App\Notification\NotificationDigestMessage          → live
App\Platform\WebhookDeliveryMessage                 → webhooks
App\Export\ExportJobMessage                         → exports
```

In tests, all transports are overridden to `in-memory://`.

## Consumer hub

`App\Infrastructure\Messenger\EventMessageHandler` receives every `EventMessage` and fans it out:

```
EventMessageHandler::__invoke(EventMessage)
   ├─► AnalyticsEventCounter::apply()        Redis counter deltas (tenant-scoped)
   ├─► AuctionStreamPublisher::publishFromEvent()   auction.* → Mercure SSE
   ├─► WebhookDeliveryService::queueDeliveries()    match webhooks → WebhookDelivery rows
   └─► NotificationDeliveryService::queueEmails()   match subscriptions → email / digest
```

## Dispatch-after-commit

`DispatchAfterCommitMiddleware` + `DispatchAfterCommitStamp` (with
`DeferredMessagesService`/`TransactionService`) defer messenger dispatch until after the Doctrine
`postFlush` — messages dispatched within a transaction are actually sent only once the transaction
has committed. See `src/Infrastructure/Messenger/`.

## Event catalog

The complete event list is defined by the JSON Schema files under `config/schemas/events/`. Events
grouped by aggregate:

| Aggregate | Events |
|---|---|
| `auction` | `auction.created`, `auction.published`, `auction.scheduled`, `auction.started`, `auction.bid`, `auction.finished`, `auction.winner_chosen`, `auction.paused`, `auction.resumed`, `auction.cancelled`, `auction.unscheduled`, `auction.updated`, `auction.expired`, `auction.deleted`, `auction.agreement.requested` |
| `bid` | `bid.submitted`, `bid.withdrawn`, `bid.qualified`, `bid.status_changed` |
| `tender` | `tender.published`, `tender.withdrawn`, `tender.republished`, `tender.opened`, `tender.bids_opened`, `tender.bidding`, `tender.evaluating`, `tender.awarding`, `tender.in_contract`, `tender.closed`, `tender.cancelled`, `tender.updated` |
| `contract` | `contract.created`, `contract.pending_signature`, `contract.signed`, `contract.registered`, `contract.terminated`, `contract.expired`, `contract.deleted`, `contract.back_to_draft`, `contract.tender_bound` |
| `execution` | `execution.in_work`, `execution.done_by_performer`, `execution.done`, `execution.claim`, `execution.done_by_claim`, `execution.terminated` |
| `claim` | `claim.created`, `claim.resolved`, `claim.accepted`, `claim.cancelled` |
| `org` / `user` | `org.registered`, `org.verified`, `user.invited`, `user.email_verified`, `user.deleted` |
| `export` | `export.created`, `export.completed`, `export.failed` |
| `analytics` | `analytics.counter_snapshot`, `analytics.counter_rotated` |
| `platform` | `platform.webhook.failed`, `platform.rate_limit.exceeded`, `platform.tenant.updated` |

## Idempotency

`Idempotency-Key` header on mutations provides replay safety. See
[api.md](api.md#idempotency) for the request flow; the technical entities are
`App\Shared\Entity\IdempotencyKey` and the service `App\Shared\Idempotency\IdempotencyService`
(middleware: `App\Infrastructure\Http\IdempotencyMiddleware`).

## Audit & trace-id

Every mutation writes an append-only `AuditLog` record via `App\Shared\Audit\AuditService`
(action, entity type/id, before/after, actor, ip, request id). `TraceContext` + `TraceIdSubscriber`
propagate a trace id across the request. See [database.md](database.md#technical-entities-shared).
