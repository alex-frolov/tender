# AGENTS.md — Tender Platform (app)

Настройки и правила для работы с кодом проекта `app/`. Обязательно к прочтению перед изменениями.

## Проект

Tender Platform — B2B-платформа закупок (тендеры, аукционы, договоры, исполнение). Backend-first API.

- **PHP 8.5** (>= 8.4), **Symfony 8.1** (framework-bundle + security-bundle; JWT-API — аутентификация своя, авторизация через security-компонент)
- **PostgreSQL 17** (Doctrine ORM 3.6 + migrations 4.0)
- **Redis 7** (rate limit, live-состояние аукционов, кэш)
- **RabbitMQ 4** (messenger async, outbox → события)
- **Mercure** (SSE-стримы аукционов)
- **Mailpit** (dev-почта: UI http://localhost:8025)
- Доп.: symfony/lock, symfony/workflow, symfony/rate-limiter, symfony/validator, lcobucci/jwt, zenstruck/foundry (dev)

## Git: коммиты и пуши

- **Коммитить и пушить — только с явного подтверждения владельца.** Подготовить изменение, показать состав (что будет закоммичено), дождаться одобрения — в том числе на feature-ветках.
- Не делать `git commit`/`git push` как промежуточный шаг «по ходу работы» без отдельного подтверждения на конкретный коммит.
- **Сообщения коммитов и описания PR — на английском.** Docblock'и, комментарии в коде и документация в `docs/` остаются на русском (см. «Стиль кода»): английский — только для истории репозитория, которую читают и вне проекта.
- Тело коммита объясняет, почему сделано именно так (какую проблему чинит, что было не так раньше), а не пересказывает диф.

## Запуск и окружение

```bash
docker compose up -d          # поднять стек (app, web:8080, db:54329, redis:56379, rabbitmq:55672/55673, mercure:3008, mailpit:8025/1025, worker, webhooks, scheduler)
docker compose exec app php bin/console <cmd>
```

- Все команды PHP выполнять ВНУТРИ контейнера: `docker compose exec -T app <php/composer> ...`
- БД dev и test — РАЗНЫЕ: dev — `db:5432/tender` (из `DATABASE_URL`), test — `tender_test` (dbname_suffix `_test` в `config/packages/doctrine.yaml`, when@test).
- Миграции применять в ОБОИХ окружениях при каждой новой миграции (dev-БД и test-БД не синхронизируются автоматически):
  ```bash
  docker compose exec -T app php bin/console doctrine:migrations:migrate --no-interaction              # dev: tender
  docker compose exec -T app php bin/console doctrine:migrations:migrate --no-interaction --env=test    # test: tender_test
  ```
  Забытая миграция в test-БД ломает весь набор: тесты падают с "Undefined column: ... does not exist".
- Порт наружу: db → 54329, redis → 56379, rabbitmq → 55672 (UI) / 55673 (amqp), web → 8080.
- Миграции: см. блок выше (dev + test); справочники (document_types, permissions, contract_types) — идемпотентные миграции данных (НЕ fixtures).

## Качество (обязательный конвейер перед сдачей)

```bash
docker compose exec -T app composer lint       # php -l src tests
docker compose exec -T app composer cs-fixer:check  # PHP CS Fixer (dry-run)
docker compose exec -T app composer phpstan    # PHPStan level max (src + tests)
docker compose exec -T app composer arkitect   # PHPArkitect: слои + границы модулей
docker compose exec -T app composer test       # PHPUnit (весь набор, последовательно)
docker compose exec -T app composer test:parallel  # PHPUnit через ParaTest (параллельно, быстрее; smoke/load исключены)
docker compose exec -T app composer test:smoke  # smoke/load тесты, последовательно, один за другим (после test:parallel)
docker compose exec -T app composer test:coverage  # PHPUnit + покрытие (pcov) + порог ≥80%
docker compose exec -T app composer quality    # всё сразу
```

- **PHP CS Fixer** (`friendsofphp/php-cs-fixer`, `composer cs-fixer` — применить правки, `composer cs-fixer:check` — dry-run без изменений). Конфиг `.php-cs-fixer.dist.php`: `@Symfony` + `@Symfony:risky` + `declare_strict_types` enforce (strict_types обязателен) + PHPUnit-ассерты через `self::`.

- 🧠 **memory_limit — `PHP_MEMORY_LIMIT` (по умолчанию 1G)**: дефолт контейнера — 128M, его не хватает ни PHPUnit/ParaTest (smoke/load падают с `Allowed memory size exhausted`), ни PHPStan level max. Поэтому все скрипты `composer test*` и `composer phpstan` запускают PHP как `php -d memory_limit=${PHP_MEMORY_LIMIT:-1G} vendor/bin/…`, а цели Makefile (`test`, `test-unit`, `test-parallel`, `test-smoke`, `test-coverage`, `phpstan`, `quality`) пробрасывают переменную в контейнер через `docker compose exec -e`. **Руками `--memory-limit`/`-d memory_limit` не дописывать** — если 1G мало, поднимать потолок: `make test PHP_MEMORY_LIMIT=2G` или `docker compose exec -T -e PHP_MEMORY_LIMIT=2G app composer test`. Прямой вызов `php bin/phpunit tests/...` идёт мимо composer-скрипта и остаётся на 128M — это нормально для одного файла, но не для всего набора и не для smoke-группы.
- PHPStan **max** (phpstan.neon.dist, phpstan-symfony); `treatPhpDocTypesAsCertain: false`.
  ⚠️ При «залипшем» кэше анализатора: `docker compose exec -T app bash -c 'rm -rf var/cache/phpstan && mkdir -p var/cache/phpstan && chmod 777 var/cache/phpstan'`, затем обычный `composer phpstan`.
- PHPArkitect (phparkitect.php, 6 правил): контроллеры не зависят от Infrastructure; Entity изолированы; Money чистый; Infrastructure не зависит от Controller; Message изолирован; границы модулей (модуль не заглядывает во внутренности других модулей: Controller/Command/Entity/Repository/Form/Input/Presenter/Exception/Storage/Rules/State/Stream/Timer/Step/Timeline/Service; доступ к ним — только через явный whitelist `$publicContractExcludes`).
- PHPUnit ≥ 80% покрытия (цель MVP), `failOnDeprecation/Notice/Warning`.
- 📊 **Покрытие кода — `composer test:coverage`**: полный набор через paratest (`--exclude-group=smoke`) с clover-отчётом + `scripts/check-coverage.php` (порог 80% строк, `COVERAGE_THRESHOLD`/первый аргумент — override). Драйвер — **pcov** (`docker/Dockerfile`, dev-образ; в CI — `coverage: pcov` в shivammathur/setup-php). `composer test:coverage:check` — только проверка уже существующего `var/coverage/clover.xml` без прогона тестов. Новые фичи не должны ронять покрытие ниже порога.
- ⚠️ Полный прогон тестов (`docker compose exec -T app php bin/phpunit`) занимает **значительно больше 120 секунд** — не запускать с таймаутом < 2–3 минут; для быстрой проверки локально запускать только конкретные тесты/директории (`php bin/phpunit tests/Functional/...`).
- ⚡ **Параллельный прогон — `composer test:parallel`** (brianium/paratest ^7.24): подготавливает БД для каждого воркера (`tender_test1..N`, `dbname_suffix` с `TEST_TOKEN` в doctrine.yaml) и запускает `vendor/bin/paratest --processes=N --exclude-group=smoke`. Число воркеров — `PARATEST_PROCESSES` (по умолчанию = числу ядер; в CI задан явно). Скрипт подготовки — `scripts/test-parallel-prepare.sh`. Только этот способ создаёт рабочие БД воркеров; `composer test` (последовательный) их не трогает.
- 🚦 **Smoke/load тесты** (`#[Group('smoke')]`: `AuctionBidPerfSmokeTest`, `AuctionBidLoadSmokeTest`) **исключены из параллельного прогона** — это нагрузочные замеры (p95, bids/sec, реальные COMMIT через `SkipDatabaseRollback`), параллельно с остальными тестами они бессмысленны/нестабильны (конкуренция за CPU/БД/Redis). Запуск отдельно и строго последовательно, один за другим: `composer test:smoke` (`test:prepare` + `phpunit --group=smoke`, один процесс). Полный прогон «параллельно → потом smoke» — `composer test:parallel:full`; в CI так и настроено. Новый нагрузочный тест — тоже `#[Group('smoke')]`.
- Единственная особенность параллельного прогона — в выводе может быть `PHPUnit Deprecations: N`: это внутренние deprecation'ы PHPUnit API, порождаемые самим paratest (printer), а не кодом проекта; exit code при этом `0`.
- Тестовые данные — **zenstruck/foundry** (PersistentObjectFactory; стори — `tests/Story`, namespace `App\Tests\Story`). doctrine-fixtures НЕ используется.

## Архитектура и соглашения

### Модульная структура

Модульный монолит: бизнес-модули + сквозное Shared-ядро. Модуль — вертикальный срез
(access + application + domain). Скелет модуля, UseCase-паттерн и план декомпозиции —
см. **`architecture/modular-monolith.md`**. Рефакторинг переноса Entity/Repository —
**`architecture/refactor-modular-monolith.md`**.

- **Бизнес-модули** на верхнем уровне `src/{Module}/` (Iam, Tender, Bid, Auction, Contract, Document, Notification, Favorite, SavedSearch): каждый модуль **владеет** своими `Entity/` и `Repository/` (`src/{Module}/Entity/`, `src/{Module}/Repository/`), плюс сервисы, `Form/`, `Input/`, `Presenter/`. Новые сущности и репозитории создаются **внутри модуля**; общих `src/Entity/` / `src/Repository/` нет.
- **Policy-плагин** `src/RuStateProcurement/` (2026-08-13): доменные правила РФ (44-ФЗ/223-ФЗ) через контракты ядра. Реализует `TimelineRules`/`AuctionRules`/`SecurityRules` (активация — compiler pass `ProcurementRulesPass` по `PROCUREMENT_PLUGIN_ENABLED`), правила — из внешнего `config/ru_state_procurement.yaml` (`ProcurementConfig`); протоколы через контракт `App\Document\DocumentGenerator` (`RuProtocolGenerator`/`RuProtocolListener`). Доступ плагина к контрактам правил и сущностям Tender/Auction как read-моделям — явно разрешён в `phparkitect.php` ($publicContractExcludes/$readModelExcludes).
- **Access-слой модуля** — `src/{Module}/Controller/` (тонкие HTTP-адаптеры, 1 контроллер = 1 route; namespace `App\{Module}\Controller`). Контроллеры модулей НЕ живут в `src/Controller/Api/*` (миграция по шагам, модуль за модулем). Route name/URL при переносе не меняются; путь — `*Controller::URL`.
- **Application-слой модуля** — `src/{Module}/UseCase/{Name}UseCase.php` (namespace `App\{Module}\UseCase`): 1 класс = 1 действие пользователя, `final` + маркер `{Module}UseCase` (например `AuctionUseCase`) + один публичный `execute()` со строгой типизацией. Контроллер: «валидация формы → `$usecase->execute(...)`»; UseCase принимает валидированный `Input`-DTO и возвращает презентацию (`Presenter`). UseCase — публичный контракт модуля (другие модули могут его вызывать); во внутренности чужих модулей UseCase не заглядывает.
- **Shared kernel** `src/Shared/` — сквозное, НЕ бизнес-модули (2026-08-12, P0: identity вынесен в Iam):
  - `Entity/` — ТОЛЬКО технические сущности (OutboxEvent, IdempotencyKey, AuditLog); identity (User, Company, Permission, RolePermission, RefreshToken, EmailVerificationToken, PasswordResetToken) живёт в `src/Iam/Entity/` (namespace `App\Iam\Entity`);
  - `Entity/Enum/` — только технические enum (OutboxEventStatus), namespace `App\Shared\Entity\Enum`; identity/бизнес enum разнесены по модулям-владельцам (`src/Iam/Entity/Enum/`, `src/{Module}/Entity/Enum/`, namespace `App\Iam\Entity\Enum`, `App\{Module}\Entity\Enum`) — refactor-modular-monolith.md;
  - `Exception/` — общее API-ядро (ApiException, ValidationException, ConflictException, StateTransitionException, NotFoundException);
  - `Repository/` — общие технические репозитории (IdempotencyKey, OutboxEvent); identity-репозитории (Permission, RolePermission) — в `src/Iam/Repository/`;
  - `Money/`, `Audit/`, `Events/`, `Idempotency/`, `Totp/` — сквозные сервисы.
- **Транспорт** (тонкие адаптеры): контроллеры модулей — `src/{Module}/Controller/` (Iam группируется по под-доменам: `src/Iam/Controller/{Auth,User,Company,Permission}`); команды модулей — `src/{Module}/Command/`, технические (outbox/idempotency/redis-cleanup/test-event) — `src/Infrastructure/Console/` (namespace `App\Infrastructure\Console`); конверты сообщений — `src/Shared/Events/EventMessage` и `src/Tender/Timeline/TimelineMessage`; хендлеры — в модуле-владельце (`src/Tender/Timeline/TimelineMessageHandler`) или `src/Infrastructure/Messenger/EventMessageHandler`. Кросс-срезовые `src/Controller/` — только AbstractBaseController и HealthController. **Авторизация** (сквозная): `src/Security/`. **Фреймворк-клей**: `src/Infrastructure/`. Iam приведён к каноническому скелету (2026-08-12): сервисы — `src/Iam/Service/`, формы/инпуты/исключения/presenter — `src/Iam/{Form,Input,Exception,Presenter}/`, под-домены Auth/User/Company/... устранены (контроллеры группируются по под-доменам).
- **Границы модулей** контролирует PHPArkitect (правило 6 в `phparkitect.php`): модуль не заглядывает во внутренности другого модуля (`Controller/Command/Entity/Repository/Form/Input/Presenter/Exception/Storage/Rules/State/Stream/Timer/Step/Timeline/Service`); разрешены только публичные контракты (корневые сервисы модуля), `App\Shared`, enum как value-типы (`App\{Module}\Entity\Enum`) и явный whitelist `$publicContractExcludes` в `phparkitect.php`. Межмодульные запросы — через публичные сервисы модуля-владельца, НЕ через чужой Repository/Service. Новый кросс-модульный доступ к внутренностям невозможен без правки whitelist'а.
- **Паттерн публичных контрактов (интерфейс + реализация в `Service/`):** любой сервис, который потребляется кросс-модульно, объявляется интерфейсом в корне модуля (`App\{Module}\X`), реализация живёт в `App\{Module}\Service\X` (`implements X` c алиасом `use App\{Module}\X as XContract`, т.к. имя класса совпадает с интерфейсом). Autowire резолвит интерфейс по единственной реализации (пример: `Tender\TenderReadService` + `Tender\Service\TenderReadService`). На 2026-08-12 на этот паттерн переведены: `Bid\BidResultService`/`BidOpeningService`, `Tender\LotWriteService`/`TenderStatusAggregator`, `Contract\ContractAccessChecker`/`ContractExecutionService`, `Document\DocumentService`/`DocumentTypeService`, `Iam\CompanyAccessGuard`. Потребители типизируют интерфейс, а не реализацию.
- Исключения из правила 6 — только задокументированные: (а) доменные read-модели (карта `$readModelExcludes` в `phparkitect.php`); (б) публичные контракты-внутренности (карта `$publicContractExcludes` — на 2026-08-12: Platform → `App\Iam\Service\AuthContext`/`AuthMiddleware`/`JwtService`/`PermissionCheckerInterface`); (в) identity-сущности модуля Iam (`App\Iam\Entity` — кросс-срезовые read-модели User/Company, потребляются всеми модулями и Security). На 2026-08-12 сняты из readModelExcludes: Document→Tender\Entity (через `TenderReadService::belongsToCompany`), Contract→Tender\Entity (belongsToCompany + SecurityService через TenderReadService), Auction→Bid\Entity (итоги торгов через `BidResultService`), Contract→Auction\Entity (P2: переходы state_machine.auction вынесены за `AuctionLifecycleService::applyTransition(Uuid, Transition)`, чтение — через `AuctionContext`/`WinningBidResult`). Остаётся осознанно: Bid→Tender\Entity (read-модель Tender/Lot для допуска участников + BidOpeningService пишет bids_opened_at). Прочие module-Entity не исключаются.
- Слои: `Controller` → сервисы модулей (`src/{Module}/*`) → модели; инфраструктура (`src/Infrastructure/*`) — нижний слой.
- Контроллеры: **один контроллер — один route** (исключение — только инфраструктурные health-проверки). Общая логика контроллеров (jsonBody, stringField, unauthorized, currentUser) — в `App\Controller\AbstractBaseController extends AbstractController`. Много-route контроллеры разбиваются на группы-директории: `AuthController` → `src/Controller/Api/Auth/*Controller.php` (RegisterController, TokenController, …). Route name и URL при рефакторинге не меняются.
- Путь route контроллера выносится в **`public const string URL`** (полный путь, включая `/api/v1`) и используется в атрибуте метода `#[Route(self::URL, ...)]`. В тестах обращаться к `*Controller::URL`, путь в тестах не хардкодить.
- HTTP-методы в `#[Route(..., methods: [...])]` указывать **только через константы `Request`**: `Request::METHOD_POST` / `METHOD_GET` / `METHOD_PATCH` / `METHOD_PUT` / `METHOD_DELETE`, а не строковые литералы `'POST'`, `'GET'` и т.п.
- **Валидация body POST/PATCH — через form-компонент** (`symfony/form`), а не ручным разбором `jsonBody`/`stringField` в контроллере. Общий helper `App\Controller\AbstractBaseController::formInput(string $type, Request $request)`: создаёт и сабмитит форму из JSON-тела, при невалидных данных бросает `App\Shared\Exception\ValidationException` (422 через `JsonApiExceptionSubscriber`). Правила:
  - Форма — потомок `AbstractType` в `src/{Module}/Form/UserInviteType.php`, `data_class` — DTO (входные данные), `csrf_protection => false`, `@extends AbstractType<XDto>`.
  - DTO-классы входных данных — в `src/{Module}/Input/*Input.php` (например `InviteUserInput`, `UpdateUserInput`), публичные nullable-поля.
  - Контроллер: `$form = $this->formInput(UserInviteType::class, $request);` → `/** @var InviteUserInput $input */ $input = $form->getData();` → передать DTO в сервис.
  - Формат/обязательность полей и enum-валидация — constraints в форме (NotBlank/Email/Choice). Бизнес-правила (duplicate email, last admin, not found) — остаются в сервисе (бросают ApiException).
  - **ChoiceType для enum-полей** объявлять через статический метод перечисления, а не руками списком пар: `'choices' => LawTypeEnum::getValues()`. Метод возвращает пары `value => value` (label == value) и добавляется в каждый enum, используемый в формах (`getValues()` — весь набор; `getCompanyRoleValues()` — подмножество без `platform_admin` для ролей компании в `UserInviteType`).
- **Entity-bound update формы (PATCH/PUT одной существующей сущности) — предпочтительный паттерн** вместо Input-DTO. Если обновляется одна уже существующая сущность и поля формы мапятся 1:1 на её свойства, отдельный `Input` не создаём: форма привязывается к сущности (`data_class = Entity::class`), контроллер резолвит сущность и передаёт её как `data`, UseCase принимает сущность и только фиксирует/аудитует. Канонический пример: `CompanyUpdateController`/`CompanyUpdateType`/`UpdateCompanyUseCase`/`CompanyRepository`. На 2026-08-22 на паттерн также переведены `DocumentTypeUpdateController` (PUT /document-types/{id}, `DocumentTypeRepository::findOrFail`) и `WebhookUpdateController` (PATCH /webhooks/{id}, `WebhookRepository::findOwnedOrFail` — резолв с tenant-изоляцией). Обязательные части паттерна:
  - **Резолв сущности вне UseCase**: собственная сущность актора — через репозиторий модуля `*Repository::findOrFail(...)` (404 при отсутствии, напр. `CompanyRepository`); сущность по id из route — через `#[MapEntity]` (+ tenant-изоляция в Voter/сервисе, если нужна).
  - **Доступ — декларативно**: проверки ролей/принадлежности — в Voter (`#[IsGranted]`), не в UseCase (для своей компании — `CompanyVoter::UPDATE`: роль + наличие компании). Проверка «сущность существует» — при резолве (404), не в UseCase.
  - **`formInput(..., strict: true, data: $entity, clearMissing: false)`** — `clearMissing: false` обязателен: отсутствующие в теле поля сохраняют текущие значения сущности (PATCH-семантика), а не очищаются. Для форм с `data: $entity` `strict: true` без `clearMissing` НЕ использовать (очистит все не переданные поля).
  - **Снапшот до мутации**: `$before = clone $entity;` в контроллере ДО `formInput`, передаётся в UseCase для корректного `before/after` в аудите (форма мутирует сущность до вызова UseCase).
  - **PATCH-семантика пустых значений — через `empty_data` в форме**: nullable-поля — `'empty_data' => null` (пустая строка/null = очистить в null); обязательное non-nullable поле (напр. `legal_name`) — `'empty_data'`-closure, возвращающий текущее значение (пустая строка = оставить): `static fn (FormInterface $form) => ... getCurrent()`.
  - **Enum-свойства сущности — `EnumType`**, а не `ChoiceType` с `getValues()`: свойство сущности типизировано перечислением, и `ChoiceType` дал бы в сеттер строку. `'class' => XEnum::class` + `'empty_data'`-closure с текущим значением (свойство non-nullable). Правило «`ChoiceType` через `getValues()`» относится к строковым полям Input-DTO. Пример: `WebhookUpdateType::status`.
  - **Коллекции «очистить»**: пустой массив → null через `CallbackTransformer` на поле (`reverseTransform: [] === $v ? null : $v`). Обратный случай — коллекцию очищать НЕЛЬЗЯ: при `clearMissing: false` явный `[]` до полей коллекции не доходит (существующие элементы остаются), поэтому `Count(min: 1)` его не увидит — отклонять на входе слушателем `FormEvents::PRE_SUBMIT` на поле (`[] === $event->getData()` → `addError`). Пример: `WebhookUpdateType::events`.
  - **UseCase**: `execute(User $user, Entity $entity, Entity $before, ?string $ip = null)` → flush + audit + презентация; без загрузки сущности по id и без ручных проверок ролей.
  - **Оставлять Input-DTO** (документированные исключения, выверены аудитом 2026-08-22 — `docs/form-entity-binding-audit.md`): create-операции (новых данных нет в БД); переходы статусов через symfony/workflow (`UserUpdate` role/status, деактивация через статус); поля-производные, которых нет на сущности (`UpdateMe` — смена пароля; `TenderUpdate` — `change_reason` только для аудита); значения, которые перед записью трансформируются или уходят в доменный метод, а не в сеттер (`LotUpdate` — `vat_rate` % → bps, `price_basis` → enum, дата-строка → `DateTimeImmutable`, плюс инвариант суммы лотов и резолв лота внутри агрегата тендера); PATCH с NOT_SET-маркерами на non-nullable числовых/enum-полях, где явный null = сброс (`AuctionUpdate` — единственное такое место в `src`); lazy-создаваемые сущности (`SupplierProfile` — upsert, резолвить до формы нечего); bulk-обновления набора (`RolePermissions`); данные, за которыми нет сущности (`PlatformTimezoneUpdate` — key-value настройка площадки); null=не менять на Choice/Checkbox-полях не-nullable сущностей (явный null в entity-bound форме меняет поведение, в отличие от DTO — проверяй по openapi, помечено ли поле `nullable`).
- **Валидация GET-query (фильтры/пагинация) — тоже через form-компонент**, а не `$request->query->get()` в контроллере. Общий helper `AbstractBaseController::formQuery(string $type, Request $request, ?object $data = null, array $options = [])`: создаёт и сабмитит форму **из query-параметров** (в отличие от `formInput` — из JSON-тела); из query берутся ТОЛЬКО ключи, объявленные полями формы (остальные игнорируются — несколько форм могут жить на одном query, например фильтры + пагинатор). Невалидные данные → `ValidationException` (422).
  - **Пагинация списков — единый `App\Shared\Form\PaginatorForm` + `App\Shared\Input\Paginator`** (`data_class` = Paginator): поля `limit` (int 1..100, default 20, клампится в `Paginator::limitValue()`) и `cursor` (OPAQUE-строка keyset-курсора). Все list-эндпоинты с пагинацией обязаны использовать их, не разбирая `limit`/`cursor` вручную.
  - **Фильтры списка** — форма модуля в `src/{Module}/Form/*ListFiltersType.php` с `data_class` = `src/{Module}/Input/*ListFiltersInput.php` (публичные nullable-поля); enum-поля — через `ChoiceType` с `getValues()`.
  - Маппинг DTO фильтров в доменный объект — в UseCase (не в контроллере); контроллер только: `$form = $this->formQuery(XListFiltersType::class, $request); $paginator = $this->formQuery(PaginatorForm::class, $request);` → передача в юзкейс.
  - DELETE-эндпоинты с query-id — единый `App\Shared\Form\EntityIdQueryType` (поле `id`, UUID-валидация; имя поля через `options['id_field']`).
- Деньги — **только int minor units**: `App\Shared\Money\Money`, `MoneyService`; float/decimal запрещены в src. Перевод в major — только presentation.
- State machines (tender 16 статусов/38 переходов, auction, contract) — **symfony/workflow** (config/packages/workflow.yaml + WorkflowInterface), не самописные переходы.
- **Конфигурации workflow хранить по одному файлу на workflow** в `config/workflow/{name}.yaml` (например `config/workflow/company_verification.yaml`). Загрузка подключается через `config/packages/workflow.yaml` (imports `../workflow/*.yaml`). Новый workflow = новый файл в `config/workflow/`, правка `config/packages/workflow.yaml` не требуется.
- Статусы (places), `initial_marking` и переходы (from/to/to) в workflow-конфигурации указывать через **enum**: `!php/enum App\...\Enum\XStatus::CASE->value`; имена переходов — через отдельный enum переходов (`XStatusTransition`). Текстовые строки вида `pending` не использовать.
- **Все переходы по статусам** (state transitions) делать **только через symfony/workflow** — не менять статус напрямую присваиванием поля/через репозиторий. Документация статусных моделей — `domain/*-state-machine.md` (tender, auction, contract, company). Подтверждение компании суперадмином (`companies.verification_status`: pending → active/rejected/suspended) — см. `domain/company-state-machine.md`; оформлять как workflow (WorkflowInterface + guard), а не как прямое обновление колонки.
- Контракты API — в корне репозитория `api/openapi.yaml` (единый источник). Каждый новый endpoint = запись в openapi.
- События — outbox → RabbitMQ (см. `src/Shared/Events/OutboxRelayer.php`), гарантия доставки после commit.
- Аудит: каждая мутация пишет append-only запись через `App\Shared\Audit\AuditService`); trace-id сквозной (TraceContext).

