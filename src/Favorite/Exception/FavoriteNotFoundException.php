<?php

declare(strict_types=1);

namespace App\Favorite\Exception;

use App\Shared\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Запись избранного не найдена или принадлежит другому пользователю (F-A6):
 * 404 {not_found}. Бросается в FavoriteService.
 */
final class FavoriteNotFoundException extends \RuntimeException implements ApiException
{
    public function getHttpStatus(): int
    {
        return Response::HTTP_NOT_FOUND;
    }

    public function getErrorCode(): string
    {
        return 'not_found';
    }

    public function getTitle(): string
    {
        return 'Not Found';
    }
}
