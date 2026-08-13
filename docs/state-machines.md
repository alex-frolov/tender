# State Machines

The application uses `symfony/workflow` to model the lifecycle of tenders, auctions, contracts and
company verification. Each workflow is declared in its own file under `config/workflow/`, loaded via
`config/packages/workflow.yaml` (imports `../workflow/*.yaml`).

Conventions:

- Workflows are `type: state_machine` with `marking_store.type: method` (property = status field).
- Places and transitions are referenced by **enum case values**, not raw strings
  (e.g. `!php/enum App\Tender\Entity\Enum\TenderStatusEnum::DRAFT->value`).
- Status is changed **only** through `WorkflowInterface::apply()`; direct assignment is forbidden.
- Guards can reference entity methods (e.g. `subject.aggregatedStatus().phase() >= 3`).

## Tender workflow

Tenders have 10 statuses. The multi-lot variant aggregates lot statuses: the **slowest** lot
determines the tender status, so a tender cannot advance past an open lot.

```
                       ┌──────────────┐
              publish  │             │  start_bid_acceptance
   ┌─────────►  draft  ──► published ─────────────────► accepting_bids
   │                    │      │   ▲                       │
   │                    │      │   │ republish              │
   │                    │      │   └──────┐                │
   │                    │      └───► withdrawn             │  start_trade
   │                    │            │                     ▼
   │                    │            │                    bidding
   │                    │            │                     │  start_evaluation
   │                    │            │                     ▼
   │                    │            │                   evaluation
   │                    │            │                     │  start_awarding
   │                    │            │                     ▼
   │                    │            │                    awarding
   │                    │            │                     │  start_contract
   │                    │            │                     ▼
   │                    │            │                    contract
   │                    │            │                     │  close
   │                    │            │                     ▼
   │                    │            │                    closed
   │                    │            │                     │
   └────────────────────┼────────────┴─────────────────────┘
                        │
                        ▼
                     cancelled        (terminal, from any active status)
```

Transitions (from `TenderStatusTransition`):

| Transition | from → to | Guard / notes |
|---|---|---|
| `publish` | draft → published | timeline calculated by `TimelineRules`; schedules delayed messages |
| `start_bid_acceptance` | published → accepting_bids | auto via `TimelineMessage` (Redis, DelayStamp) |
| `withdraw` | published → withdrawn | before accepting_bids |
| `republish` | withdrawn → published | guard `lotCount() > 0` |
| `cancel` | many → cancelled | terminal; reason required |
| `start_trade` | accepting_bids → bidding | guard `aggregatedStatus().phase() >= 3` |
| `start_evaluation` | bidding → evaluation | guard phase >= 4 |
| `start_awarding` | evaluation → awarding | guard phase >= 5 |
| `start_contract` | awarding → contract | guard phase >= 6 |
| `close` | contract → closed | guard phase >= 7 |

The aggregation transitions are driven by `TenderStatusAggregator` when lot statuses change. See
[tenders.md](tenders.md).

## Auction workflow

Auctions have 16 persisted statuses plus a virtual `created` used before persist. Trading lifecycle:

```
                      persist_to_new (default) / persist_to_draft / persist_to_agreement
  created ──────────────────────────────────────────────────────────────────► ...

  draft ── publish ──► new ── schedule ──► scheduled ── start_trade ──► trade
    │   └─ request_agreement ─► agreement                    │            │
    │        agreement ── approve_agreement ──► new          │            │
    │   └─ delete ──► deleted                                │            │
    │                                                         │            │
    │                                     ┌── pause ──► paused ── resume ──┤
    │                                     │                                │
    │   trade ── finish ──► choice ── approve_winner ──► approve           │
    │   trade ── choose_winner_manual ──► approve                          │
    │                                                                      │
    │   approve ── start_work ──► in_work ── mark_done_by_performer ──► done_by_performer
    │       │            │                                                  │
    │       │            └──────────────── confirm_done ──► done ◄──────────┘
    │       └────────────────────────────── confirm_done ──► done
    │
    │   claim ──► (from approve / in_work / done_by_performer)
    │       claim ── resolve_claim ──► in_work
    │       claim ── accept_claim ──► done_by_claim
    │
    │   cancel (from many states) ──► cancelled
    │   expire (new / trade / choice) ──► expired
    └──► deleted (only from draft)
```

Key transition invariants:

- `start_trade` (scheduled → trade) is guarded by `subject.getRulesSnapshot() !== null`: trading
  cannot start before the rules snapshot is captured (`Auction::captureRulesSnapshot` at start).
- `pause` (trade → paused) persists the remaining seconds (`paused_remaining_sec`) so it survives a
  Redis loss; `resume` restores the timer from the remainder.
- `confirm_done` is reachable from approve / in_work / done_by_performer.
- Multi-from `cancel` covers 10 states; `expire` covers new / trade / choice.

## Contract workflow

Contracts flow from draft through signature to execution:

```
  draft ── send_for_signature ──► pending_signature ── sign ──► signed ── register ──► registered
    │            │                      │  ▲                      │              │
    │            │      back_to_draft ◄──┘  │                      │              │
    │            │                          └── sign requires:     │              │
    │            │                             signedByCustomer    │              │
    │            │                             && signedBySupplier │              │
    │            ▼                                              │              │
    │        (delete ──► deleted)                               ▼              ▼
    └──────────────────────────────────────────────►       terminated ◄────── terminated
                                                          (signed/registered → terminated)
                                                          expired (signed/registered → expired)
```

Transitions (from `ContractStatusTransition`):

| Transition | from → to | Notes |
|---|---|---|
| `send_for_signature` | draft → pending_signature | customer initiates |
| `sign` | pending_signature → signed | guard: both parties signed |
| `back_to_draft` | pending_signature → draft | return for rework |
| `register` | signed → registered | |
| `terminate` | signed / registered → terminated | |
| `expire` | signed / registered → expired | system-initiated by `valid_to` |
| `delete` | draft / pending_signature / signed / registered → deleted | soft delete before execution |

## Company verification workflow

```
  pending ── approve ──► active ── suspend ──► suspended
     │                     ▲                     │   │
     │  reject             └──── approve ────────┘   └── reject ──► rejected
     └── reject ──► rejected ◄───────────────────────────┘
```

Transitions (from `CompanyStatusTransition`):

| Transition | from → to |
|---|---|
| `approve` | pending / suspended → active |
| `reject` | pending / suspended → rejected |
| `suspend` | active → suspended |

## Applying transitions

Services apply transitions only through the workflow:

```php
$workflow->can($entity, TransitionEnum::CASE->value)   // guard check
$workflow->apply($entity, TransitionEnum::CASE->value) // apply + persist
```

Cross-module transition invocation is exposed through public contracts, e.g.
`AuctionLifecycleService::applyTransition(Uuid, Transition)`, so other modules never manipulate the
auction status field directly.
