<?php

declare(strict_types=1);

namespace App\Document\UseCase;

use App\Document\DocumentPresenter;
use App\Document\Entity\DocumentType;
use App\Iam\Entity\User;
use App\Shared\Audit\AuditService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Изменение типа документа суперадмином (FR-1.2.7, PUT /document-types/{id}).
 *
 * Доступ проверяется ДО вызова (platform_admin, атрибут на контроллере), тип —
 * уже резолвленная сущность из контроллера (DocumentTypeRepository::findOrFail),
 * а изменения применены entity-bound формой UpdateDocumentTypeType
 * (clearMissing: false, см. AGENTS.md). UseCase только фиксирует изменения
 * (flush), пишет аудит и возвращает презентацию (openapi DocumentType).
 */
final readonly class UpdateDocumentTypeUseCase implements DocumentUseCase
{
    public function __construct(
        private EntityManagerInterface $em,
        private DocumentPresenter $presenter,
        private AuditService $audit,
    ) {
    }

    /**
     * @param DocumentType $before снапшот типа ДО мутации формой (для аудита before/after)
     *
     * @return array<string, mixed> презентация типа документа (openapi DocumentType)
     */
    public function execute(User $user, DocumentType $type, DocumentType $before, ?string $ip = null): array
    {
        $this->em->flush();

        $this->audit->record(
            action: 'document_type.updated',
            entityType: 'document_type',
            entityId: (string) $type->getId(),
            actorType: 'user',
            actorId: (string) $user->getId(),
            before: ['name' => $before->getName(), 'required' => $before->isRequired(), 'active' => $before->isActive()],
            after: ['name' => $type->getName(), 'required' => $type->isRequired(), 'active' => $type->isActive()],
            ip: $ip,
        );

        return $this->presenter->type($type);
    }
}
