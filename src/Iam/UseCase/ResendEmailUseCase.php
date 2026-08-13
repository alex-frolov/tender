<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

use App\Iam\Input\ResendEmailInput;
use App\Iam\Service\EmailVerificationService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Повторная отправка письма подтверждения email (FR-1.5.5, POST /auth/email/resend).
 *
 * Существование email не раскрывается: not_found/already_verified → 202 без
 * письма. Cooldown rate-limiter `email_send` (RL-1) возвращается как 429
 * `Too Many Requests` с Retry-After и X-RateLimit-* заголовками — статус/тело/
 * заголовки инкапсулированы в UseCaseResult, контроллер остаётся тонким.
 */
final readonly class ResendEmailUseCase implements IamUseCase
{
    public function __construct(private EmailVerificationService $email)
    {
    }

    public function execute(ResendEmailInput $input, ?string $ip = null): UseCaseResult
    {
        $result = $this->email->resend($input->email, $ip);
        if ('rate_limited' === $result['status']) {
            return new UseCaseResult(
                Response::HTTP_TOO_MANY_REQUESTS,
                [
                    'type' => 'https://tools.ietf.org/html/rfc6585#section-4',
                    'title' => 'Too Many Requests',
                    'status' => Response::HTTP_TOO_MANY_REQUESTS,
                    'detail' => 'Email resend cooldown, try later',
                    'retry_after' => $result['retry_after'] ?? 0,
                ],
                $result['headers'] ?? [],
            );
        }

        return new UseCaseResult(Response::HTTP_ACCEPTED, ['status' => $result['status']]);
    }
}
