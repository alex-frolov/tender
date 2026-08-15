# Observability roadmap (2026-08-15)

План закрытия пробелов из `ideas/2026-08-15-observability-best-practices-materials.md`
(«что усилить в пет-проекте», пункты 1–9). Решения владельца зафиксированы 2026-08-15 20:31.

Легенда: ⬜ не начат · 🔧 в работе · ✅ готово · 📄 только доки (не делаем в коде).

| # | Пункт | Решение владельца | Батч | Статус |
|---|---|---|---|---|
| 1 | OPcache-метрики (hit rate, restarts) | делаем | S | ✅ |
| 2 | Алерт насыщения FPM-пула + listen queue | делаем | S | ✅ |
| 3 | SLO/burn-rate алерты | таргеты: 99% ставок ≤100 мс, 99.9% HTTP | SLO | ✅ |
| 4 | p50/p90/p99 панели на дашбордах | делаем | S | ✅ |
| 5 | Кардинальность auction_no_bids_alert | **Вариант A**: counter событий + count-gauge без auction_id | M | ✅ |
| 6 | Метрики console-команд (scheduler) | делаем | M | ✅ |
| 7a | Loki + promtail + trace-id | делаем | Big | ✅ |
| 7b | Sentry в prod-профиль | **только доки** (дальнейшее развитие) | — | 📄 |
| 8 | Node-exporter (диск/CPU/RAM) | делаем (без cAdvisor) | Big | ✅ |
| 9 | Внешний аптайм-чек (blackbox на /health/ready) | делаем | S | ✅ |

Порядок: S → M → SLO → Big. Каждый батч — отдельный коммит/PR, валидация
через CI-джобу `observability` (docker compose config + promtool + jq).

Контрактные имена метрик/алертов (observability.md §1/§5) обновляются в том же
батче, что и код, — дашборды и алерты живут на одних именах.

---

## #7b Sentry — план подключения (отложено, только документация)

Решение владельца (2026-08-15): в коде не делаем, фиксируем план.

1. `composer require sentry/sentry-symfony`;
2. Активация только при `SENTRY_DSN` (prod): bundle включается через env-gate,
   в dev-профиле не активен;
3. `traces_sample_rate: 0.1` (сэмплинг перф-трейсов, практика #9);
4. compose: prod-профиль (см. deployment.md / .env.prod.dist), DSN — секрет,
   не в репозиторий;
5. Связка с остальным стеком: `trace_id` Sentry ↔ request-id/trace-id в JSON-
   логах (Loki) — единый id для метрика → лог → трейс.
