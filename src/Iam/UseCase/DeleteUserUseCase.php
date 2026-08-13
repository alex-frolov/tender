<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

use App\Iam\Entity\User;
use App\Iam\Service\UserManagementService;

/**
 * Мягкое удаление пользователя с маскированием email (FR-1.5.9, DELETE /users/{userId}).
 *
 * Только admin (атрибут на контроллере). Нельзя удалить последнего активного
 * администратора (FR-1.5.8) — доменное правило в UserManagementService::softDelete.
 * Ответ 204 No Content формирует контроллер (мутация без тела).
 */
final readonly class DeleteUserUseCase implements IamUseCase
{
    public function __construct(private UserManagementService $users)
    {
    }

    public function execute(User $user, string $userId, ?string $ip = null): void
    {
        $this->users->softDelete($user, $userId, $ip);
    }
}
