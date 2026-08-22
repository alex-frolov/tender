# Database

> Query performance: index coverage, growth behaviour and optimisation proposals are
> collected in [db-query-audit.md](db-query-audit.md).

This document describes the data model: entities, relationships, enums and the table layout.

## Conventions

- UUID v4 surrogate keys for business entities; bigint identities for reference/dictionary and
  technical tables.
- Money is stored as integer minor units (`*_minor` bigint).
- Status fields are workflow-managed (`status`, `verification_status`).
- Polymorphic references (string `entity_type` + `entity_id`) exist for `documents`,
  `securities` and `contract_documents`.
- Tenancy: most business tables carry `tenant_id` (company). Services enforce tenant isolation at
  the application level (404 for foreign entities).

## ER overview

```
companies (tenant) 1──N users
users 1──N refresh_tokens · email_verification_tokens · password_reset_tokens
      ├──N api_keys · favorites · saved_searches
      └──N notification_subscriptions · notification_digest_items
companies 1──N tenders (tenant/customer) · bids (supplier) · contracts · securities
              · claims · webhooks · analytics_counters
tenders 1──N lots
tenders 1──N bids  ──N bid_documents ──N documents
lots    1──1 auctions  ──N auction_bids
tenders 1──N auctions
auctions ──N contract_tenders
contracts 1──N contract_tenders ──N contract_stages
contracts 1──N claims ──N documents
contracts 1──N contract_documents ──N documents
documents 1──N document_versions · documents N──1 document_types
contracts N──1 contract_types
```

## Entities by module

### Iam (identity)

| Table | Entity | Notes |
|---|---|---|
| `users` | `App\Iam\Entity\User` | id uuid, company_id, email (unique), name, role, verification_status, locale, password_hash, email_verified_at, two_factor_enabled, totp_secret, last_login_at, deleted_at, masked_email |
| `companies` | `Company` | id uuid, type (customer/supplier/both), legal_name, inn (unique), kpp, ogrn, address, contacts, verification_status, verified_at, timezone_default |
| `permissions` | `Permission` | bigint id, code (unique), name, group, description (catalog) |
| `role_permissions` | `RolePermission` | bigint id, role (manager/agent), permission_code, enabled, is_default; unique (role, permission_code) |
| `refresh_tokens` | `RefreshToken` | user_id, token_hash (unique), expires_at, revoked_at, ip, user_agent |
| `email_verification_tokens` | `EmailVerificationToken` | user_id, token_hash (unique), expires_at, used_at |
| `password_reset_tokens` | `PasswordResetToken` | user_id, token_hash (unique), expires_at, used_at |

### Tenders

| Table | Entity | Notes |
|---|---|---|
| `tenders` | `App\Tender\Entity\Tender` | tenant_id, number, title, procedure_type, law_type, nmck_minor, no_start_price, currency, vat_rate_bps, price_basis, customer_id, region, access_type, required_contract_type_id, status, execution_rating, cancellation_*, bids_opened_at, timeline (json), security_required, bids_required, national_regime (json), created_by, version |
| `lots` | `Lot` | tender_id (FK), number, title, price_net_minor, price_gross_minor, vat_rate_bps, price_basis, currency, quantity, unit, delivery_terms (json), execution_start_at, trade_end_lead_hours, security_percent, status, winner_bid_id |

Indexes: `tenders (tenant,status)`, `tenders (tenant,customer)`, catalog keyset on
`(created_at,id)`.

### Bids

| Table | Entity | Notes |
|---|---|---|
| `bids` | `App\Bid\Entity\Bid` | tenant_id, tender_id, lot_id, supplier_id, status, encrypted_payload (bytea), decrypted_payload (json, null until opening), submitted_at, evaluated_at, decision_reason; unique (tender_id, lot_id, supplier_id) |
| `bid_documents` | `BidDocument` | bid_id (FK), document_id, part (1/2), is_encrypted |

### Auctions

| Table | Entity | Notes |
|---|---|---|
| `auctions` | `App\Auction\Entity\Auction` | tenant_id, tender_id, lot_id, type, step_mode, no_start_price, status, scheduled_start_at, paused_at, paused_remaining_sec, start_price_minor, current_price_minor, bid_step_minor, bid_step_percent_bps, price_min_limit_minor, price_max_limit_minor, trade_end_lead_hours, price_basis, vat_rate_bps, step_duration_sec, started_at, planned_end_at, actual_end_at, winner_bid_id, extensions_count, max_extensions, rules_snapshot (json), version; unique (tender_id, lot_id) |
| `auction_bids` | `AuctionBid` | auction_id (FK), bidder_id, round, price_minor, price_display_minor, price_basis, vat_rate_bps, is_first_price, rounding_log (json), placed_at, status, reason, idempotency_key; unique (auction_id, bidder_id, round) and (auction_id, idempotency_key) |

