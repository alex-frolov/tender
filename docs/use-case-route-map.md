# Аудит документации: кейсы ↔ маршруты

- **Дата:** 2026-08-22
- **Назначение:** сверить документацию репозитория с реальным набором HTTP-маршрутов приложения, найти пробелы и закрыть их; дать сквозную карту «пользовательский кейс → маршрут», в которой у каждого маршрута есть кейс, а у каждого кейса — маршруты.
- **Источник истины по маршрутам:** `php bin/console debug:router` (121 маршрут `/api/v1/*` + 4 служебных: `/health/live`, `/health/ready`, `/metrics`, `/scalar`).
- **Связанные документы:** `../../api/openapi.yaml` (контракт), `../../domain/use-cases.md` (кейсы), `../../api/api-methods.md` (группы AM-*), `api.md` (справочник эндпоинтов).

---

## 1. Как проверялось

Три независимых списка эндпоинтов сведены между собой:

1. **Код** — `debug:router`, только маршруты `/api/v1/*` (служебные `/health/*`, `/metrics`, `/scalar` и `_profiler` исключены);
2. **Контракт** — операции из `api/openapi.yaml` (пары «метод + путь»);
3. **Справочник разработчика** — таблицы эндпоинтов в `app/docs/api.md`.

Затем каждый маршрут из кода привязан к кейсу из `domain/use-cases.md`. Маршруты, которым
кейса не нашлось, стали основанием для новых кейсов (раздел 4) — так, чтобы после правки
покрытие было полным в обе стороны.

---

## 2. Результат сверки

| Сверка | Итог |
|---|---|
| Код ↔ `api/openapi.yaml` | **121 из 121, расхождений нет.** Контракт полный: ни одного маршрута без операции, ни одной операции без маршрута |
| Код ↔ `app/docs/api.md` (до правки) | **102 из 121.** Не описан 21 эндпоинт, один описан неверно |
| Код ↔ `app/docs/api.md` (после правки) | **121 из 121** (см. раздел 2.1) |
| Код ↔ `domain/use-cases.md` (до правки) | **109 из 121.** 12 маршрутов не покрывались ни одним кейсом |
| Код ↔ `domain/use-cases.md` (после правки) | **121 из 121** (см. раздел 4) |

### 2.1. Что не было описано в `app/docs/api.md`

Пробел был именно в справочнике разработчика — контракт `openapi.yaml` эти эндпоинты
описывал. Отсутствовали:

| Эндпоинт | Контроллер | Почему пропущен |
|---|---|---|
| `GET /users/me`, `PATCH /users/me` | `MeController`, `UpdateMeController` | раздел «Users & companies» описывал только управление чужими учётками |
| `GET /companies`, `PATCH /companies` | `CompanyGetController`, `CompanyUpdateController` | карточка своей компании |
| `GET /admin/companies` | `CompanyListController` | список компаний для суперадмина |
| `GET /suppliers/profile`, `PUT /suppliers/profile`, `GET /suppliers/{supplierId}` | `SupplierProfile*`, `SupplierGetController` | профиль поставщика не был описан вовсе |
| `GET /platform/timezone`, `PUT /platform/timezone` | `PlatformTimezone*Controller` | настройка площадки |
| `GET /tenders/{tenderId}/lots`, `POST /tenders/{tenderId}/lots`, `PATCH …/lots/{lotId}`, `DELETE …/lots/{lotId}` | `TenderLot*Controller` | вся работа с лотами |
| `GET /auctions`, `GET /auctions/stream` | `AuctionListController`, `AuctionsStreamController` | список аукционов и общий SSE-дискавери |
| `GET /procurement-plans`, `POST /procurement-plans` | `ProcurementPlan*Controller` | планы закупок |
| `POST /contract_tenders/{contractTenderId}/stages` | `ContractStageCreateController` | этапы исполнения договора |
| `GET /usage`, `GET /rate-limits` | `UsageController`, `RateLimitsController` | квоты и лимиты |

