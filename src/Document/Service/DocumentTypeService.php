<?php

declare(strict_types=1);

namespace App\Document\Service;

use App\Document\DocumentTypeService as DocumentTypeServiceContract;
use App\Document\Entity\DocumentType;
use App\Document\Input\CreateDocumentTypeInput;
use App\Document\Input\UpdateDocumentTypeInput;
use App\Iam\Entity\User;
use App\Shared\Audit\AuditService;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\NotFoundException;
use App\Shared\Exception\ValidationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Реализация публичного контракта справочника типов документов
 * (см. App\Document\DocumentTypeService). Алиас импорта — имя класса совпадает
 * с именем интерфейса (PHP запрещает объявление класса с именем, занятым `use`).
 *
 * Управляется суперадмином платформы: добавление, изменение, деактивация;
 * применяется ко всем тендерам. Каждая мутация пишет append-only запись
 * в аудит (FR-1.8).
 */
final class DocumentTypeService implements DocumentTypeServiceContract
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditService $audit,
    ) {
    }

    public function list(): array
    {
        $result = $this->em->getRepository(DocumentType::class)
            ->createQueryBuilder('t')
            ->andWhere('t.active = true')
            ->orderBy('t.sortOrder', 'ASC')
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();

        /** @var list<DocumentType> */
        return $result;
    }

    public function findByCode(string $code): ?DocumentType
    {
        /** @var DocumentType|null $type */
        $type = $this->em->getRepository(DocumentType::class)->findOneBy(['code' => $code]);

        return $type;
    }

    public function create(User $actor, CreateDocumentTypeInput $input, ?string $ip = null): DocumentType
    {
        $code = trim($input->code);
        if ('' === $code) {
            throw new ValidationException('code is required');
        }

        $existing = $this->em->getRepository(DocumentType::class)->findOneBy(['code' => $code]);
        if (null !== $existing) {
            throw new ConflictException('Document type code already exists');
        }

        $type = new DocumentType(
            code: $code,
            name: trim($input->name),
            ownerRole: $input->ownerRole,
            visibility: $input->visibility,
            required: $input->required,
            sortOrder: $this->nextSortOrder(),
        );

        $this->em->persist($type);
        $this->em->flush();

        $this->audit->record(
            action: 'document_type.created',
            entityType: 'document_type',
            entityId: (string) $type->getId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: ['code' => $type->getCode(), 'name' => $type->getName(), 'required' => $type->isRequired()],
            ip: $ip,
        );

        return $type;
    }

    public function update(User $actor, string $documentTypeId, UpdateDocumentTypeInput $input, ?string $ip = null): DocumentType
    {
        $type = $this->resolve($documentTypeId);
        $before = ['name' => $type->getName(), 'required' => $type->isRequired(), 'active' => $type->isActive()];

        if (null !== $input->name) {
            $type->setName($input->name);
        }
        if (null !== $input->ownerRole) {
            $type->setOwnerRole($input->ownerRole);
        }
        if (null !== $input->visibility) {
            $type->setVisibility($input->visibility);
        }
        if (null !== $input->required) {
            $type->setRequired($input->required);
        }
        if (null !== $input->active) {
            $type->setActive($input->active);
        }

        $this->em->flush();

        $this->audit->record(
            action: 'document_type.updated',
            entityType: 'document_type',
            entityId: (string) $type->getId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            before: $before,
            after: ['name' => $type->getName(), 'required' => $type->isRequired(), 'active' => $type->isActive()],
            ip: $ip,
        );

        return $type;
    }

    public function deactivate(User $actor, string $documentTypeId, ?string $ip = null): DocumentType
    {
        $type = $this->resolve($documentTypeId);
        $before = $type->isActive();
        $type->setActive(false);
        $this->em->flush();

        $this->audit->record(
            action: 'document_type.deactivated',
            entityType: 'document_type',
            entityId: (string) $type->getId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            before: ['active' => $before],
            after: ['active' => false],
            ip: $ip,
        );

        return $type;
    }

    /**
     * @throws NotFoundException
     */
    private function resolve(string $documentTypeId): DocumentType
    {
        $id = (int) $documentTypeId;
        if ($id <= 0) {
            throw new NotFoundException('Document type not found');
        }

        /** @var DocumentType|null $type */
        $type = $this->em->getRepository(DocumentType::class)->find($id);
        if (null === $type) {
            throw new NotFoundException('Document type not found');
        }

        return $type;
    }

    private function nextSortOrder(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('MAX(t.sortOrder)')
            ->from(DocumentType::class, 't')
            ->getQuery()
            ->getSingleScalarResult() + 10;
    }
}
