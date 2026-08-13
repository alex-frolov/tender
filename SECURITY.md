# Security Policy

Tender Platform is a B2B procurement backend. Security is a shared responsibility: if you find a
security issue, report it privately so that installations can be protected before the problem
becomes public.

## Reporting a vulnerability

**Do not open a public issue, pull request or discussion for a security problem.** A public report
exposes the issue to everyone while installations are still unpatched.

Instead, report it privately. The preferred channel is a private report through the repository's
security advisory flow (GitHub → *Security* → *Report a vulnerability*). Only the maintainers can
see it, and you can attach a suggested patch.

```
reporter                        maintainers
   │                                 │
   ├── private advisory ────────────►│  review
   │   (no public issue/PR/discussion)│
   │                                 │  fix on main
   │                                 │
   └── credited in advisory ◄────────┘  (unless you prefer not to be named)
```

You can expect a first response within a few days. Once a fix is available, the advisory is
published and you are credited unless you prefer not to be named.

## Supported versions

Security fixes are applied to the `main` branch. There are no backports to older releases;
updating to the current state is the only way to receive a security fix.

| Version | Supported |
|---|---|
| `main` branch | :white_check_mark: |
| older releases | :x: |

## Scope

The application follows these security practices as part of its design:

- **Sealed bids**: bid content is encrypted at rest (sodium XSalsa20-Poly1305) and decrypted only
  at the opening deadline (`docs/bids.md`).
- **Credentials**: access tokens are short-lived JWT; refresh tokens and API keys are stored as
  SHA-256 hashes and rotated; email/password tokens are single-use with TTL
  (`docs/authentication.md`).
- **Tenant isolation**: each company is a tenant; module services resolve objects within the
  caller's tenant and return 404 for foreign entities (`docs/architecture.md`).
- **Signing**: webhook deliveries are signed with HMAC-SHA256 and include the event id
  (`docs/integrations.md`).