Описан **неверно**: `DELETE /notifications/subscriptions/{subscriptionId}` — в коде id
передаётся query-параметром (`DELETE /notifications/subscriptions?subscriptionId=…`,
общий `EntityIdQueryType`), как у `favorites` и `saved-searches`.

**Исправлено:** `app/docs/api.md` дополнен всеми перечисленными эндпоинтами, ошибочный
путь приведён к реальному.

### 2.2. Чего не хватало в кейсах

Двенадцать маршрутов не были описаны ни одним кейсом:

| Маршруты | Чего не было в кейсах |
|---|---|
| `POST /tenders/{tenderId}/withdraw` | отзыв публикации до старта приёма заявок (статус `withdrawn` в state-machine есть, кейса не было) |
| `POST /auctions/{auctionId}/cancel` | отмена аукциона заказчиком |
| `GET /contract-types`, `POST /contract-types` | справочник типов договоров |
| `GET /dashboard`, `GET /stats/tenders` | сводка и статистика закупок |
| `GET /permissions`, `GET /role-permissions`, `PUT /role-permissions` | настройка набора прав роли (FR-1.5.11 был, кейса не было) |
| `GET /platform/timezone`, `PUT /platform/timezone` | часовой пояс площадки |

**Исправлено:** в `domain/use-cases.md` добавлены кейсы UC-41…UC-46 (раздел 4).

---

## 3. Карта «кейс → маршрут»

Полное покрытие: каждый из 121 маршрута `/api/v1/*` привязан к кейсу.
Служебные маршруты (`GET /health/live`, `GET /health/ready`, `GET /metrics`, `GET /scalar`)
кейсами не описываются — это эксплуатация, см. `../../operations/observability.md`.

### Аутентификация и сессии

| Метод и путь | Кейс | Действие |
|---|---|---|
| `POST /auth/2fa/confirm` | UC-36 | включение TOTP |
| `POST /auth/2fa/disable` | UC-36 | отключение TOTP |
| `POST /auth/2fa/setup` | UC-36 | начало настройки TOTP |
| `POST /auth/email/resend` | UC-37 | повторная отправка письма |
| `POST /auth/email/verify` | UC-37 | подтверждение email |
| `POST /auth/logout` | UC-36 | выход, отзыв refresh |
| `POST /auth/password/forgot` | UC-37 | запрос сброса пароля |
| `POST /auth/password/reset` | UC-37 | сброс пароля по токену |
| `POST /auth/refresh` | UC-36 | ротация refresh-токена |
| `POST /auth/register` | UC-35 | регистрация компании и первого админа |
| `POST /auth/token` | UC-36 | вход (пароль + TOTP) |

### Пользователи, компании, права

| Метод и путь | Кейс | Действие |
|---|---|---|
| `GET /admin/companies` | UC-38 | список компаний площадки (суперадмин) |
| `GET /companies` | UC-16, UC-40 | карточка своей компании |
| `PATCH /companies` | UC-16, UC-40 | правка реквизитов компании |
| `GET /companies/search` | UC-08d | поиск контрагента (ИНН/название) |
| `POST /companies/{companyId}/verify` | UC-38 | подтверждение компании суперадмином |
| `GET /permissions` | UC-45 | каталог прав |
| `GET /platform/timezone` | UC-46 | часовой пояс площадки |
| `PUT /platform/timezone` | UC-46 | смена часового пояса площадки |
| `GET /role-permissions` | UC-45 | матрица прав по ролям |
| `PUT /role-permissions` | UC-45 | правка набора прав роли |
| `GET /suppliers/profile` | UC-16 | свой профиль поставщика |
| `PUT /suppliers/profile` | UC-16 | правка профиля поставщика |
| `GET /suppliers/{supplierId}` | UC-16 | публичный профиль поставщика |
| `GET /users` | UC-39 | пользователи компании |
| `POST /users` | UC-39 | приглашение сотрудника |
| `GET /users/me` | UC-40 | свой профиль |
| `PATCH /users/me` | UC-40 | правка профиля / смена пароля |
| `PATCH /users/{userId}` | UC-39 | смена роли/статуса |
| `DELETE /users/{userId}` | UC-39 | soft-delete сотрудника |

