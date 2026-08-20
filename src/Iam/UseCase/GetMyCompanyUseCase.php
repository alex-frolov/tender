<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

use App\Iam\Entity\User;
use App\Iam\Exception\CompanyNotFoundException;
use App\Iam\Presenter\CompanyPresenter;
use App\Iam\Repository\CompanyRepository;

/**
 * Карточка своей компании (FR-1.5.4, GET /companies).
 *
 * Query-use-case: компания — из привязки пользователя (tenant-изоляция:
 * чужая компания не отдаётся). Доступ — любой сотрудник компании (agent —
 * минимальная роль, IsGranted в контроллере).
 */
final readonly class GetMyCompanyUseCase implements IamUseCase
{
    public function __construct(
        private CompanyRepository $companies,
        private CompanyPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация компании (openapi Company)
     *
     * @throws CompanyNotFoundException если у актора нет компании
     */
    public function execute(User $user): array
    {
        return $this->presenter->single($this->companies->findOrFail($user->getCompanyId()));
    }
}
