<?php

declare(strict_types=1);

namespace App\Favorite\Exception;

use App\Shared\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Сущность уже в избранном пользователя (409 Conflict).
 *
 * Код (openapi POST /favorites): duplicate_favorite — пользователь уже добавил
 * конкретную сущность (tender/lot) в избранное; unique (user_id, entity_type,
 * entity_id) в favorites. Реализует ApiException → JsonApiExceptionSubscriber
 * отвечает 409 {duplicate_favorite}.
 */
final class DuplicateFavoriteException extends \RuntimeException implements ApiException
{
    public function getHttpStatus(): int
    {
        return Response::HTTP_CONFLICT;
    }

    public function getErrorCode(): string
    {
        return 'duplicate_favorite';
    }

    public function getTitle(): string
    {
        return 'Favorite already exists';
    }
}