### Тендеры и лоты

| Метод и путь | Кейс | Действие |
|---|---|---|
| `GET /tenders` | UC-17 | каталог тендеров |
| `POST /tenders` | UC-01 | создание тендера с лотами |
| `GET /tenders/{tenderId}` | UC-17 | карточка тендера |
| `PATCH /tenders/{tenderId}` | UC-03 | правка тендера после публикации |
| `GET /tenders/{tenderId}/access` | UC-17a | проверка доступа к закрытому тендеру |
| `POST /tenders/{tenderId}/cancel` | UC-04 | отмена тендера с причиной |
| `GET /tenders/{tenderId}/lots` | UC-01, UC-17 | лоты тендера |
| `POST /tenders/{tenderId}/lots` | UC-01 | добавление лота |
| `PATCH /tenders/{tenderId}/lots/{lotId}` | UC-01, UC-03 | правка лота |
| `DELETE /tenders/{tenderId}/lots/{lotId}` | UC-01, UC-03 | удаление лота |
| `POST /tenders/{tenderId}/publish` | UC-02 | публикация и расчёт таймлайна |
| `POST /tenders/{tenderId}/rating` | UC-10c | оценка исполнения заказа |
| `POST /tenders/{tenderId}/withdraw` | UC-41 | отзыв публикации до приёма заявок |

### Заявки участников

| Метод и путь | Кейс | Действие |
|---|---|---|
| `POST /bids/{bidId}/documents` | UC-19a | документы второй части заявки |
| `POST /bids/{bidId}/qualification` | UC-05 | допуск/отклонение заявки |
| `POST /bids/{bidId}/withdraw` | UC-20 | отзыв заявки |
| `GET /tenders/{tenderId}/bids` | UC-05, UC-06 | заявки тендера (до/после вскрытия) |
| `POST /tenders/{tenderId}/bids` | UC-19 | подача запечатанной заявки |

### Аукцион (торги и исполнение)

| Метод и путь | Кейс | Действие |
|---|---|---|
| `GET /auctions` | UC-12, UC-21 | список видимых аукционов |
| `POST /auctions` | UC-12 | создание аукциона на лот |
| `GET /auctions/stream` | UC-21 | SSE-дискавери по всем видимым аукционам |
| `PATCH /auctions/{auctionId}` | UC-12 | правка правил до старта торгов |
| `GET /auctions/{auctionId}/bids` | UC-13 | история ставок |
| `POST /auctions/{auctionId}/bids` | UC-13, UC-29, UC-30 | ставка в ходе торгов |
| `POST /auctions/{auctionId}/cancel` | UC-42 | отмена аукциона |
| `POST /auctions/{auctionId}/confirm-done` | UC-10 | подтверждение выполнения заказчиком |
| `POST /auctions/{auctionId}/finish` | UC-14 | завершение торгов |
| `POST /auctions/{auctionId}/mark-done` | UC-10 | отметка о выполнении исполнителем |
| `POST /auctions/{auctionId}/schedule` | UC-12 | планирование старта торгов |
| `POST /auctions/{auctionId}/start-work` | UC-10 | старт работ исполнителем |
| `GET /auctions/{auctionId}/state` | UC-15, UC-21 | состояние аукциона (снапшот) |
| `GET /auctions/{auctionId}/stream` | UC-15, UC-21 | SSE-дискавери одного аукциона |
| `POST /auctions/{auctionId}/winner` | UC-07, UC-13a | выбор/утверждение победителя |

### Вопросы и претензии

