<?php

declare(strict_types=1);

namespace App\Iam\Exception;

use App\Shared\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Компания не подтверждена суперадмином (FR-1.5.7).
 *
 * Возникает при org_pending-ограничении: пока статус компании ≠ active,
 * заказчик не может создавать/публиковать тендеры, исполнитель — подавать
 * заявки и участвовать в торгах (только просмотр доски тендеров).
 *
 * Реализует ApiException → JsonApiExceptionSubscriber отвечает 403
 * с кодом org_pending (реестр ErrorCode в openapi.yaml).
 */
final class OrgPendingException extends \RuntimeException implements ApiException
{
    public function __construct(string $message = 'Company is not verified (org_pending)')
    {
        parent::__construct($message);
    }

    public function getHttpStatus(): int
    {
        return Response::HTTP_FORBIDDEN;
    }

    public function getErrorCode(): string
    {
        return 'org_pending';
    }

    public function getTitle(): string
    {
        return 'Company is not verified';
    }
}
