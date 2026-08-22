<?php

declare(strict_types=1);

namespace App\Auction\Repository;

use App\Auction\Entity\Auction;
use App\Auction\Entity\AuctionBid;
use App\Auction\Entity\Enum\AuctionBidStatusEnum;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Read-запросы к аукционам (FR-1.3, AM-5): поиск по id, аукцион лота,
 * аукционы тендера, последние ставки страницы списка. Проверки принадлежности
 * (tenant/роли) — в сервисах.
 *
 * @extends ServiceEntityRepository<Auction>
 */
final class AuctionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Auction::class);
    }

    /**
     * Аукцион по id (или null, если id невалиден или аукцион не найден).
     */
    public function findById(string $auctionId): ?Auction
    {
        if (!Uuid::isValid($auctionId)) {
            return null;
        }

        /** @var Auction|null $auction */
        $auction = $this->findOneBy(['id' => Uuid::fromString($auctionId)]);

        return $auction;
    }

    /**
     * Победившая ставка аукциона (FR-1.3.5): auction_bids по winner_bid_id.
     * Возвращает null, если победитель ещё не выбран (winner_bid_id пуст)
     * или ставка не найдена.
     */
    public function findWinningBid(Auction $auction): ?AuctionBid
    {
        $winnerBidId = $auction->getWinnerBidId();
        if (null === $winnerBidId) {
            return null;
        }

        /** @var AuctionBid|null $bid */
        $bid = $this->getEntityManager()->createQueryBuilder()
            ->select('b')
            ->from(AuctionBid::class, 'b')
            ->where('b.id = :bidId')
            ->setParameter('bidId', $winnerBidId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $bid;
    }

    /**
     * Аукцион лота тендера (data-model unique (tender_id, lot_id)).
     */
    public function findForLot(Uuid $tenderId, Uuid $lotId): ?Auction
    {
        /** @var Auction|null $auction */
        $auction = $this->createQueryBuilder('a')
            ->where('a.tenderId = :tenderId')
            ->andWhere('a.lotId = :lotId')
            ->setParameter('tenderId', $tenderId)
            ->setParameter('lotId', $lotId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $auction;
    }

    /**
     * Аукционы тендера (все лоты). Скалярные FK — без JOIN'а на лоты;
     * порядок — по id лота (детерминированный, от порядка по номеру лота
     * потребители не зависят).
     *
     * @return list<Auction>
     */
    public function listForTender(Uuid $tenderId): array
    {
        /** @var list<Auction> $result */
        $result = $this->createQueryBuilder('a')
            ->where('a.tenderId = :tenderId')
            ->setParameter('tenderId', $tenderId)
            ->orderBy('a.lotId', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Принятая ставка по ключу идемпотентности клиента (ARCH-6, FR-1.3.6):
     * повторная доставка ставки (at-least-once) с тем же Idempotency-Key
     * возвращает уже принятую — дубль не создаётся. Скоуп — аукцион (ключ
     * клиента уникален в рамках операции); cross-tenant доступа нет (статус
     * аукциона проверяется сервисом).
     */
    public function findBidByAuctionAndIdempotencyKey(Auction $auction, string $idempotencyKey): ?AuctionBid
    {
        /** @var AuctionBid|null $bid */
        $bid = $this->getEntityManager()->createQueryBuilder()
            ->select('b')
            ->from(AuctionBid::class, 'b')
            ->where('b.auction = :auction')
            ->andWhere('b.idempotencyKey = :key')
            ->setParameter('auction', $auction)
            ->setParameter('key', $idempotencyKey)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $bid;
    }

    /**
     * Число активных аукционов компании (AM-13, GET /dashboard): статусы
     * жизненного цикла торгов (scheduled/trade/paused/choice/approve).
     * draft/new/agreement и терминальные не входят.
     */
    public function countActive(Uuid $tenantId): int
    {
        $count = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.tenantId = :tenantId')
            ->andWhere('a.status IN (:statuses)')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('statuses', [
                AuctionStatusEnum::SCHEDULED->value,
                AuctionStatusEnum::TRADE->value,
                AuctionStatusEnum::PAUSED->value,
                AuctionStatusEnum::CHOICE->value,
                AuctionStatusEnum::APPROVE->value,
            ])
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * Ближайшие окончания живых торгов (AM-13, GET /dashboard upcoming_deadlines):
     * аукционы в TRADE с planned_end_at в будущем, отсортированные по сроку.
     * $until — верхняя граница горизонта дедлайнов (period day/week/month
     * дашборда): null = без ограничения.
     *
     * $participatingTenderIds — процедуры участия компании (чужие по тенанту):
     * их торги тоже попадают в дедлайны, иначе у исполнителя, у которого своих
     * аукционов нет, раздел всегда пуст.
     *
     * @param list<Uuid> $participatingTenderIds
     *
     * @return list<array{auction_id: string, tender_id: string, deadline_at: string}>
     */
    public function upcomingTradeEnds(Uuid $tenantId, int $limit, ?\DateTimeImmutable $until = null, array $participatingTenderIds = []): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $qb = $this->createQueryBuilder('a')
            ->select('a.id', 'a.tenderId', 'a.plannedEndAt')
            ->where([] === $participatingTenderIds
                ? 'a.tenantId = :tenantId'
                : 'a.tenantId = :tenantId OR a.tenderId IN (:participating)')
            ->andWhere('a.status = :trade')
            ->andWhere('a.plannedEndAt IS NOT NULL')
            ->andWhere('a.plannedEndAt > :now')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('trade', AuctionStatusEnum::TRADE->value)
            ->setParameter('now', $now)
            ->orderBy('a.plannedEndAt', 'ASC')
            ->setMaxResults($limit);
        if ([] !== $participatingTenderIds) {
            $qb->setParameter('participating', $participatingTenderIds);
        }
        if (null !== $until) {
            $qb->andWhere('a.plannedEndAt <= :until')->setParameter('until', $until);
        }

        $rows = $qb->getQuery()->getArrayResult();

        /** @var list<array{id: string, tenderId: string, plannedEndAt: \DateTimeImmutable|string}> $rows */
        $items = [];
        foreach ($rows as $row) {
            $end = $row['plannedEndAt'];
            $items[] = [
                'auction_id' => (string) $row['id'],
                'tender_id' => (string) $row['tenderId'],
                'deadline_at' => $end instanceof \DateTimeImmutable
                    ? $end->format('Y-m-d\TH:i:s\Z')
                    : (string) $end,
            ];
        }

        return $items;
    }

    /**
     * Снижение цен по аукционам компании за период [from, to) (AM-13,
     * GET /stats/tenders): стартовая цена и итоговая (цена победившей ставки
     * либо current_price_minor) по аукциону. Итоговая цена — в канонической
     * базе (PR-6). Нативным SQL: winner_bid_id — скалярный FK на auction_bids.id,
     * в ORM-модели отношения нет (одна сводная выборка, без N+1).
     *
     * $participatingTenderIds расширяет выборку процедурами участия компании
     * (чужими по тенанту): снижение на них — такой же факт её статистики.
     *
     * @param list<Uuid> $participatingTenderIds
     *
     * @return list<array{tender_id: string, start_price_minor: int, final_price_minor: int}>
     */
    public function reductionRows(Uuid $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to, array $participatingTenderIds = []): array
    {
        $sql = <<<'SQL'
SELECT a.tender_id::text AS tender_id,
       a.start_price_minor AS start_price_minor,
       COALESCE(wb.price_minor, a.current_price_minor) AS final_price_minor
FROM auctions a
LEFT JOIN auction_bids wb ON wb.id = a.winner_bid_id
WHERE (a.tenant_id = :tenant OR a.tender_id = ANY(CAST(:participating AS uuid[])))
  AND a.created_at >= :from
  AND a.created_at < :to
  AND a.start_price_minor IS NOT NULL
  AND COALESCE(wb.price_minor, a.current_price_minor) IS NOT NULL
SQL;
        $rows = $this->getEntityManager()->getConnection()->executeQuery($sql, [
            'tenant' => (string) $tenantId,
            // ANY(uuid[]) вместо IN (...): пустой список остаётся валидным
            // выражением, а параметр — скалярным (без раскрытия массива).
            'participating' => '{'.implode(',', array_map(
                static fn (Uuid $id): string => (string) $id,
                $participatingTenderIds,
            )).'}',
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ])->fetchAllAssociative();

        /** @var list<array{tender_id: string, start_price_minor: int|string, final_price_minor: int|string}> $rows */
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'tender_id' => (string) $row['tender_id'],
                'start_price_minor' => (int) $row['start_price_minor'],
                'final_price_minor' => (int) $row['final_price_minor'],
            ];
        }

        return $items;
    }

    /**
     * Тендеры, в аукционах которых компания делала ставки (AM-13). Участие
     * в торгах возможно и без заявки (тендер с bids_required=false), поэтому
     * «мои процедуры» исполнителя не сводятся к его заявкам.
     *
     * @return list<Uuid>
     */
    public function tenderIdsForBidder(Uuid $companyId): array
    {
        /** @var list<array{tender_id: Uuid}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT a.tenderId AS tender_id')
            ->from(AuctionBid::class, 'b')
            ->join('b.auction', 'a')
            ->where('b.bidderId = :companyId')
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): Uuid => $row['tender_id'], $rows);
    }

    /**
     * Аукционы в статусе TRADE (живые торги). Для планировщика/восстановления
     * (FR-1.3.6, UC-15) и аггрегации тендера (tender.bidding).
     *
     * @return list<Auction>
     */
    public function listTrading(): array
    {
        /** @var list<Auction> $result */
        $result = $this->createQueryBuilder('a')
            ->where('a.status = :trade')
            ->setParameter('trade', AuctionStatusEnum::TRADE->value)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Запланированные аукционы, чей момент старта уже наступил
     * (SCHEDULED, scheduled_start_at <= now). Источник для планировщика
     * (auctions:start-scheduled): без него SCHEDULED → TRADE не происходит
     * и торги никогда не начинаются.
     *
     * @return list<Auction>
     */
    public function listDueForTrading(\DateTimeImmutable $now): array
    {
        /** @var list<Auction> $result */
        $result = $this->createQueryBuilder('a')
            ->where('a.status = :scheduled')
            ->andWhere('a.scheduledStartAt IS NOT NULL')
            ->andWhere('a.scheduledStartAt <= :now')
            ->setParameter('scheduled', AuctionStatusEnum::SCHEDULED->value)
            ->setParameter('now', $now)
            ->orderBy('a.scheduledStartAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Торгующиеся аукционы, у которых окно торгов уже закрыто по времени
     * (TRADE, planned_end_at <= now). Источник для планировщика
     * (auctions:finish-expired): истечение таймера само по себе TRADE → CHOICE
     * не выполняет, и без этой команды торги остаются открытыми бесконечно
     * (heartbeat при этом продолжает считать их живыми).
     *
     * @return list<Auction>
     */
    public function listExpiredTrading(\DateTimeImmutable $now): array
    {
        /** @var list<Auction> $result */
        $result = $this->createQueryBuilder('a')
            ->where('a.status = :trade')
            ->andWhere('a.plannedEndAt IS NOT NULL')
            ->andWhere('a.plannedEndAt <= :now')
            ->setParameter('trade', AuctionStatusEnum::TRADE->value)
            ->setParameter('now', $now)
            ->orderBy('a.plannedEndAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Список аукционов компании-тенанта (GET /auctions).
     * Сортировка: сначала активные/ближайшие (по planned_end_at/created_at),
     * затем остальные — по created_at DESC. Без пагинации — размер списка
     * аукционов компании ограничен бизнес-процессами (по одному на лот).
     *
     * @return list<Auction>
     */
    public function listForTenant(Uuid $tenantId): array
    {
        /** @var list<Auction> $result */
        $result = $this->createQueryBuilder('a')
            ->where('a.tenantId = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->orderBy('a.plannedEndAt', 'DESC')
            ->addOrderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Тендеры, по которым у компании-зрителя МОЖЕТ быть видимый аукцион
     * (GET /auctions): вход для фильтра видимости тендеров
     * (TenderVisibility::filterVisible).
     *
     * Кандидаты сразу сужаются условием видимости самого аукциона (FR-1.5.14),
     * а не собираются по всей таблице: иначе стоимость запроса росла бы с
     * объёмом площадки, а не с тем, что зрителю вообще доступно (NFR-22).
     * Три ветки — ровно те, что дают видимость:
     *   - свои аукционы (tenant_id зрителя) — в любом статусе;
     *   - чужие в публичных статусах ($publicStatuses — фаза торгов);
     *   - чужие на лотах, выигранных компанией ($wonLotIds) — стадии исполнения.
     * Пустой список в ветку не добавляется: `IN ()` в DQL невалиден.
     *
     * @param Uuid         $viewerCompanyId компания-зритель (тенант актора)
     * @param list<string> $publicStatuses  статусы, видимые всем зрителям тендера
     * @param list<Uuid>   $wonLotIds       лоты, выигранные компанией-зрителем
     *
     * @return list<Uuid> id тендеров (без дублей)
     */
    public function distinctTenderIds(Uuid $viewerCompanyId, array $publicStatuses, array $wonLotIds): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('DISTINCT a.tenderId AS tender_id');

        $visible = $qb->expr()->orX($qb->expr()->eq('a.tenantId', ':viewerCompany'));
        $qb->setParameter('viewerCompany', $viewerCompanyId);

        if ([] !== $publicStatuses) {
            $visible->add($qb->expr()->in('a.status', ':publicStatuses'));
            $qb->setParameter('publicStatuses', $publicStatuses);
        }

        if ([] !== $wonLotIds) {
            $visible->add($qb->expr()->in('a.lotId', ':wonLots'));
            $qb->setParameter('wonLots', $wonLotIds);
        }

        /** @var list<array{tender_id: string|Uuid}> $rows */
        $rows = $qb->where($visible)
            ->getQuery()
            ->getArrayResult();

        // DQL-проекция uuid-колонки отдаёт Uuid-объект, а не строку.
        return array_map(
            static fn (array $row): Uuid => $row['tender_id'] instanceof Uuid
                ? $row['tender_id']
                : Uuid::fromString($row['tender_id']),
            $rows,
        );
    }

    /**
     * Аукционы по набору тендеров (GET /auctions после фильтра видимости).
     * Сортировка та же, что в listForTenant(). Пустой набор → пустой список
     * (IN () в DQL не выражается).
     *
     * @param list<Uuid> $tenderIds
     *
     * @return list<Auction>
     */
    public function listForTenders(array $tenderIds): array
    {
        if ([] === $tenderIds) {
            return [];
        }

        /** @var list<Auction> $result */
        $result = $this->createQueryBuilder('a')
            ->where('a.tenderId IN (:tenderIds)')
            ->setParameter('tenderIds', $tenderIds)
            ->orderBy('a.plannedEndAt', 'DESC')
            ->addOrderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Последняя принятая ставка по каждому из аукционов (AuctionListItem.
     * last_bid_at / last_bid_price_minor, GET /auctions): в списке нужно видеть,
     * когда торговались в последний раз и по какой цене.
     *
     * Одним запросом на всю страницу (DISTINCT ON, без N+1). Учитываются только
     * accepted-ставки: отклонённые остаются в истории (append-only, PR-9),
     * но на цену не влияют. Цена — в канонической базе (PR-6), как и
     * auctions.current_price_minor рядом в той же строке списка. Личность
     * участника не отдаётся — анонимность торгов (AuctionBid.bidder_id) не
     * затрагивается.
     *
     * Нативным SQL: DISTINCT ON — расширение PostgreSQL, в DQL не выражается.
     *
     * @param list<Uuid> $auctionIds
     *
     * @return array<string, array{placed_at: \DateTimeImmutable, price_minor: int}> auction_id → последняя ставка
     */
    public function lastAcceptedBids(array $auctionIds): array
    {
        if ([] === $auctionIds) {
            return [];
        }

        $sql = <<<'SQL'
SELECT DISTINCT ON (b.auction_id)
       b.auction_id::text AS auction_id,
       b.price_minor      AS price_minor,
       b.placed_at        AS placed_at
FROM auction_bids b
WHERE b.auction_id IN (:ids)
  AND b.status = :accepted
ORDER BY b.auction_id, b.placed_at DESC, b.round DESC
SQL;

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            [
                'ids' => array_map(static fn (Uuid $id): string => (string) $id, $auctionIds),
                'accepted' => AuctionBidStatusEnum::ACCEPTED->value,
            ],
            ['ids' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        /** @var list<array{auction_id: string, price_minor: int|string, placed_at: string}> $rows */
        $result = [];
        foreach ($rows as $row) {
            $placedAt = \DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                (string) $row['placed_at'],
                new \DateTimeZone('UTC'),
            );
            if (false === $placedAt) {
                continue;
            }
            $result[(string) $row['auction_id']] = [
                'placed_at' => $placedAt,
                'price_minor' => (int) $row['price_minor'],
            ];
        }

        return $result;
    }

    /**
     * История ставок аукциона (GET /auctions/{id}/bids, AM-5): принятые и
     * отклонённые ставки (append-only, PR-9) в порядке раундов — хронология
     * торгов. Анонимность bidder_id (до конца торгов) — на уровне представления.
     *
     * @return list<AuctionBid>
     */
    public function listBids(Auction $auction): array
    {
        /** @var list<AuctionBid> $result */
        $result = $this->getEntityManager()->createQueryBuilder()
            ->select('b')
            ->from(AuctionBid::class, 'b')
            ->where('b.auction = :auction')
            ->setParameter('auction', $auction)
            ->orderBy('b.round', 'ASC')
            ->addOrderBy('b.placedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Лучшая принятая ставка аукциона (FR-1.3.5): минимальная цена в
     * канонической базе (PR-6). При равенстве цен — более ранняя по времени
     * (первый предложивший). Для авто-выбора победителя REDUCTION
     * (минимальная цена) и завершения (auction.finished, winner_bid_id).
     */
    public function findBestAcceptedBid(Auction $auction): ?AuctionBid
    {
        /** @var AuctionBid|null $bid */
        $bid = $this->getEntityManager()->createQueryBuilder()
            ->select('b')
            ->from(AuctionBid::class, 'b')
            ->where('b.auction = :auction')
            ->andWhere('b.status = :accepted')
            ->setParameter('auction', $auction)
            ->setParameter('accepted', AuctionBidStatusEnum::ACCEPTED->value)
            ->orderBy('b.priceMinor', 'ASC')
            ->addOrderBy('b.placedAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $bid;
    }

    /**
     * Принятая ставка аукциона по id (FR-1.3.5, ручной выбор победителя):
     * только в рамках аукциона и только со статусом accepted. Отклонённая
     * (rejected) ставка победителем быть не может (append-only, PR-9).
     */
    public function findAcceptedBid(Auction $auction, Uuid $auctionBidId): ?AuctionBid
    {
        /** @var AuctionBid|null $bid */
        $bid = $this->getEntityManager()->createQueryBuilder()
            ->select('b')
            ->from(AuctionBid::class, 'b')
            ->where('b.auction = :auction')
            ->andWhere('b.id = :bidId')
            ->andWhere('b.status = :accepted')
            ->setParameter('auction', $auction)
            ->setParameter('bidId', $auctionBidId)
            ->setParameter('accepted', AuctionBidStatusEnum::ACCEPTED->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $bid;
    }

    /**
     * Число принятых ставок аукциона (rounds_count для auction.finished,
     * domain/events.md).
     */
    public function countAcceptedBids(Auction $auction): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(b.id)')
            ->from(AuctionBid::class, 'b')
            ->where('b.auction = :auction')
            ->andWhere('b.status = :accepted')
            ->setParameter('auction', $auction)
            ->setParameter('accepted', AuctionBidStatusEnum::ACCEPTED->value)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
