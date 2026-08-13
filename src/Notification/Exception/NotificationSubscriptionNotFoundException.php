<?php

declare(strict_types=1);

namespace App\Notification\Exception;

use App\Shared\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Подписка на уведомления не найдена или принадлежит другому пользователю
 * (FR-1.6): 404 {not_found}. Бросается в NotificationSubscriptionService.
 */
final class NotificationSubscriptionNotFoundException extends \RuntimeException implements ApiException
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
