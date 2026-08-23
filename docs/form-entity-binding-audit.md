# Резольвинг форм в Entity вместо Input-DTO — аудит контроллеров

Дата: 2026-08-22. Задача 2 из `app/var/todo.md`. **Статус: реализовано** — оба кандидата
(P1 и P2) переведены на entity-bound формы, `api/openapi.yaml` и `AGENTS.md` поправлены.
Раздел «Что сделано» — в конце файла.

Цель — найти контроллеры, где форму можно привязать напрямую к сущности
(`data_class = Entity::class`, `formInput(..., data: $entity, clearMissing: false)`)
и отказаться от промежуточного `Input`-DTO. Паттерн и его обязательные части описаны
в `AGENTS.md` → «Entity-bound update формы»; канонический пример —
`CompanyUpdateController` / `CompanyUpdateType` / `UpdateCompanyUseCase`.

## Что просмотрено

| Срез | Значение |
|---|---|
| Контроллеров в `src/**/Controller/` | 124 |
| Вызовов `formInput()` | 55 (54 через Input-DTO, 1 entity-bound) |
| Эндпоинтов PATCH/PUT (обновление существующей сущности) | 11 |
| Контроллеров с ручным разбором тела (`jsonBody`/`stringField`) | 0 — валидация через формы внедрена полностью |

Остальные 44 вызова `formInput()` — это create-операции, действия/переходы статусов
(`/finish`, `/cancel`, `/qualify`, `/answer`, `/sign`, auth-эндпоинты) и bulk-операции.
Под entity-bound паттерн они по определению не подходят: либо сущности ещё нет, либо
тело описывает не поля сущности, а команду.

## Итоги по всем 11 update-эндпоинтам

| # | Эндпоинт | Контроллер | Сейчас | Вердикт |
|---|---|---|---|---|
| 1 | `PATCH /companies/{companyId}` | `Iam/Controller/Company/CompanyUpdateController` | **entity-bound** | Эталон, менять нечего |
| 2 | `PUT /document-types/{documentTypeId}` | `Document/Controller/DocumentTypeUpdateController` | **entity-bound** | Переведён (P1) |
| 3 | `PATCH /webhooks/{webhookId}` | `Platform/Controller/Webhook/WebhookUpdateController` | **entity-bound** | Переведён (P2) |
| 4 | `PATCH /tenders/{tenderId}` | `Tender/Controller/TenderUpdateController` | Input-DTO | Оставить |
| 5 | `PATCH /tenders/{tenderId}/lots/{lotId}` | `Tender/Controller/TenderLotUpdateController` | Input-DTO | Оставить |
| 6 | `PATCH /auctions/{auctionId}` | `Auction/Controller/AuctionUpdateController` | Input-DTO + `NOT_SET` | Оставить |
| 7 | `PATCH /users/{userId}` | `Iam/Controller/User/UserUpdateController` | Input-DTO | Оставить |
| 8 | `PATCH /users/me` | `Iam/Controller/User/UpdateMeController` | Input-DTO | Оставить |
| 9 | `PUT /roles/{role}/permissions` | `Iam/Controller/Permission/RolePermissionUpdateController` | Input-DTO | Оставить |
| 10 | `PUT /supplier-profile` | `Supplier/Controller/SupplierProfileUpdateController` | Input-DTO | Оставить |
| 11 | `PUT /platform/timezone` | `Platform/Controller/Platform/PlatformTimezoneUpdateController` | Input-DTO | Оставить |

### Почему «оставить» — по каждому

- **`PATCH /tenders/{id}`** — `change_reason` пишется только в аудит и на сущности его нет;
  `timeline` — массив ключевых дат, который раскладывается доменным сервисом, а не сеттером.
- **`PATCH /tenders/{tid}/lots/{lid}`** — сплошные трансформации на пути в сущность:
  `vat_rate` (проценты) → `vatRateBps`, `price_basis` (строка) → enum, `execution_start_at`
  (строка) → `DateTimeImmutable`; плюс `Lot::update()` — доменный метод, а после него
  пересчитывается инвариант суммы лотов против НМЦК тендера (409). Лот резолвится внутри
  агрегата тендера (tenant-изоляция), `#[MapEntity]` здесь не применим.
- **`PATCH /auctions/{id}`** — реально использует `NOT_SET`-маркеры (единственное место в `src`,
  где они остались) для non-nullable числовых полей, где явный `null` = сброс.