### Механизм управления доступом через роли

Авторизация — декларативно через атрибут `#[IsGranted]` на методе контроллера. Аутентификация остаётся за `App\Iam\Service\AuthMiddleware` (JWT), который кладёт authenticated-токен в `TokenStorage`; Voter'ы читают пользователя из токена.

**1. Ролевой доступ** — атрибут `#[IsGranted(UserRoleEnum::X->value)]` (обязательно через `->value`, т.к. `IsGranted` принимает `string|array`, а не enum-case). Можно задать одну или несколько ролей (массив = «любая из ролей», стратегия affirmative):
```php
#[IsGranted(UserRoleEnum::ADMIN->value)]
#[IsGranted([UserRoleEnum::ADMIN->value, UserRoleEnum::MANAGER->value])]
```
Иерархия (закрывает все «младшие»): `platform_admin > admin > manager > agent`.

**2. Voter-механизм** — как дополнение к роли, или вместо конкретной роли, когда право зависит от объекта (subject). Subject передаётся аргументом контроллера (напр. через `#[MapEntity]`) и указывается в атрибуте:
```php
#[IsGranted(CompanyVoter::VERIFY, subject: 'company')]
```
Пример `App\Security\CompanyVoter` (шаблон для новых object-voters):
```php
final class CompanyVoter extends Voter
{
    final public const VERIFY = 'CompanyVerify';

    protected function supports(string $attribute, $subject): bool
    {
        return $subject instanceof Company && \in_array($attribute, [self::VERIFY], true);
    }

    protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return UserRoleEnum::PLATFORM_ADMIN === $user->getRole();
    }
}
```

