<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

use App\Iam\Entity\User;
use App\Iam\Presenter\CompanyPresenter;
use App\Iam\Presenter\UserPresenter;
use App\Iam\Service\ProfileService;

/**
 * Текущий пользователь и его компания (FR-1.5.8, GET /users/me).
 *
 * Query-use-case: чтение без мутаций. Доступен любому аутентифицированному
 * пользователю (в отличие от GET /users — только admin). Оркестрация —
 * ProfileService::me, презентация — UserPresenter + CompanyPresenter
 * (компания может отсутствовать: null).
 */
final readonly class GetMeUseCase implements IamUseCase
{
    public function __construct(
        private ProfileService $profile,
        private UserPresenter $userPresenter,
        private CompanyPresenter $companyPresenter,
    ) {
    }

    /**
     * @return array{user: array<string, mixed>, company: array<string, mixed>|null}
     */
    public function execute(User $user): array
    {
        ['user' => $u, 'company' => $company] = $this->profile->me($user);

        return [
            'user' => $this->userPresenter->single($u),
            'company' => null !== $company ? $this->companyPresenter->single($company) : null,
        ];
    }
}
