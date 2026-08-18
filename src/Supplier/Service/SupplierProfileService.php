<?php

declare(strict_types=1);

namespace App\Supplier\Service;

use App\Iam\Entity\Company;
use App\Shared\Audit\AuditService;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\NotFoundException;
use App\Supplier\Entity\SupplierProfile;
use App\Supplier\Input\SupplierProfileUpdateInput;
use App\Supplier\Repository\SupplierProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Профили поставщиков (supplier_profiles, FR-1.5.5).
 *
 * - getForCompany(): профиль своей компании (null — ещё не заполнен; выводимые
 *   legal_name/inn/verification_status читаются из Company вживую);
 * - update(): правка категорий/возможностей/документов (admin компании),
 *   lazy-создание профиля при первом сохранении (GET не пишет в БД);
 * - getById(): карточка поставщика по id.
 */
final readonly class SupplierProfileService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditService $audit,
        private SupplierProfileRepository $profiles,
    ) {
    }

    /**
     * Профиль поставщика компании актора (GET /suppliers/profile).
     *
     * @throws ConflictException если актор без компании
     */
    public function getForCompany(Uuid $companyId): ?SupplierProfile
    {
        return $this->profiles->findByCompanyId($companyId);
    }

    /**
     * Обновление профиля поставщика (PUT /suppliers/profile, admin).
     *
     * @throws ConflictException если актор без компании
     */
    public function update(Uuid $companyId, SupplierProfileUpdateInput $input, string $actorId, ?string $ip = null): SupplierProfile
    {
        $profile = $this->profiles->findByCompanyId($companyId);
        if (null === $profile) {
            $profile = new SupplierProfile($companyId);
            $this->em->persist($profile);
        }
        $profile->update($input->categories, $input->capabilities, $input->documents);
        $this->em->flush();

        $this->audit->record(
            action: 'supplier.profile.updated',
            entityType: 'supplier_profile',
            entityId: (string) $profile->getId(),
            tenantId: (string) $companyId,
            actorType: 'user',
            actorId: $actorId,
            before: ['categories' => $profile->getCategories()],
            after: ['categories' => $input->categories],
            ip: $ip,
        );

        return $profile;
    }

    /**
     * Карточка поставщика по id (GET /suppliers/{supplierId}).
     *
     * @throws NotFoundException если профиль не найден
     */
    public function getById(string $supplierId): SupplierProfile
    {
        $profile = $this->profiles->findById($supplierId);
        if (null === $profile) {
            throw new NotFoundException('Supplier profile not found');
        }

        return $profile;
    }

    /**
     * Компания профиля (для презентации выводимых полей).
     */
    public function companyOf(SupplierProfile $profile): ?Company
    {
        return $this->companyById($profile->getCompanyId());
    }

    /**
     * Компания по id (для презентации выводимых полей профиля).
     */
    public function companyById(Uuid $companyId): ?Company
    {
        /** @var Company|null $company */
        $company = $this->em->getRepository(Company::class)->find($companyId);

        return $company;
    }
}
