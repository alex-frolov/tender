<?php

declare(strict_types=1);

namespace App\Supplier\Presenter;

use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\CompanyStatusEnum;
use App\Supplier\Entity\SupplierProfile;

/**
 * Публичное представление профиля поставщика (openapi SupplierProfile).
 *
 * Выводимые поля legal_name/inn/verification_status читаются из Company
 * (единый источник регистрационных данных); категории/возможности/документы/
 * рейтинг/проверки — из SupplierProfile. Маппинг verification_status:
 * company active → verified, pending → pending, rejected/suspended → unverified.
 * При отсутствии профиля (null) — пустые значения (профиль ещё не заполнен).
 */
final readonly class SupplierProfilePresenter
{
    /**
     * @return array<string, mixed>
     */
    public function single(?SupplierProfile $profile, ?Company $company): array
    {
        return [
            'id' => null !== $profile ? (string) $profile->getId() : (null !== $company ? (string) $company->getId() : null),
            'company_id' => null !== $company ? (string) $company->getId() : (null !== $profile ? (string) $profile->getCompanyId() : null),
            'legal_name' => null !== $company ? $company->getLegalName() : null,
            'inn' => null !== $company ? $company->getInn() : null,
            'categories' => null !== $profile ? $profile->getCategories() : [],
            'capabilities' => null !== $profile ? $profile->getCapabilities() : [],
            'documents' => null !== $profile ? $profile->getDocuments() : [],
            'rating' => null !== $profile ? $profile->getRating() : null,
            'verification_status' => null !== $company ? self::verificationStatus($company->getVerificationStatus()) : 'unverified',
            'rnp_blocked' => null !== $profile && $profile->isRnpBlocked(),
            'checks' => null !== $profile ? $profile->getChecks() : [],
        ];
    }

    private static function verificationStatus(CompanyStatusEnum $status): string
    {
        return match ($status) {
            CompanyStatusEnum::ACTIVE => 'verified',
            CompanyStatusEnum::PENDING => 'pending',
            CompanyStatusEnum::REJECTED,
            CompanyStatusEnum::SUSPENDED => 'unverified',
        };
    }
}
