# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Authoritative conventions

`AGENTS.md` (Russian, in this directory) is the detailed rulebook for this codebase — module
skeleton, form/DTO patterns, voter patterns, error handling, factory rules. **Read the relevant
section of `AGENTS.md` before changing code in an area you have not touched yet.** This file is a
condensed English orientation, not a replacement.

Design docs live one level up, in the parent repo (this project is the `app/` subdirectory):
`../architecture/` (modular-monolith.md, modules.md, adr/), `../domain/` (permissions.md,
role-matrix.md, `*-state-machine.md`, data-model.md), `../api/openapi.yaml`. In-repo docs: `docs/`.

## Environment & commands

Everything PHP runs **inside the `app` Docker container** — never on the host.

```bash
docker compose up -d                                     # dev stack (web:8080, db:54329, redis:56379,
                                                         # rabbitmq:55672, mercure:3008, mailpit:8025,
                                                         # worker, webhooks, scheduler)
docker compose exec -T app php bin/console <cmd>
docker compose exec -T app composer <script>
```

`Makefile` wraps these (`make up`, `make console CMD="..."`, `make test`, `make quality`, …).

**Dev and test databases are separate** (`tender` vs `tender_test`). Every new migration must be
applied to both, or the whole suite fails with `Undefined column`:

```bash
docker compose exec -T app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec -T app php bin/console doctrine:migrations:migrate --no-interaction --env=test
```

### Quality gate (run before handing work back)

```bash
docker compose exec -T app composer lint            # php -l
docker compose exec -T app composer cs-fixer:check  # PHP CS Fixer dry-run (composer cs-fixer to apply)
docker compose exec -T app composer phpstan         # level max, src + tests
docker compose exec -T app composer arkitect        # layers + module boundaries
docker compose exec -T app composer test            # PHPUnit (drops/recreates test DB first)
docker compose exec -T app composer quality         # all of the above
```

PHPStan OOMs at the container's default `memory_limit`; run it as
`php vendor/bin/phpstan analyse --no-progress --memory-limit=1G` (that is what `make phpstan` does).

### Tests

```bash
composer test              # full suite, sequential; runs test:prepare (drop+create+migrate) first
composer test:parallel     # ParaTest, smoke/load excluded
composer test:smoke        # smoke/load group, sequential, after test:parallel
composer test:unit         # tests/Unit only
composer test:coverage     # pcov + ≥80% line gate (scripts/check-coverage.php)
php bin/phpunit tests/Functional/Auction/AuctionListTest.php          # single file, no DB reset
php bin/phpunit --filter testSomething tests/Unit/Money/MoneyTest.php # single test
```

## Architecture

Modular monolith on Symfony 8.1 / PHP 8.4+ / PostgreSQL 17 / Redis 7 / RabbitMQ 4 / Mercure.
API-first: REST JSON under `/api/v1`, stateless JWT (or `X-API-Key`), RFC 9457 error bodies.

### Module layout

Every top-level `src/{Module}/` is a vertical slice owning its own entities:

```
src/{Module}/
  Controller/   thin HTTP adapters — ONE controller = ONE route
  UseCase/      application layer — one final class per user action, single execute()
  Service/      domain services (implementations of the module's root contracts)
  Entity/       module-owned entities + Entity/Enum/
  Repository/   module-owned repositories
  Form/ Input/  form types + input DTOs
  Presenter/    response shaping
  Exception/    domain exceptions implementing ApiException
  {Contract}.php  root-level interfaces = the module's PUBLIC API
```

Business modules: `Iam`, `Tender`, `Bid`, `Auction`, `Contract`, `Document`, `Notification`,
`Favorite`, `SavedSearch`, `Complaint`, `ProcurementPlan`, `Question`, `Supplier`.
Platform modules: `Platform` (API keys, webhooks), `Analytics`, `Export`.
Policy plugin: `RuStateProcurement` (44-ФЗ/223-ФЗ rules behind core contracts, toggled by
`PROCUREMENT_PLUGIN_ENABLED`; values from `config/ru_state_procurement.yaml`).
Cross-cutting: `src/Shared/` (money, outbox/events, audit, idempotency, API exception core,
`PaginatorForm`), `src/Security/` (voters, access-denied handler), `src/Infrastructure/`
(framework glue: HTTP subscribers, messenger, Redis, AMQP, console commands),
`src/Controller/` (only `AbstractBaseController` + `HealthController`).

There are **no** global `src/Entity/` or `src/Repository/`.

### Module boundaries (enforced by PHPArkitect, `phparkitect.php`)

Rule 6 forbids reaching into another module's `Controller/Command/Entity/Repository/Form/Input/
Presenter/Exception/Storage/Rules/State/Stream/Timer/Step/Timeline/Service`. Allowed cross-module
dependencies: the owner module's **root-level public contracts and UseCases**, `App\Shared`, enums
as value types, and the explicit whitelists `$publicContractExcludes` / `$readModelExcludes` in
`phparkitect.php`. New cross-module access to internals requires editing that whitelist — treat that
as a design decision, not a shortcut.

**Public contract pattern:** interface at the module root (`App\Tender\TenderReadService`),
implementation in `App\Tender\Service\TenderReadService` (`implements TenderReadService` via a
`use ... as TenderReadServiceContract` alias). Consumers type-hint the interface; autowiring resolves
the single implementation.

### Request flow and conventions

`Controller (validate form) → UseCase::execute(Input DTO) → Service → Entity/Repository → Presenter`

- Route path is `public const string URL` (full path incl. `/api/v1`), used in `#[Route(self::URL)]`
  and referenced from tests as `*Controller::URL` — never hardcode paths in tests.
