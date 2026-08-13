<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

use App\Iam\Entity\User;
use App\Iam\Input\TwoFactorDisableInput;
use App\Iam\Service\TwoFactorService;

/**
 * Отключение 2FA по TOTP-коду (FR-1.5.3, POST /auth/2fa/disable).
 *
 * Неверный/отсутствующий секрет TwoFactorService бросает ValidationException
 * (422) — превращает JsonApiExceptionSubscriber. Ответ {two_factor_enabled:
 * false}; фиксированный статус 200 проставляет контроллер.
 */
final readonly class TwoFactorDisableUseCase implements IamUseCase
{
    public function __construct(private TwoFactorService $twoFactor)
    {
    }

    /**
     * @return array{two_factor_enabled: bool}
     */
    public function execute(User $user, TwoFactorDisableInput $input): array
    {
        $this->twoFactor->disable($user, $input->code);

        return ['two_factor_enabled' => false];
    }
}
