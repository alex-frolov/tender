// NFR-1 (ставки аукциона): нагрузка на write-путь POST /auctions/{id}/bids.
//
// Модель: prep создаёт N аукционов (один допущенный поставщик на аукцион) —
// каждый VU ставит в СВОЁМ аукционе (NFR-1 «суммарно по всем активным
// аукционам»), параллельные ставки не сериализуются на pessimistic-lock одной
// строки (FR-1.3.6) и замеряется чистый write-путь. Перед ставкой читает
// live-состояние (GET /state → current_price_minor) и ставит на шаг ниже.
//
// SLO: NFR-1 — p95 задержки записи ставки < 100 мс (без учёта сети); первый
// этап — 100–200 ставок/сек. Пороги калибруются под dev-сервер (слабый docker
// dev — см. AuctionBidLoadSmokeTest MIN_TARGET=30) и пишутся в отчёт.
import http from 'k6/http';
import { sleep } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';
import { apiUrl, bearer, uniqueKey, auctions, suppliers } from './state.js';

const bidWriteMs = new Trend('bid_write_ms', true);
const bidStateMs = new Trend('bid_state_ms', true);
const bidsTotal = new Counter('bid_requests_total');
const bidsAccepted = new Counter('bids_accepted');
const bidsRejected = new Counter('bids_rejected');
const bidsOther = new Counter('bids_other');
const acceptRate = new Rate('bids_accept_rate');

export const options = {
  scenarios: {
    bids: {
      executor: 'ramping-vus',
      exec: 'bid',
      startVUs: 0,
      stages: [
        { duration: '5s', target: 10 },
        { duration: '15s', target: 20 },
        { duration: '20s', target: 20 },
        { duration: '5s', target: 0 },
      ],
      gracefulStop: '15s',
    },
  },
  thresholds: {
    // NFR-1: p95 задержки ЗАПИСИ ставки < 100 мс (без учёта сети) — валидируется
    // доменным load-smoke (AuctionBidLoadSmokeTest: p95 ~9–13 мс). HTTP-замер
    // здесь end-to-end (auth + rate limit + сериализация + dev-стек): порог —
    // dev-floor 1000 мс, фактическое значение пишется в отчёт.
    bid_write_ms: ['p(95)<1000'],
    bids_accept_rate: ['rate>0.9'],
  },
};

export function bid() {
  const a = auctions[(__VU - 1) % auctions.length];
  const supplier = suppliers[a.supplier_index];
  const stateHeaders = bearer(supplier.token);

  const stateRes = http.get(apiUrl(`/auctions/${a.id}/state`), { headers: stateHeaders });
  bidStateMs.add(stateRes.timings.duration);
  if (stateRes.status !== 200) {
    bidsOther.add(1);
    return;
  }

  let current = stateRes.json('current_price_minor');
  if (current == null) {
    current = stateRes.json('start_price_minor');
  }
  if (typeof current !== 'number') {
    bidsOther.add(1);
    return;
  }

  const price = current - a.step_minor;
  const res = http.post(
    apiUrl(`/auctions/${a.id}/bids`),
    JSON.stringify({ price_minor: price }),
    { headers: Object.assign({}, stateHeaders, { 'Idempotency-Key': uniqueKey('bid') }) },
  );

  bidWriteMs.add(res.timings.duration);
  bidsTotal.add(1);
  if (res.status === 201) {
    bidsAccepted.add(1);
    acceptRate.add(true);
  } else if (res.status === 409) {
    bidsRejected.add(1);
    acceptRate.add(false);
  } else {
    bidsOther.add(1);
    acceptRate.add(false);
  }

  sleep(0.05);
}
