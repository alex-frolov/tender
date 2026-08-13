<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

use App\Iam\Input\RefreshInput;
use App\Iam\Service\AuthenticationService;

/**
 * Ротация refresh-токена (FR-1.5.3, POST /auth/refresh).
 *
 * Механику ротации (отзыв старого, выпуск новой пары, аудит) выполняет
 * доменный AuthenticationService::rotate. Невалидный/отозванный/истёкший токен
 * сервис бросает RuntimeException → UseCase возвращает 401 `invalid_credentials`
 * (не ApiException — 401 остаётся контрактом эндпоинта, см. TokenUseCase).
 */
final readonly class RefreshUseCase implements IamUseCase
{
    public function __construct(private AuthenticationService $auth)
    {
    }

    public function execute(RefreshInput $input, ?string $ip = null, ?string $userAgent = null): UseCaseResult
    {
        try {
            $tokens = $this->auth->rotate($input->refreshToken, $ip, $userAgent);
        } catch (\RuntimeException) {
            return new UseCaseResult(
                401,
                ['title' => 'Unauthorized', 'code' => 'invalid_credentials', 'detail' => 'Invalid refresh token'],
            );
        }

        return new UseCaseResult(200, $tokens);
    }
}
