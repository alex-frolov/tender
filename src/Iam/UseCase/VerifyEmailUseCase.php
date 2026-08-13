<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

use App\Iam\Input\VerifyEmailInput;
use App\Iam\Service\EmailVerificationService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Подтверждение email по одноразовому токену (FR-1.5.5, POST /auth/email/verify).
 *
 * Проверку токена (single-use + TTL, hash в БД) выполняет доменный
 * EmailVerificationService::verify. Неверный/истёкший/использованный токен →
 * 401 `invalid_verification_token` (контракт эндпоинта, не ApiException).
 */
final readonly class VerifyEmailUseCase implements IamUseCase
{
    public function __construct(private EmailVerificationService $email)
    {
    }

    public function execute(VerifyEmailInput $input): UseCaseResult
    {
        $user = $this->email->verify($input->token);
        if (null === $user) {
            return new UseCaseResult(
                Response::HTTP_UNAUTHORIZED,
                [
                    'title' => 'Unauthorized',
                    'code' => 'invalid_verification_token',
                    'detail' => 'Token is invalid or expired',
                ],
            );
        }

        return new UseCaseResult(Response::HTTP_OK, ['email_verified' => true]);
    }
}
