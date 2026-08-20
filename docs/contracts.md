# Contracts

This document describes the contract subsystem: contract lifecycle, signing, execution stages,
claims and security.

## Overview

Contracts formalize the outcome of an award. A contract links a customer and a supplier, optionally
binds tenders, goes through signature, registration, execution, and can be terminated, expired or
deleted. Claims let the customer raise issues during execution.

```
contract ── bind tenders ──► draft ──► pending_signature ──► signed ──► registered ──► execution
                                                                    │
                                                                    ├── terminated
                                                                    └── expired
```

## Contract model

`Contract` (`src/Contract/Entity/Contract.php`):

| Field | Notes |
|---|---|
| `number` | generated contract number |
| `contractTypeId` | reference to `contract_types` |
| `customerId` / `supplierId` | parties (tenant = customer) |
| `source` | `tender` or `external` |
| `awardId` | reference to the winning auction bid |
| `priceNetMinor` / `priceGrossMinor` / `vatRateBps` / `priceBasis` / `currency` | pricing |
| `status` | workflow-managed |
| `scope` | `single_use` or `multi_use` (framework) |
| `validFrom` / `validTo` | validity window |
| `signedByCustomer` / `signedBySupplier` | signature flags |
| `signatureCustomer` / `signatureSupplier` | signature payloads |
| `terms` | JSON terms |

`ContractType` is a reference (`contract_types`): `code`, `name`, `default_scope`, `active`.

## Contract lifecycle

```
POST /contracts → create draft
   │
   ▼
POST /contracts/{id}/tenders  bind a tender (contract_tenders link)
   │
   ▼
POST /contracts/{id}/send-for-signature   (draft → pending_signature)
   │
   ▼
POST /contracts/{id}/sign   each party signs; when both signed → signed
   │
   ▼
POST /contracts/{id}/register  (signed → registered)
   │
   ▼
execution stages → done · claims → resolve
```

Workflow transitions (see [state-machines.md](state-machines.md#contract-workflow)):
`send_for_signature`, `sign` (guarded by both signatures), `back_to_draft`, `register`, `terminate`,
`expire`, `delete`.

## Signing

- `send_for_signature` is initiated by the customer (draft → pending_signature).
- `sign` moves to `signed` only when **both** parties have signed (`signedByCustomer` and
  `signedBySupplier`); a single signature keeps the contract in `pending_signature`.
- The `sign` action is authorized by permission `contracts.sign` or when the actor's company is the
  supplier party (`ContractVoter::SIGN`).
- `back_to_draft` returns the contract to draft for rework.

## Contract ↔ tender binding

`ContractTender` (`contract_tenders`) links a contract to tenders and lots:

```
Contract 1 ── N contract_tenders N ── 1 Tender / Lot
```

Each link records the award, net/gross price, VAT and status (`ContractTenderStatusEnum`):
`pending`, `in_work`, `done_by_performer`, `done`, `claim`, `done_by_claim`, `terminated`.

## Execution stages

`ContractStage` (`contract_stages`) are per-contract-tender execution milestones:

```
pending ── accept ──► accepted
```

Each stage has a number, title, amount, due date, acceptance documents and accepted-at timestamp.
The execution workflow on the auction side mirrors stages (`execution.*` events in
[events.md](events.md#event-catalog)).

## Claims

`Claim` (`claims`):

```
submitted ── resolve (reject) ──► resolved_rejected
     │
     └── resolve (accept) ──► resolved_accepted
```

A claim references a contract, optional auction, supplier and customer, stage, reason, description
and amount. Resolving writes a resolution and updates the claim status; related documents are
tracked in `documents_refs`.

## Security (bid/contract)

`Security` (`securities`) models bid or contract security with a polymorphic
`(entity_type, entity_id)` reference:

| Field | Notes |
|---|---|
| `kind` | `bid` or `contract` |
| `type` | `blocked_funds` or `guarantee` |
| `basis` | `nmck` or `first_bid` |
| `amountMinor` | security amount |
| `status` | `pending`, `active`, `released`, `forfeited` |

- `GET /securities` — deposits visible to the actor's company: on its own procedures (as the
  customer) and provided by it (as the performer); optional `kind` / `status` filters.
- `POST /securities/{id}/forfeit` — customer forfeits the security (contract context).
- `POST /securities/{id}/release` — security is released.

## Access control

`ContractAccessChecker` (public contract) verifies that a company is a party to a contract
(customer or supplier); the Contract module exposes `ContractReadService`, `ContractExecutionService`
and `ContractTypeService` as cross-module contracts. Documents for contracts are checked through
`ContractReadService::isParty` (see [database.md](database.md#documents)).

## Related documents

- [state-machines.md](state-machines.md#contract-workflow) — contract workflow transitions
- [auctions.md](auctions.md) — the award that leads to a contract
- [integrations.md](integrations.md) — contract exports and notifications