### Contracts

| Table | Entity | Notes |
|---|---|---|
| `contracts` | `App\Contract\Entity\Contract` | tenant_id, number, contract_type_id, customer_id, supplier_id, source, award_id, price_net_minor, price_gross_minor, vat_rate_bps, price_basis, currency, status, scope, valid_from, valid_to, signed_at, registered_at, terminated_at, terms (json), signed_by_customer, signed_by_supplier, signature_customer, signature_supplier, version |
| `contract_types` | `ContractType` | bigint id, code (unique), name, default_scope, description, active (reference) |
| `contract_tenders` | `ContractTender` | contract_id (FK, cascade), tender_id, lot_id, award_id, price_net_minor, price_gross_minor, vat_rate_bps, status; unique (contract_id, tender_id, lot_id) |
| `contract_stages` | `ContractStage` | contract_tender_id, number, title, amount_minor, due_at, status, acceptance_docs_refs (json), accepted_at |
| `contract_documents` | `ContractDocument` | contract_id, document_id, uploaded_by; unique (contract_id, document_id) |
| `securities` | `Security` | tenant_id, kind (bid/contract), entity_type, entity_id (polymorphic), supplier_id, type (blocked_funds/guarantee), amount_minor, basis (nmck/first_bid), basis_amount_minor, currency, status, valid_until, external_ref |
| `claims` | `Claim` | tenant_id, contract_id, auction_id, supplier_id, customer_id, stage, reason, description, amount_minor, status, resolution, resolved_by, resolved_at, documents_refs (json) |

### Documents

| Table | Entity | Notes |
|---|---|---|
| `documents` | `App\Document\Entity\Document` | document_type_id (FK), entity_type (tender/lot/bid/contract/claim), entity_id, title, owner_role, visibility, scope, is_auto_generated, tenant_id, created_by |
| `document_types` | `DocumentType` | bigint id, code (unique), name, description, owner_role, visibility, required, auto_generated, active, sort_order (reference) |
| `document_versions` | `DocumentVersion` | document_id (FK), version, sha256, size_bytes, mime_type, original_name, storage_path, uploaded_by, uploaded_at |

### Notifications / favorites / saved searches

| Table | Entity | Notes |
|---|---|---|
| `notification_subscriptions` | `NotificationSubscription` | user_id, tenant_id, channel (email/webhook/telegram), events (json), filters (json), digest, active |
| `notification_digest_items` | `NotificationDigestItem` | user_id, event_id, event_type, occurred_at, payload, sent_at; unique (user_id, event_id) |
| `favorites` | `Favorite` | user_id, tenant_id, entity_type (tender/lot), entity_id, note; unique (user_id, entity_type, entity_id) |
| `saved_searches` | `SavedSearch` | user_id, tenant_id, name, filters (json), digest_period, active |

### Platform

| Table | Entity | Notes |
|---|---|---|
| `api_keys` | `App\Platform\Entity\ApiKey` | tenant_id, user_id, name, token_hash (unique), scopes (json), expires_at, last_used_at, revoked_at |
| `webhooks` | `Webhook` | tenant_id, url, secret, events (json), filters (json), status (active/paused) |
| `webhook_deliveries` | `WebhookDelivery` | webhook_id (FK, cascade), event_id, event_type, payload (text), status (pending/delivered/failed/dead), attempts, next_retry_at, last_http_status, last_error, delivered_at; unique (webhook_id, event_id) |

### Analytics / export

| Table | Entity | Notes |
|---|---|---|
| `analytics_counters` | `App\Analytics\Entity\AnalyticsCounter` | bigint id, tenant_id, metric, period, dimension (json), value; unique (tenant_id, metric, period, dimension) |
| `export_jobs` | `App\Export\Entity\ExportJob` | tenant_id, export_type (tenders/bids/contracts), format (xlsx/csv), filters (json), status (queued/processing/ready/failed), storage_path, file_name, file_size, error, requested_by, started_at, completed_at |

### Technical entities (Shared)

| Table | Entity | Notes |
|---|---|---|
| `outbox_events` | `App\Shared\Entity\OutboxEvent` | bigint id, tenant_id, event_type, payload (json), aggregate_type, aggregate_id, status (pending/published), created_at, published_at |
| `idempotency_keys` | `App\Shared\Entity\IdempotencyKey` | bigint id, tenant_id, key, method, path, request_hash, response_status, response_body (json), created_at, expires_at |
| `audit_log` | `App\Shared\Entity\AuditLog` | bigint id, tenant_id, actor_type, actor_id, action, entity_type, entity_id, before (json), after (json), created_at, ip, request_id, timezone (append-only) |

## Enums

### Iam

