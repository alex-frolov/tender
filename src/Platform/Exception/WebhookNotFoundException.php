<?php

declare(strict_types=1);

namespace App\Platform\Exception;

use App\Shared\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhook-подписка не найдена или не принадлежит компании актора (WH-7).
 * Бросается в WebhookService (tenant-изоляция) → JsonApiExceptionSubscriber
 * отвечает 404.
 */
final class WebhookNotFoundException extends \RuntimeException implements ApiException
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
