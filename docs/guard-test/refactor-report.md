# QueryGuard — рефакторинг функциональных тестов (вынос фабрик в `setUp`)

**Дата:** 2026-08-28
**Объём:** 70 файлов `tests/Functional/**` (440 тестов)
**Паттерн:** эталон `tests/Functional/Auction/AuctionCreateTest.php` — `createClient()` ровно 1 раз в `setUp()` до `getContainer()`, фабрики + логины в `setUp()`, `tearDown()` обнуляет статический клиент, `loginAs` — инстанс-метод, варьируемые сущности остаются в теле теста.

## Механика

`QueryGuard` открывает трассу на `Test\Prepared`, т.е. **после** `setUp()` (`vendor/alex-frolov/query-guard/src/Subscriber/Test/PreparedSubscriber.php`). Всё, что создано в `setUp()`, попадает в фазу Fixtures → `fixtureQueries` (`in setUp`), учитывается в счётчике, но **не подаётся в RuleEngine**. Фабрики и логины в теле теста — наоборот: дают ложные `n-plus-one` (3 логина → 3 одинаковых `SELECT users`), `query-in-loop` (батч-фабрики Foundry, батчинг `UnitOfWork`) и `query-count` (база 19+ на каждый тест). Подробный разбор — `analysis.md`, `trace.md`, `after-fix.md`.

## Итог

| Метрика | До | После | Δ |
|---|---|---|---|
| queries в трассе (судятся правилами) | 8188 | **4572** | **-3616 (-44%)** |
| queries в `setUp` (не судятся) | 171 | **4198** | +4027 |
| findings (n-plus-one / query-in-loop / query-count / duplicate) | 508 | **150** | **-358 (-70%)** |

Трасса теперь измеряет целевой HTTP-сценарий, а не подготовку данных.

## Оставшиеся 150 findings — классификация

Это **не** фикстурный шум, устранению переносом в `setUp` не подлежит (детальный разбор — `prod-cycle-analysis.md`):

1. **Прод-циклы внутри HTTP-запроса** (~140): `AuthMiddleware:84` (SELECT пользователя на каждый запрос), `AuditService:75` (append-only аудит + батчинг `UnitOfWork`), `BidTransaction:178` / `WinnerTransaction:68` / `AuctionWinnerService` (транзакционная запись ставки/выбор победителя), `TenderRepository:306/180` (группировка каталога с visibility-подзапросами `ContractRepository:188`, `BidRepository:152`), `RolePermissionRepository:38` (пересборка матрицы прав). Это проверяемое поведение прод-кода, а не тест-хелпер.
2. **E2E-сценарии с превышением бюджета 35** (~10): `FullCycleE2ETest` (261), `ContractFullCycleE2ETest` (533, максимум 77 в одном тесте — цепочка place bid → finish → winner), `AnalyticsE2ETest`, `WebhookDeliveryE2ETest`. Весь флоу и есть предмет теста; режим `report`, не `strict`.
3. **Redis-rate-limit-зависимые тесты**: `EmailVerificationTest` / `PasswordResetTest` — пользователь с уникальным email на тест (общий лимит `email_send`), `duplicate-query` — прод-логика cooldown.

## Исключения (2026-08-28)

По результатам анализа (`prod-cycle-analysis.md`) прод-код менять не нужно; все findings закрыты атрибутами:

- **`#[AllowQueries(n)]`** — 14 E2E/тяжёлых тестов с превышением бюджета 35 (с запасом ~20%): `FullCycleE2ETest` 310, `ContractFullCycleE2ETest` 90-180 (5 тестов), `AnalyticsE2ETest` 95, `BidE2EFlowTest` 80, `AuctionWinnerAccessTest` 60 (2), `WebhookDeliveryE2ETest` 60, `TenderVisibilityTest` 55, `TenderListPaginationTest` 55, `BidOpeningFlowTest` 45.
- **`#[IgnoreRule]`** классовые — для ложных правил на прод-callsites (`n-plus-one` ← `AuthMiddleware:84`, `query-in-loop` ← батч-транзакции/Foundry-персист в теле, `duplicate-query` ← visibility-подзапросы на каждый запрос); 24 класса, причины перечислены в docblock каждого.
- **`#[IgnoreRule('duplicate-query')]`** методовые — только cooldown-тесты Redis-rate-limit: `EmailVerificationTest::testResendCooldownReturns429`, `PasswordResetTest::testForgotCooldownReturns429`.

После расстановки атрибутов: **0 findings по всем 70 файлам**.

Риск классовых `#[IgnoreRule]` — маскирование будущего реального N+1 в этих классах; принят осознанно, docblock каждого класса перечисляет причины и ссылку на `prod-cycle-analysis.md`.

Файлы без правок («no change», 0 findings, фабрики уже минимальны/вариантны): ApiKeyAuthTest, RateLimitE2ETest, QuestionCrudTest, ComplaintCreateTest, Company*Test, IamE2EFlowTest, ProfileTest, RegistrationTest, DocumentTypeTest, SupplierProfileTest, ProcurementPlanCrudTest, AccessControlTest, TraceIdSubscriberTest, MetricsControllerTest, RateLimitMiddlewareTest, WelcomePageTest, TenderE2EFlowTest.

## Замеры

- **До рефактора:** `docs/guard-test/summary-before.md`, per-file логи — `docs/guard-test/per-file/*.before.log`
- **После рефактора:** `docs/guard-test/summary-after.md`, per-file логи — `docs/guard-test/per-file/*.after.log`
- Имя лога: `tests__Functional__<Path>__<ClassName>.{before,after}.log`

## Верификация

```text
composer lint            # No syntax errors detected in src / tests
composer cs-fixer:check  # Found 0 of 1094 files
composer phpstan         # [OK] No errors
composer arkitect        # No violations detected
composer test:parallel   # OK — 1222 tests, 10351 assertions, exit 0
                         # (12 PHPUnit Deprecations — внутренние deprecation'ы paratest, см. AGENTS.md)
```

Каждый изменённый файл дополнительно прогонялся индивидуально (`php -l` + `phpunit`, OK + `in setUp` > 0) до записи after-лога.

## Замечания по сопровождению

- `var/cache/test` устаревает при изменении DI-конфигурации QueryGuard-мидлвари (`config/services_test.yaml`) — при «queries: 0» в трейсе очистить кэш.
- Дальнейшее снижение findings — только оптимизация прод-кода (например, объединение `belongsToCompany` + `resolveTender` в один SELECT) либо точечные `#[AllowQueries(n)]` на E2E; глобально поднимать `max-queries` не требуется.
