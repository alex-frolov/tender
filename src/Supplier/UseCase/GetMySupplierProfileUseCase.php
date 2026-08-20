<?php

declare(strict_types=1);

namespace App\Supplier\UseCase;

use App\Iam\Entity\User;
use App\Shared\Exception\ConflictException;
use App\Supplier\Presenter\SupplierProfilePresenter;
use App\Supplier\Service\SupplierProfileService;

/**
 * Своя карточка поставщика (FR-1.5.5, GET /suppliers/profile).
 *
 * Query-use-case: профиль своей компании; выводимые поля — из Company вживую,
 * доп. данные — из SupplierProfile (или пустые, если профиль ещё не заполнен).
 * Доступ: любой сотрудник компании (agent — минимальная роль).
 */
final readonly class GetMySupplierProfileUseCase implements SupplierUseCase
{
    public function __construct(
        private SupplierProfileService $profiles,
        private SupplierProfilePresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация профиля (openapi SupplierProfile)
     *
     * @throws ConflictException если актор без компании
     */
    public function execute(User $user): array
    {
        $companyId = $user->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        $profile = $this->profiles->getForCompany($companyId);

        return $this->presenter->single($profile, $this->profiles->companyById($companyId));
    }
}
