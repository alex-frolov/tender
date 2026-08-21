# API Reference

This document describes the REST JSON API: base conventions, authentication, errors, idempotency,
rate limiting, and the endpoint list grouped by domain.

## Base

- Base path: `/api/v1`
- Content type: `application/json` (except document upload/download).
- All mutating endpoints are protected by bearer authentication (JWT or API key).
- Money fields are **integer minor units** (e.g. `price_minor`), never floats.

## Authentication

- Access token: `Authorization: Bearer <jwt>` (short-lived, see
  [authentication.md](authentication.md)).
- API key: `Authorization: Bearer <api_key>` or `X-API-Key: <api_key>` (scoped).
- Public auth endpoints do not require a token.

## Errors

Errors follow the Problem Details shape with machine-readable codes:

```json
{ "title": "Conflict", "code": "state_transition_forbidden", "detail": "..." }
```

| HTTP | title | code (examples) | Source |
|---|---|---|---|
| 401 | Unauthorized | `invalid_credentials`, `invalid_verification_token`, `invalid_reset_token` | security component / auth endpoints |
| 403 | Forbidden | `forbidden` | security component |
| 404 | Not Found | `not_found`, `user_not_found`, `company_not_found` | domain services |
| 409 | Conflict | `conflict`, `state_transition_forbidden`, `idempotency_conflict`, `duplicate_bid`, `bid_rejected`, `auction_not_trade`, `duplicate_favorite`, `last_active_admin` | domain services |
| 422 | Unprocessable Entity | (validation) | form validation |
| 429 | Too Many Requests | (rate limit) | rate limiter |
| 500 | Internal Server Error | `event_schema_violation` | schema guard / unexpected |

`JsonApiExceptionSubscriber` converts any `ApiException` into the JSON body. The security component
(`ApiAccessDeniedHandler`) produces the 401/403 bodies before the controller runs.

```
error path
   │  service throws ApiException
   ▼
kernel.exception → JsonApiExceptionSubscriber
   │  build {title, code?, detail?}
   ▼
JSON response with HTTP status
```


## Idempotency

Mutations accept an `Idempotency-Key` header. Semantics:

1. **No header / GET** → request proceeds normally.
2. **New key** → key row is inserted, the mutation runs, the response is saved.
3. **Same key + same payload** → the saved response is returned (replay) without invoking the
   controller.
4. **Same key + different payload** → `409 idempotency_conflict`.
5. **5xx** → key is discarded so the client may retry.

```
request with Idempotency-Key
   │
   ▼
IdempotencyMiddleware (kernel.request)
   │  key exists?
   ├── yes, completed, same hash ──► replay saved response (controller not called)
   ├── yes, completed, diff hash ──► 409 idempotency_conflict
   ├── yes, expired ──► remove and treat as new
   └── no ──► insert key row (unique per tenant, concurrent insert → 409)
   │
   ▼
mutation runs → on response: 5xx → discard key · else → save status+body
```

The key is unique per tenant (anonymous mutations included). TTL `IDEMPOTENCY_TTL`; expired keys
are cleaned by `idempotency:cleanup`.

## Rate limiting

Global per-IP token bucket (`RATE_LIMIT_GLOBAL_PER_MIN`) plus per-route limiters:

| Limiter | Policy | Limit |
|---|---|---|
| `api_global` | token bucket | `RATE_LIMIT_GLOBAL_PER_MIN` per IP/min |
| `auction_bids` | sliding window | 10/s |
| `tender_reads` | sliding window | 100/min |
| `email_send` | fixed window | 5 per 10 min |

Responses include `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` and, on
exceeding, `429` + `Retry-After`.

## Pagination

The tender catalog uses keyset pagination: `?cursor=<opaque>` returns items plus a `next_cursor`
when more results exist. Other list endpoints use limit/offset-style parameters where supported.

