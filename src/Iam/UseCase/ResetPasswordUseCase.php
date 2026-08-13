<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

use App\Iam\Input\ResetPasswordInput;
use App\Iam\Service\PasswordResetService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Сброс пароля по одноразовому токену (FR-1.5.6, шаг 2, POST /auth/password/reset).
 *
 * Проверку токена (single-use + TTL) и смену пароля с отзывом всех
 * refresh-токенов выполняет доменный PasswordResetService::reset. Неверный/
 * истёкший/использованный токен → 401 `invalid_reset_token` (контракт
 * эндпоинта, не ApiException).
 */
final readonly class ResetPasswordUseCase implements IamUseCase
{
    public function __construct(private PasswordResetService $password)
    {
    }

    public function execute(ResetPasswordInput $input): UseCaseResult
    {
        $user = $this->password->reset($input->token, $input->newPassword);
        if (null === $user) {
            return new UseCaseResult(
                Response::HTTP_UNAUTHORIZED,
                [
                    'title' => 'Unauthorized',
                    'code' => 'invalid_reset_token',
                    'detail' => 'Token is invalid or expired',
                ],
            );
        }

        return new UseCaseResult(Response::HTTP_OK, ['password_reset' => true]);
    }
}
