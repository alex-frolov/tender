<?php

declare(strict_types=1);

namespace App\Platform\Service;

/**
 * Генерация raw API-токена и его хэша (FR-1.5.13, AR-3).
 *
 * - raw-токен: `key_` + 32 случайных байта (hex). Отдаётся клиенту ОДИН раз
 *   при создании/ротации; в БД хранится только SHA-256 хэш (token_hash) —
 *   компрометация БД не даёт действующих токенов;
 * - hash — SHA-256 от raw (по нему происходит lookup при аутентификации).
 *
 * @return array{token: string, hash: string}
 */
final readonly class ApiKeyTokenFactory
{
    /**
     * @return array{token: string, hash: string}
     */
    public function generate(): array
    {
        $token = 'key_'.bin2hex(random_bytes(32));

        return ['token' => $token, 'hash' => $this->hash($token)];
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
