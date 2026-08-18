<?php

declare(strict_types=1);

namespace App\Complaint\Service;

use App\Complaint\Entity\Complaint;
use App\Complaint\Input\CreateComplaintInput;
use App\Shared\Audit\AuditService;
use App\Shared\Exception\ConflictException;
use App\Tender\TenderReadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Жалобы по тендеру (complaints, FR-1.2.10).
 *
 * Подача жалобы (участник, право tenders.qa): лот валидируется
 * принадлежностью тендеру через TenderReadService; аудит append-only.
 */
final readonly class ComplaintService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditService $audit,
        private TenderReadService $tenders,
    ) {
    }

    /**
     * Подача жалобы (POST /tenders/{tenderId}/complaints).
     *
     * @throws \App\Shared\Exception\NotFoundException если тендер не найден
     * @throws ConflictException                       если актор без компании
     * @throws ConflictException                       если лот не принадлежит тендеру
     */
    public function file(string $tenderId, CreateComplaintInput $input, Uuid $companyId, string $actorId, ?string $ip = null): Complaint
    {
        $tender = $this->tenders->resolveTender($tenderId);
        $lotId = null !== $input->lotId && '' !== $input->lotId
            ? $this->tenders->resolveLot($tender->getId(), $input->lotId)?->getId()
            : null;

        $complaint = new Complaint(
            tenderId: $tender->getId(),
            lotId: $lotId,
            text: trim($input->text),
            ground: trim($input->ground),
            documentIds: $input->documentIds,
        );

        $this->em->persist($complaint);
        $this->em->flush();

        $this->audit->record(
            action: 'tender.complaint_filed',
            entityType: 'complaint',
            entityId: (string) $complaint->getId(),
            tenantId: (string) $tender->getTenantId(),
            actorType: 'user',
            actorId: $actorId,
            after: ['tender_id' => $tenderId, 'status' => $complaint->getStatus()->value],
            ip: $ip,
        );

        return $complaint;
    }
}
