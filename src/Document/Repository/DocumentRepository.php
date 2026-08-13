<?php

declare(strict_types=1);

namespace App\Document\Repository;

use App\Document\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Доступ к документам (AM-8, FR-1.1.5). Документ идентифицируется глобально
 * по id; проверка принадлежности и правил видимости (FR-1.2.6) — в
 * DocumentService (404 для несуществующего, 403 для существующего без прав).
 *
 * @extends ServiceEntityRepository<Document>
 */
final class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    /**
     * Документ по id (или null, если не существует).
     */
    public function findById(string $documentId): ?Document
    {
        if (!Uuid::isValid($documentId)) {
            return null;
        }

        return $this->findOneBy(['id' => Uuid::fromString($documentId)]);
    }
}
