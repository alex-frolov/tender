# Реестр схем событий (schema registry)

- Каждое событие из `docs/events.md` (реестр событий, 63 типа) получает JSON Schema (`{event_type}.json`).
- Общий конверт (envelope) обязателен для всех событий:

| Поле | Тип | Описание |
|---|---|---|
| `event_id` | uuid | уникальный id события (идемпотентность) |
| `event_type` | string (const) | тип события, напр. `auction.bid` |
| `occurred_at` | date-time (UTC) | момент события |
| `tenant_id` | uuid | тенант |
| `aggregate_type` | string | тип агрегата (auction, tender, contract…) |
| `aggregate_id` | uuid | id агрегата |
| `payload` | object | данные события (`additionalProperties: false`) |

- **Правила изменения:** новые поля payload — только optional (обратная совместимость); удаление/переименование полей — мажорная версия API.
- **Тесты (testing-strategy.md, раздел 5):** publisher → валидация схемы; consumer fixture → валидация; CI проверяет совместимость при изменении.
- **Покрытие:** схемы есть для **всех 63 событий** `docs/events.md` — и для эмитируемых в коде (write-граница outbox), и для событий-контрактов, ожидающих реализации.
- **Проверка в CI (`composer schema:check`):** `scripts/check-event-schemas.php` — каждое событие events.md имеет схему, нет orphan-файлов, каждая схема структурно корректна (envelope, `event_type.const` == имени файла, `aggregate_type.const`, `payload.additionalProperties=false`). Юнит-тест структуры — `tests/Unit/Shared/Events/EventSchemaRegistryTest.php` (DataProvider по всем файлам каталога).