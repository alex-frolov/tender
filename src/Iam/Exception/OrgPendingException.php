<?php

declare(strict_types=1);

namespace App\Iam\Exception;

/**
 * Компания не подтверждена суперадмином (FR-1.5.7).
 *
 * Возникает при org_pending-ограничении: пока статус компании ≠ active,
 * заказчик не может создавать/публиковать тендеры, исполнитель — подавать
 * заявки и участвовать в торгах (только просмотр доски тендеров).
 */
final class OrgPendingException extends \RuntimeException
{
    public function __construct(string $message = 'Company is not verified (org_pending)')
    {
        parent::__construct($message);
    }
}
