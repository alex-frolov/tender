# Authentication & Authorization

This document describes how requests are authenticated and authorized in the application.

## Overview

Authentication is handled by a custom JWT mechanism (`App\Iam\Service\AuthMiddleware`) that puts an
authenticated token into the security `TokenStorage`. Authorization is handled declaratively by the
security component: controllers use `#[IsGranted(...)]`, and voters read the user from the token.
The firewall is stateless and defines no URL-level `access_control`.

```
                     Authentication (custom)               Authorization (security component)
HTTP request  ──►   AuthMiddleware ──► TokenStorage  ──►   #[IsGranted] ──► Voters ──► allow/deny
```

Two credential types are supported, both carried as `Authorization: Bearer <token>` (or
`X-API-Key`):

1. **JWT access token** — issued by `POST /api/v1/auth/token` / `refresh`.
2. **API key** (personal access token) — issued by `POST /api/v1/api-keys`, scoped.

## Credentials

### Access token (JWT)

- HS256 via `lcobucci/jwt`, signed with `AUTH_JWT_SECRET`, TTL `AUTH_ACCESS_TTL` (default 900 s).
- Claims: issuer `tender-platform`, `sub` = user id, `role`, `org` = company id (when present),
  `jti`, `iat`, `nbf`, `exp`.
- Access tokens are **stateless and not revocable**; safety comes from the short TTL plus a
  per-request user-status check in `AuthMiddleware`.

```
JWT payload
┌──────────────────────────────────────────────┐
│ iss: tender-platform                         │
│ sub: {userId}                                │
│ role: {role}                                 │
│ org: {companyId}   (when set)                │
│ jti, iat, nbf, exp                           │
└──────────────────────────────────────────────┘
```

### Refresh token

- Opaque `bin2hex(random_bytes(32))`; the database stores only its SHA-256 hash.
- TTL `AUTH_REFRESH_TTL` (default 30 days).
- **Rotated on every refresh** (old token revoked, new issued).
- Revoked in bulk on password reset, user blocking and soft-delete.

### API key

- Raw token `key_` + 32 random bytes hex; the database stores only the SHA-256 hash.
- Carries **scopes**; every permission check is narrowed to the key's scopes
  (`ScopedPermissionChecker`, `ApiKeyScopeMap`).
- Owned by a user; can be rotated and revoked.

## Authentication flows

### Login (token)

```
POST /api/v1/auth/token  {email, password, totp_code?}
   │
   ▼
AuthenticationService::authenticate()
   │  email exists? user deleted/blocked? email verified?
   │  password valid? (if 2FA enabled) totp valid?
   ├── failure ──► 401 invalid_credentials
   ▼
issueTokens(): access_token + refresh_token
   │  markLastLogin() · audit auth.login
   ▼
{access_token, refresh_token, expires_in, token_type: Bearer}
```

### Refresh

```
POST /api/v1/auth/refresh  {refresh_token}
   │
   ▼
RefreshTokenService::findActive(hash)  → not found/revoked/expired ──► 401
   │
   ▼
rotate(): revoke old, issue new refresh + fresh access token (reloaded user data)
   │
   ▼
audit auth.refresh → {access_token, refresh_token}
```

### Logout

`POST /api/v1/auth/logout` revokes the current refresh token (idempotent) and writes
`auth.logout`.

### Registration → verification → approval

```
POST /api/v1/auth/register
   │  duplicate INN ──► 409 conflict
   ▼
create Company (status pending) + first User (role admin)
   │
   ▼
issue email verification token + send email (email_verify)
   │
   ▼
POST /api/v1/auth/email/verify  {token}
   │  single-use, TTL EMAIL_VERIFY_TTL
   ▼
user.emailVerifiedAt set · status active
   │
   ▼
company pending ──► super-admin approves ──► company active
   (POST /api/v1/companies/{id}/verify)
```

### Password reset

```
POST /api/v1/auth/password/forgot {email}
   │  never discloses existence (202 not_found), rate-limited email_send
   ▼
issue reset token + email  (TTL PASSWORD_RESET_TTL)
   │
   ▼
POST /api/v1/auth/password/reset {token, password}
   │  single-use, revokes ALL refresh tokens for the user
   ▼
audit auth.password.reset (sessions_revoked: true)
```

### Two-factor (TOTP)

- TOTP per RFC 6238 (HMAC-SHA1, 6 digits, 30 s period, ±1 window) implemented in
  `App\Shared\Totp\TotpService`.
