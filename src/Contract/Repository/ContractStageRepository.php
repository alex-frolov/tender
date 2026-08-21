<?php

declare(strict_types=1);

namespace App\Contract\Repository;

use App\Contract\Entity\ContractStage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Read-запросы к этапам исполнения (contract_stages, UC-10).
 *
 * @extends ServiceEntityRepository<ContractStage>
 */
final class ContractStageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContractStage::class);
    }

    /**
     * Этапы сразу по нескольким привязкам «договор — тендер», сгруппированные
     * по contract_tender_id. Пакетная выборка: карточка договора показывает
     * этапы по каждой привязке, и запрос на привязку дал бы N+1.
     *
     * @param list<Uuid> $contractTenderIds
     *
     * @return array<string, list<ContractStage>> contract_tender_id → этапы (по номеру)
     */
    public function listForContractTenders(array $contractTenderIds): array
    {
        if ([] === $contractTenderIds) {
            return [];
        }

        /** @var list<ContractStage> $rows */
        $rows = $this->createQueryBuilder('s')
            ->where('s.contractTenderId IN (:ids)')
            ->setParameter('ids', $contractTenderIds)
            ->orderBy('s.number', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($rows as $stage) {
            $grouped[(string) $stage->getContractTenderId()][] = $stage;
        }

        return $grouped;
    }

    /**
     * Следующий свободный номер этапа для привязки (нумерация 1..N в пределах
     * одной привязки «договор — тендер»).
     */
    public function nextNumber(Uuid $contractTenderId): int
    {
        $max = $this->createQueryBuilder('s')
            ->select('MAX(s.number)')
            ->where('s.contractTenderId = :contractTenderId')
            ->setParameter('contractTenderId', $contractTenderId)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $max + 1;
    }
}