| Метод и путь | Кейс | Действие |
|---|---|---|
| `GET /complaints` | UC-22 | претензии компании |
| `POST /tenders/{tenderId}/complaints` | UC-22 | претензия по тендеру |
| `GET /tenders/{tenderId}/questions` | UC-22 | вопросы и ответы по тендеру |
| `POST /tenders/{tenderId}/questions` | UC-22 | вопрос к документации |
| `POST /tenders/{tenderId}/questions/{questionId}/answer` | UC-22 | ответ заказчика |

### Документы

| Метод и путь | Кейс | Действие |
|---|---|---|
| `GET /document-types` | UC-33a | справочник типов документов |
| `POST /document-types` | UC-33a | создание типа документа |
| `PUT /document-types/{documentTypeId}` | UC-33a | правка типа документа |
| `DELETE /document-types/{documentTypeId}` | UC-33a | деактивация типа документа |
| `GET /documents` | UC-19a | документы сущности |
| `POST /documents` | UC-19a | загрузка документа / новой версии |
| `GET /documents/{documentId}` | UC-19a | карточка документа |
| `GET /documents/{documentId}/download` | UC-19a | скачивание файла |

### Договоры, обеспечения, претензии по договору

| Метод и путь | Кейс | Действие |
|---|---|---|
| `GET /claims` | UC-10a | претензии по договорам |
| `POST /claims` | UC-10a | создание претензии |
| `POST /claims/{claimId}/resolve` | UC-10a | разрешение претензии |
| `GET /contract-types` | UC-43 | справочник типов договоров |
| `POST /contract-types` | UC-43 | создание типа договора |
| `POST /contract_tenders/{contractTenderId}/stages` | UC-10 | этап исполнения договора |
| `GET /contracts` | UC-08, UC-10b | договоры компании |
| `POST /contracts` | UC-08, UC-08d | создание договора (из тендера/рамочного) |
| `GET /contracts/{contractId}` | UC-08, UC-10b | карточка договора |
| `POST /contracts/{contractId}/scan` | UC-08a | приложение скана договора |
| `POST /contracts/{contractId}/send-for-signature` | UC-08, UC-10b | отправка на подписание |
| `POST /contracts/{contractId}/sign` | UC-08, UC-10b | подпись стороны |
| `POST /contracts/{contractId}/tenders` | UC-08d | привязка тендера к multi_use-договору |
| `GET /securities` | UC-09 | обеспечения компании |
| `POST /securities/{securityId}/forfeit` | UC-09 | удержание обеспечения |
| `POST /securities/{securityId}/release` | UC-09 | возврат обеспечения |

### Мониторинг рынка и уведомления

| Метод и путь | Кейс | Действие |
|---|---|---|
| `GET /favorites` | UC-17 | избранные тендеры |
| `POST /favorites` | UC-17 | добавление в избранное |
| `DELETE /favorites` | UC-17 | удаление из избранного |
| `GET /notifications/subscriptions` | UC-18 | подписки на уведомления |
| `POST /notifications/subscriptions` | UC-18 | создание подписки |
| `DELETE /notifications/subscriptions` | UC-18 | удаление подписки |
| `POST /notifications/subscriptions/{subscriptionId}/toggle` | UC-18 | вкл/выкл подписки |
| `GET /saved-searches` | UC-17 | сохранённые поиски |
| `POST /saved-searches` | UC-17 | сохранение поиска |
| `DELETE /saved-searches` | UC-17 | удаление поиска |
| `GET /webhooks` | UC-25 | webhook-подписки |
| `POST /webhooks` | UC-25 | создание подписки |
| `PATCH /webhooks/{webhookId}` | UC-25 | правка подписки |
| `DELETE /webhooks/{webhookId}` | UC-25 | удаление подписки |
| `GET /webhooks/{webhookId}/deliveries` | UC-25 | журнал доставок |
| `POST /webhooks/{webhookId}/rotate-secret` | UC-25 | ротация секрета подписи |

### Аналитика, выгрузки, планы, квоты

