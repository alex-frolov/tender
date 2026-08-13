<?php

declare(strict_types=1);

namespace App\Iam\Presenter;

use App\Iam\Entity\User;

/**
 * Публичное представление пользователя (openapi User, FR-1.5.8).
 *
 * Используется Iam-UseCase'ами (прикладной слой) для формирования ответа;
 * извлечено из AbstractBaseController::userPayload, чтобы UseCase не зависел
 * от Controller.
 */
final readonly class UserPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function single(User $user): array
    {
        return [
            'id' => (string) $user->getId(),
            'company_id' => null !== $user->getCompanyId() ? (string) $user->getCompanyId() : null,
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'role' => $user->getRole()->value,
            'verification_status' => $user->getVerificationStatus()->value,
            'email_verified_at' => $user->getEmailVerifiedAt()?->format('Y-m-d\TH:i:s\Z'),
            'two_factor_enabled' => $user->isTwoFactorEnabled(),
            'last_login_at' => $user->getLastLoginAt()?->format('Y-m-d\TH:i:s\Z'),
            'created_at' => $user->getCreatedAt()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
