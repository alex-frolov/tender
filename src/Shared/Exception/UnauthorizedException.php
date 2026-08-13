<?php

declare(strict_types=1);

namespace App\Shared\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * Пользователь не аутентифицирован (401 Unauthorized).
 *
 * Бросается из AbstractBaseController::currentUser(), когда в запросе нет
 * действующего пользователя (AuthMiddleware не создал контекст). Реализует
 * ApiException → JsonApiExceptionSubscriber отвечает 401 {invalid_credentials}.
 * Для #[IsGranted]-эндпоинтов этот случай недостижим (security срабатывает до
 * контроллера); для публичных 2FA-эндпоинтов — единственная проверка доступа.
 */
final class UnauthorizedException extends \RuntimeException implements ApiException
{
    public function getHttpStatus(): int
    {
        return Response::HTTP_UNAUTHORIZED;
    }

    public function getErrorCode(): string
    {
        return 'invalid_credentials';
    }

    public function getTitle(): string
    {
        return 'Unauthorized';
    }
}
