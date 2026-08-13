<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

use App\Iam\Entity\User;
use App\Iam\Input\InviteUserInput;
use App\Iam\Presenter\UserPresenter;
use App\Iam\Service\UserManagementService;

/**
 * Приглашение сотрудника (FR-1.5.8, POST /users).
 *
 * Только admin (атрибут на контроллере). Создаёт пользователя со статусом
 * invited, отправляет письмо-приглашение. Вход — валидированный InviteUserInput
 * (форма UserInviteType), оркестрация — UserManagementService::invite, ответ —
 * UserPresenter::single (openapi User).
 */
final readonly class InviteUserUseCase implements IamUseCase
{
    public function __construct(
        private UserManagementService $users,
        private UserPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация пользователя (openapi User)
     */
    public function execute(User $user, InviteUserInput $input, ?string $ip = null): array
    {
        return $this->presenter->single($this->users->invite($user, $input, $ip));
    }
}
