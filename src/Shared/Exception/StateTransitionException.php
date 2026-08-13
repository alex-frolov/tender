<?php

declare(strict_types=1);

namespace App\Shared\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * Переход по workflow недопустим для текущего статуса сущности (409 Conflict).
 *
 * Реализует ApiException → JsonApiExceptionSubscriber отвечает 409
 * {state_transition_forbidden}. Бросается сервисами, работающими через
 * symfony/workflow (напр. CompanyVerificationService).
 */
final class StateTransitionException extends \RuntimeException implements ApiException
{
    public function getHttpStatus(): int
    {
        return Response::HTTP_CONFLICT;
    }

    public function getErrorCode(): string
    {
        return 'state_transition_forbidden';
    }

    public function getTitle(): string
    {
        return 'Conflict';
    }
}
