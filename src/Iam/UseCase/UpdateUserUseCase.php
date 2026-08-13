<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

use App\Iam\Entity\User;
use App\Iam\Input\UpdateUserInput;
use App\Iam\Presenter\UserPresenter;
use App\Iam\Service\UserManagementService;

/**
 * Обновление пользователя компании админом: смена роли и/или статуса (FR-1.5.8,
 * PATCH /users/{userId}).
 *
 * Вход — валидированный UpdateUserInput (форма UserUpdateType), оркестрация —
 * UserManagementService::update (last admin и пр. — доменные правила), ответ —
 * UserPresenter::single.
 */
final readonly class UpdateUserUseCase implements IamUseCase
{
    public function __construct(
        private UserManagementService $users,
        private UserPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация пользователя (openapi User)
     */
    public function execute(User $user, string $userId, UpdateUserInput $input, ?string $ip = null): array
    {
        return $this->presenter->single($this->users->update($user, $userId, $input, $ip));
    }
}
