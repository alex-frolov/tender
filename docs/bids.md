# Bids

This document describes the sealed-bid subsystem: bid content encryption, submission, replacement,
withdrawal, opening, and qualification.

## Overview

Bids are **sealed**: content is encrypted at rest and decrypted only when the opening deadline
passes. Before opening, only metadata is visible. After opening, the customer sees the full bid;
participants see their own bids.

```
draft ── submit ──► submitted ── qualify (admit) ──► admitted ──► (auction) ──► winning / lost
                       │
                       ├── qualify (reject) ──► rejected
                       ├── withdraw ──► withdrawn
                       └── re-submit (before deadline) ──► replaces content, back to submitted
```

## Sealed content encryption

`BidPayloadCipher` (`src/Bid/`) encrypts the bid payload:

- Key: `sodium_crypto_generichash(ENCRYPTION_KEY)` (32 bytes).
- Cipher: `sodium_crypto_secretbox` (XSalsa20-Poly1305) with a fresh 24-byte nonce per payload.
- Stored as `bids.encrypted_payload` (BYTEA); plaintext is never stored before opening.
- On opening, the payload is decrypted and frozen into `bids.decrypted_payload` (JSON) — the
  encrypted column is left intact for audit.

```
plaintext payload ──json_encode──► json ──secretbox(nonce, key)──► nonce + ciphertext ──► BYTEA
```

## Bid entity & parts

`Bid` columns: `tender_id`, `lot_id` (null only for a tender without lots), `supplier_id`, `status`,
`encrypted_payload`, `decrypted_payload` (nullable until opening), `submitted_at`, `evaluated_at`,
`decision_reason`.

Bids are two-part: `BidPartEnum` = `PART_1` (consent/characteristics) and `PART_2` (documents).
Documents are linked through `BidDocument` (join entity with `part`, `is_encrypted`).

Unique constraint: one bid per supplier per `(tender, lot)` (`uniq_bids_tender_lot_supplier`).

## Submission

`POST /api/v1/tenders/{tenderId}/bids`

```
BidSubmitController (#[IsGranted(BidVoter::SUBMIT)])
   ▼
SubmitBidUseCase → BidService::submit()
   │  requireCompany · declared supplier must match actor company
   │  company must be active (CompanyAccessGuard)
   │  contract_holders access: active framework contract required (409 access_denied)
   │  tender must require a participation bid at all (bids_required, 409)
   │  tender must be ACCEPTING_BIDS and bids_end not passed
   │  lot_id required when the tender has lots (422)
   │  price ≥ 0 (minor units)
   │  duplicate check → if existing bid, replace instead
   ▼
new Bid → setEncryptedPayload(cipher->encrypt(payload)) → submit()
   ▼
BidTransaction::commitSubmitted   persist + flush + audit bid.submitted
```

Re-submission before the deadline **replaces** the existing bid: content is
re-encrypted and the status returns to `submitted`.

A bid on a tender that has lots must name the lot. Admission to trading is looked
up by the `(tender, lot, supplier)` triple (`BidRepository::isAdmitted`), so a
lot-less bid on such a tender could be submitted and even admitted, yet its
supplier would be turned away at the auction with «Only admitted participants».
`BidService::submit()` rejects it up front instead (422).

A tender created with `bids_required = false` takes no bids at all: it never enters
`accepting_bids` (it goes `published → bidding` directly), and submission, replacement
and withdrawal answer 409 `This tender does not require participation bids` — a distinct
message from «bid acceptance is closed», because in such a procedure the bid phase does
not exist rather than having ended. Trading is then open to anyone who can see the
tender; see [tenders.md](tenders.md#tenders-without-a-participation-bid).

## Withdrawal

`POST /api/v1/bids/{bidId}/withdraw`

```
WithdrawBidUseCase → BidService::withdraw()
   │  owner check (supplier matches actor company)
   │  tender still ACCEPTING_BIDS
   │  status must be submitted
   │  non-empty reason
   ▼
bid->withdraw(reason) → status withdrawn · audit bid.withdrawn
```

## Opening

At `bids_end`, a delayed `TimelineMessage` (see [tenders.md](tenders.md#timeline)) triggers
`BidOpeningService::open()`:

```
TimelineMessageHandler::openBids()
   ▼
BidOpeningService::open(tenderId)
   │  already opened (bids_opened_at set) → return        (idempotent)
   │  tender not ACCEPTING_BIDS → return
   │  only submitted bids are decrypted
   ▼
for each submitted bid: decrypt → set decrypted_payload
   │
   ▼
set bids_opened_at · outbox tender.opened · audit tender.opened
```

Withdrawn bids are never opened. `encrypted_payload` is not modified.

## Qualification

`POST /api/v1/bids/{bidId}/qualification`

```
BidQualifyController (#[IsGranted(BidVoter::QUALIFY)])
   ▼
QualifyBidUseCase → BidService::qualify()
   │  customer tenant check (bid belongs to actor company)
   │  decision: admit | reject (BidDecisionEnum), reason required
   │  status must be submitted
   ▼
admit → status admitted · reject → status rejected (decision_reason, evaluated_at)
   │  reject: notify all active supplier users by email
   ▼
BidTransaction::commitQualified   audit + outbox bid.qualified
```

## Admittance & results

- `BidRepository::isAdmitted(tender, lot, supplier)` — true when an `admitted` bid exists;
  exposed cross-module via `BidReadService` and used by the auction module to gate participants.
- `BidResultService::markResults(tender, lot, winnerSupplierId)` — marks admitted bids of the lot
  as `winning` / `lost`; called by the auction winner selection inside its transaction.

## Visibility rules

`BidPresenter`:

- Before opening: metadata only, `payload_encrypted: true`.
- After opening: customer sees full bid (`part1`, `part2_ref`, `price_minor`, `price_basis`,
  `vat_rate`); a participant sees only `part1`.
- `BidService::listForCompany`: the customer sees all; a participant always sees **own** bids
  (in any status) and, after opening, other suppliers' `submitted` bids on top. The "own bids
  always" part matters in practice: qualification moves a bid to `admitted`/`rejected`, and while
  the filter was `submitted`-only after opening, the author's own bid vanished from their own list
  together with the decision reason and the part-2 documents section.

## Related documents

- [auctions.md](auctions.md) — live trading and winner selection
- [tenders.md](tenders.md) — the timeline that schedules opening
- [database.md](database.md#bids) — bid entities and constraints
- [state-machines.md](state-machines.md#tender-workflow) — tender acceptance windows
