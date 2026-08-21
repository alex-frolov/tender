<?php

declare(strict_types=1);

namespace App\Document\Repository;

use App\Document\Entity\Document;
use App\Document\Entity\Enum\DocumentVisibility;
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
     * Документы сущности (тендер, лот, заявка, договор, претензия), видимые
     * компании актора: свои — все, чужие — только публичные (FR-1.2.6).
     * Фильтр по видимости в запросе, а не после выборки: иначе постраничная
     * отдача возвращала бы «дырявые» страницы.
     *
     * @return list<Document>
     */
    public function listForEntity(string $entityType, Uuid $entityId, Uuid $viewerCompanyId): array
    {
        /** @var list<Document> $result */
        $result = $this->createQueryBuilder('d')
            ->where('d.entityType = :entityType')
            ->andWhere('d.entityId = :entityId')
            ->andWhere('d.tenantId = :company OR d.visibility = :public')
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->setParameter('company', $viewerCompanyId)
            ->setParameter('public', DocumentVisibility::PUBLIC->value)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
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
