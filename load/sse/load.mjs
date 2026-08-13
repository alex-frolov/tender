// SSE-нагрузка на Mercure hub (NFR-22 / R9): N подписчиков на приватный topic
// + publisher-поток. Замеры:
//   - время установки SSE-соединения (p95 < 1 сек);
//   - задержка доставки события до клиента: received − load_ts (p95 < 1 сек);
//   - удержание соединений (кол-во открытых подписчиков).
//
// Почему не k6: stock k6 не умеет стримить SSE-ответы (нет SSE-модуля;
// xk6-sse-расширение более не поддерживается). Сценарий — нагрузочный скрипт
// на Node (fetch + ReadableStream), что допускает testing-strategy.md §7
// («k6 / нагрузочный скрипт»). Hub-часть SSE-стека (Go/Mercure), приложение
// (discovery) грузится отдельно — load/k6/sse-discovery.js.
//
// Запуск: node load/sse/load.mjs
// env: LOAD_SSE_SUBSCRIBERS (20), LOAD_SSE_DURATION (15 c), LOAD_SSE_PUBLISH_MS (250).
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const state = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'state.json'), 'utf8'));
const { hub } = state;

const SUBSCRIBERS = Number(process.env.LOAD_SSE_SUBSCRIBERS || 20);
const DURATION = Number(process.env.LOAD_SSE_DURATION || 15);
const PUBLISH_MS = Number(process.env.LOAD_SSE_PUBLISH_MS || 250);

const url = `${hub.url}?topic=${encodeURIComponent(hub.topic)}`;
const headers = {
  Authorization: `Bearer ${hub.subscribe_token}`,
  Accept: 'text/event-stream',
};

const connectLatency = [];
const deliveryLatency = [];
let connected = 0;
let deliveries = 0;
let errors = 0;

const abort = new AbortController();
setTimeout(() => abort.abort(), DURATION * 1000 + 3000);

/**
 * Подписчик: открывает SSE-соединение, держит до конца теста, парсит data:.
 */
async function subscriber(id) {
  const t0 = Date.now();
  try {
    const res = await fetch(url, { headers, signal: abort.signal });
    if (!res.ok) {
      errors += 1;
      return;
    }
    connectLatency.push(Date.now() - t0);
    connected += 1;

    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buf = '';
    for (;;) {
      const { value, done } = await reader.read();
      if (done) {
        break;
      }
      buf += decoder.decode(value, { stream: true });
      let idx;
      while ((idx = buf.indexOf('\n\n')) !== -1) {
        const block = buf.slice(0, idx);
        buf = buf.slice(idx + 2);
        for (const line of block.split('\n')) {
          if (line.startsWith('data:')) {
            const data = line.slice(5).trim();
            if ('' === data || ':' === data) {
              continue;
            }
            try {
              const parsed = JSON.parse(data);
              if (typeof parsed.load_ts === 'number') {
                deliveryLatency.push(Date.now() - parsed.load_ts);
                deliveries += 1;
              }
            } catch {
              // heartbeat/прочие не-JSON данные — пропускаем
            }
          }
        }
      }
    }
  } catch (e) {
    if ('AbortError' !== e.name) {
      errors += 1;
    }
  }
}

/**
 * Publisher: публикует тестовые события в hub (publish-JWT) с интервалом.
 * Формат — form-body (topic/data), как в Mercure 0.16+ и symfony/mercure.
 */
async function publish() {
  const end = Date.now() + DURATION * 1000;
  let seq = 0;
  while (Date.now() < end) {
    const body = new URLSearchParams({
      topic: hub.topic,
      data: JSON.stringify({ load_ts: Date.now(), seq: ++seq }),
    });
    await fetch(hub.url, {
      method: 'POST',
      body,
      headers: {
        Authorization: `Bearer ${hub.publish_token}`,
        'Content-Type': 'application/x-www-form-urlencoded',
      },
    });
    await new Promise((r) => setTimeout(r, PUBLISH_MS));
  }
}

function p95(list) {
  if (0 === list.length) {
    return null;
  }
  const sorted = [...list].sort((a, b) => a - b);
  return sorted[Math.min(sorted.length - 1, Math.ceil(0.95 * sorted.length) - 1)];
}

await Promise.all([...Array.from({ length: SUBSCRIBERS }, (_, i) => subscriber(i)), publish()]);

const connectP95 = p95(connectLatency);
const deliveryP95 = p95(deliveryLatency);

const fmt = (v) => (null === v ? 'n/a' : `${v} ms`);
console.log('\n── SSE load (Mercure hub) ────────────────────────────────');
console.log(`  subscribers:     ${connected}/${SUBSCRIBERS} connected`);
console.log(`  deliveries:      ${deliveries}`);
console.log(`  connect p95:     ${fmt(connectP95)}  (SLO < 1000 ms)`);
console.log(`  delivery p95:    ${fmt(deliveryP95)}  (SLO < 1000 ms)`);
console.log(`  errors:          ${errors}`);
console.log('──────────────────────────────────────────────────────────\n');

let failed = false;
if (connected < SUBSCRIBERS) {
  console.error(`FAIL: only ${connected}/${SUBSCRIBERS} subscribers connected`);
  failed = true;
}
if (null === connectP95 || connectP95 >= 1000) {
  console.error(`FAIL: SSE connect p95 ${fmt(connectP95)} >= 1000 ms (NFR-22/R9)`);
  failed = true;
}
if (null === deliveryP95 || deliveryP95 >= 1000) {
  console.error(`FAIL: SSE delivery p95 ${fmt(deliveryP95)} >= 1000 ms (NFR-22/R9)`);
  failed = true;
}

process.exit(failed ? 1 : 0);
