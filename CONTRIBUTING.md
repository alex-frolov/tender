# Contributing to Tender Platform

Thanks for your interest in contributing. This document describes how to set up a development
environment, the project conventions you must follow, and how to verify your changes before
submitting a pull request.

## Table of contents

- [Development environment](#development-environment)
- [Project layout](#project-layout)
- [Conventions](#conventions)
- [Architecture rules](#architecture-rules)
- [Quality gate](#quality-gate)
- [Tests](#tests)
- [Pull request process](#pull-request-process)

---

## Development environment

All PHP/Composer commands run **inside** the `app` container.

```bash
docker compose up -d                                            # start the dev stack
docker compose exec app php bin/console <cmd>                   # run a console command
docker compose exec -T app <php/composer> ...                   # run php/composer
```

The dev database and the test database are separate. Apply migrations to **both** when a new
migration is added:

```bash
docker compose exec -T app php bin/console doctrine:migrations:migrate --no-interaction              # dev: tender
docker compose exec -T app php bin/console doctrine:migrations:migrate --no-interaction --env=test    # test: tender_test
```

A forgotten migration in the test DB breaks the whole suite (`Undefined column: ... does not exist`).

## Project layout

```
src/
├── {Module}/             business + platform modules (vertical slices)
│   ├── Controller/       thin HTTP adapters, one controller per route
│   ├── UseCase/          application layer, one public execute()
│   ├── Service/          domain services
│   ├── Entity/           module-owned entities + Repository/
│   ├── Form/ Input/      form types + input DTOs
│   ├── Presenter/        response presentation
│   └── Exception/        domain exceptions (implement ApiException)
├── Shared/               shared kernel: money, events/outbox, audit, idempotency, exceptions
├── Security/             voters, access-denied handler
├── Infrastructure/       framework glue: HTTP middleware, messenger, Redis, AMQP, console
└── Controller/           cross-cutting: AbstractBaseController, HealthController
config/
├── packages/             bundle configuration
├── services/             service definitions per module
├── workflow/             state machine definitions (one file per workflow)
└── schemas/events/       JSON Schema contracts for outbox events
migrations/               Doctrine migrations
tests/
├── Unit/                 pure logic without the container
├── Integration/          services with container / external resources
└── Functional/           WebTestCase (kernel + client)
load/                     k6 + node load scenarios
docker/                   Dockerfile, nginx, php-fpm, worker entrypoint
```

## Conventions

These rules are enforced by the quality gate; follow them while writing code.

- `declare(strict_types=1)` in every file; classes are `final`.
- Class/interface docblocks and comments are written in **Russian** and explain invariants or
  requirement references. Avoid redundant comments.
- Formatting is applied only through PHP CS Fixer — do not hand-align code.
- One controller = one route. Route paths are `public const string URL` and are referenced in the
  `#[Route]` attribute and in tests.
- HTTP methods in `#[Route]` use `Request::METHOD_*` constants, never string literals.
- POST/PATCH body validation goes through the form component (`AbstractBaseController::formInput`),
  not manual `jsonBody`/`stringField` parsing.
- Enum fields in forms use `ChoiceType` with a static `getValues()` (or subset) method on the enum.
- Money is stored as **integer minor units** only (`App\Shared\Money\Money`); float/decimal are
  forbidden for currency.
- State transitions go **only** through `symfony/workflow` (`WorkflowInterface`); never assign
  status fields directly.
- Workflow places/transitions are declared via enum values (e.g.
  `!php/enum App\...\Enum\XStatus::CASE->value`), not raw strings.
- Every mutation writes an append-only audit record via `App\Shared\Audit\AuditService`.
- Domain events are emitted through the outbox (`OutboxEvent`), never dispatched directly.
- Exceptions that reach the API must implement `App\Shared\Exception\ApiException`; never throw
  bare `\RuntimeException` / `\InvalidArgumentException` for domain errors.
- 401/403 are produced by the security component (`ApiAccessDeniedHandler`), not thrown from
  controllers.

## Architecture rules

The module boundaries are enforced by **PHPArkitect** (`phparkitect.php`, 7 rules). A module
must not reach into another module's internals (`Controller`, `Command`, `Entity`, `Repository`,
`Form`, `Input`, `Presenter`, `Exception`, `Storage`, `Rules`, `State`, `Stream`, `Timer`, `Step`,
`Timeline`, `Service`). Cross-module access is allowed only through:

- public module contracts (root interfaces, e.g. `Tender\TenderReadService`);
- `App\Shared` (the shared kernel);
- enum value-types (`App\{Module}\Entity\Enum`);
- `App\Iam\Entity` identity read-models;
- an explicit whitelist in `phparkitect.php` (`$publicContractExcludes` / `$readModelExcludes`).

New cross-module access to another module's internals is impossible without editing the whitelist.

The public-contract pattern: any service consumed cross-module is declared as an interface in the
module root (`App\{Module}\X`) with the implementation in `App\{Module}\Service\X`. Consumers type
the interface.

```
┌────────────┐   UseCase / interface   ┌────────────┐
│ Module A   │ ───────────────────────►│ Module B   │  root contract only
│            │                         │            │
└────────────┘   never internals       └────────────┘
```

## Quality gate

Run the full pipeline before submitting. Everything runs inside the container:

```
quality gate
   │
   ├─ composer lint            php -l src tests
   ├─ composer cs-fixer:check  PHP CS Fixer (dry-run)
   ├─ composer phpstan         PHPStan level max
   ├─ composer arkitect        PHPArkitect layers + module boundaries
   └─ composer test            PHPUnit (full suite)
        │
        ▼
   composer quality   (everything above)
```

```bash
docker compose exec -T app composer lint          # php -l src tests
docker compose exec -T app composer cs-fixer:check # PHP CS Fixer (dry-run)
docker compose exec -T app composer phpstan        # PHPStan level max (raise memory limit)
docker compose exec -T app composer arkitect       # PHPArkitect layers + module boundaries
docker compose exec -T app composer test           # PHPUnit (full suite)
docker compose exec -T app composer quality        # everything above
```

Notes:

- **PHPStan** in the container fails on `memory_limit=128M`. Run it with a raised limit:
  `docker compose exec -T app php vendor/bin/phpstan analyse --no-progress --memory-limit=1G`
  (after clearing `var/cache/phpstan`).
- The **full PHPUnit run takes significantly more than 120 seconds**. For a quick local check, run
  only the relevant directory, e.g. `php bin/phpunit tests/Functional/Tender/...`.
- The **parallel run** is `composer test:parallel` (brianium/paratest). It prepares a worker DB
  per process (`tender_test1..N`). Smoke/load tests (`#[Group('smoke')]`) are excluded from the
  parallel run and are executed separately and strictly sequentially: `composer test:smoke`. The
  combined command is `composer test:parallel:full`.
- The only quirk of the parallel run is a possible `PHPUnit Deprecations: N` line in the output —
  these are PHPUnit-internal deprecations produced by the paratest printer; the exit code is `0`.

## Tests

- Test data is created with **zenstruck/foundry** factories (`tests/Factory`) and stories
  (`tests/Story`). doctrine-fixtures is not used.
- Do **not** use `PersistentProxyObjectFactory` / `proxy()` — it is deprecated in Foundry 2.11 and
  does not work on Symfony 8. Factories return plain objects.
- Database isolation is provided by `dama/doctrine-test-bundle`: each test runs inside a
  BEGIN...ROLLBACK transaction. Tests that commit themselves use `#[SkipDatabaseRollback]` and must
  clean up in `tearDown()` (use `App\Tests\Support\AuctionDataCleanerTrait` where applicable).
- Test rate limit is 3/min per IP. Use `$client->setServerParameter('REMOTE_ADDR', ...)` on a
  single client.
- Create entities in functional tests through factories/stories, never by hand-written
  persist+flush.

## Pull request process

1. Create a branch from the latest `main`.
2. Make your changes and add tests for them.
3. Run the [quality gate](#quality-gate); make sure it is green.
4. Open a pull request. Keep it focused on a single concern.

By contributing you agree that your contributions are licensed under the repository's license.