```
page(cursor) → SELECT ... WHERE (created_at, id) < (:c, :i) ORDER BY created_at DESC, id DESC
   │  fetch limit+1
   ▼
has more? → next_cursor = base64url({c: created_at, i: id}) : null
```

---

## Endpoints by domain

### Auth

| Method | Path | Description |
|---|---|---|
| POST | `/auth/register` | register company + first admin user |
| POST | `/auth/token` | login (password, optional TOTP) → tokens |
| POST | `/auth/refresh` | rotate refresh token → new tokens |
| POST | `/auth/logout` | revoke current refresh token |
| POST | `/auth/email/verify` | verify email with token |
| POST | `/auth/email/resend` | resend verification email |
| POST | `/auth/password/forgot` | request password reset |
| POST | `/auth/password/reset` | reset password with token |
| POST | `/auth/2fa/setup` | start TOTP setup → secret + otpauth uri |
| POST | `/auth/2fa/confirm` | confirm and enable TOTP |
| POST | `/auth/2fa/disable` | disable TOTP (requires valid code) |

### Users / companies / permissions

| Method | Path | Description |
|---|---|---|
| POST | `/users` | invite a user (admin) |
| GET | `/users` | list users (admin) |
| PATCH | `/users/{userId}` | update user (admin) |
| DELETE | `/users/{userId}` | soft-delete user (admin) |
| POST | `/companies/{companyId}/verify` | approve/reject/suspend company (platform admin) |
| GET | `/permissions` | permission catalog (platform admin) |
| GET | `/role-permissions` | role permission matrix (platform admin) |
| PUT | `/role-permissions` | update role permissions (platform admin) |

### Tenders & lots

| Method | Path | Description |
|---|---|---|
| POST | `/tenders` | create tender with lots |
| GET | `/tenders` | list tenders (keyset cursor) |
| GET | `/tenders/{tenderId}` | get tender |
| PATCH | `/tenders/{tenderId}` | update tender |
| POST | `/tenders/{tenderId}/publish` | publish (computes timeline) |
| POST | `/tenders/{tenderId}/withdraw` | withdraw before accepting bids |
| POST | `/tenders/{tenderId}/cancel` | cancel with reason |
| GET | `/tenders/{tenderId}/access` | check participation access |
| POST | `/tenders/{tenderId}/rating` | rate closed tender |
| GET | `/tenders/{tenderId}/questions` | questions and answers |
| POST | `/tenders/{tenderId}/questions` | ask a question |
| POST | `/tenders/{tenderId}/questions/{questionId}/answer` | answer a question (customer) |
| POST | `/tenders/{tenderId}/complaints` | file a complaint |
| GET | `/complaints` | complaints filed by the company and against its tenders |

### Bids

| Method | Path | Description |
|---|---|---|
| POST | `/tenders/{tenderId}/bids` | submit a sealed bid |
| GET | `/tenders/{tenderId}/bids` | list bids (visibility per opening) |
| POST | `/bids/{bidId}/qualification` | admit / reject a bid |
| POST | `/bids/{bidId}/withdraw` | withdraw a bid |
| POST | `/bids/{bidId}/documents` | set the documents of bid part 2 |

### Auctions

| Method | Path | Description |
|---|---|---|
| POST | `/auctions` | create auction |
| PATCH | `/auctions/{auctionId}` | update auction |
| GET | `/auctions/{auctionId}/state` | current auction state |
| GET | `/auctions/{auctionId}/stream` | SSE discovery (hub, topic, token, state) |
| POST | `/auctions/{auctionId}/bids` | place a live bid |
| GET | `/auctions/{auctionId}/bids` | bid history |
| POST | `/auctions/{auctionId}/schedule` | schedule trading start |
| POST | `/auctions/{auctionId}/finish` | finish trading |
| POST | `/auctions/{auctionId}/winner` | choose winner (automatic/manual) |
| POST | `/auctions/{auctionId}/cancel` | cancel auction |
| POST | `/auctions/{auctionId}/start-work` | start execution work |
| POST | `/auctions/{auctionId}/mark-done` | mark done by performer |
| POST | `/auctions/{auctionId}/confirm-done` | confirm done by customer |

