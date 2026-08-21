<?php

declare(strict_types=1);

namespace App\Contract\Service;

use App\Contract\Entity\ContractStage;
use App\Contract\Entity\ContractTender;
use App\Contract\Exception\ContractNotFoundException;
use App\Contract\Input\ContractStageCreateInput;
use App\Contract\Repository\ContractStageRepository;
use App\Contract\Repository\ContractTenderRepository;
use App\Iam\Entity\User;
use App\Shared\Audit\AuditService;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\ValidationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Этапы исполнения по тендеру (contract_stages, FR-1.4.3, UC-10).
 *
 * Создание этапа: party-проверка через contract_tenders → договор
 * (404 для чужих компаний), номер по умолчанию — следующий по порядку,
 * дата due_at — ISO-8601 (UTC). Каждая мутация пишет append-only аудит.
 */
final readonly class ContractStageService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditService $audit,
        private ContractTenderRepository $contractTenders,
        private ContractStageRepository $stages,
    ) {
    }

    /**
     * Создание этапа исполнения (UC-10, POST /contract_tenders/{id}/stages).
     * Актор должен быть стороной договора, к которому привязан тендер.
     *
     * @throws ContractNotFoundException если привязка не найдена или актор не сторона договора
     * @throws ConflictException         если актор без компании
     * @throws ValidationException       если due_at не является корректной датой-временем
     */
    public function create(User $actor, string $contractTenderId, ContractStageCreateInput $input, ?string $ip = null): ContractStage
    {
        $companyId = $actor->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        $contractTender = $this->contractTenders->findById($contractTenderId);
        if (null === $contractTender) {
            throw new ContractNotFoundException('Contract tender not found');
        }
        $this->assertParty($contractTender, $companyId);

        $dueAt = null;
        if (null !== $input->dueAt && '' !== $input->dueAt) {
            try {
                $dueAt = new \DateTimeImmutable($input->dueAt, new \DateTimeZone('UTC'));
            } catch (\Exception $e) {
                throw new ValidationException('due_at must be a valid date-time');
            }
        }

        $stage = new ContractStage(
            contractTenderId: $contractTender->getId(),
            number: $input->number ?? $this->stages->nextNumber($contractTender->getId()),
            title: trim($input->title),
            amountMinor: $input->amountMinor,
            dueAt: $dueAt,
        );

        $this->em->persist($stage);
        $this->em->flush();

        $this->audit->record(
            action: 'contract_stage.created',
            entityType: 'contract_stage',
            entityId: (string) $stage->getId(),
            tenantId: (string) $contractTender->getContract()->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: [
                'contract_tender_id' => (string) $contractTender->getId(),
                'number' => $stage->getNumber(),
                'title' => $stage->getTitle(),
            ],
            ip: $ip,
        );

        return $stage;
    }

    private function assertParty(ContractTender $contractTender, Uuid $companyId): void
    {
        $contract = $contractTender->getContract();
        if (!$contract->getCustomerId()->equals($companyId) && !$contract->getSupplierId()->equals($companyId)) {
            throw new ContractNotFoundException('Contract tender not found');
        }
    }
}
