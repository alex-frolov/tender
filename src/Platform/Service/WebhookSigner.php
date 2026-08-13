<?php

declare(strict_types=1);

namespace App\Platform\Service;

/**
 * Подпись HMAC-SHA256 payload webhook (WH-3).
 *
 * Подписанный payload передаётся в заголовке X-Signature
 * («sha256=<hex>»); подписчик проверяет подпись своим секретом.
 * Подпись считается от ТОЧНОГО тела запроса (payload), поэтому
 * при ретраях отправляется байт-в-байт тот же payload (WH-5).
 */
final readonly class WebhookSigner
{
    public function signature(string $payload, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $payload, $secret);
    }
}