- **`PATCH /users/{id}`** — `role` и `status` меняются через symfony/workflow, а не сеттером.
- **`PATCH /users/me`** — `current_password` / `new_password` на сущности отсутствуют.
- **`PUT /roles/{role}/permissions`** — bulk-обновление набора связей, не полей одной сущности.
- **`PUT /supplier-profile`** — upsert: профиль может не существовать и создаётся на лету,
  то есть резолвить сущность до формы нечем.
- **`PUT /platform/timezone`** — это не сущность вовсе, а key-value настройка платформы
  (`PlatformSettingsService::setTimezone`).

## Кандидат P1 — `PUT /document-types/{documentTypeId}`

Самый чистый случай во всём проекте: все 5 полей формы мапятся 1:1 на сеттеры
`DocumentType`, ни одной трансформации, tenancy нет (эндпоинт только для `platform_admin`).

| Поле формы | Свойство сущности | Тип | Трансформация |
|---|---|---|---|
| `name` | `name` | `string` (NOT NULL) | нет → `setName()` |
| `owner_role` | `ownerRole` | `string` (NOT NULL) | нет → `setOwnerRole()` |
| `visibility` | `visibility` | `string` (NOT NULL) | нет → `setVisibility()` |
| `required` | `required` | `bool` (NOT NULL) | нет → `setRequired()` |
| `active` | `active` | `bool` (NOT NULL) | нет → `setActive()` |

`DocumentTypeService::update()` сегодня — это пять `if (null !== $input->x) { $type->setX(...) }`,
то есть ручная реализация того, что даёт `clearMissing: false`.

Что даст переход: минус `UpdateDocumentTypeInput`, минус пять `if`-ов в сервисе, резолв
сущности переезжает в контроллер (`#[MapEntity]` → 404 автоматически).

Что нужно сделать:
1. `UpdateDocumentTypeType`: `data_class = DocumentType::class`; `name` получает
   `empty_data`-closure, возвращающую текущее значение (non-nullable поле, пустая строка = оставить).
2. Контроллер: `#[MapEntity(mapping: ['documentTypeId' => 'id'])] DocumentType $type`,
   `$before = clone $type;`, `formInput(..., strict: true, data: $type, clearMissing: false)`.
3. `UpdateDocumentTypeUseCase::execute(User, DocumentType $type, DocumentType $before, ?string $ip)`
   → flush + audit + презентация.

Оговорка (документирована в `AGENTS.md`): для `ChoiceType`/`CheckboxType` на non-nullable
свойствах явный `null` в теле в entity-bound форме ведёт себя иначе, чем в DTO
(`null` = «не менять» превращается в `false`/ошибку). **По контракту это безопасно**:
в `api/openapi.yaml` (`/document-types/{documentTypeId}` PUT) ни одно из полей не помечено
`nullable: true` — явный `null` в контракте не предусмотрен. Тесты на явный `null` нужно
проверить перед правкой.

**Побочная находка (баг контракта, не связан с задачей):** в `api/openapi.yaml` параметр
`documentTypeId` описан как `{ type: string, format: uuid }`, а `DocumentType::$id` —
`bigint`. Контракт врёт про тип идентификатора.

## Кандидат P2 — `PATCH /webhooks/{webhookId}`

Поля тоже мапятся 1:1 (`url`, `events`, `status`), но переход дороже:

- `status` на сущности — `WebhookStatusEnum`, в форме — строковый `ChoiceType`;
  нужен `EnumType` или трансформер.
- `events` — сервис дедуплицирует список (`array_values` по ключам); нужен `CallbackTransformer`.
- `url` — сервис делает `trim` + проверку схемы `http/https`; переносится в constraints формы
  (`Url(protocols: ['http','https'])`), но эти же хелперы (`url()`, `events()`, `status()`)
  используются методом `create()` — их нельзя просто удалить, придётся разводить валидацию
  create/update по формам.
- Резолв: `resolveOwned()` делает tenant-проверку (чужой webhook → 404). Нужен либо
  `#[MapEntity]` + доработка `WebhookVoter` до проверки с subject, либо
  `WebhookRepository::findOwnedOrFail()`.

Выгода та же (минус DTO, минус ветвления), цена — правка воутера и разделение валидации
с create-веткой. Предлагаю делать только после P1 и отдельной сессией.

## Расхождение с AGENTS.md

