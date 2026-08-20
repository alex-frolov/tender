<?php

declare(strict_types=1);

namespace App\Question\Repository;

use App\Question\Entity\TenderQuestion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Read-запросы к вопросам тендера (tender_questions, FR-1.2.9).
 *
 * - listForTender(): вопросы по тендеру (новые сверху) для GET
 *   /tenders/{tenderId}/questions.
 *
 * @extends ServiceEntityRepository<TenderQuestion>
 */
final class TenderQuestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TenderQuestion::class);
    }

    /**
     * @return list<TenderQuestion>
     */
    public function listForTender(Uuid $tenderId): array
    {
        /** @var list<TenderQuestion> $result */
        $result = $this->createQueryBuilder('q')
            ->where('q.tenderId = :tenderId')
            ->setParameter('tenderId', $tenderId)
            ->orderBy('q.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
