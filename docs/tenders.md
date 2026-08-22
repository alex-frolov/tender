# Tenders

This document describes the tender subsystem: tender/lot model, lifecycle, timeline scheduling, and
multi-lot status aggregation.

## Overview

A tender is a container of lots. Buyers publish tenders with a timeline; bids are accepted during
`accepting_bids`; then the tender proceeds through evaluation, awarding and contract phases. The
tender status is **aggregated** from its lots — the slowest lot determines the tender status.

A tender may also run **without a participation bid** (`bids_required = false`): there is no bid
phase at all, and it goes from `published` straight to `bidding`. See
[Tenders without a participation bid](#tenders-without-a-participation-bid).

```
Tender
┌──────────────────────────────────────────────┐
│ number · title · procedure_type · law_type   │
│ nmck_minor (Σ lots = nmck unless no_start)   │
│ customer (tenant) · access_type · status     │
│ timeline (bids_start / bids_end) · rating    │
│                                              │
│  lots: Lot[]  (price, vat, delivery terms,   │
│                execution window, status)     │
└──────────────────────────────────────────────┘
```

## Tender & lot model

`Tender` (`src/Tender/Entity/Tender.php`):

| Field | Notes |
|---|---|
| `procedureType` | `auction`, `competition`, `rfq`, `rfp`, `direct` |
| `lawType` | `fz44`, `fz223`, `commercial` |
| `nmckMinor` | max contract price in minor units (nullable when `noStartPrice`) |
| `noStartPrice` | prices defined by bids |
| `currency`, `vatRateBps`, `priceBasis` | pricing model |
| `accessType` | `open` or `contract_holders` (sealed to active framework contracts) |
| `bidsRequired` | a participation bid is required (default `true`); `false` drops the bid phase |
| `status` | workflow-managed |
| `timeline` | JSON map of ISO-8601 key dates (`bids_start`, `bids_end`) |
| `securityRequired`, `nationalRegime` | procurement options |

`Lot` (`src/Tender/Entity/Lot.php`): `number`, `title`, `price_net_minor` (canonical),
`price_gross_minor` (derived), `vat_rate_bps`, `price_basis`, `quantity`, `unit`, `delivery_terms`,
`execution_start_at`, `trade_end_lead_hours`, `security_percent`, `status`, `winner_bid_id`.

**Invariant**: `Σ lot prices = nmck_minor` (unless `noStartPrice`), enforced at publish time
(`assertLotsSumInvariant`).

## Timeline

On publish, `TimelineRules::calculate()` computes key dates. The core implementation
(`DefaultTimelineRules`) sets `bids_start = now` and `bids_end` from a duration by procedure type:

| Procedure | Duration |
|---|---|
| auction | P7D |
| competition | P15D |
| rfq | P4D |
| rfp | P7D |
| direct | P1D |

`TimelineRules` is a plugin contract (`config/services/tender.yaml` aliases it to
`DefaultTimelineRules`); a jurisdiction-specific policy plugin can replace it.

## Publish & scheduling

```
PublishTenderController → TenderService::publish()
   │  timeline = timelineRules->calculate(tender)
   │  set timeline on tender
   │  workflow PUBLISH (draft → published)
   │  scheduleStartBidAcceptance(timeline['bids_start'])
   │  scheduleBidOpening(timeline['bids_end'])
   ▼
commit (audit + outbox tender.published)
```

`TenderTimelineScheduler` dispatches delayed `TimelineMessage`s on the Redis `live` transport with
a `DelayStamp`:

- `START_BID_ACCEPTANCE` at `bids_start` → workflow `start_bid_acceptance` (published →
  accepting_bids); when `bids_required = false` the same message applies
  `start_trade_without_bids` instead (published → bidding).
- `OPEN_BIDS` at `bids_end` → `BidOpeningService::open()` (see [bids.md](bids.md#opening)).
  Not scheduled when `bids_required = false` — there is nothing to open.

```
publish ──► published ──[bids_start]──► accepting_bids ──[bids_end]──► bids opened
                                                    (delayed TimelineMessage)
```

`TimelineMessageHandler` guards with `workflow->can()` so replay is idempotent.

## Withdraw / republish / cancel

- `withdraw` (published → withdrawn): only before `accepting_bids`; reason as free text.
- `republish` (withdrawn → published): guard `lotCount() > 0`; timeline recalculated.
- `cancel`: from any active or withdrawn status, terminal, requires a reason code
  (`CancellationReasonEnum`); cascades lots/auctions to `cancelled`.

## Multi-lot aggregation

`TenderStatusAggregator` advances the tender status as lots progress. The tender status is the
**slowest** lot: `Tender::aggregatedStatus()` returns the minimum non-terminal lot phase, with
special cases for all-cancelled / all-closed and admin phases.

```
Lot A: accepting_bids ──► bidding ──► evaluation ──► ...
Lot B: bidding ─────────► evaluation ──► ...
Tender status = min(lot phases)  → follows the slowest lot (B here)
```

When lot statuses change, `TenderStatusAggregator::recalculate()` walks the FORWARD chain and
applies each allowed workflow transition:

```
FORWARD:  accepting_bids → bidding → evaluation → awarding → contract → closed
          (each guarded by aggregatedStatus().phase() >= target phase)
```

Transitions are applied only through the tender workflow; direct status assignment is forbidden.
A tender cannot reach `closed` while any lot is still open.

## Tenders without a participation bid

`bids_required` (set at creation, immutable afterwards) answers a single question: must a supplier
file a participation bid before it can trade?

| | `bids_required = true` (default) | `bids_required = false` |
|---|---|---|
| Statuses | draft → published → **accepting_bids** → bidding → … | draft → published → bidding → … |
| At `bids_start` | `start_bid_acceptance` | `start_trade_without_bids` |
| At `bids_end` | bids opened (`BidOpeningService`) | nothing scheduled; the date stays as the procedure deadline |
| `POST /tenders/{id}/bids` | accepted while `accepting_bids` | 409 `This tender does not require participation bids` |
| Who may trade | companies with an `admitted` bid (`BidReadService::isAdmitted`) | anyone who can see the tender (`access_type`, framework contract) |
| Aggregation chain | `start_bid_acceptance → start_trade → …` | `start_trade_without_bids → …` |

The two branches are kept apart by workflow guards (`isBidsRequired()` and its negation), so a
tender can never take both. Admission is asked through `BidReadService::isAdmittedToTrade()`, which
returns `true` outright when the tender needs no bids; the strict `isAdmitted()` stays in place
where the *fact of an admitted bid* is what matters — `AuctionStreamVoter` keeps a participant on
the live stream of their own auction after their framework contract has expired, and a tender
without bids leaves no such trace to keep.

Access to a closed tender (`access_type = contract_holders`) is unaffected: `PlaceBidUseCase` still
checks the framework contract before every auction bid.

## Catalog & dashboards

- `TenderCatalogQueryService` — keyset pagination on `(created_at, id)` with an opaque
  base64url cursor; each page aggregates lot statuses via `STRING_AGG` and includes `bids_end`
  deadline and lot count.
- `TenderDashboardQueryService` — counts of active tenders, status distribution, upcoming bid
  deadlines, and facts by dimension (region / customer / period).
- `TenderExportSource` — streaming row source for the export module.

## Access & rating

- `GET /tenders/{id}/access` reports whether the current company may participate (contract-holders
  access requires an active framework contract).
- Rating (`POST /tenders/{id}/rating`) is allowed when the aggregated status is `closed` and
  produces an `execution_rating`.

## Related documents

- [state-machines.md](state-machines.md#tender-workflow) — the tender workflow transitions
- [bids.md](bids.md) — bid acceptance and opening
- [auctions.md](auctions.md) — lot auctions
- [contracts.md](contracts.md) — contract creation and binding
- [database.md](database.md#tenders) — tender/lot entities
