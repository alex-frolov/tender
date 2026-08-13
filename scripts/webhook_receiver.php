<?php

declare(strict_types=1);

/**
 * Тестовый webhook-receiver для E2E-тестов доставки (WH-3/WH-6).
 *
 * Запускается самим тестом (WebhookDeliveryE2ETest) как встроенный PHP-сервер:
 *   php -S 127.0.0.1:{port} scripts/webhook_receiver.php
 *
 * Контракт эндпоинтов:
 * - GET /ping → 200 «pong» (проверка готовности сервера);
 * - POST /hook → 200 + лог входящего запроса в файл из RECEIVER_LOG
 *   (method, path, X-Signature, X-Event-Id, Content-Type, body) — файл
 *   дописывается построчно (JSON), чтобы тест проверил подпись и конверт;
 * - всё остальное → 404.
 *
 * Файл лежит вне src/tests: lint/PHPStan его не сканируют, но CS Fixer
 * требует соблюдения стиля (@Symfony + declare(strict_types=1)).
 */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH) ?? '/';

if ('GET' === $method && '/ping' === $path) {
    http_response_code(200);
    echo 'pong';

    return;
}

if ('POST' === $method && '/hook' === $path) {
    $log = getenv('RECEIVER_LOG');
    if (is_string($log) && '' !== $log) {
        $record = [
            'method' => $method,
            'path' => $path,
            'x_signature' => $_SERVER['HTTP_X_SIGNATURE'] ?? null,
            'x_event_id' => $_SERVER['HTTP_X_EVENT_ID'] ?? null,
            'content_type' => $_SERVER['HTTP_CONTENT_TYPE'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'body' => file_get_contents('php://input') ?: '',
        ];
        $line = json_encode($record, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (is_string($line)) {
            file_put_contents($log, $line."\n", \FILE_APPEND | \LOCK_EX);
        }
    }
    http_response_code(200);
    echo '{"ok":true}';

    return;
}

http_response_code(404);
echo 'not found';
