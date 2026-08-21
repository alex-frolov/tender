<?php

declare(strict_types=1);

namespace App\Document\Service;

use App\Contract\ContractReadService;
use App\Document\DocumentPresenter;
use App\Document\DocumentService as DocumentServiceContract;
use App\Document\Entity\Document;
use App\Document\Entity\DocumentType;
use App\Document\Entity\DocumentVersion;
use App\Document\Entity\Enum\DocumentEntityType;
use App\Document\Entity\Enum\DocumentOwnerRole;
use App\Document\Entity\Enum\DocumentScope;
use App\Document\Entity\Enum\DocumentVisibility;
use App\Document\Exception\DocumentAccessDeniedException;
use App\Document\Exception\DocumentNotFoundException;
use App\Document\Exception\StorageException;
use App\Document\Repository\DocumentRepository;
use App\Document\Storage\FileStorage;
use App\Iam\CompanyAccessGuard;
use App\Iam\Entity\User;
use App\Shared\Audit\AuditService;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\NotFoundException;
use App\Shared\Exception\ValidationException;
use App\Tender\TenderReadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Реализация публичного контракта модуля Document (AM-8, FR-1.1.5, FR-1.2.6).
 * См. App\Document\DocumentService. Алиас импорта — имя класса совпадает с
 * именем интерфейса (PHP запрещает объявление класса с именем, занятым `use`).
 *
 * - Каждая загрузка — новая версия документа (file_id/version/sha256/размер,
 *   FR-1.1.5); бинарное содержимое — в FileStorage, в БД только метаданные.
 * - Владелец (owner_role) выводится из document_type (customer/executor/both);
 *   при both — из типа компании актора.
 * - Видимость (FR-1.2.6): по умолчанию из document_type, при переопределении —
 *   из запроса. Доступ: владелец видит все свои документы; публичные видит
 *   каждый; приватные — только владелец и (в фазе заявок) победитель.
 * - Tenant-изоляция: документ привязан к компании актора; чтение чужого → 404.
 * - Лимиты (FR-1.1.5): размер ≤ $maxFileBytes (из env DOCUMENT_MAX_FILE_BYTES),
 *   mime из whitelist.
 */
