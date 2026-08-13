<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

use App\Iam\Entity\User;
use App\Iam\Presenter\UserPresenter;
use App\Iam\Service\UserManagementService;

/**
 * Список пользователей компании (FR-1.5.8, GET /users).
 *
 * Query-use-case: чтение без мутаций. Только admin (атрибут на контроллере).
 * Оркестрация — UserManagementService::listUsers, презентация — UserPresenter::single.
 */
final readonly class ListUsersUseCase implements IamUseCase
{
    public function __construct(
        private UserManagementService $users,
        private UserPresenter $presenter,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function execute(User $user): array
    {
        return [
            'items' => array_map(
                fn ($u): array => $this->presenter->single($u),
                $this->users->listUsers($user),
            ),
        ];
    }
}
