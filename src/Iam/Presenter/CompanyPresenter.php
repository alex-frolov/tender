<?php

declare(strict_types=1);

namespace App\Iam\Presenter;

use App\Iam\Entity\Company;

/**
 * Публичное представление компании (openapi Company, Iam).
 *
 * Поля строго по схеме Company из api/openapi.yaml. Используется UseCase'ами
 * модуля для формирования ответа (GET /users/me → {user, company}).
 */
final readonly class CompanyPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function single(Company $company): array
    {
        return [
            'id' => (string) $company->getId(),
            'type' => $company->getType()->value,
            'legal_name' => $company->getLegalName(),
            'inn' => $company->getInn(),
            'kpp' => $company->getKpp(),
            'ogrn' => $company->getOgrn(),
            'address' => $company->getAddress(),
            'contacts' => $company->getContacts() ?? [],
            'verification_status' => $company->getVerificationStatus()->value,
            'verified_at' => $company->getVerifiedAt()?->format('Y-m-d\TH:i:s\Z'),
            'created_at' => $company->getCreatedAt()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