**3. Permission-доступ через Voter** — когда доступ определяется не ролью, а **конкретным правом** (permission code) из каталога `domain/permissions.md` (`tenders.create`, `tenders.update`, `tenders.board.view`, …). Право — ролевое (admin/platform_admin — всегда; manager/agent — набор из `role_permissions`), поэтому такой Voter **не использует subject** и делегирует проверку `PermissionCheckerInterface::can()`:
```php
#[IsGranted(TenderVoter::VIEW)]
final class TenderVoter extends Voter
{
    final public const CREATE = 'TenderCreate';
    final public const UPDATE = 'TenderUpdate';
    final public const VIEW = 'TenderView';

    /** @var array<string, string> атрибут → permission code */
    private const CODES = [
        self::CREATE => 'tenders.create',
        self::UPDATE => 'tenders.update',
        self::VIEW => 'tenders.board.view',
    ];

    public function __construct(private readonly PermissionCheckerInterface $permissions)
    {
    }

    protected function supports(string $attribute, $subject): bool
    {
        return null === $subject && isset(self::CODES[$attribute]);
    }

    protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $this->permissions->can($user, self::CODES[$attribute]);
    }
}
```

Правила:
- **Доступ к действие, у которого есть permission code, задавать через permission-Voter**, а не через роль: `#[IsGranted(TenderVoter::VIEW)]`, а не `#[IsGranted(UserRoleEnum::AGENT->value)]`. Роль-гейт открывает «дверь» (кто вообще может дотянуться), permission — решает, разрешено ли конкретное действие (example: agent видит тендеры, но 403 на create/update).
- Permission-Voter живёт в `src/Security/`, зависит от `App\Iam\Service\PermissionCheckerInterface` (а не от конкретного `PermissionCheckService`, чтобы быть тестируемым). Конкретный сервис реализует интерфейс.
- Список permission codes — единый источник истины `domain/permissions.md`; коды в Voter'е должны совпадать с каталогом и default-матрицой (seed в миграциях).
- Принадлежность объекта компании (tenant-изоляция) permission-Voter не проверяет — она остаётся в сервисе (напр. `TenderService::resolveTender` → 404 для чужого), т.к. право ролевое и не зависит от объекта.
- Тесты: юнит `tests/Unit/Security/*VoterTest` (в т.ч. что результат = `can()`), функциональные — по ролям/правам (403 на запрещённое действие, 200/201 на разрешённое).

