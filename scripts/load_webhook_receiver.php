<?php

declare(strict_types=1);

/**
 * Webhook-receiver для нагрузочного k6-сценария.
 *
 * Запуск (на ХОСТЕ, вне docker):
 *   RECEIVER_LOG=load/receiver.log php -S 0.0.0.0:8787 scripts/load_webhook_receiver.php
 *
 * В отличие от scripts/webhook_receiver.php (E2E), этот receiver держит в памяти
 * статистику доставок и отдаёт её по GET /stats — k6-сценарий webhooks.js
 * опрашивает /stats и считает пропускную способность и p95 задержки end-to-end
 * (received_at − occurred_at из payload). Входной журнал дописывается в файл
 * RECEIVER_LOG (JSON построчно), как в E2E-receiver.
 *
 * Контракт:
 * - GET  /ping  → 200 «pong»;
 * - POST /hook  → 200 {ok:true}, считает статистику (count, латентности);
 * - GET  /stats → 200 {count, rate_per_min, p95_ms, last_latency_ms, started_at};
 * - всё остальное → 404.
 */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH) ?? '/';

$statsFile = getenv('RECEIVER_STATS');
if (!is_string($statsFile) || '' === $statsFile) {
    $statsFile = sys_get_temp_dir().'/load_receiver_stats.json';
}

// Загрузка статистики (в памяти php -S переживает запросы, но файл надёжнее).
$stats = ['count' => 0, 'latencies' => [], 'started_at' => time()];
if (is_file($statsFile)) {
    $loaded = json_decode((string) file_get_contents($statsFile), true);
    if (is_array($loaded)) {
        $stats = $loaded;
    }
}

if ('GET' === $method && '/ping' === $path) {
    http_response_code(200);
    echo 'pong';

    return;
}

if ('GET' === $method && '/reset' === $path) {
    $stats = ['count' => 0, 'latencies' => [], 'started_at' => time()];
    file_put_contents($statsFile, json_encode($stats));
    http_response_code(200);
    echo '{"ok":true}';

    return;
}

if ('POST' === $method && '/hook' === $path) {
    $log = getenv('RECEIVER_LOG');
    $body = file_get_contents('php://input') ?: '';
    $latencyMs = null;
    $decoded = json_decode($body, true);
    if (is_array($decoded) && isset($decoded['occurred_at']) && is_string($decoded['occurred_at'])) {
        $occurred = strtotime($decoded['occurred_at']);
        if (false !== $occurred) {
            $latencyMs = (int) round((microtime(true) - $occurred) * 1000);
        }
    }
    if (null !== $latencyMs) {
        ++$stats['count'];
        $stats['latencies'][] = $latencyMs;
        if (count($stats['latencies']) > 20000) {
            array_shift($stats['latencies']);
        }
    }
    if (is_string($log) && '' !== $log) {
        $record = [
            'method' => $method,
            'path' => $path,
            'x_signature' => $_SERVER['HTTP_X_SIGNATURE'] ?? null,
            'x_event_id' => $_SERVER['HTTP_X_EVENT_ID'] ?? null,
            'received_at_ms' => (int) round(microtime(true) * 1000),
            'latency_ms' => $latencyMs,
            'body' => $body,
        ];
        $line = json_encode($record, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (is_string($line)) {
            file_put_contents($log, $line."\n", \FILE_APPEND | \LOCK_EX);
        }
    }
    file_put_contents($statsFile, json_encode($stats));

    http_response_code(200);
    echo '{"ok":true}';

    return;
}

if ('GET' === $method && '/stats' === $path) {
    $latencies = $stats['latencies'];
    sort($latencies, \SORT_NUMERIC);
    $p95 = [] === $latencies ? null : $latencies[(int) (0.95 * (count($latencies) - 1))];
    $elapsed = max(1, time() - $stats['started_at']);
    $ratePerMin = (int) round($stats['count'] * 60 / $elapsed);
    $last = [] === $latencies ? null : $latencies[count($latencies) - 1];

    header('Content-Type: application/json');
    http_response_code(200);
    echo json_encode([
        'count' => $stats['count'],
        'rate_per_min' => $ratePerMin,
        'p95_ms' => $p95,
        'last_latency_ms' => $last,
        'started_at' => $stats['started_at'],
    ], \JSON_UNESCAPED_SLASHES);

    return;
}

http_response_code(404);
echo 'not found';