final class DocumentService implements DocumentServiceContract
{
    /** Разрешённые mime-типы (FR-1.1.5): офисные форматы, PDF, изображения. */
    private const array ALLOWED_MIME = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
        'application/x-rar-compressed',
        'text/plain',
        'text/csv',
        'image/jpeg',
        'image/png',
        'image/gif',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditService $audit,
        private readonly FileStorage $storage,
        private readonly DocumentRepository $documents,
        private readonly CompanyAccessGuard $companyGuard,
        private readonly ContractReadService $contracts,
        private readonly TenderReadService $tenders,
        private readonly DocumentPresenter $presenter,
        private readonly int $maxFileBytes,
    ) {
    }

    public function upload(User $actor, UploadedFile $file, string $documentTypeId, string $entityType, string $entityId, ?string $visibility, ?string $scope, ?string $ip = null): Document
    {
        $companyId = $this->requireCompany($actor);
        $this->companyGuard->assertActive($companyId);

        $type = $this->resolveDocumentType($documentTypeId);
        $entityTypeEnum = $this->resolveEntityType($entityType);
        $entityIdUuid = $this->resolveUuid($entityId, 'entity_id');

        $this->assertEntityBelongsToTenant($entityTypeEnum, $entityIdUuid, $companyId);
        $this->assertFile($file);

        $content = $file->getContent();
        $sha256 = hash('sha256', $content);
        $ownerRole = $this->resolveOwnerRole($type, $actor, $companyId);
        $documentVisibility = $this->resolveVisibility($visibility ?? $type->getVisibility());
        $documentScope = null !== $scope ? $this->resolveScope($scope) : DocumentScope::TENDER;

        $storagePath = $this->storage->store(
            $content,
            (string) Uuid::v4(),
            $file->getExtension() ?? '',
        );

        $document = new Document(
            documentType: $type,
            entityType: $entityTypeEnum,
            entityId: $entityIdUuid,
            title: $file->getClientOriginalName(),
            ownerRole: $ownerRole,
            visibility: $documentVisibility,
            scope: $documentScope,
            tenantId: $companyId,
            createdBy: $actor->getId(),
        );

        $version = new DocumentVersion(
            document: $document,
            version: $document->nextVersionNumber(),
            sha256: $sha256,
            sizeBytes: $file->getSize(),
            mimeType: $file->getClientMimeType(),
            originalName: $file->getClientOriginalName(),
            storagePath: $storagePath,
            uploadedBy: $actor->getId(),
        );
        $document->addVersion($version);

        $this->em->persist($document);
        $this->em->persist($version);
        $this->em->flush();

        $this->audit->record(
            action: 'document.uploaded',
            entityType: 'document',
            entityId: (string) $document->getId(),
            tenantId: (string) $companyId,
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: [
                'document_type' => $type->getCode(),
                'entity_type' => $entityTypeEnum->value,
                'entity_id' => (string) $entityIdUuid,
                'version' => $version->getVersion(),
                'sha256' => $sha256,
                'size_bytes' => $file->getSize(),
                'visibility' => $document->getVisibility()->value,
            ],
            ip: $ip,
        );

        return $document;
    }

    public function addVersion(User $actor, string $documentId, UploadedFile $file, ?string $ip = null): Document
    {
        $companyId = $this->requireCompany($actor);
        $document = $this->resolveForWrite($actor, $companyId, $documentId);
        $this->assertFile($file);

        $content = $file->getContent();
        $storagePath = $this->storage->store(
            $content,
            (string) Uuid::v4(),
            $file->getExtension() ?? '',
        );

        $version = new DocumentVersion(
            document: $document,
            version: $document->nextVersionNumber(),
            sha256: hash('sha256', $content),
            sizeBytes: $file->getSize(),
            mimeType: $file->getClientMimeType(),
            originalName: $file->getClientOriginalName(),
            storagePath: $storagePath,
            uploadedBy: $actor->getId(),
        );
        $document->addVersion($version);

        $this->em->persist($version);
        $this->em->flush();

        $this->audit->record(
            action: 'document.version_added',
            entityType: 'document',
            entityId: (string) $document->getId(),
            tenantId: (string) $companyId,
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: ['version' => $version->getVersion(), 'sha256' => $version->getSha256()],
            ip: $ip,
        );

        return $document;
    }

    public function get(User $actor, string $documentId): Document
    {
        $this->requireCompany($actor);
        $document = $this->resolveForRead($actor, $documentId);

        return $document;
    }

    /**
     * Документы сущности, видимые компании актора (GET /documents).
     *
     * Видимость (FR-1.2.6) применяется в запросе: свои документы — все,
     * чужие — только публичные. Принадлежность самой сущности не проверяется
     * отдельно: приватные документы чужого тендера в выборку не попадают,
     * а публичные и так открыты допущенным участникам.
     *
     * @return list<array<string, mixed>> презентации документов (openapi Document)
     *
     * @throws ConflictException если актор без компании
     */
    public function listForEntity(User $actor, string $entityType, string $entityId): array
    {
        $companyId = $this->requireCompany($actor);
        $type = DocumentEntityType::tryFrom($entityType)
            ?? throw new ValidationException('invalid entity_type');
        if (!Uuid::isValid($entityId)) {
            throw new ValidationException('entity_id must be a valid uuid');
        }

        $documents = $this->documents->listForEntity($type->value, Uuid::fromString($entityId), $companyId);

        return array_map(
            fn (Document $document): array => $this->presenter->single(
                $document,
                str_replace('{documentId}', (string) $document->getId(), DocumentPresenter::DOWNLOAD_URL),
            ),
            $documents,
        );
    }

    public function present(User $actor, string $documentId): array
    {
        $document = $this->get($actor, $documentId);
        $downloadUrl = str_replace('{documentId}', $documentId, DocumentPresenter::DOWNLOAD_URL);

        return $this->presenter->single($document, $downloadUrl);
    }

    public function download(User $actor, string $documentId): array
    {
        $document = $this->get($actor, $documentId);
        $version = $document->currentVersion();
        if (null === $version) {
            throw new DocumentNotFoundException('Document has no versions');
        }

        try {
            $content = $this->storage->read($version->getStoragePath());
        } catch (StorageException $e) {
            throw new DocumentNotFoundException('Stored file not found');
        }

        return [
            'content' => $content,
            'mimeType' => $version->getMimeType(),
            'originalName' => $version->getOriginalName(),
        ];
    }

    /**
     * @throws ConflictException если актор без компании
     * @throws NotFoundException если тип не найден/неактивен
     */
    private function resolveDocumentType(string $documentTypeId): DocumentType
    {
        $id = (int) $documentTypeId;
        if ($id <= 0) {
            throw new NotFoundException('Document type not found');
        }

        /** @var DocumentType|null $type */
        $type = $this->em->getRepository(DocumentType::class)->find($id);
        if (null === $type || !$type->isActive()) {
            throw new NotFoundException('Document type not found');
        }

        return $type;
    }

    /**
     * @throws ValidationException если entity_type вне допустимых значений
     */
    private function resolveEntityType(string $entityType): DocumentEntityType
    {
        $enum = DocumentEntityType::tryFrom($entityType) ?? throw new ValidationException('invalid entity_type');

        return $enum;
    }

    /**
     * @throws ValidationException если передан невалидный UUID
     */
    private function resolveUuid(string $value, string $field): Uuid
    {
        if ('' === $value || !Uuid::isValid($value)) {
            throw new ValidationException(\sprintf('%s must be a valid UUID', $field));
        }

        return Uuid::fromString($value);
    }

    /**
     * Привязка документа к сущности в рамках компании актора (tenant-изоляция).
     *
     * - entity_type=tender: тендер в компании актора (заказчик) — через
     *   публичный read-контракт Tender-модуля (TenderReadService::belongsToCompany),
     *   а не напрямую через EM/чужой Repository (границы модулей, rule 6);
     * - entity_type=contract: договор, где компания актора — заказчик или
     *   исполнитель (contract_documents, FR-1.4.7 — скан договора);
     * - прочие типы (lot/bid/…) подключаются в соответствующих фазах.
     *
     * @throws NotFoundException             если тендер не в компании актора
     * @throws DocumentAccessDeniedException если договор не принадлежит компании
     */
    private function assertEntityBelongsToTenant(DocumentEntityType $entityType, Uuid $entityId, Uuid $companyId): void
    {
        if (DocumentEntityType::TENDER === $entityType) {
            if (!$this->tenders->belongsToCompany($entityId, $companyId)) {
                throw new NotFoundException('Tender not found');
            }

            return;
        }

        if (DocumentEntityType::CONTRACT === $entityType) {
            // Принадлежность к договору (заказчик/исполнитель) — через публичный
            // read-контракт Contract-модуля (ContractReadService::isParty),
            // а не напрямую через чужой Repository/EM (границы модулей).
            if (!$this->contracts->isParty($entityId, $companyId)) {
                throw new DocumentAccessDeniedException('Contract not found');
            }
        }
    }

    /**
     * Проверка лимитов файла (FR-1.1.5): размер и mime-тип.
     *
     * @throws ValidationException
     */
    private function assertFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new ValidationException('file upload failed');
        }

        if ($file->getSize() > $this->maxFileBytes) {
            throw new ValidationException(\sprintf('file exceeds maximum size of %d MB', intdiv($this->maxFileBytes, 1024 * 1024)));
        }

        $mime = strtolower((string) $file->getClientMimeType());
        if (!\in_array($mime, self::ALLOWED_MIME, true)) {
            throw new ValidationException(\sprintf('file type not allowed: %s', $file->getClientMimeType()));
        }
    }

    /**
     * Определение владельца документа (FR-1.2.6). owner_role из document_type:
     * customer → CUSTOMER; executor → EXECUTOR; both → по типу компании актора.
     */
    private function resolveOwnerRole(DocumentType $type, User $actor, Uuid $companyId): DocumentOwnerRole
    {
        $ownerRole = $type->getOwnerRole();
        if ('executor' === $ownerRole) {
            return DocumentOwnerRole::EXECUTOR;
        }
        if ('customer' === $ownerRole) {
            return DocumentOwnerRole::CUSTOMER;
        }

        // both → по типу компании актора
        $company = $this->em->getRepository(\App\Iam\Entity\Company::class)->find($companyId);
        if (null !== $company && $company->getType()->isSupplier()) {
            return DocumentOwnerRole::EXECUTOR;
        }

        return DocumentOwnerRole::CUSTOMER;
    }

    /**
     * @throws ValidationException
     */
    private function resolveVisibility(?string $value): DocumentVisibility
    {
        $enum = DocumentVisibility::tryFrom($value ?? '') ?? throw new ValidationException('invalid visibility');

        return $enum;
    }

    /**
     * @throws ValidationException
     */
    private function resolveScope(?string $value): DocumentScope
    {
        $enum = DocumentScope::tryFrom($value ?? '') ?? throw new ValidationException('invalid scope');

        return $enum;
    }

    /**
     * Права просмотра документа (FR-1.2.6):
     * - владелец (компания-tenant) видит все свои документы;
     * - публичные видит любой допущенный участник;
     * - приватные — только владелец (победитель подключается в фазе заявок).
     */
    private function canView(User $actor, Document $document): bool
    {
        $companyId = $actor->getCompanyId();
        if (null === $companyId) {
            return false;
        }

        if ($companyId->equals($document->getTenantId())) {
            return true;
        }

        return DocumentVisibility::PUBLIC === $document->getVisibility();
    }

    /**
     * @throws ConflictException             если актор без компании
     * @throws DocumentNotFoundException     если документ не существует
     * @throws DocumentAccessDeniedException если нет прав по видимости
     */
    private function resolveForRead(User $actor, string $documentId): Document
    {
        $document = $this->findDocument($documentId);
        if (!$this->canView($actor, $document)) {
            throw new DocumentAccessDeniedException();
        }

        return $document;
    }

    /**
     * @throws ConflictException             если актор без компании
     * @throws DocumentNotFoundException     если документ не существует
     * @throws DocumentAccessDeniedException если актор не владелец
     */
    private function resolveForWrite(User $actor, Uuid $companyId, string $documentId): Document
    {
        $document = $this->findDocument($documentId);
        if (!$companyId->equals($document->getTenantId())) {
            throw new DocumentAccessDeniedException('Only the owner can add document versions');
        }

        return $document;
    }

    /**
     * @throws DocumentNotFoundException
     */
    private function findDocument(string $documentId): Document
    {
        $document = $this->documents->findById($documentId);
        if (null === $document) {
            throw new DocumentNotFoundException('Document not found');
        }

        return $document;
    }

    /**
     * @throws ConflictException если актор без компании
     */
    private function requireCompany(User $actor): Uuid
    {
        $companyId = $actor->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        return $companyId;
    }
}