| Метод и путь | Кейс | Действие |
|---|---|---|
| `GET /api-keys` | UC-28 | список API-ключей компании |
| `POST /api-keys` | UC-28 | выпуск API-ключа |
| `DELETE /api-keys/{apiKeyId}` | UC-28 | отзыв API-ключа |
| `POST /api-keys/{apiKeyId}/rotate` | UC-28 | ротация секрета ключа |
| `GET /dashboard` | UC-44 | сводка по закупкам компании |
| `POST /exports` | UC-31 | постановка выгрузки в очередь |
| `GET /exports/{jobId}` | UC-31 | статус выгрузки |
| `GET /exports/{jobId}/download` | UC-31 | скачивание выгрузки |
| `GET /procurement-plans` | UC-11 | планы закупок |
| `POST /procurement-plans` | UC-11 | создание плана закупок |
| `GET /rate-limits` | UC-24 | текущие лимиты запросов |
| `GET /stats/tenders` | UC-44 | статистика тендеров |
| `GET /usage` | UC-23 | потребление квот тенанта |

---

## 4. Новые кейсы (закрывают пробел)

Добавлены в `../../domain/use-cases.md`:

| Кейс | Роль | Маршруты |
|---|---|---|
| **UC-41** Отзыв публикации тендера | заказчик | `POST /tenders/{tenderId}/withdraw` |
| **UC-42** Отмена аукциона | заказчик | `POST /auctions/{auctionId}/cancel` |
| **UC-43** Справочник типов договоров | суперадмин | `GET/POST /contract-types` |
| **UC-44** Сводка и статистика закупок | заказчик, поставщик | `GET /dashboard`, `GET /stats/tenders` |
| **UC-45** Настройка прав ролей | суперадмин | `GET /permissions`, `GET/PUT /role-permissions` |
| **UC-46** Часовой пояс площадки | суперадмин | `GET/PUT /platform/timezone` |

---

## 5. Диаграммы кейсов

Диаграммы описывают сквозные сценарии — те, где кейс проходит через несколько модулей
и последовательность вызовов не очевидна из таблицы выше. Одиночные CRUD-кейсы
(справочники, подписки, профили) диаграммы не требуют.

Диаграммы включены в предметную документацию, здесь — указатель:

| Сценарий | Кейсы | Где диаграмма |
|---|---|---|
| Жизненный цикл закупки глазами заказчика | UC-01…UC-04, UC-41, UC-10c | `../../domain/use-cases.md`, раздел 7.1 |
| Заявка участника: подача → вскрытие → допуск | UC-19, UC-19a, UC-06, UC-05, UC-20 | раздел 7.2 |
| Аукцион: планирование → торги → победитель → исполнение | UC-12…UC-15, UC-42, UC-10 | раздел 7.3 |
| Договор: заключение, подписание, исполнение, претензия | UC-08, UC-08a, UC-08d, UC-10, UC-10a, UC-09 | раздел 7.4 |
| Регистрация и доступ: компания, email, подтверждение, права | UC-35…UC-39, UC-45 | раздел 7.5 |

Все пять — sequence-диаграммы: они показывают **порядок вызовов** между актором, API и
фоновой обработкой. Статусные модели диаграммами кейсов намеренно не дублируются — они
живут в `../../domain/tender-state-machine.md`, `auction-state-machine.md`,
`contract-state-machine.md` и `company-state-machine.md`.

---

## 6. Как поддерживать карту

Новый эндпоинт = четыре записи, а не одна:

1. `api/openapi.yaml` — операция (обязательное правило проекта, `AGENTS.md`);
2. `app/docs/api.md` — строка в таблице соответствующего раздела;
3. `domain/use-cases.md` — существующий кейс расширяется или заводится новый;
4. этот файл — строка в карте раздела 3.

Проверка расхождений повторяется командами:

```bash
docker compose exec -T app php bin/console debug:router --format=json   # маршруты из кода
```

и сверкой полученного списка с операциями `api/openapi.yaml` и таблицами `app/docs/api.md`.