Правила:
- Voter'ы живут в `src/Security/` (namespace `App\Security`), наследуют `Symfony\Component\Security\Core\Authorization\Voter\Voter` и авторегистрируются (тег `security.voter`) — отдельная регистрация в `services.yaml` не нужна. Добавить `@extends Voter<string, Subject>` в docblock.
- Ролевые проверки старого образца (`$this->requireAdmin()`, ручные `if (UserRoleEnum::X !== $user->getRole())`) в контроллерах **не использовать** — заменяются на `#[IsGranted]`.
- `currentUser($request)` оставляем только чтобы получить действующего пользователя для сервисов; авторизацию он не выполняет. При отсутствии действующего пользователя в запросе бросает `App\Shared\Exception\UnauthorizedException` (401 `{title: Unauthorized, code: invalid_credentials}`), поэтому возвращает `User` без null; проверки `instanceof`/`null === $user` после вызова в контроллерах не нужны. Для `#[IsGranted]`-эндпоинтов это недостижимо (security срабатывает до контроллера), для публичных 2FA-эндпоинтов — единственная проверка доступа.
- Ответы доступа формирует `App\Security\ApiAccessDeniedHandler` (entry_point + access_denied_handler): не аутентифицирован → **401** `{title: Unauthorized, code: invalid_credentials}`; аутентифицирован, но нет прав → **403** `{title: Forbidden, code: forbidden}`. Формат совпадает с контрактом openapi.
- 401/403 при этом **не** возвращать вручную из контроллеров — их отдаёт security-компонент до вызова метода. Исключение — `UnauthorizedException` из `currentUser()` (недостижимо для `#[IsGranted]`-эндпоинтов, см. выше).
- Конфиг — `config/packages/security.yaml` (stateless firewall `api`, `access_decision_manager`).
- Тесты доступа: `tests/Unit/Security/*VoterTest` (иерархия/правила) и `tests/Functional/Security/AccessControlTest` (JSON-контракт 401/403 по ролям).

