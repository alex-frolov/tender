# Tender Platform — Mercure hub (v0.16.3, локальный патч /metrics)
# ops/observability.md §1 (SSE): Prometheus-метрики хаба.
#
# ЗАЧЕМ ПАТЧ: dunglas/mercure v0.16.3 УЖЕ собирает Prometheus-метрики
# (mercure_subscribers_connected / mercure_subscribers_total / mercure_updates_total)
# в prometheus.DefaultRegisterer (metrics.go), но Caddy-модуль НЕ регистрирует
# HTTP-роут /metrics (Register() deprecated, используется только standalone-
# библиотекой) → в официальном образе /metrics → 404.
#
# Патч caddy/caddy.go: в ServeHTTP при r.URL.Path == "/metrics" отдаём рендер
# реестра (promhttp.HandlerFor(DefaultRegisterer)) и возвращаем nil. Всё остальное
# поведение хаба (SSE, publish/subscribe JWT, Caddyfile с env-шаблонизацией) —
# без изменений.
#
# ВНИМАНИЕ: версия НАМЕРЕННО запинена на v0.16.3 — НЕ апгрейдить на 2.x!
# (v2 ломает symfony/mercure 0.7.x: легаси-claims JWT mercure.publish/subscribe,
# publish → 401, SSE-стримы аукционов сломаны; см. docker-compose.yml).

# ---------- Stage 1: сборка бинаря caddy+mercure из исходников ----------
FROM golang:1.22-alpine AS builder

RUN apk add --no-build-deps --no-cache git ca-certificates

# Пин тега (v0.16.3) — версия фиксирована (см. выше).
ARG MERCURE_VERSION=v0.16.3

WORKDIR /src
RUN git clone --depth 1 --branch ${MERCURE_VERSION} \
    https://github.com/dunglas/mercure.git .

# Локальный патч /metrics (docker/mercure/caddy_metrics.patch)
COPY docker/mercure/caddy_metrics.patch /src/caddy_metrics.patch
RUN git apply /src/caddy_metrics.patch

# go.mod в подкаталоге caddy содержит `replace github.com/dunglas/mercure => ../`
# и main-пакет caddy/mercure → собираем бинарь caddy+mercure.
RUN cd /src/caddy \
    && go build -trimpath -o /out/mercure ./mercure \
    && /out/mercure version | head -2

# ---------- Stage 2: рантайм на базе официального образа ----------
# caddy:2.8.4-alpine — соответствует Caddy v2.8.4, на который собран mercure
# v0.16.3 (caddy/go.mod: caddyserver/caddy/v2 v2.8.4). Тег запинен.
FROM caddy:2.8.4-alpine

ENV MERCURE_TRANSPORT_URL=bolt:///data/mercure.db

# Бинарь caddy+mercure (заменяет штатный /usr/bin/caddy) + Caddyfile из тега.
COPY --from=builder /out/mercure /usr/bin/caddy
COPY --from=builder /src/Caddyfile /etc/caddy/Caddyfile
