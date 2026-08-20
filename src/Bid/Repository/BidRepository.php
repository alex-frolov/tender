<?php

declare(strict_types=1);

namespace App\Bid\Repository;

use App\Bid\Entity\Bid;
use App\Bid\Entity\Enum\BidStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Заявки (FR-1.2, AM-4). Read-запросы к bids: поиск по id, дубликат
 * (tender, lot, supplier) для инварианта «одна заявка на лот», список заявок
 * тендера. Проверки принадлежности (supplier/tenant) — в BidService.
 *
 * @extends ServiceEntityRepository<Bid>
 */
final class BidRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bid::class);
    }

    /**
     * Существующая заявка по id (или null, если не найдена).
     */
    public function findById(string $bidId): ?Bid
    {
        if (!Uuid::isValid($bidId)) {
            return null;
        }

        return $this->findOneBy(['id' => Uuid::fromString($bidId)]);
    }

    /**
     * Дубликат заявки (FR-1.2.1, data-model unique (tender_id, lot_id,
     * supplier_id)): уже есть заявка от компании на этот лот тендера.
     * lot_id = null для заявки без привязки к лоту.
     */
    public function findDuplicate(Uuid $tenderId, ?Uuid $lotId, Uuid $supplierId): ?Bid
    {
        $qb = $this->createQueryBuilder('b')
            ->where('b.tenderId = :tenderId')
            ->andWhere('b.supplierId = :supplierId')
            ->setParameter('tenderId', $tenderId)
            ->setParameter('supplierId', $supplierId);

        if (null === $lotId) {
            $qb->andWhere('b.lotId IS NULL');
        } else {
            $qb->andWhere('b.lotId = :lotId')->setParameter('lotId', $lotId);
        }

        $query = $qb->setMaxResults(1)->getQuery();

        /** @var Bid|null $bid */
        $bid = $query->getOneOrNullResult();

        return $bid;
    }

    /**
     * Заявки тендера (все, включая withdrawn). Для списка заказчика.
     *
     * @return list<Bid>
     */
    public function listForTender(Uuid $tenderId): array
    {
        /** @var list<Bid> $result */
        $result = $this->createQueryBuilder('b')
            ->where('b.tenderId = :tenderId')
            ->setParameter('tenderId', $tenderId)
            ->orderBy('b.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Поданные (submitted) заявки тендера — те, что расшифровываются на
     * вскрытии (FR-1.2.3). Withdrawn/прочие статусы не вскрываются:
     * отозванная заявка не участвует в рассмотрении.
     *
     * @return list<Bid>
     */
    public function listSubmittedForTender(Uuid $tenderId): array
    {
        /** @var list<Bid> $result */
        $result = $this->createQueryBuilder('b')
            ->where('b.tenderId = :tenderId')
            ->andWhere('b.status = :submitted')
            ->setParameter('tenderId', $tenderId)
            ->setParameter('submitted', BidStatusEnum::SUBMITTED->value)
            ->orderBy('b.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Победитель закупки (FR-1.3.5): существует winning-заявка компании
     * в тендере ($lotId не задан) либо по конкретному лоту.
     *
     * Победа фиксируется статусом заявки при выборе победителя аукциона
     * (BidResultService::markResults), отдельной таблицы award нет.
     * Выборку покрывает idx_bids_tender_status (tender_id, status).
     */
    public function isWinner(Uuid $tenderId, ?Uuid $lotId, Uuid $supplierId, bool $anyLot = false): bool
    {
        $qb = $this->createQueryBuilder('b')
            ->select('1')
            ->where('b.tenderId = :tenderId')
            ->andWhere('b.supplierId = :supplierId')
            ->andWhere('b.status = :winning')
            ->setParameter('tenderId', $tenderId)
            ->setParameter('supplierId', $supplierId)
            ->setParameter('winning', BidStatusEnum::WINNING->value)
            ->setMaxResults(1);

        if (!$anyLot) {
            if (null === $lotId) {
                $qb->andWhere('b.lotId IS NULL');
            } else {
                $qb->andWhere('b.lotId = :lotId')->setParameter('lotId', $lotId);
            }
        }

        return null !== $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Тендеры, где компания победила (видимость каталога, FR-1.5.14).
     *
     * @return list<Uuid>
     */
    public function tenderIdsWonBy(Uuid $supplierId): array
    {
        /** @var list<array{tenderId: Uuid}> $rows */
        $rows = $this->createQueryBuilder('b')
            ->select('DISTINCT b.tenderId AS tenderId')
            ->where('b.supplierId = :supplierId')
            ->andWhere('b.status = :winning')
            ->setParameter('supplierId', $supplierId)
            ->setParameter('winning', BidStatusEnum::WINNING->value)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): Uuid => $row['tenderId'], $rows);
    }

    /**
     * Лоты, где компания победила (видимость списка аукционов, FR-1.5.14).
     * Заявки на тендер целиком (lot_id = null) сюда не попадают: у аукциона
     * лот есть всегда.
     *
     * @return list<Uuid>
     */
    public function lotIdsWonBy(Uuid $supplierId): array
    {
        /** @var list<array{lotId: Uuid}> $rows */
        $rows = $this->createQueryBuilder('b')
            ->select('DISTINCT b.lotId AS lotId')
            ->where('b.supplierId = :supplierId')
            ->andWhere('b.status = :winning')
            ->andWhere('b.lotId IS NOT NULL')
            ->setParameter('supplierId', $supplierId)
            ->setParameter('winning', BidStatusEnum::WINNING->value)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): Uuid => $row['lotId'], $rows);
    }

    /**
     * Допуск участника к аукциону (FR-1.3.2): существует admitted-заявка
     * компании на лот (или тендер при lot_id = null). Ставки принимаются
     * только от допущенных участников (bids.status = admitted, FR-1.2.4).
     */
    public function isAdmitted(Uuid $tenderId, ?Uuid $lotId, Uuid $supplierId): bool
    {
        $qb = $this->createQueryBuilder('b')
            ->select('1')
            ->where('b.tenderId = :tenderId')
            ->andWhere('b.supplierId = :supplierId')
            ->andWhere('b.status = :admitted')
            ->setParameter('tenderId', $tenderId)
            ->setParameter('supplierId', $supplierId)
            ->setParameter('admitted', BidStatusEnum::ADMITTED->value)
            ->setMaxResults(1);

        if (null === $lotId) {
            $qb->andWhere('b.lotId IS NULL');
        } else {
            $qb->andWhere('b.lotId = :lotId')->setParameter('lotId', $lotId);
        }

        return null !== $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Число поданных заявок компании как поставщика (AM-13, GET /dashboard
     * my_bids): заявки, которые компания реально подала/проходит рассмотрение
     * (submitted/admitted/rejected/winning/lost). Черновики и отозванные не
     * считаются — это ещё не «мои заявки» на витрине.
     */
    public function countForSupplier(Uuid $companyId): int
    {
        $count = $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.supplierId = :companyId')
            ->andWhere('b.status IN (:statuses)')
            ->setParameter('companyId', $companyId)
            ->setParameter('statuses', [
                BidStatusEnum::SUBMITTED->value,
                BidStatusEnum::ADMITTED->value,
                BidStatusEnum::REJECTED->value,
                BidStatusEnum::WINNING->value,
                BidStatusEnum::LOST->value,
            ])
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * Допущенные заявки на лот (FR-1.3.5): admitted-заявки
     * участников торга. Используются при фиксации победителя — победителю
     * ставится статус winning, остальным участникам — lost (data-model.md,
     * bids.status).
     *
     * @return list<Bid>
     */
    public function listAdmittedForLot(Uuid $tenderId, ?Uuid $lotId): array
    {
        $qb = $this->createQueryBuilder('b')
            ->where('b.tenderId = :tenderId')
            ->andWhere('b.status = :admitted')
            ->setParameter('tenderId', $tenderId)
            ->setParameter('admitted', BidStatusEnum::ADMITTED->value);

        if (null === $lotId) {
            $qb->andWhere('b.lotId IS NULL');
        } else {
            $qb->andWhere('b.lotId = :lotId')->setParameter('lotId', $lotId);
        }

        /** @var list<Bid> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}
