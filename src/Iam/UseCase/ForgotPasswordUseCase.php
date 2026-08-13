<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

use App\Iam\Input\ForgotPasswordInput;
use App\Iam\Service\PasswordResetService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Запрос восстановления пароля (FR-1.5.6, шаг 1, POST /auth/password/forgot).
 *
 * Существование email не раскрывается: not_found → 202 без письма. Cooldown
 * rate-limiter `email_send` (RL-1) возвращается как 429 `Too Many Requests`
 * с Retry-After и X-RateLimit-* заголовками — статус/тело/заголовки
 * инкапсулированы в UseCaseResult.
 */
final readonly class ForgotPasswordUseCase implements IamUseCase
{
    public function __construct(private PasswordResetService $password)
    {
    }

    public function execute(ForgotPasswordInput $input, ?string $ip = null): UseCaseResult
    {
        $result = $this->password->forgot($input->email, $ip);
        if ('rate_limited' === $result['status']) {
            return new UseCaseResult(
                Response::HTTP_TOO_MANY_REQUESTS,
                [
                    'type' => 'https://tools.ietf.org/html/rfc6585#section-4',
                    'title' => 'Too Many Requests',
                    'status' => Response::HTTP_TOO_MANY_REQUESTS,
                    'detail' => 'Password reset cooldown, try later',
                    'retry_after' => $result['retry_after'] ?? 0,
                ],
                $result['headers'] ?? [],
            );
        }

        return new UseCaseResult(Response::HTTP_ACCEPTED, ['status' => $result['status']]);
    }
}
