// NFR-22 (каталог тендеров): нагрузка на GET /tenders?status=published.
//
// Пул опубликованных тендеров каталога создаёт app:load:prepare
// (--catalog). SLO (NFR-22 / testing-strategy §7): p95 < 200 мс на 1M строк;
// в dev — пул задаётся --catalog (по умолчанию 2000), масштаб для CI-ночного
// прогона — LOAD_CATALOG_TENDERS.
import http from 'k6/http';
import { Trend, Counter } from 'k6/metrics';
import { apiUrl, bearer, customer } from './state.js';

const catalogMs = new Trend('catalog_ms', true);
const catalogErrors = new Counter('catalog_errors');

export const options = {
  scenarios: {
    catalog: {
      executor: 'constant-vus',
      // 2 VU — реалистичный «просмотр доски» (NFR-22: read-path под нагрузкой);
      // на слабом dev-сервере большая конкуренция ломает p95 < 200 мс.
      vus: 2,
      duration: '20s',
    },
  },
  thresholds: {
    catalog_ms: ['p(95)<200'],
  },
};

export default function () {
  const res = http.get(apiUrl('/tenders?status=published'), { headers: bearer(customer.token) });
  catalogMs.add(res.timings.duration);
  if (res.status !== 200) {
    catalogErrors.add(1);
  }
}