- HTTP methods only via `Request::METHOD_*` constants.
- **Body validation** through the form component: `$this->formInput(SomeType::class, $request)`
  → `ValidationException` (422) automatically. No manual JSON parsing, no `Uuid::isValid` checks in
  controllers. DTOs in `src/{Module}/Input/`, forms in `src/{Module}/Form/` with
  `csrf_protection => false`. Enum choices via `SomeEnum::getValues()`, never hand-written pairs.
- **PATCH/PUT of one existing entity** prefers entity-bound forms (`data_class = Entity::class`,
  `formInput(..., data: $entity, clearMissing: false)`, `$before = clone $entity` snapshot for
  audit) over Input DTOs — canonical example: `CompanyUpdateController`. Documented exceptions
  (create, workflow transitions, NOT_SET markers) are listed in `AGENTS.md`.
- **GET query validation** through `$this->formQuery(...)`; pagination always via
  `App\Shared\Form\PaginatorForm` + `App\Shared\Input\Paginator` (`limit`, opaque keyset `cursor`).
- **No `try/catch` in controllers.** Services throw exceptions implementing
  `App\Shared\Exception\ApiException`; `App\Infrastructure\Http\JsonApiExceptionSubscriber` renders
  `{title, code?, detail}`. Ready-made: `ValidationException` 422, `ConflictException` 409,
  `StateTransitionException` 409, `NotFoundException` 404, `UnauthorizedException` 401.
- **Authorization is declarative**: `#[IsGranted(...)]` on the controller method. Never
  `$this->requireAdmin()` or manual role `if`s, never return 401/403 by hand — `ApiAccessDeniedHandler`
  does that. Three flavors: role (`#[IsGranted(UserRoleEnum::ADMIN->value)]`, hierarchy
  `platform_admin > admin > manager > agent`), object voter (`#[IsGranted(CompanyVoter::VERIFY,
  subject: 'company')]`), and **permission voter** (no subject, delegates to
  `PermissionCheckerInterface::can()` with codes from `../domain/permissions.md`) — prefer the
  permission voter whenever the action has a permission code. Voters live in `src/Security/` and
  autoregister. Tenant isolation stays in services (404 for another company's object), not in voters.
- **Money is int minor units only** (`App\Shared\Money\Money`). float/decimal are banned in `src`;
  major units exist only in presentation.
- **All status changes go through `symfony/workflow`** — never assign a status field directly. One
  file per workflow in `config/workflow/*.yaml` (auto-imported by `config/packages/workflow.yaml`),
  places/transitions declared as `!php/enum ...::CASE->value`. Workflows: tender (16 statuses /
  38 transitions), auction, contract, company_verification, user.
- **Events**: written to the transactional outbox, relayed to RabbitMQ (`OutboxRelayer`). Every event
  has a JSON Schema in `config/schemas/events/`, validated at the write boundary and checked in CI
  (`composer schema:check`). Mutations are audited via `App\Shared\Audit\AuditService` with a
  cross-cutting trace-id.
- Every new endpoint must also be added to the OpenAPI spec (`public/openapi.yaml`, mirrored by
  `../api/openapi.yaml`).
- Style: `declare(strict_types=1)` everywhere, classes `final`, **docblocks in Russian** explaining
  invariants / FR-references, Doctrine enum columns via `enumType:`.

### Auction hot path

`POST /auctions/{id}/bids` runs one transaction (insert bid + update current price + outbox) under a
`SELECT … FOR UPDATE` row lock, then writes the Redis snapshot and publishes to Mercure. Live state
is Redis, source of truth is PostgreSQL; `auctions:recover` / `auctions:state:rebuild` rebuild Redis.

## Test specifics

- `tests/Unit` (no container) · `tests/Integration` (container/external) · `tests/Functional`
  (WebTestCase — call `createClient()` exactly once per test and never `getContainer()` before it).
- Fixtures are **zenstruck/foundry** factories (`tests/Factory`) and stories (`tests/Story`);
  doctrine-fixtures is not used. Do **not** use `PersistentProxyObjectFactory`/`proxy()` — broken on
  Symfony 8; factories return plain objects. Scalar FK fields take an explicit id, not a factory.
- DB isolation is `dama/doctrine-test-bundle` (BEGIN/ROLLBACK per test) — no manual cleanup. Tests
  marked `#[SkipDatabaseRollback]` must clean up in `tearDown()` (see `AuctionDataCleanerTrait`; the
  test Redis is shared with dev, so never `FLUSHALL`).
- Rate limit in tests is 3/min per IP: give each request a unique
  `$client->setServerParameter('REMOTE_ADDR', ...)` on a single client.

## Git

Commit and push **only with the owner's explicit approval**, including on feature branches. Prepare
the change, show what would be committed, and wait — do not commit as an intermediate step.