- `POST /api/v1/auth/2fa/setup` → returns `{secret, otpauth_uri}` (once).
- `POST /api/v1/auth/2fa/confirm {code}` → enables 2FA on the user.
- `POST /api/v1/auth/2fa/disable {code}` → requires a valid code (proof of possession).
- When 2FA is enabled, `totp_code` is required at login.

## Request authentication pipeline

Middleware order (kernel.request priorities):

```
priority 100  RateLimitMiddleware     global token bucket → 429 + Retry-After
priority  95  ApiKeyAuthMiddleware    X-API-Key / non-JWT Bearer → API key auth
priority  90  AuthMiddleware          JWT Bearer → user load + status check
```

`AuthMiddleware`:

1. Skips public prefixes (`/api/v1/auth/*`, `/health`, `/api/doc`).
2. Requires `Authorization: Bearer <jwt>`; otherwise the request stays anonymous.
3. Validates the JWT signature and time window; failure → anonymous.
4. Loads the user from the DB and rejects deleted / `blocked` / `email_pending` / `invited`.
5. Sets request attributes `_auth_user` (the `User`) and `_auth` (`AuthContext`).
6. Puts a `UsernamePasswordToken` into the `TokenStorage` so voters see an authenticated user.

## Authorization model

The security firewall is stateless (`pattern: ^/`) with `ApiAccessDeniedHandler` as entry point and
access-denied handler, and `access_decision_manager` strategy **affirmative**
(`allow_if_all_abstain: false`).

### Role hierarchy

```
platform_admin  (3)
    ▲
admin           (2)
    ▲
manager         (1)
    ▲
agent           (0)
```

`UserRoleVoter` grants a `#[IsGranted(UserRoleEnum::X->value)]` attribute when the user's level is
>= the required level. An array means "any of" (affirmative).

### Permission voters

Actions that have a permission code are gated by a **permission voter** that delegates to
`PermissionCheckerInterface::can(user, code)`:

```php
#[IsGranted(TenderVoter::CREATE)]
```

`PermissionCheckService` resolves the code: `admin` / `platform_admin` always pass; `manager` /
`agent` use the `role_permissions` matrix cached in Redis
(`role_permissions:enabled`, cleared on update). Deny-by-default.

When the request is authenticated with an **API key**, the interface is decorated by
`ScopedPermissionChecker`, which denies any code not covered by the key's scopes.

### Voters

All voters live in `src/Security/` and auto-register via the `security.voter` tag:

| Voter | Guards |
|---|---|
| `UserRoleVoter` | role attribute, hierarchy |
| `TenderVoter` | `tenders.create/update/publish/withdraw/cancel/board.view/rate` |
| `BidVoter` | `bids.submit/withdraw/qualify`, `tenders.board.view` |
| `AuctionVoter` | `auction.control/bid/choose_winner` |
| `ClaimVoter` | `claims.manage` |
| `DocumentVoter` | `tenders.manage_docs`, `tenders.board.view` |
| `SecurityVoter` | `bids.submit`, `contracts.create` (any) |
| `ExecutionVoter` | `execution.manage`, `auction.control` |
| `ApiKeyVoter` | `api_keys.manage` |
| `WebhookVoter` | `webhooks.manage` |
| `DashboardVoter` | `dashboard.view` |
| `ExportVoter` | `exports.export` |
| `NotificationVoter` | `notifications.subscribe` |
| `SavedSearchVoter` | `search.save`, `favorites.manage` |
| `CompanyVoter` | object voter for company verification (platform admin) |
| `AuctionStreamVoter` | auction SSE subscribe/view: platform admin, tenant owner, or admitted participant |
| `ContractVoter` | contract create/bind; sign allowed by permission or supplier party |

### Tenant isolation

Permission voters do not check object ownership; that stays in the service layer. Services resolve
objects within the caller's tenant and return 404 for foreign entities (e.g.
`TenderService::resolveTender`).

## Access responses

`ApiAccessDeniedHandler` produces the JSON contract:

- not authenticated → `401 {title: Unauthorized, code: invalid_credentials}`
- authenticated but insufficient rights → `403 {title: Forbidden, code: forbidden, detail: ...}`

## Company verification

`companies.verification_status` is a workflow (`company_verification`):
`pending → active / rejected / suspended`. Super-admin approves/rejects/suspends through
`CompanyVerificationService`, driven by `WorkflowInterface`. See
[state-machines.md](state-machines.md).

```
                approve ────────────────►  active
pending ──► ┌──────────┐                 ▲    │
            │ decision │                 │    │ suspend
            └──────────┘                 │    ▼
                │ reject              suspended
                ▼                      │    │
             rejected ◄────────────────┘    │
                                           │ approve / reject
                                           ▼
                                       (back to active / rejected)
```
