# Integrations & Platform Services

This document describes the cross-cutting platform services: webhooks, notifications, analytics
counters and background exports. All of them are consumers of the domain event stream described in
[events.md](events.md).

## Overview

A single event handler (`EventMessageHandler`) receives every `EventMessage` from RabbitMQ and fans
it out to analytics, the auction SSE publisher, webhook delivery and notification delivery:

```
EventMessage (RabbitMQ)
   │
   ▼
EventMessageHandler
   ├─► AnalyticsEventCounter::apply()        Redis counter deltas
   ├─► AuctionStreamPublisher::publishFromEvent()   auction.* → Mercure SSE
   ├─► WebhookDeliveryService::queueDeliveries()    match webhooks → delivery rows
   └─► NotificationDeliveryService::queueEmails()   match subscriptions → email / digest
```

## Webhooks

### Subscription

A tenant (company) subscribes to events via `POST /webhooks`:

```
webhook
├── url (http/https)
├── secret (auto-generated 32-byte hex if absent, returned once)
├── events[]       (event types to receive)
├── filters?       (payload key → value matches)
└── status         active | paused
```

`WebhookMatcher` matches an event to a webhook when the event has a tenant, the webhook is active,
the event type is in the subscription and all filters match (string equality on payload fields).

### Delivery pipeline

```
outbox → RabbitMQ → EventMessageHandler
   │
   ▼
queueDeliveries(EventMessage)
   │  for each matching webhook:
   │    unique (webhook_id, event_id) → idempotent (replay-safe)
   │    create WebhookDelivery (pending)
   │  dispatch WebhookDeliveryMessage → webhooks transport
   ▼
WebhookDeliveryService::process()
   │  paused webhook → skip (keep pending)
   │  attempts++
   │  send(): HTTP POST
   │    headers: Content-Type: application/json
   │             X-Signature: sha256=<hmac-sha256(payload, secret)>
   │             X-Event-Id
   │    timeout: WEBHOOK_DELIVERY_TIMEOUT
   │
   ├── 2xx ──► markDelivered
   └── failure:
         attempts >= WEBHOOK_MAX_ATTEMPTS (5) ──► markDead + outbox platform.webhook.failed
         else ──► markFailed + rethrow (transport retries, backoff 1s × 5^n)
```

The `webhooks` transport has `max_retries: 5, delay: 1000ms, multiplier: 5` (backoff
1/5/25/125 s). After retries are exhausted, the message goes to the `failed` (dead-letter)
transport and the delivery is marked `dead`.

```
delivery: pending ──► delivered
               └────► failed ──(retries exhausted)──► dead
```

Delivery records are listed via `GET /webhooks/{webhookId}/deliveries`.

## Notifications

### Subscriptions

Users subscribe per tenant with a channel (`email`/`webhook`/`telegram`), event types, optional
payload filters, and an instant/digest mode:

```
notification_subscription
├── user_id · tenant_id
├── channel (email implemented; webhook at tenant level; telegram contract)
├── events[]
├── filters?
├── digest bool
└── active
```

`NotificationMatcher` matches an event to an active email subscription whose event types and filters
match. Unlike webhooks, notification subscriptions are not restricted by tenant.

### Delivery

```
NotificationDeliveryService::queueEmails(EventMessage)
   │  for each matched subscription:
   │    digest=true  → NotificationDigestItem (unique user_id+event_id, idempotent)
   │    digest=false → dispatch NotificationEmailMessage → emails transport
   ▼
NotificationEmailMessageHandler
   │  load subscription + user (skip if inactive/deleted/no email)
   │  render twig email/notification.txt.twig
   ▼
mailer send
```

### Digest loop

`NotificationDigestMessage` is self-scheduling: after each run it re-dispatches itself with a
`DelayStamp(notification_digest_interval)`. The first run is scheduled by
`notifications:digest:schedule`; pending items are grouped by event type into one email per user.

```
notifications:digest:schedule
   │  dispatch NotificationDigestMessage (+ DelayStamp)
   ▼
NotificationDigestMessageHandler
   │  send pending digests
   │  re-dispatch next run (+ DelayStamp)
   ▼
(next iteration)
```

## Analytics counters

Analytics uses a two-tier design: **Redis** for real-time deltas and **PostgreSQL** for
accumulated history.

### Write side

`AnalyticsEventCounter::apply()` (in `EventMessageHandler`) maps events to counter increments:

| Event | Counter |
|---|---|
| `tender.opened` | `tenders_by_status {status: opened}` |
| `auction.started` | `auctions_total` |
| `bid.qualified` | `bids_by_status {status: decision}` |
| `contract.signed` | `contracts_total` + `contracts_amount_sum {amount}` |

`CounterService::increment()` writes `INCRBY` + `expire` on Redis key
`ctr:{tenant}:{metric}:{date}[:{dimension}]`. Redis failure is logged and treated as a no-op
(best-effort).

### Snapshot

`analytics:counters:snapshot` (scheduled externally) moves deltas from Redis to PostgreSQL:

```
CounterService::all()  (SCAN ctr:*)
   │  parse key → (tenant, metric, period, dimension)
   ▼
AnalyticsCounterRepository::increment()
   │  INSERT ... ON CONFLICT (tenant_id, metric, period, dimension)
   │  DO UPDATE SET value = value + EXCLUDED.value
   ▼
outbox analytics.counter_snapshot + analytics.counter_rotated · audit
```

### Read side

`AnalyticsQueryService` reads **PG + Redis**: a value = PG total + today's Redis delta; series and
`totalSince` add today's delta to the last bucket. If Redis is down, reads degrade to PG only.

```
┌─────────┐   increment    ┌──────────┐   snapshot   ┌───────────────────┐
│  Redis  │◄───────────────│  events  │─────────────►│ analytics_counters│
│ ctr:*   │   (best-effort)│          │  (upsert)    │ (PG, accumulated) │
└─────────┘                └──────────┘              └───────────────────┘
      ▲                         ▲                           │
      └───────────── read (value = PG + Redis delta) ───────┘
```

## Exports

Background export jobs generate xlsx/csv files streamed row-by-row.

```
POST /exports → ExportService::create()
   │  persist ExportJob (queued) · outbox export.created
   │  dispatch ExportJobMessage → exports transport
   ▼
ExportJobProcessor::process()
   │  status != queued → no-op (idempotent)
   │  markProcessing
   │  source = ExportRowSourceRegistry::for(exportType)
   │  writer = XlsxWriter | CsvWriter (OpenSpout)
   │  addRow(headers) · iterate source->rows() → addRow
   │
   ├── success → markReady (storage_path, file_name, file_size)
   │             outbox export.completed · audit
   └── failure → delete partial file, markFailed
   │             outbox export.failed · audit
   ▼
GET /exports/{jobId}        → status
GET /exports/{jobId}/download → file (only when ready)
```

Row sources (`ExportRowSourceRegistry`, tagged services):

| Export type | Source |
|---|---|
| `tenders` | `TenderExportSource` (status/from/to filters, streamed) |
| `bids` | `BidExportSource` |
| `contracts` | `ContractExportSource` |

Files are stored under `var/exports` (`export_dir` parameter) and streamed — the full file is never
built in memory. `ExportJobStatusEnum`: `queued`, `processing`, `ready`, `failed`.

## Related documents

- [events.md](events.md) — the event stream these services consume
- [api.md](api.md) — the REST endpoints for webhooks, notifications, exports, analytics
- [auctions.md](auctions.md) — the SSE publisher used for live auction push