### Единая обработка ошибок API (ApiException + JsonApiExceptionSubscriber)

Контроллеры **не должны** содержать «портянки» из `try/catch` для бизнес-ошибок. Валидация входных данных, разбор id и оркестрация живут в сервисах; ошибки бросаются как исключения, которые превращаются в JSON-ответ **централизованно**.

Механизм:
- Исключение реализует интерфейс `App\Shared\Exception\ApiException` (методы `getHttpStatus()`, `getErrorCode(): ?string`, `getTitle()`).
- Оно летит из сервиса наверх, ловится подписчиком `App\Infrastructure\Http\JsonApiExceptionSubscriber` (на `kernel.exception`, авторегистрация через `#[AsEventListener]`) и оформляется в `{title, code?, detail}` с нужным HTTP-статусом.
- Готовые исключения: `ValidationException` (422), `ConflictException` (409/`conflict`), `StateTransitionException` (409/`state_transition_forbidden`), `NotFoundException` (404/`not_found`), `UnauthorizedException` (401/`invalid_credentials`, только из `currentUser()`), плюс модульные `UserNotFoundException`, `LastAdminException`, `CompanyNotFoundException`. Новые доменные ошибки — создавать как класс, реализующий `ApiException`, а не бросать голые `\RuntimeException`/`\InvalidArgumentException`.