### Contracts & claims & security

| Method | Path | Description |
|---|---|---|
| POST | `/contracts` | create contract |
| GET | `/contracts` | list contracts |
| GET | `/contracts/{contractId}` | get contract |
| POST | `/contracts/{contractId}/send-for-signature` | send for signature |
| POST | `/contracts/{contractId}/sign` | sign (own party) |
| POST | `/contracts/{contractId}/scan` | upload contract scan |
| POST | `/contracts/{contractId}/tenders` | bind a tender |
| GET | `/contract-types` | list contract types |
| POST | `/contract-types` | create contract type |
| GET | `/claims` | list claims of the actor's company (both sides) |
| POST | `/claims` | create a claim |
| POST | `/claims/{claimId}/resolve` | resolve a claim |
| GET | `/securities` | list security deposits of the actor's company |
| POST | `/securities/{securityId}/forfeit` | forfeit security |
| POST | `/securities/{securityId}/release` | release security |

### Documents

| Method | Path | Description |
|---|---|---|
| GET | `/documents` | documents of one entity (entity_type + entity_id) |
| POST | `/documents` | upload document (multipart) |
| GET | `/documents/{documentId}` | get document metadata |
| GET | `/documents/{documentId}/download` | download current version |
| GET | `/document-types` | list document types |
| POST | `/document-types` | create document type |
| PUT | `/document-types/{documentTypeId}` | update document type |
| DELETE | `/document-types/{documentTypeId}` | deactivate document type |

### Platform: api keys, webhooks, exports, notifications, favorites, saved searches, analytics

| Method | Path | Description |
|---|---|---|
| GET | `/api-keys` | list API keys |
| POST | `/api-keys` | create API key |
| DELETE | `/api-keys/{apiKeyId}` | revoke API key |
| POST | `/api-keys/{apiKeyId}/rotate` | rotate API key secret |
| GET | `/webhooks` | list webhook subscriptions |
| POST | `/webhooks` | create webhook subscription |
| PATCH | `/webhooks/{webhookId}` | update webhook subscription |
| DELETE | `/webhooks/{webhookId}` | delete webhook subscription |
| GET | `/webhooks/{webhookId}/deliveries` | list webhook deliveries |
| POST | `/webhooks/{webhookId}/rotate-secret` | rotate webhook secret |
| POST | `/exports` | create export job (202) |
| GET | `/exports/{jobId}` | export job status |
| GET | `/exports/{jobId}/download` | download ready export |
| GET | `/notifications/subscriptions` | list notification subscriptions |
| POST | `/notifications/subscriptions` | create subscription |
| DELETE | `/notifications/subscriptions/{subscriptionId}` | delete subscription |
| POST | `/notifications/subscriptions/{subscriptionId}/toggle` | toggle subscription |
| GET | `/favorites` | list favorites |
| POST | `/favorites` | add favorite |
| DELETE | `/favorites?favoriteId=...` | remove favorite |
| GET | `/saved-searches` | list saved searches |
| POST | `/saved-searches` | create saved search |
| DELETE | `/saved-searches?savedSearchId=...` | delete saved search |
| GET | `/dashboard` | dashboard summary |
| GET | `/stats/tenders` | tender statistics |

### Health

| Method | Path | Description |
|---|---|---|
| GET | `/health/live` | liveness (app + db + redis) |
| GET | `/health/ready` | readiness (+ rabbitmq) |

## Full contract

This document mirrors the endpoint map and conventions implemented in `src/*/Controller/`. Route
paths are defined as `public const string URL` on each controller and are referenced by tests.
Request bodies are validated through form types in `src/{Module}/Form/`, and the JSON body schemas
follow the OpenAPI 3.1 specification that describes this API.