В `AGENTS.md`, в списке документированных исключений, значится:
«PATCH с NOT_SET-маркерами … (`TenderUpdate`/`AuctionUpdate`/`LotUpdate`)».
Фактически `NOT_SET` остался только в `Auction`; у `TenderUpdate` и `LotUpdate` причины
другие (производные поля / трансформации и инварианты). Формулировку стоит поправить —
после утверждения правок по P1/P2, одной правкой.

---

## Что сделано (2026-08-22)

### P1 — `PUT /document-types/{documentTypeId}`

- Новый `src/Document/Repository/DocumentTypeRepository.php` с `findOrFail(string): DocumentType`
  (нечисловой/несуществующий id → `NotFoundException` → 404). Идентификатор типа — bigint,
  поэтому `#[MapEntity]` не используется: строка из route не должна доезжать до приведения
  типа в Doctrine.
- `UpdateDocumentTypeType` привязан к `DocumentType` (`data_class`); у `name`/`owner_role`/
  `visibility` — `empty_data`-замыкания с текущим значением (свойства NOT NULL).
- Контроллер резолвит тип, снимает `clone` для аудита и сабмитит форму с
  `strict: true, data: $type, clearMissing: false`.
- `UpdateDocumentTypeUseCase` теперь сам делает flush + аудит + презентацию.
- Удалены `Document\Input\UpdateDocumentTypeInput` и метод `update()` — из реализации
  `DocumentTypeService` и из публичного контракта `App\Document\DocumentTypeService`
  (других потребителей у метода не было).
- Тесты: `testUpdateKeepsFieldsMissingFromBody` (частичное обновление не сбрасывает
  остальные поля), `testUpdateUnknownDocumentTypeReturns404`.

### P2 — `PATCH /webhooks/{webhookId}`

- `WebhookRepository::findOwnedOrFail(string $webhookId, Uuid $tenantId)` — резолв с
  tenant-изоляцией (чужая подписка неотличима от несуществующей → 404).
- `WebhookUpdateType` привязан к `Webhook`: `url` — `UrlType` + `Url(protocols: http/https)`
  и `Length(max: 2048)`; `status` — `EnumType` (свойство сущности типизировано
  `WebhookStatusEnum`, `ChoiceType` отдал бы в сеттер строку); `events` — коллекция с
  дедупликацией через `CallbackTransformer`.
- Явный `events: []` отклоняется слушателем `PRE_SUBMIT` на поле. Так пришлось сделать
  потому, что при `clearMissing: false` пустой массив до полей коллекции не доходит —
  существующие элементы просто остаются, и `Count(min: 1)` его не видит; без этой проверки
  запрос, который раньше давал 422, молча возвращал бы 200.
- Контроллер берёт тенанта через `InputValue::companyId()` (то же исключение
  `Actor has no company`, что и прежний `WebhookService::requireCompany()`).
- `UpdateWebhookUseCase` делает flush + аудит + презентацию; `WebhookService::update()`
  и `Platform\Input\UpdateWebhookInput` удалены. Хелперы `url()`/`events()`/`status()`
  в `WebhookService` остались — их использует `create()`.
- Тесты: `testUpdateKeepsFieldsMissingFromBodyAndDeduplicatesEvents`,
  `testUpdateValidatesUrlAndEvents` (пустой список, неверный формат события, не-http url).

### Документация и контракт

- `api/openapi.yaml`: параметр пути `documentTypeId` был описан как `{type: string, format: uuid}`,
  хотя `DocumentType::$id` — bigint (и схема `DocumentType.id` уже была `integer/int64`).
  Исправлено на `{type: integer, format: int64, minimum: 1}` в обеих операциях
  (PUT и DELETE `/document-types/{documentTypeId}`).
- `AGENTS.md`, раздел «Entity-bound update формы»: добавлены оба новых канонических примера;
  добавлено правило про `EnumType` для enum-свойств сущности и про запрет очистки коллекции
  через `PRE_SUBMIT`; список документированных исключений выверен по фактическому коду —
  `NOT_SET` остался только в `AuctionUpdate`, у `TenderUpdate`/`LotUpdate` вписаны реальные
  причины, добавлен `PlatformTimezoneUpdate`.

### Проверки

`composer lint`, `composer cs-fixer:check`, `composer phpstan` (level max), `composer arkitect`,
`tests/Functional/Document/DocumentTypeTest.php` + `tests/Functional/Platform/WebhookCrudTest.php`
(17 тестов, 148 проверок) — всё зелёное.
