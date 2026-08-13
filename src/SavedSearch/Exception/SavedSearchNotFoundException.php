<?php

declare(strict_types=1);

namespace App\SavedSearch\Exception;

use App\Shared\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Сохранённый поиск не найден или принадлежит другому пользователю (F-A5):
 * 404 {not_found}. Бросается в SavedSearchService.
 */
final class SavedSearchNotFoundException extends \RuntimeException implements ApiException
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