Правила:
- В контроллере **нет** `try/catch` и ручных проверок (валидация полей, `Uuid::isValid`, enum-разбор) — всё это в сервисе, который бросает ApiException.
- Невалидный UUID/не найденная сущность в сервисе — бросать `*NotFoundException` (404), не `\InvalidArgumentException`.
- 401/403 при этом **не** бросать как ApiException — их по-прежнему отдаёт security-компонент (`ApiAccessDeniedHandler`) до вызова контроллера.
- Примеры: `UserInviteController`/`UserDeleteController`/`UserUpdateController` → `UserManagementService`; `CompanyVerifyController` → `CompanyVerificationService`.

### Стиль кода

- `declare(strict_types=1)` везде; классы `final`; docblock-комментарии **на русском**, поясняющие инварианты и ссылки на требования (FR-*).
- Форматирование кода — **только через PHP CS Fixer** (`.php-cs-fixer.dist.php`, `@Symfony` + `@Symfony:risky`): перед сдачей выполнять `composer cs-fixer:check`, расхождения чинить `composer cs-fixer`. Ручное выравнивание не вводить — его всё равно поправит фиксер.
- Комментарии — только там, где объясняют «почему» (правило проекта: без лишних комментариев, но RU-докблоки в сервисах обязательны).
- Enum-колонки Doctrine: `enumType: SomeEnum::class`.
- UUID v4 — суррогатные ключи; в AuditLog/сущностях id типа uuid/bigint по данным model.

