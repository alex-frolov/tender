<?php

declare(strict_types=1);

namespace App\Contract\Exception;

use App\Shared\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Перевод в DONE без действительного договора (B2, FR-1.4.3).
 *
 * 409 {contract_required} (openapi): перевод в DONE (APPROVE/IN_WORK/
 * DONE_BY_PERFORMER → DONE) возможен только при наличии действительного
 * договора (contract.status ∈ signed/registered, не terminated/expired/deleted).
 * Проверяется ContractExecutionService::assertCanDone() по contract_tenders.
 */
final class ContractRequiredException extends \RuntimeException implements ApiException
{
    public function getHttpStatus(): int
    {
        return Response::HTTP_CONFLICT;
    }

    public function getErrorCode(): string
    {
        return 'contract_required';
    }

    public function getTitle(): string
    {
        return 'Valid contract required';
    }
}
