<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

use App\Iam\Entity\Company;
use App\Iam\Entity\User;
use App\Iam\Presenter\CompanyPresenter;
use App\Shared\Audit\AuditService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Изменение реквизитов своей компании (FR-1.5.4, PATCH /companies).
 *
 * Доступ проверяется ДО вызова — CompanyVoter::UPDATE (только admin компании,
 * при наличии компании). Компания — уже резолвленная сущность из контроллера
 * (CompanyRepository::findOrFail по привязке пользователя, tenant-изоляция),
 * а изменения применены entity-bound формой CompanyUpdateType (clearMissing:
 * false, см. AGENTS.md). UseCase только фиксирует изменения (flush) и пишет аудит.
 */
final readonly class UpdateCompanyUseCase implements IamUseCase
{
    public function __construct(
        private EntityManagerInterface $em,
        private CompanyPresenter $presenter,
        private AuditService $audit,
    ) {
    }

    /**
     * @param Company $before снапшот компании ДО мутации формой (для аудита before/after)
     *
     * @return array<string, mixed> презентация компании (openapi Company)
     */
    public function execute(User $user, Company $company, Company $before, ?string $ip = null): array
    {
        $this->em->flush();

        $this->audit->record(
            action: 'auth.company.update',
            entityType: 'company',
            entityId: (string) $company->getId(),
            tenantId: (string) $company->getId(),
            actorType: 'user',
            actorId: (string) $user->getId(),
            before: [
                'legal_name' => $before->getLegalName(),
                'kpp' => $before->getKpp(),
                'ogrn' => $before->getOgrn(),
                'address' => $before->getAddress(),
                'contacts' => $before->getContacts(),
            ],
            after: [
                'legal_name' => $company->getLegalName(),
                'kpp' => $company->getKpp(),
                'ogrn' => $company->getOgrn(),
                'address' => $company->getAddress(),
                'contacts' => $company->getContacts(),
            ],
            ip: $ip,
        );

        return $this->presenter->single($company);
    }
}
