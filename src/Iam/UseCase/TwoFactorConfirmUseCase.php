<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

use App\Iam\Entity\User;
use App\Iam\Input\TwoFactorConfirmInput;
use App\Iam\Service\TwoFactorService;

/**
 * Подтверждение и включение 2FA по TOTP-коду (FR-1.5.3, POST /auth/2fa/confirm).
 *
 * Неверный код TwoFactorService бросает ValidationException (422) — превращает
 * JsonApiExceptionSubscriber. Ответ {two_factor_enabled: true}; фиксированный
 * статус 200 проставляет контроллер.
 */
final readonly class TwoFactorConfirmUseCase implements IamUseCase
{
    public function __construct(private TwoFactorService $twoFactor)
    {
    }

    /**
     * @return array{two_factor_enabled: bool}
     */
    public function execute(User $user, TwoFactorConfirmInput $input): array
    {
        $this->twoFactor->confirm($user, $input->secret, $input->code);

        return ['two_factor_enabled' => true];
    }
}
