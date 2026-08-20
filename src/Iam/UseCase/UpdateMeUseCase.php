<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

use App\Iam\Entity\User;
use App\Iam\Input\UpdateMeInput;
use App\Iam\Presenter\UserPresenter;
use App\Iam\Service\ProfileService;

/**
 * Обновление своего профиля (FR-1.5.8, PATCH /users/me).
 *
 * Вход — валидированный UpdateMeInput (форма UpdateMeType), оркестрация —
 * ProfileService::update (проверка current_password, revoke refresh-токенов),
 * ответ — UserPresenter::single.
 */
final readonly class UpdateMeUseCase implements IamUseCase
{
    public function __construct(
        private ProfileService $profile,
        private UserPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация пользователя (openapi User)
     */
    public function execute(User $user, UpdateMeInput $input, ?string $ip = null): array
    {
        return $this->presenter->single($this->profile->update($user, $input, $ip));
    }
}
