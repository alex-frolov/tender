# Нагрузочные тесты

k6 (HTTP) + node (SSE-хаб) сценарии нагрузки против dev-стека docker compose.
Цель — «SLO достигнуты»: каждый сценарий завершается зелёными порогами.

## Сценарии и SLO

| Сценарий | Инструмент | Скрипт | SLO | Как валидируется |
|---|---|---|---|---|
| **Ставки** | k6 | `k6/bids.js` | p95 записи ставки < 100 мс (без сети), 100–200 ставок/сек (этап 1) | доменный write-path: `AuctionBidLoadSmokeTest` (p95 ~9–13 мс, ≥30/сек); HTTP end-to-end: `bid_write_ms p(95)<1000` (dev-floor, факт в отчёте) |
| **Каталог** | k6 | `k6/catalog.js` | GET /tenders на каталоге: p95 < 200 мс | `catalog_ms p(95)<200` |
| **SSE (discovery)** | k6 | `k6/sse-discovery.js` | GET /auctions/{id}/stream: p95 < 1 сек | `sse_discovery_ms p(95)<1000` |
| **SSE (хаб)** | node | `sse/load.mjs` | доставка события до клиента p95 < 1 сек; N подписчиков | connect p95 < 1000 мс, delivery p95 < 1000 мс |
| **Webhooks** | k6 | `k6/webhooks.js` | ≥10 000 событий/мин, задержка < 5 сек | `wh_p95_ms p(95)<5000`, `wh_delivered_pct>0.8`, `wh_rate_per_min avg>300` (dev-масштаб) |

## Запуск

```bash
# весь набор (готовит стек + state, гоняет все сценарии)
./load/run.sh

# один сценарий
./load/run.sh bids        # catalog | sse | webhooks

# параметры (env)
LOAD_SUPPLIERS=50 LOAD_CATALOG=20000 ./load/run.sh
K6_BIN=/path/to/k6 ./load/run.sh webhooks
```

**Требования:** docker compose, k6 (`brew install k6`), node ≥ 20 (SSE).

Порядок «из коробки» (~4–6 минут):
`load/run.sh` поднимает load-стек (`docker-compose.load.yml`), готовит state
(`app:load:prepare`), прогоняет ставки → каталог → SSE → webhooks.

## Что готовит `app:load:prepare` (`load/state.json`)

- Подтверждённые компании: заказчик + N поставщиков (роль admin, JWT-токены);
- опубликованный тендер в `accepting_bids`;
- **N аукционов REDUCTION(fixed) в TRADE** — один допущенный поставщик на
  аукцион (суммарно по всем активным аукционам: параллельные ставки
  не сериализуются на pessimistic-lock одной строки, меряется чистый write-path);
- webhook-подписку `auction.bid` на load-receiver;
- каталог тендеров (bulk-insert): `--catalog` всего, из них 100 `published`
  (рабочий набор доски), остальные `closed` — «фильтры на большом каталоге»;
- hub-JWT для SSE (`mercure.publish ['*']`, `mercure.subscribe [load:{runId}]`);
- повторный запуск очищает предыдущее состояние по маркерам `LOAD-*`.

## Ключевые решения и находки

### 1. Mercure пинится на v0.16.3 (не `latest`)
`latest` = Mercure **2.x**: авторизация переведена на OAuth2
`authorization_details` (RFC 9396) + `typ: at+jwt` + обязательные `iss`/`aud` —
легаси-claims `mercure.publish/subscribe` (которые генерирует `symfony/mercure`
0.7.x) на v2-хабе **не работают** (publish → 401).
Приложение осталось на легаси-API, поэтому образ зафиксирован на `v0.16.3`
(последняя 0.x) — иначе SSE-стримы аукционов сломаны. Находка зафиксирована
в `docker-compose.yml` и в `../operations/observability.md` (SSE-раздел).