## Контракт окружения (.env)

Обязательные переменные (контракт, см. `.env.example` в корне репо и `config/services.yaml`):

| Переменная | Назначение |
|---|---|
| `DATABASE_URL` | PostgreSQL (doctrine) |
| `REDIS_URL` | Redis (rate limit, состояние). Формат — `redis://host:port`, **без пути**: номер БД дописывает конфиг из параметра `redis_db` (0 в dev/prod, 1 в тестах) |
| `RABBITMQ_URL` / `MESSENGER_TRANSPORT_DSN` | AMQP (messenger, очередь `tender_events`) |
| `MESSENGER_EMAILS_DSN` | AMQP-канал почты (отдельная очередь `tender_emails`; письма async через messenger) |
| `MERCURE_URL`, `MERCURE_PUBLIC_URL`, `MERCURE_PUBLISH_URL`, `MERCURE_JWT_SECRET_PUBLISH`, `MERCURE_JWT_SECRET_SUBSCRIBE`, `MERCURE_SUBSCRIBE_TTL` | SSE-стримы аукционов: publish/subscribe JWT (HS256) |
| `MAILER_DSN`, `MAILER_FROM` | почта |
| `EMAIL_VERIFY_TTL` (3600), `EMAIL_VERIFY_URL_TEMPLATE` | токен подтверждения email |
| `AUTH_JWT_SECRET` (64 hex), `AUTH_ACCESS_TTL` (900), `AUTH_REFRESH_TTL` (2592000) | JWT/refresh |
| `DOMAIN_TIMEZONE` | доменный пояс (Europe/Moscow) |
| `APP_SECRET`, `LOCK_DSN`, `FILES_STORAGE`/`FILES_LOCAL_DIR` | фреймворк/файлы |

## Тесты

