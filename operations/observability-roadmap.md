# Observability roadmap (2026-08-15)

План закрытия пробелов из `ideas/2026-08-15-observability-best-practices-materials.md`
(«что усилить в пет-проекте», пункты 1–9). Решения владельца зафиксированы 2026-08-15 20:31.

Легенда: ⬜ не начат · 🔧 в работе · ✅ готово · 📄 только доки (не делаем в коде).

| # | Пункт | Решение владельца | Батч | Статус |
|---|---|---|---|---|
| 1 | OPcache-метрики (hit rate, restarts) | делаем | S | ✅ |
| 2 | Алерт насыщения FPM-пула + listen queue | делаем | S | ✅ |
| 3 | SLO/burn-rate алерты | таргеты: 99% ставок ≤100 мс, 99.9% HTTP | SLO | ⬜ |
| 4 | p50/p90/p99 панели на дашбордах | делаем | S | ✅ |
| 5 | Кардинальность auction_no_bids_alert | **Вариант A**: counter событий + count-gauge без auction_id | M | ⬜ |
| 6 | Метрики console-команд (scheduler) | делаем | M | ⬜ |
| 7a | Loki + promtail + trace-id | делаем | Big | ⬜ |
| 7b | Sentry в prod-профиль | **только доки** (дальнейшее развитие) | — | 📄 |
| 8 | Node-exporter (диск/CPU/RAM) | делаем (без cAdvisor) | Big | ⬜ |
| 9 | Внешний аптайм-чек (blackbox на /health/ready) | делаем | S | ✅ |

Порядок: S → M → SLO → Big. Каждый батч — отдельный коммит/PR, валидация
через CI-джобу `observability` (docker compose config + promtool + jq).

Контрактные имена метрик/алертов (observability.md §1/§5) обновляются в том же
батче, что и код, — дашборды и алерты живут на одних именах.
