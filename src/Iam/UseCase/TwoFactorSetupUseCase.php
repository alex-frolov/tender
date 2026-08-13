<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

use App\Iam\Entity\User;
use App\Iam\Service\TwoFactorService;

/**
 * Начало включения 2FA: выдача секрета и QR-данных (FR-1.5.3, POST /auth/2fa/setup).
 *
 * При уже включённой 2FA TwoFactorService бросает ConflictException (409) —
 * превращает JsonApiExceptionSubscriber. Ответ {secret, otpauth_uri}
 * возвращается как презентация; фиксированный статус 200 проставляет контроллер.
 */
final readonly class TwoFactorSetupUseCase implements IamUseCase
{
    public function __construct(private TwoFactorService $twoFactor)
    {
    }

    /**
     * @return array{secret: string, otpauth_uri: string}
     */
    public function execute(User $user): array
    {
        return $this->twoFactor->setup($user);
    }
}
