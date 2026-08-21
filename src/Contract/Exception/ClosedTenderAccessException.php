<?php

declare(strict_types=1);

namespace App\Contract\Exception;

use App\Shared\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Участие в закрытом тендере без действующего рамочного договора
 * (access_type=contract_holders, FR-1.5.14).
 *
 * 409 {access_denied} (openapi ErrorCode: «closed tender without an active
 * framework contract»). Отдельный код, а не contract_required: последний в
 * реестре ошибок закреплён за другим случаем — переводом аукциона в DONE без
 * действительного договора (B2, ContractRequiredException). Причина отказа
 * (contract_required | contract_expired | contract_terminated — те же значения,
 * что у GET /tenders/{id}/access) уходит в detail, чтобы клиенту не приходилось
 * запрашивать доступ повторно ради формулировки.
 */
final class ClosedTenderAccessException extends \RuntimeException implements ApiException
{
    public function getHttpStatus(): int
    {
        return Response::HTTP_CONFLICT;
    }

    public function getErrorCode(): string
    {
        return 'access_denied';
    }

    public function getTitle(): string
    {
        return 'Access denied';
    }
}
