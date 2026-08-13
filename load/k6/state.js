// Общее состояние нагрузочного прогона (load/state.json, app:load:prepare).
// Хелперы для URL, авторизации и идемпотентности.
const state = JSON.parse(open('../state.json'));

export function apiUrl(path) {
  return state.base_url + state.api_base + path;
}

export function bearer(token) {
  return {
    Authorization: 'Bearer ' + token,
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  };
}

// Уникальный Idempotency-Key (AR-4): повтор доставки/ретрай не создаст дубль.
export function uniqueKey(prefix) {
  return `${prefix}-${__VU}-${__ITER}-${Math.random().toString(16).slice(2, 10)}`;
}

export const auction = state.auction;
export const auctions = state.auctions;
export const customer = state.customer;
export const suppliers = state.suppliers;
export const webhook = state.webhook;
export const hub = state.hub;
