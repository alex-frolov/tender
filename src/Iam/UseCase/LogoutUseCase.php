<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

use App\Iam\Input\LogoutInput;
use App\Iam\Service\AuthenticationService;

/**
 * Отзыв refresh-токена (FR-1.5.3, POST /auth/logout).
 *
 * Идемпотентен: повторный logout с уже отозванным/неизвестным токеном не
 * является ошибкой (200). Механика отзыва — в AuthenticationService::logout,
 * фиксированный статус 200 проставляет контроллер.
 */
final readonly class LogoutUseCase implements IamUseCase
{
    public function __construct(private AuthenticationService $auth)
    {
    }

    /**
     * @return array{status: string}
     */
    public function execute(LogoutInput $input): array
    {
        $this->auth->logout($input->refreshToken);

        return ['status' => 'ok'];
    }
}
