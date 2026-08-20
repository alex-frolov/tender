<?php

declare(strict_types=1);

namespace App\Supplier\UseCase;

use App\Iam\Entity\User;
use App\Supplier\Presenter\SupplierProfilePresenter;
use App\Supplier\Service\SupplierProfileService;

/**
 * Карточка поставщика по id (FR-1.5.5, GET /suppliers/{supplierId}).
 *
 * Query-use-case: профиль + рейтинг + проверки (RNP, суды — от плагина).
 * Доступ: любой сотрудник компании (agent — минимальная роль).
 */
final readonly class GetSupplierUseCase implements SupplierUseCase
{
    public function __construct(
        private SupplierProfileService $profiles,
        private SupplierProfilePresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация профиля (openapi SupplierProfile)
     */
    public function execute(User $user, string $supplierId): array
    {
        $profile = $this->profiles->getById($supplierId);

        return $this->presenter->single($profile, $this->profiles->companyOf($profile));
    }
}