| Enum | Cases |
|---|---|
| `UserRoleEnum` | `admin`, `manager`, `agent`, `platform_admin` |
| `UserStatusEnum` | `invited`, `email_pending`, `active`, `blocked` |
| `CompanyTypeEnum` | `customer`, `supplier`, `both` |
| `CompanyStatusEnum` | `pending`, `active`, `rejected`, `suspended` |
| `RolePermissionRoleEnum` | `manager`, `agent` |
| `LocaleEnum` | `ru`, `en` |

### Tender

| Enum | Cases |
|---|---|
| `TenderStatusEnum` | `draft`, `published`, `withdrawn`, `accepting_bids`, `bidding`, `evaluation`, `awarding`, `contract`, `closed`, `cancelled` |
| `LotStatusEnum` | `draft`, `published`, `accepting_bids`, `bidding`, `evaluation`, `awarding`, `contract`, `closed`, `cancelled` |
| `ProcedureTypeEnum` | `auction`, `competition`, `rfq`, `rfp`, `direct` |
| `LawTypeEnum` | `fz44`, `fz223`, `commercial` |
| `PriceBasisEnum` | `net`, `gross` |
| `AccessTypeEnum` | `open`, `contract_holders` |
| `CancellationReasonEnum` | `cancellation_needs`, `changing_order_conditions`, `carrier_refusal`, `other` |

### Auction

| Enum | Cases |
|---|---|
| `AuctionStatusEnum` | `created` (virtual), `draft`, `agreement`, `new`, `scheduled`, `trade`, `paused`, `choice`, `approve`, `in_work`, `done_by_performer`, `done`, `claim`, `done_by_claim`, `cancelled`, `expired`, `deleted` |
| `AuctionTypeEnum` | `reduction`, `free_price`, `price_request` |
| `AuctionStepModeEnum` | `fixed`, `free` |
| `AuctionBidStatusEnum` | `accepted`, `rejected` |

### Bid

| Enum | Cases |
|---|---|
| `BidStatusEnum` | `draft`, `submitted`, `withdrawn`, `admitted`, `rejected`, `winning`, `lost` |
| `BidPartEnum` | `PART_1 = 1`, `PART_2 = 2` |
| `BidDecisionEnum` | `admit`, `reject` |

### Contract

| Enum | Cases |
|---|---|
| `ContractStatusEnum` | `draft`, `pending_signature`, `signed`, `registered`, `terminated`, `expired`, `deleted` |
| `ContractScopeEnum` | `single_use`, `multi_use` |
| `ContractSourceEnum` | `tender`, `external` |
| `ContractTenderStatusEnum` | `pending`, `in_work`, `done_by_performer`, `done`, `claim`, `done_by_claim`, `terminated` |
| `SecurityKindEnum` | `bid`, `contract` |
| `SecurityTypeEnum` | `blocked_funds`, `guarantee` |
| `SecurityStatusEnum` | `pending`, `active`, `released`, `forfeited` |
| `SecurityBasisEnum` | `nmck`, `first_bid` |
| `ClaimStatusEnum` | `draft`, `submitted`, `resolved_rejected`, `resolved_accepted`, `cancelled` |
| `ClaimStageEnum` | `approve`, `in_work`, `done_by_performer` |

### Document

| Enum | Cases |
|---|---|
| `DocumentEntityType` | `tender`, `lot`, `bid`, `contract`, `claim` |
| `DocumentOwnerRole` | `customer`, `executor`, `system` |
| `DocumentVisibility` | `public`, `private` |
| `DocumentScope` | `tender`, `contract` |

### Other modules

| Enum | Cases |
|---|---|
| `AnalyticsMetricEnum` | `tenders_total`, `tenders_by_status`, `bids_total`, `bids_by_status`, `auctions_total`, `avg_price_reduction`, `contracts_total`, `contracts_amount_sum`, `execution_rating_avg` |
| `ExportTypeEnum` | `tenders`, `bids`, `contracts` |
| `ExportFormatEnum` | `xlsx`, `csv` |
| `ExportJobStatusEnum` | `queued`, `processing`, `ready`, `failed` |
| `FavoriteEntityTypeEnum` | `tender`, `lot` |
| `NotificationChannelEnum` | `email`, `webhook`, `telegram` |
| `WebhookStatusEnum` | `active`, `paused` |
| `WebhookDeliveryStatusEnum` | `pending`, `delivered`, `failed`, `dead` |
| `SavedSearchDigestPeriodEnum` | `none`, `daily`, `weekly` |
| `OutboxEventStatusEnum` | `pending`, `published` |

## Migrations

All schema changes are applied via Doctrine migrations in `migrations/`. Reference data
(`document_types`, `permissions`, `contract_types`, `role_permissions`) is seeded by idempotent
data migrations (not fixtures).

## Related documents

- [events.md](events.md) — outbox/idempotency/audit entities and flows
- [architecture.md](architecture.md) — module layout and boundaries
