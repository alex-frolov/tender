// NFR-22 (SSE-путь приложения): GET /auctions/{id}/stream — discovery для
// Mercure hub (hub + topic + subscribe-JWT + state-снапшот). Нагрузка на
// PHP-часть SSE-стека (AuthMiddleware → Voter R7 → Redis snapshot → JWT).
import http from 'k6/http';
import { Trend, Counter } from 'k6/metrics';
import { apiUrl, bearer, auction, suppliers } from './state.js';

const discoveryMs = new Trend('sse_discovery_ms', true);
const errors = new Counter('sse_discovery_errors');

export const options = {
  scenarios: {
    discovery: {
      executor: 'constant-vus',
      vus: 5,
      duration: '15s',
    },
  },
  thresholds: {
    'sse_discovery_ms': ['p(95)<1000'],
  },
};

export default function () {
  // Допущен на primary-аукцион только suppliers[0] (R7: stream/discovery
  // доступен допущенным участникам).
  const supplier = suppliers[0];
  const res = http.get(apiUrl(`/auctions/${auction.id}/stream`), { headers: bearer(supplier.token) });
  discoveryMs.add(res.timings.duration);
  if (res.status !== 200) {
    errors.add(1);
  }
}
