<?php

declare(strict_types=1);

namespace App\Complaint\Service;

use App\Complaint\Entity\Complaint;
use App\Complaint\Input\CreateComplaintInput;
use App\Complaint\Repository\ComplaintRepository;
use App\Shared\Audit\AuditService;
use App\Shared\Exception\ConflictException;
use App\Tender\TenderReadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Жалобы по тендеру (complaints, FR-1.2.10).
 *
 * - file(): подача жалобы (участник, право tenders.qa) — лот валидируется
 *   принадлежностью тендеру через TenderReadService, аудит append-only;
 * - list(): жалобы, видимые компании — поданные ею и поданные на её процедуры.
 *
 * Тендер резолвится через resolveVisibleTender (FR-1.5.14): право tenders.qa
 * субъекта не имеет, поэтому без проверки видимости жалобу можно было бы
 * подать на любой id площадки, включая чужие черновики. Невидимый → 404.
 */
final readonly class ComplaintService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditService $audit,
        private TenderReadService $tenders,
        private ComplaintRepository $complaints,
    ) {
    }

    /**
     * Жалобы, видимые компании актора (GET /complaints): поданные ею самой
     * и поданные на её процедуры — разбирательство видят обе стороны.
     * Чужие жалобы не отдаются: охват «мои процедуры» строится по списку
     * тендеров компании (TenderReadService), а не по параметру запроса.
     *
     * @return list<Complaint>
     *
     * @throws ConflictException если у актора нет компании
     */
    public function list(Uuid $companyId, ?string $tenderId = null, ?string $status = null): array
    {
        return $this->complaints->listVisible(
            $companyId,
            $this->tenders->idsForCompany($companyId),
            $tenderId,
            $status,
        );
    }

    /**
     * Подача жалобы (POST /tenders/{tenderId}/complaints).
     *
     * @throws \App\Shared\Exception\NotFoundException если тендер не найден
     *                                                 или невидим компании
     * @throws ConflictException                       если лот не принадлежит тендеру
     */
    public function file(string $tenderId, CreateComplaintInput $input, Uuid $companyId, string $actorId, ?string $ip = null): Complaint
    {
        $tender = $this->tenders->resolveVisibleTender($tenderId, $companyId);
        $lotId = null !== $input->lotId && '' !== $input->lotId
            ? $this->tenders->resolveLot($tender->getId(), $input->lotId)?->getId()
            : null;

        $complaint = new Complaint(
            tenderId: $tender->getId(),
            companyId: $companyId,
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
            after: [
                'tender_id' => $tenderId,
                'company_id' => (string) $companyId,
                'status' => $complaint->getStatus()->value,
            ],
            ip: $ip,
        );

        return $complaint;
    }
}
