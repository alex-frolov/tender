<?php

declare(strict_types=1);

namespace App\Shared\Exception;

/**
 * Контракт доменных исключений, которые должны превращаться в JSON-ответ API.
 *
 * Реализуется исключениями сервисов (UserNotFoundException, LastAdminException,
 * UserValidationException, ConflictException и т.п.). Единая обработка — в
 * App\Infrastructure\Http\JsonApiExceptionSubscriber: исключение летит из
 * сервиса наверх, ловится подписчиком kernel.exception и оформляется в JSON.
 * Это убирает «портянки» try/catch из контроллеров (см. AGENTS.md).
 */
interface ApiException
{
    public function getHttpStatus(): int;

    /**
     * @return string|null машинный код ошибки (openapi code), null если кода нет
     */
    public function getErrorCode(): ?string;

    public function getTitle(): string;
}
