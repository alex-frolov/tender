<?php

declare(strict_types=1);

namespace App\Contract\Repository;

use App\Contract\Entity\Contract;
use App\Contract\Entity\ContractTender;
use App\Contract\Entity\Enum\ContractTenderStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Read-запросы к связям договор ↔ тендер (contract_tenders, FR-1.4.6).
 *
 * - findByTender(): активная привязка тендера к договору (для исполнения);
 * - listForContract(): тендеры договора (для карточки AM-9, presenter);
 * - hasForTender(): флаг привязки (B2 — договор по тендеру существует).
 *
 * @extends ServiceEntityRepository<ContractTender>
 */
final class ContractTenderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContractTender::class);
    }

    /**
     * Привязка по id (для действий по id). Возвращает null при невалидном id.
     */
    public function findById(string $contractTenderId): ?ContractTender
    {
        if (!Uuid::isValid($contractTenderId)) {
            return null;
        }

        /** @var ContractTender|null $row */
        $row = $this->findOneBy(['id' => Uuid::fromString($contractTenderId)]);

        return $row;
    }

    /**
     * Привязки тендера к договорам (FR-1.4.6): для исполнения и проверки B2.
     *
     * @return list<ContractTender>
     */
    public function findByTender(Uuid $tenderId): array
    {
        /** @var list<ContractTender> $result */
        $result = $this->createQueryBuilder('ct')
            ->where('ct.tenderId = :tenderId')
            ->setParameter('tenderId', $tenderId)
            ->orderBy('ct.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Тендеры договора (карточка AM-9 GET /contracts/{id}: tenders[]).
     *
     * @return list<ContractTender>
     */
    public function listForContract(Contract $contract): array
    {
        /** @var list<ContractTender> $result */
        $result = $this->createQueryBuilder('ct')
            ->where('ct.contract = :contract')
            ->setParameter('contract', $contract)
            ->orderBy('ct.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Активная привязка тендера (для исполнения, B2): привязка, по которой
     * выполняется договор по данному тендеру. Если несколько — самая свежая.
     */
    public function findActiveForTender(Uuid $tenderId): ?ContractTender
    {
        /** @var ContractTender|null $row */
        $row = $this->createQueryBuilder('ct')
            ->where('ct.tenderId = :tenderId')
            ->andWhere('ct.status != :terminated')
            ->setParameter('tenderId', $tenderId)
            ->setParameter('terminated', ContractTenderStatusEnum::TERMINATED->value)
            ->orderBy('ct.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row;
    }

    /**
     * Сумма цен договоров по тендеру за период [from, to) (AM-13,
     * GET /stats/tenders contracts_amount_sum_minor): сумма price_net_minor
     * привязок contract_tenders по договорам компании, созданным в период.
     * Многоразовый договор (multi_use) распределяется по своим тендерам.
     *
     * @return array<string, int> tender_id → сумма цен (minor units)
     */
    public function amountSumByTender(Uuid $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->createQueryBuilder('ct')
            ->select('ct.tenderId AS tender_id')
            ->addSelect('SUM(ct.priceNetMinor) AS amount')
            ->join('ct.contract', 'c')
            ->where('c.tenantId = :tenant')
            ->andWhere('c.createdAt >= :from')
            ->andWhere('c.createdAt < :to')
            ->setParameter('tenant', $tenantId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('ct.tenderId')
            ->getQuery()
            ->getArrayResult();

        /** @var list<array{tender_id: string, amount: int|string|null}> $rows */
        $amounts = [];
        foreach ($rows as $row) {
            $amount = $row['amount'];
            $amounts[(string) $row['tender_id']] = null === $amount ? 0 : (int) $amount;
        }

        return $amounts;
    }
}
