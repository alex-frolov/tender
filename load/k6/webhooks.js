// NFR-3 / WH-2..6 (webhook-доставка): end-to-end латентность и пропускная
// способность async-конвейера outbox → RabbitMQ → EventMessageHandler →
// WebhookDeliveryService → worker → HTTP-POST в receiver.
//
// События генерирует app:load:emit-events (--rate/--duration, daemon-режим) —
// steady-state поток auction.bid. Receiver (scripts/load_webhook_receiver.php,
// compose-сервис loadreceiver) считает доставки и отдаёт /stats (count, p95,
// rate). k6 опрашивает /stats и /webhooks/{id}/deliveries.
//
// SLO (NFR-3): ≥ 10 000 событий/мин, задержка < 5 сек (штатный режим).
// В dev (один worker) масштаб ниже — пороги: p95 < 5 с, rate ≥ 300/мин,
// доставлено ≥ 80% эмитированных (LOAD_WEBHOOK_TOTAL).
import http from 'k6/http';
import { sleep } from 'k6';
import { Counter, Gauge, Trend } from 'k6/metrics';
import { apiUrl, bearer, webhook, customer } from './state.js';

const receiverStats = __ENV.LOAD_RECEIVER_STATS || 'http://localhost:8787/stats';
const expectedTotal = Number(__ENV.LOAD_WEBHOOK_TOTAL || 0);

const whP95Ms = new Trend('wh_p95_ms', true);
const whRatePerMin = new Trend('wh_rate_per_min', true);
const whDelivered = new Gauge('wh_delivered');
const whDeliveredPct = new Gauge('wh_delivered_pct');
const whPollFailures = new Counter('wh_poll_failures');

export const options = {
  scenarios: {
    poll: {
      executor: 'per-vu-iterations',
      vus: 1,
      iterations: 35,
      startTime: '3s',
    },
  },
  thresholds: {
    'wh_p95_ms': ['p(95)<5000'],           // NFR-3: < 5 сек
    'wh_rate_per_min': ['avg>300'],        // dev-масштаб (прод: ≥10k/мин)
    'wh_delivered_pct': ['value>0.8'],     // ≥80% эмитированных доставлено
  },
};

export default function () {
  const res = http.get(receiverStats);
  if (res.status !== 200) {
    whPollFailures.add(1);
    sleep(1);
    return;
  }
  const stats = res.json();
  const count = Number(stats.count || 0);
  const p95 = Number(stats.p95_ms || 0);
  const rate = Number(stats.rate_per_min || 0);

  whP95Ms.add(p95);
  whRatePerMin.add(rate);
  whDelivered.add(count);
  if (expectedTotal > 0) {
    whDeliveredPct.add(count / expectedTotal);
  } else {
    whDeliveredPct.add(0);
  }

  // Параллельно читаем журнал доставок приложения (диагностика dead-letter).
  http.get(apiUrl(`/webhooks/${webhook.id}/deliveries`), { headers: bearer(customer.token) });

  sleep(1);
}
