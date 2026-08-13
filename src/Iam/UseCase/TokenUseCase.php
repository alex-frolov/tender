<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

use App\Iam\Input\TokenInput;
use App\Iam\Service\AuthenticationService;

/**
 * Аутентификация по credentials + выдача JWT-пары (FR-1.5.3, POST /auth/token).
 *
 * Проверку учётных данных (пароль, 2FA, статус пользователя) и выдачу токенов
 * выполняет доменный AuthenticationService. Неверные credentials НЕ бросаются
 * как ApiException (правило: 401/403 — контракт security-компонента/эндпоинта,
 * не подписчик) — UseCase возвращает UseCaseResult со статусом 401 и телом
 * `invalid_credentials` (тот же контракт, что у ApiAccessDeniedHandler).
 */
final readonly class TokenUseCase implements IamUseCase
{
    public function __construct(private AuthenticationService $auth)
    {
    }

    public function execute(TokenInput $input, ?string $ip = null, ?string $userAgent = null): UseCaseResult
    {
        $totpCode = $input->totpCode;
        $user = $this->auth->authenticate(
            $input->email,
            $input->password,
            null !== $totpCode && '' !== $totpCode ? $totpCode : null,
        );
        if (null === $user) {
            return new UseCaseResult(
                401,
                ['title' => 'Unauthorized', 'code' => 'invalid_credentials'],
            );
        }

        return new UseCaseResult(200, $this->auth->issueTokens($user, $ip, $userAgent));
    }
}
