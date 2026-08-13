# Auctions

This document describes the live auction subsystem: auction types, the rules snapshot, live trading
state in Redis, bid placement, the anti-sniping timer, SSE streaming, and heartbeat/recovery.

## Overview

Each tender lot can have one auction. Auctions are live (real-time reverse) events where
participants place price bids. The hot path writes a single transaction under a row lock, then
updates the Redis snapshot and pushes to subscribers over Mercure SSE.

```
POST /auctions/{id}/bids
   │
   ▼
AuctionBidController  (#[IsGranted(AuctionVoter::BID)])
   │  validate form (price_minor) · Idempotency-Key
   ▼
PlaceBidUseCase ──► AuctionBidService::placeBid()
   │  dispatch by type/step-mode
   ▼
transactionalBid():  BEGIN
   │  lockAuction        SELECT ... FOR UPDATE
   │  replayBid          idempotency replay (same key → saved result)
   │  status must be TRADE
   │  rules snapshot present
   │  bidder admitted (BidReadService::isAdmitted)
   │  validate price by step rules
   │  commitBid: INSERT auction_bids + UPDATE current_price
   │            + optional timer extension + audit + outbox
   │  COMMIT
   ▼
AuctionStateService::write()   Redis snapshot (after commit, best-effort)
   │
   ▼
outbox → EventMessageHandler → AuctionStreamPublisher → Mercure SSE (topic auction:{id})
```

## Auction types & step modes

`AuctionTypeEnum`: `reduction`, `free_price`, `price_request`.
`AuctionStepModeEnum`: `fixed` (fixed reduction step), `free` (any lower price).

| Type | Step mode | Bid semantics |
|---|---|---|
| `reduction` + `fixed` | fixed | `price(n) = start − n×step`, must be ≤ current − step |
| `reduction` + `free` | free | any price strictly below current, ≥ price_min_limit |
| `free_price` | — | price within [min, max] bounds; best-min tracking, no mandatory reduction |
| `price_request` | — | one proposal per participant per window, no timer extension |

## Rules snapshot

Before trading starts (`AuctionService::startTrading`), auction parameters plus plugin-provided
rules are frozen into a **rules snapshot** (`Auction::rules_snapshot`, JSON) via
`Auction::captureRulesSnapshot()`.

```
startTrading(scheduled → trade)
   │  capture rules snapshot (rules factory + auction params)
   │  started_at = now · planned_end_at = now + step_duration_sec
   │  workflow START_TRADE (guard: snapshot captured)
   │  audit + outbox auction.started
   ▼
write Redis snapshot
```

`Rules/AuctionRules` is a plugin contract; the core ships `DefaultAuctionRules`
(step 50–500 bps, step duration 600 s, extensions on, extension 600 s, max 10). The snapshot holds
the resolved step (absolute or percent), price bounds, timer parameters and `trade_end_lead_hours`.

## Live state in Redis

`AuctionStateService` stores JSON snapshots:

- `auction:state:{auctionId}` — `AuctionStateSnapshot` (status, current price, planned end,
  extensions, last bid). Written after the DB commit; read path degrades to null on Redis failure.
- `auction:heartbeat:{auctionId}` — ISO-8601 UTC heartbeat written periodically for TRADE auctions.

```
Redis
├── auction:state:{id}      JSON snapshot (TTL 86400, refreshed on every write)
└── auction:heartbeat:{id}  ISO-8601 timestamp (written on heartbeat interval)
```

PostgreSQL remains the source of truth; Redis is a cache for live state and SSE payloads.

## Bid placement details

`AuctionBidService` dispatches by type/step-mode to a type-specific closure, then runs the common
transactional pipeline. `BidTransaction`:

- `lockAuction` — pessimistic row lock (`SELECT ... FOR UPDATE`) serializes concurrent bids.
- `replayBid` — idempotency key replay: same key returns the stored result.
- `commitBid` — inserts an append-only `AuctionBid`, sets the first-price start price when
  `no_start_price`, updates `current_price_minor`, applies the anti-sniping extension, writes audit
  and outbox — all in a single `flush()`.