### 2. Publish-формат Mercure 0.16+: topic в form-body
`POST /hub` с `Content-Type: application/x-www-form-urlencoded` и полями
`topic`/`data` (query-param → 400 «Missing topic parameter»). Так публикует и
`symfony/mercure`. publish-claim в JWT — только `['*']` (glob `load:*` → 401).

### 3. APP_DEBUG=0 для нагрузочного прогона
Dev c `APP_DEBUG=1` добавляет ~300–400 мс на каждый запрос (компиляция
контейнера, profiler) — SLO (p95 < 100 мс / < 200 мс) недостижимы.
`docker-compose.load.yml` ставит `APP_DEBUG: "0"` (профиль «как prod-компиляция»,
приложение и логика те же). Без этого даже каталог 100 строк = p95 ~600 мс.

### 4. php-fpm `max_children`
Дефолт `php:8.5-fpm` = 5 воркеров → ~15–20 ставок/сек и p95 ~700 мс при 20 VU.
Load-профиль монтирует `docker/php/load-www.conf` (`max_children=30`):
~22 ставки/сек, p95 ~550 мс, accept rate 100%.

### 5. Webhook-доставка: отдельный worker `webhooks` (по умолчанию)
Единый `messenger:consume async emails live exports` (общий worker) циклит по
транспортам; пустые RabbitMQ/Redis-очереди тормозят выборку webhook-задач →
~10 доставок/сек и задержка «хвоста бурста» растёт десятками секунд.
Поэтому webhook-доставка вынесена на **отдельный worker `webhooks`** — сервис
compose `webhooks` (`messenger:consume webhooks`) поднимается по умолчанию вместе
с dev-стеком. На нём ~1200+/мин, p95 ~2,7 сек. Бурст (все события сразу)
по-прежнему даёт большой хвост задержек — для SLO < 5 сек нужен **steady-state**
эмиттер (`app:load:emit-events --rate --duration`, батчами по 1 сек).

### 6. SSE-сценарий — node, не k6
Stock k6 не умеет читать SSE-стрим (нет SSE-модуля; xk6-sse снят с поддержки).
Хаб (Go/Mercure) грузится node-скриптом (`sse/load.mjs`: N подписчиков +
publisher, замер connect/delivery p95); приложение (discovery) — k6.
Это соответствует стратегии тестирования («k6 / нагрузочный скрипт»).

### 7. Каталог: endpoint без пагинации
`GET /tenders` раньше отдавал весь фильтрованный набор (нет `next_cursor`); сериализация
+ агрегация статусов (STRING_AGG) по тенанту — узкое место. В dev на ~100
published / 5000 всего: p95 ~166 мс при 3 VU (уже зелёное).
**Исправлено (вариант B, 2026-08-13):** read-модель `TenderCatalogQuery` —
keyset-пагинация по (created_at, id), лимит 1..100 (default 20), `next_cursor`;
агрегация статусов и lot_count — по id страницы; индексы `idx_tenders_catalog_*`.
Дальнейшее (вариант C): фильтры openapi q/region/price/okpd2/law_type/access_type,
FTS (PG GIN), при деградации > 1M — денормализованная read-таблица / поисковый
движок.

## Инфраструктура нагрузочного профиля

`docker-compose.load.yml` (поверх `docker-compose.yml`):
- `RATE_LIMIT_GLOBAL_PER_MIN=100000` (общий limiter 600/мин/IP мешает сценарию ставок);
- `APP_DEBUG=0`;
- php-fpm pool `max_children=30`;
- сервис `loadreceiver` (php:8.5-cli + `scripts/load_webhook_receiver.php`,
  порт 8787): принимает доставки webhook и отдаёт `/stats` (count, p95, rate)
  для k6-сценария.

```bash
docker compose -f docker-compose.yml -f docker-compose.load.yml up -d app worker webhooks loadreceiver
```

Вернуть обычный dev-профиль: `docker compose up -d app worker` (переопределения
load-профиля при этом пропадают).