- `tests/Unit` — чистая логика без контейнера (Money, Totp, …).
- `tests/Functional` — WebTestCase (kernel + клиент); НЕ вызывать `createClient()` больше одного раза за тест и не звать `getContainer()` до него (исключение Symfony 8.1).
- `tests/Integration` — сервисы с контейнером/внешними ресурсами.
- Rate limit в тестах = 3/мин на IP (config/packages/test/rate_limiter.yaml): каждый запрос с уникального IP — через `$client->setServerParameter('REMOTE_ADDR', ...)` на ОДНОМ клиенте.
- Изоляция БД — **dama/doctrine-test-bundle** (config/packages/test/dama_doctrine_test_bundle.yaml + PHPUnitExtension в phpunit.dist.xml): каждый тест в транзакции BEGIN...ROLLBACK, ручная чистка (DQL DELETE) НЕ нужна. Пропуск отката — `#[SkipDatabaseRollback]`, если тест управляет транзакциями сам.
- **Изоляция Redis — параметр `redis_db`** (`config/services/infrastructure.yaml`): dev/prod — db 0, тесты — db 1 (`when@test`), тот же приём, что `dbname_suffix: _test` у Doctrine. Номер БД задаётся параметром, а не в `REDIS_URL`: последний приходит переменной окружения контейнера, а такие переменные `.env.test` не перекрывает. Без разделения поднятый dev-стек ломает прогон: `analytics:counters:snapshot` из планировщика сканирует `ctr:*` ВСЕХ тенантов и удаляет перенесённые в PG ключи — счётчики теста исчезали между инкрементом и проверкой (`AnalyticsE2ETest`). `app:test:redis-cleanup` (в `composer test:prepare`) чистит ту же db 1.
- **`composer test` самовосстанавливается**: перед прогоном выполняется `composer test:prepare` (drop+create+migrate test-БД) — мусор от прерванного/упавшего прогона стирается автоматически. Ручной сброс в любой момент: `composer test:prepare`. Для точечного прогона без сброса — `php bin/phpunit tests/...` напрямую.
- **`#[SkipDatabaseRollback]`-тесты обязаны чистить созданное в `tearDown()`** (выполняется и при падении assert), а не в конце тела теста. Общая реализация — трейт `App\Tests\Support\AuctionDataCleanerTrait` (DELETE по цепочке FK + точечная чистка Redis-ключей `auction:state:*`/`auction:heartbeat:*`; db 1 общая у параллельных воркеров — глобальный FLUSHALL/FLUSHDB не использовать). Новые тесты с реальными COMMIT используют этот трейт.

### Фабрики (`tests/Factory`, namespace `App\Tests\Factory`)

- Тестовые данные — **zenstruck/foundry** (v2, `PersistentObjectFactory`). doctrine-fixtures НЕ используется.
- ⚠️ **Не использовать `PersistentProxyObjectFactory`/`proxy()`** — деприкатед в Foundry 2.11 и **не работает на Symfony 8** (`LazyProxyTrait` удалён из var-exporter): фабрики возвращают **обычные объекты**, не Proxy.
- Фабрики регистрируются как сервисы в `config/packages/zenstruck_foundry.yaml` (`services:` для `App\Tests\Factory\`), поэтому могут иметь конструкторные зависимости (напр. `UserPasswordHasherInterface` в `UserFactory`).
- Путь и namespace: `tests/Factory/<Entity>Factory.php` → `App\Tests\Factory\<Entity>Factory`.
- Класс: `final class XFactory extends PersistentObjectFactory`, методы `public static function class(): string` и `protected function defaults(): array`.
- Docblock: `@extends PersistentObjectFactory<X>` + `@method`/`@phpstan-method` для магических статик (`createOne`, `createMany`, `find`, `random`, `all`, …). Типизировать параметры `array<string, mixed> $attributes` (иначе phpstan `missingType.iterableValue`).
- Уникальные значения — `self::faker()->unique()->...` (email, inn, code, tokenHash).
- scaffold-состояния — fluent-методы, возвращающие `static`:
  - простые атрибуты: `return $this->with([...]);`
  - вызов мутатора сущности после создания: `return $this->afterInstantiate(fn (X $e) => $e->someMethod());` (напр. `CompanyFactory::approved()`, `RefreshTokenFactory::revoked()`).
- Скалярные FK-поля (`companyId`, `userId`) задавать **явно** id созданной сущности, а не фабрикой (фабрика в скалярное поле даст TypeError). Для значения по умолчанию — `Zenstruck\Foundry\LazyValue::new(fn (): Uuid => UserFactory::createOne()->getId())`.
- Доп. атрибуты, которых нет в конструкторе/сеттерах сущности (напр. `password`, `verified` в `UserFactory`), обрабатывать через `instantiateWith(Instantiator::withConstructor()->allowExtra('password','verified'))` + `afterInstantiate`; сами ключи держать в `defaults()`.

### Стори (`tests/Story`, namespace `App\Tests\Story`)

- Стори — сценарии подготовки данных из фабрик для групп тестов. `final class extends Zenstruck\Foundry\Story`, метод `public function build(): void`.
- В `build()` вызываются фабрики (напр. `CompanyFactory::new([...])->approved()->createOne()`, `UserFactory::createOne([...])`) и результат регистрируется через `$this->addState('user', $user)`.
- Доступ в тестах — магические методы (имя = имя состояния): `VerifiedUserStory::user()` / `::company()`. Для подсказок PHPStan добавить `@method static User user()` в docblock стори.
- Стори пересоздаются на каждый тест (PHPUnit-расширение Foundry вызывает shutdown после каждого теста), поэтому объекты всегда из текущей транзакции.
- Константы фикстуры (email/password) держать в стори и ссылаться из теста: `self::EMAIL = VerifiedUserStory::EMAIL`.
- Story Registry не требует `#[AsFixture]` для тестовых стори — они грузятся явно вызовом `::user()`/`::company()`.

### Правила для существующих тестов

- Сущности в функциональных тестах создавать через фабрики/стори, а не вручную persist+flush (пример: `AuthenticationTest::createVerifiedUser()` → `VerifiedUserStory::user()`).
- Пароль и статус верификации пользователя не хардкодить в тесте — брать из `UserFactory`/`VerifiedUserStory` (переопределять атрибутами при необходимости).

## CI (GitHub Actions)

`app/.github/workflows/ci.yml`: lint → php-cs-fixer check → phpstan → phparkitect → phpunit (сервисы postgres/redis/rabbitmq; перед phpunit выполняются миграции) → docker build. Цель: зелёный конвейер на каждом push/PR.
