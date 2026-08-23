# State Machines

The application uses `symfony/workflow` to model the lifecycle of tenders, lots, auctions, contracts
and company verification. Each workflow is declared in its own file under `config/workflow/`, loaded via
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
| `start_bid_acceptance` | published → accepting_bids | auto via `TimelineMessage` (Redis, DelayStamp); guard `isBidsRequired()` |
| `start_trade_without_bids` | published → bidding | same `TimelineMessage`; guard `not isBidsRequired()` |
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

### Tenders without a participation bid

`tenders.bids_required = false` removes the bid phase from the machine entirely: such a tender has
**no** `accepting_bids` status. The same `bids_start` timeline message that would open bid
acceptance opens trading instead (`start_trade_without_bids`, published → bidding), no bid opening
is scheduled for `bids_end`, and `TenderStatusAggregator` runs a chain in which the
`accepting_bids` phase (and the `start_trade` transition into `bidding` that follows it) is
replaced by `start_trade_without_bids`.

```
bids_required = true    draft ─► published ─► accepting_bids ─► bidding ─► …
bids_required = false   draft ─► published ─────────────────► bidding ─► …
```

Two guards keep the branches apart, so a tender can never take both: `start_bid_acceptance`
requires `isBidsRequired()`, `start_trade_without_bids` requires its negation.

## Lot workflow

A lot is the unit of the real procurement process: bids are submitted per lot, a lot is traded, a
lot is executed. Lot phases mirror the tender's, and the tender status is aggregated back from them
(slowest lot wins). Nine places: `draft`, `published`, `accepting_bids`, `bidding`, `evaluation`,
`awarding`, `contract`, `closed`, `cancelled`.

Two parties move a lot, and only through `state_machine.lot`:

| Event | Lot transition | Applied by |
|---|---|---|
| tender published | `draft → published` | `TenderService::publish` |
| `bids_start`, tender with participation bids | `→ accepting_bids` | `TimelineMessageHandler` |
| `bids_start`, tender without them | `→ bidding` | `TimelineMessageHandler` |
| auction enters `trade` / `paused` | `→ bidding` | `AuctionLotPhaseListener` |
| auction enters `choice` | `→ evaluation` | `AuctionLotPhaseListener` |
| auction enters `approve` | `→ awarding` | `AuctionLotPhaseListener` |
| auction enters `in_work` / `done_by_performer` / `claim` | `→ contract` | `AuctionLotPhaseListener` |
| auction enters `done` / `done_by_claim` | `→ closed` | `AuctionLotPhaseListener` |
| auction enters `cancelled` / `expired` | `→ cancelled` | `AuctionLotPhaseListener` |
| tender cancelled | `→ cancelled` (cascade) | `TenderService::cancel` |

Three properties worth knowing:

1. **Monotone advance, not a strict chain.** Every transition is allowed from *any* earlier phase,
   but never backwards and never out of a terminal one. An auction can be created without checking
   the tender status (`AuctionWriteService::create`), so trading may well run on a lot that was
   never taken through publication; refusing the transition would store a status contradicting
   what is actually happening.
2. **Calls are idempotent.** The advancing side asks `can()` and silently skips a transition that
   is not allowed — `resume` (paused → trade) asks for `→ bidding` again while the lot is already
   there; cancelling a tender asks `→ cancelled` of every lot, closed ones included.
3. **New lots catch up.** A lot may be added to an already published tender (until bid acceptance
   closes). It is created in `draft` and immediately advanced to the tender's phase
   (`LotPhaseService::catchUpWith`), otherwise it would drag the aggregation backwards.

The auction → lot map lives in one place: `AuctionStatusEnum::lotTransition()`. The listener
subscribes to `workflow.auction.entered` rather than being scattered across call sites — auction
transitions are applied from eight places in the module, some under a pessimistic lock, and the
subscription guarantees no transition (existing or future) forgets the lot. It passes
`flush: false` to both the lot write and the tender aggregation, so changes are persisted by the
caller's transaction instead of a separate commit in the middle of one.

Direction of the coupling is one-way and cycle-free: **tender → lots** administratively,
**auction → lot** during the procedure, and only **lots → tender** through aggregation.

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

## User status workflow

Статусная модель пользователя (`user_status`, `App\Iam\Entity\User`, marking store —
`verificationStatus`). Покрывает FR-1.5.5 (регистрация / подтверждение email) и FR-1.5.8
(приглашение / блокировка).

```
 invited ── accept_invite ──► active ── block ──► blocked
                                    ▲                │
 email_pending ── verify_email ────┘                └── unblock ──► active

 (invited / email_pending / active / blocked) ── delete ──► deleted  [терминальный]
```

Начальные состояния задаются при создании пользователя (НЕ переходы):

| Сценарий | Начальный статус |
|---|---|
| Самостоятельная регистрация (`RegisterService`) | `email_pending` (дефолт конструктора) |
| Приглашение админом (`UserManagementService::invite`) | `invited` |
| Платформенный админ (`CreatePlatformAdminCommand`) | `email_pending` → сразу `verify_email` |

Transitions (from `UserStatusTransition`):

| Transition | from → to | Когда |
|---|---|---|
| `verify_email` | email_pending → active | Подтверждение email по токену (`EmailVerificationService::verify`); также при создании платформенного админа |
| `accept_invite` | invited → active | Принятие приглашения: сброс пароля приглашённого (`PasswordResetService::reset`) |
| `block` | active → blocked | Админ блокирует пользователя (`UserManagementService::setStatus`); сессии отзываются |
| `unblock` | blocked → active | Админ разблокирует (`UserManagementService::setStatus`) |
| `delete` | invited / email_pending / active / blocked → deleted | Мягкое удаление (`UserManagementService::softDelete`); терминальный, обратных переходов нет |

Пометки:
- `markEmailVerified()` (сущность User) вызывается ПОСЛЕ `apply()` для фиксации
  `email_verified_at` — маркировка хранит только статус, не дату.
- `blocked` / `deleted` пользователь не может войти (`AuthenticationService`); блокировка
  отзывает refresh-токены.
- `deleted` = мягкое удаление (FR-1.5.9): email маскируется на `u_{uuid}@deleted.local`,
  `deleted_at`/`masked_email` сохраняются для аудита; из списков исключается фильтром
  `verificationStatus <> deleted` (enum-колонка индексируема — без сравнения с NULL).
- Прямые мутации `verificationStatus` вне workflow запрещены (кроме первичной установки
  начального статуса при создании).

## Applying transitions

Services apply transitions only through the workflow:

```php
$workflow->can($entity, TransitionEnum::CASE->value)   // guard check
$workflow->apply($entity, TransitionEnum::CASE->value) // apply + persist
```

Cross-module transition invocation is exposed through public contracts, e.g.
`AuctionLifecycleService::applyTransition(Uuid, Transition)`, so other modules never manipulate the
auction status field directly.
