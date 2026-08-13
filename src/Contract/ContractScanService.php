<?php

declare(strict_types=1);

namespace App\Contract;

use App\Contract\Entity\ContractDocument;
use App\Contract\Exception\ContractNotFoundException;
use App\Contract\Repository\ContractRepository;
use App\Document\DocumentService;
use App\Document\DocumentTypeService;
use App\Document\Entity\Enum\DocumentEntityType;
use App\Document\Entity\Enum\DocumentScope;
use App\Iam\Entity\User;
use App\Shared\Audit\AuditService;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\NotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Скан договора (contract_documents, FR-1.4.7, UC-08a).
 *
 * Исполнитель прикладывает скан договора для работы с заказчиком; для
 * многоразового договора (multi_use) один скан действует для многих тендеров
 * (не перезагружается на каждый тендер). Заказчик видит скан в карточке
 * договора.
 *
 * Технически: загружаем документ (entity_type=contract, scope=contract,
 * document_type=contract_scan) через DocumentService и фиксируем связь
 * contract_documents (contract_id, document_id, uploaded_by).
 */
final readonly class ContractScanService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditService $audit,
        private ContractRepository $contracts,
        private DocumentService $documents,
        private DocumentTypeService $documentTypes,
    ) {
    }

    /**
     * Приложение скана к договору (FR-1.4.7). Исполнитель или заказчик —
     * сторона договора (party-проверка 404 для чужих).
     *
     * @throws ContractNotFoundException если договор не найден/не принадлежит стороне
     * @throws ConflictException         если актор без компании
     */
    public function upload(User $actor, string $contractId, UploadedFile $file, ?string $ip = null): ContractDocument
    {
        $companyId = $actor->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        $contract = $this->contracts->findById($contractId);
        if (null === $contract || (!$contract->getCustomerId()->equals($companyId) && !$contract->getSupplierId()->equals($companyId))) {
            throw new ContractNotFoundException('Contract not found');
        }

        $type = $this->documentTypes->findByCode('contract_scan');
        if (null === $type || !$type->isActive()) {
            throw new NotFoundException('contract_scan document type not found');
        }

        $document = $this->documents->upload(
            actor: $actor,
            file: $file,
            documentTypeId: (string) $type->getId(),
            entityType: DocumentEntityType::CONTRACT->value,
            entityId: (string) $contract->getId(),
            visibility: 'private',
            scope: DocumentScope::CONTRACT->value,
            ip: $ip,
        );

        $scan = new ContractDocument(
            contractId: $contract->getId(),
            documentId: $document->getId(),
            uploadedBy: $companyId->equals($contract->getCustomerId()) ? 'customer' : 'executor',
        );
        $this->em->persist($scan);
        $this->em->flush();

        $this->audit->record(
            action: 'contract.scan_uploaded',
            entityType: 'contract',
            entityId: (string) $contract->getId(),
            tenantId: (string) $contract->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: [
                'document_id' => (string) $document->getId(),
                'contract_document_id' => (string) $scan->getId(),
                'uploaded_by' => $scan->getUploadedBy(),
            ],
            ip: $ip,
        );

        return $scan;
    }
}