- `AuctionBidStatusEnum`: `accepted`, `rejected`.

Unique constraints: `(auction_id, bidder_id, round)` and `(auction_id, idempotency_key)`.

### Step calculation

`BidStepCalculator` (`src/Auction/Step/`):

- `stepMinor(snapshot, start)` — absolute `bid_step_minor` or percent `floor(start × pctBps / 10000)`.
- `priceAtRound(start, step, round) = start − round×step`.
- `assertValidFixedBid` — price ≤ current − step and ≥ min limit.
- `assertValidFreeBid` — strictly below current, ≥ min limit.
- `assertValidBoundedBid` — min ≤ price ≤ max (free_price / price_request).

Bid rejection raises `BidRejectedException` (409) with codes `bid_rejected`, `auction_not_trade`,
`duplicate_bid`.

### Anti-sniping timer

`AuctionTimer::extendOnBid()`:

```
bid lands within the last step_duration_sec window
        AND extensions_count < max_extensions
                AND boundary allows it
        ──► planned_end_at += extension_duration_sec (truncated at trade_end_lead_hours)
```

The extension is recorded on `Auction` (`extensions_count`, `planned_end_at`).

## SSE streaming (Mercure)

```
GET /auctions/{id}/stream
   │  #[IsGranted(AuctionStreamVoter::SUBSCRIBE, subject: 'auction')]
   ▼
AuctionStreamDiscovery
   │  hub:  MERCURE_PUBLIC_URL
   │  topic: auction:{id}
   │  token: subscribe JWT (mercure.subscribe=[topic])
   │  state: current Redis snapshot
   ▼
client opens EventSource(hub?topic=auction:{id})
```

Publishing happens from the outbox consumer (`EventMessageHandler` →
`AuctionStreamPublisher`): `auction.*` events are read from the Redis snapshot (no DB read) and
published as private Mercure updates to `auction:{id}`. `auction.bid` maps to SSE type `'bid'`,
everything else to `'status'`. Publish failure is best-effort — clients reconnect and re-read state
through discovery.

Access to the stream/state (`AuctionStreamVoter`): `platform_admin`, the customer tenant, or an
admitted participant.

## Pause / resume / recovery

- `pause` (trade → paused) persists `paused_remaining_sec` + `paused_at` in PostgreSQL and freezes
  the timer; `resume` (paused → trade) restores the end time from the remainder.
- `auctions:heartbeat` writes heartbeats for all TRADE auctions (interval must be less than
  `AUCTION_HEARTBEAT_TIMEOUT`).
- `auctions:recover` rebuilds Redis snapshots from PostgreSQL and **auto-pauses** any TRADE auction
  whose heartbeat is missing or older than the timeout.
- `auctions:state:rebuild` rebuilds snapshots only.

```
TRADE ── heartbeat timeout (idle > AUCTION_HEARTBEAT_TIMEOUT) ──► PAUSED ── resume ──► TRADE
```

Recovery scenario: Redis lost → restart → recovery command auto-pauses stale TRADE auctions → bids
are intact because PostgreSQL is the source of truth.

## Finish & winner selection

`AuctionWinnerService`:

- `finish()` — trade → choice under a pessimistic lock, sets `actual_end_at`, outbox
  `auction.finished`.
- `selectWinnerAutomatic()` — reduction only; finishes if still in trade, picks the best accepted
  bid (min price, then earliest), marks bids winning/lost via `BidResultService` and sets the lot
  winner via `LotWriteService`, applies `approve_winner`, outbox `auction.winner_chosen`.
- `selectWinnerManual()` — from choice, validates the chosen bid is an accepted proposal.

See [state-machines.md](state-machines.md#auction-workflow) for the full status model and
[contracts.md](contracts.md) for what happens after the winner is chosen.
