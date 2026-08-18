<?php

declare(strict_types=1);

namespace App\Tender\Repository;

use App\Tender\Entity\Enum\LotStatusEnum;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Tender;
use App\Tender\TenderFilters;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Read-оптимизированные запросы к тендерам (FR-1.1.1/1.1.3).
 *
 * - listForTenant(): eager-загрузка лотов (JOIN + addSelect) — один запрос вместо
 *   N+1 на lotCount()/aggregatedStatus() в списке.
 * - aggregatedStatuses(): агрегация статуса при мультилоте на стороне БД через
 *   STRING_AGG статусов лотов. Результат пересобирается Tender::aggregateStatus()
 *   (единый источник истины варианта C), без гидратации объектов Lot.
 *
 * @extends ServiceEntityRepository<Tender>
 */
final class TenderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tender::class);
    }

    /**
     * Тендер по id БЕЗ tenant-фильтра (публичный lookup для подачи заявок —
     * FR-1.2.1: участник из другой компании находит открытый тендер; закрытые
     * тендеры проверяются в 3.5 по contract_holders). Возвращает null, если
     * id невалиден или тендер не найден.
     */
    public function findById(string $tenderId): ?Tender
    {
        if (!Uuid::isValid($tenderId)) {
            return null;
        }

        /** @var Tender|null $tender */
        $tender = $this->findOneBy(['id' => Uuid::fromString($tenderId)]);

        return $tender;
    }

    /**
     * Принадлежность тендера компании (tenant-проверка): существует ли тендер
     * с id в компании-тенанте. Используется кросс-модульными проверками
     * (Document, Contract) через TenderReadService::belongsToCompany.
     */
    public function belongsToCompany(Uuid $tenderId, Uuid $companyId): bool
    {
        return null !== $this->findOneBy(['id' => $tenderId, 'tenantId' => $companyId]);
    }

    /**
     * Срез каталога тендеров компании (read-модель, FR-1.1.1, AR-6/NFR-22).
     *
     * Keyset-пагинация: условие «следующая страница» — (created_at, id) строго
     * меньше позиции курсора при ORDER BY created_at DESC, id DESC. Возвращает
     * до $limit строк-проекций БЕЗ гидратации сущностей (getArrayResult) —
     * O(limit) строк и памяти независимо от размера каталога. Вызывающий
     * запрашивает limit+1, чтобы определить наличие следующей страницы.
     * Покрывается композитными индексами idx_tenders_catalog_* (миграция
     * Version20260813160000): равенство tenant_id [+ status] → диапазон
     * created_at → tiebreaker id.
     *
     * @param Uuid                    $tenantId        компания-тенант
     * @param TenderFilters           $filters         фильтры каталога (q/status/law_type/region/price/access)
     * @param \DateTimeImmutable|null $cursorCreatedAt created_at позиции курсора (null — первая страница)
     * @param Uuid|null               $cursorId        id позиции курсора (tiebreaker, null — первая страница)
     * @param int                     $limit           размер страницы
     *
     * @return list<array{id: string, number: string, title: string, status: TenderStatusEnum|string, nmck_minor: int|string|null, currency: string, region: string|null, okpd2: string|null, timeline: array<string, string>|null, created_at: \DateTimeImmutable}>
     */
    public function listCatalogPage(Uuid $tenantId, TenderFilters $filters, ?\DateTimeImmutable $cursorCreatedAt, ?Uuid $cursorId, int $limit): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select(
                't.id AS id',
                't.number AS number',
                't.title AS title',
                't.status AS status',
                't.nmckMinor AS nmck_minor',
                't.currency AS currency',
                't.region AS region',
                't.okpd2 AS okpd2',
                't.timeline AS timeline',
                't.createdAt AS created_at',
            )
            ->where('t.tenantId = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults(max(1, $limit));

        if (null !== $filters->status) {
            $qb->andWhere('t.status = :status')->setParameter('status', $filters->status->value);
        }

        if (null !== $filters->lawType) {
            $qb->andWhere('t.lawType = :lawType')->setParameter('lawType', $filters->lawType->value);
        }

        if (null !== $filters->region && '' !== $filters->region) {
            // Подстрока без учёта регистра (ILIKE); точное совпадение не требуется.
            $qb->andWhere('LOWER(t.region) LIKE :region')
                ->setParameter('region', '%'.mb_strtolower($filters->region).'%');
        }

        if (null !== $filters->okpd2 && '' !== $filters->okpd2) {
            // Код ОКПД2 (префиксный поиск, как в каталоге 44-ФЗ): совпадение
            // по началу кода (ILIKE) — фильтр из openapi (параметр okpd2).
            $qb->andWhere('t.okpd2 IS NOT NULL')
                ->andWhere('LOWER(t.okpd2) LIKE :okpd2')
                ->setParameter('okpd2', mb_strtolower($filters->okpd2).'%');
        }

        if (null !== $filters->priceMin) {
            $qb->andWhere('t.nmckMinor >= :priceMin')->setParameter('priceMin', $filters->priceMin);
        }
        if (null !== $filters->priceMax) {
            $qb->andWhere('t.nmckMinor <= :priceMax')->setParameter('priceMax', $filters->priceMax);
        }

        if (null !== $filters->accessType) {
            $qb->andWhere('t.accessType = :accessType')->setParameter('accessType', $filters->accessType->value);
        }

        if (null !== $filters->q && '' !== $filters->q) {
            // Поиск по номеру/названию/описанию (полнотекст — упрощённо: ILIKE по трём полям).
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('LOWER(t.number)', ':q'),
                    $qb->expr()->like('LOWER(t.title)', ':q'),
                    $qb->expr()->like('LOWER(t.description)', ':q'),
                ),
            )->setParameter('q', '%'.mb_strtolower($filters->q).'%');
        }

        if (null !== $cursorCreatedAt && null !== $cursorId) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->lt('t.createdAt', ':cursorCreatedAt'),
                    $qb->expr()->andX(
                        $qb->expr()->eq('t.createdAt', ':cursorCreatedAt'),
                        $qb->expr()->lt('t.id', ':cursorId'),
                    ),
                ),
            )
                ->setParameter('cursorCreatedAt', $cursorCreatedAt)
                ->setParameter('cursorId', $cursorId);
        }

        /** @var list<array{id: string, number: string, title: string, status: TenderStatusEnum|string, nmck_minor: int|string|null, currency: string, region: string|null, okpd2: string|null, timeline: array<string, string>|null, created_at: \DateTimeImmutable}> $result */
        $result = $qb->getQuery()->getArrayResult();

        return $result;
    }

    /**
     * Агрегация статусов и число лотов по id конкретных тендеров (страница
     * каталога, FR-1.1.3 вариант C + lot_count). Та же DB-агрегация
     * (STRING_AGG/COUNT), что и aggregatedStatuses(), но только по набору
     * id страницы — без сканирования всего каталога. Результат пересобирается
     * вызывающим через Tender::aggregateStatus() (единый источник истины).
     *
     * @param list<Uuid> $ids id тендеров страницы
     *
     * @return array<string, array{lot_statuses: list<LotStatusEnum>, lot_count: int}> tender_id → статусы лотов + счётчик
     */
    public function aggregatedStatusesForIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<array{tender_id: string, statuses: string|null, lot_count: int|string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.id AS tender_id')
            ->addSelect("STRING_AGG(l.status, ',') AS statuses")
            ->addSelect('COUNT(l.id) AS lot_count')
            ->leftJoin('t.lots', 'l')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->groupBy('t.id')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $statusesRaw = $row['statuses'];
            $map[(string) $row['tender_id']] = [
                'lot_statuses' => null === $statusesRaw || '' === $statusesRaw
                    ? []
                    : array_map(
                        static fn (string $s): LotStatusEnum => LotStatusEnum::from($s),
                        explode(',', $statusesRaw),
                    ),
                'lot_count' => (int) $row['lot_count'],
            ];
        }

        return $map;
    }

    /**
     * Тендеры компании актора с eager-загрузкой лотов (fix N+1 на lotCount()).
     *
     * @return list<Tender>
     */
    public function listForTenant(Uuid $tenantId, ?TenderStatusEnum $status = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.lots', 'l')
            ->addSelect('l')
            ->where('t.tenantId = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->orderBy('t.createdAt', 'DESC');

        if (null !== $status) {
            $qb->andWhere('t.status = :status')->setParameter('status', $status->value);
        }

        /** @var list<Tender> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Карта агрегированных статусов тендеров компании (FR-1.1.3, вариант C).
     * Агрегация выполняется на стороне БД (STRING_AGG статусов лотов по тендеру),
     * затем пересобирается Tender::aggregateStatus() — тот же результат, что у
     * Tender::aggregatedStatus(), но без гидратации коллекций лотов (N+1-free).
     *
     * @return array<string, TenderStatusEnum> тендер_id → агрегированный статус
     */
    public function aggregatedStatuses(Uuid $tenantId): array
    {
        $result = [];
        foreach ($this->aggregatedStatusRows($tenantId) as $row) {
            $result[(string) $row['tender_id']] = self::aggregateRow($row);
        }

        return $result;
    }

    /**
     * Счётчик тендеров компании по агрегированному статусу (FR-1.1.3, AM-13):
     * карта статус → число тендеров. Та же DB-агрегация, что и aggregatedStatuses,
     * но результат группируется по статусу — для дашборда (active_tenders и др.).
     *
     * @return array<string, int> агрегированный статус (value) → количество
     */
    public function countByAggregatedStatus(Uuid $tenantId): array
    {
        $counts = [];
        foreach ($this->aggregatedStatusRows($tenantId) as $row) {
            $status = self::aggregateRow($row);
            $counts[$status->value] = ($counts[$status->value] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Ближайшие дедлайны приёма заявок (FR-1.1.4/1.1.7, AM-13): тендеры компании
     * с непустым таймлайном (bids_end ещё в будущем), отсортированные по сроку.
     * Ограничение — $limit; $until — верхняя граница горизонта дедлайнов
     * (period day/week/month дашборда): null = без ограничения.
     * Результат для GET /dashboard upcoming_deadlines.
     *
     * @return list<array{tender_id: string, deadline_at: string}> до $limit записей
     */
    public function upcomingBidDeadlines(Uuid $tenantId, int $limit, ?\DateTimeImmutable $until = null): array
    {
        /** @var list<array{id: string, status: string, timeline: array<string, string>|null}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.id', 't.status', 't.timeline')
            ->where('t.tenantId = :tenantId')
            ->andWhere('t.timeline IS NOT NULL')
            ->andWhere('t.status IN (:statuses)')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('statuses', [TenderStatusEnum::PUBLISHED->value, TenderStatusEnum::ACCEPTING_BIDS->value])
            ->getQuery()
            ->getArrayResult();

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $items = [];
        foreach ($rows as $row) {
            /** @var array<string, string>|null $timeline */
            $timeline = $row['timeline'];
            $deadlineAt = $timeline['bids_end'] ?? null;
            if (!\is_string($deadlineAt) || '' === $deadlineAt) {
                continue;
            }
            try {
                $deadline = new \DateTimeImmutable($deadlineAt);
            } catch (\Exception) {
                continue;
            }
            if ($deadline <= $now) {
                continue;
            }
            if (null !== $until && $deadline > $until) {
                continue;
            }
            $items[] = ['tender_id' => (string) $row['id'], 'deadline_at' => $deadlineAt];
        }

        usort($items, static fn (array $a, array $b): int => $a['deadline_at'] <=> $b['deadline_at']);

        return \array_slice($items, 0, $limit);
    }

    /**
     * Факты тендеров по срезу dimension за период [from, to) (AM-13,
     * GET /stats/tenders): один ряд на тендер — id, значение среза и НМЦК.
     * Срезы: region (tenders.region), customer (customer_id), period (дата
     * создания, Y-m-d). Срез okpd2 не поддерживается (в модели отсутствует) —
     * пустой результат. Значение среза — на стороне БД: TO_CHAR для даты,
     * TO_TEXT (CAST AS text) для uuid-заказчика.
     *
     * @return list<array{tender_id: string, dimension_value: string, nmck_minor: int|null}>
     */
    public function factsByDimension(Uuid $tenantId, string $dimension, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t.id AS tender_id')
            ->addSelect('t.nmckMinor AS nmck_minor')
            ->where('t.tenantId = :tenantId')
            ->andWhere('t.createdAt >= :from')
            ->andWhere('t.createdAt < :to')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        switch ($dimension) {
            case 'region':
                $qb->addSelect("COALESCE(t.region, '') AS dimension_value");
                break;
            case 'customer':
                $qb->addSelect('TO_TEXT(t.customerId) AS dimension_value');
                break;
            case 'period':
                $qb->addSelect("TO_CHAR(t.createdAt, 'YYYY-MM-DD') AS dimension_value");
                break;
            default:
                return [];
        }

        $rows = $qb->getQuery()->getArrayResult();

        /** @var list<array{tender_id: string, dimension_value: string, nmck_minor: int|string|null}> $rows */
        $facts = [];
        foreach ($rows as $row) {
            $nmck = $row['nmck_minor'];
            $facts[] = [
                'tender_id' => (string) $row['tender_id'],
                'dimension_value' => (string) $row['dimension_value'],
                'nmck_minor' => null === $nmck ? null : (int) $nmck,
            ];
        }

        return $facts;
    }

    /**
     * Сырые ряды агрегации статусов (FR-1.1.3): STRING_AGG статусов лотов по
     * тендеру + админ-статус. Единый источник для aggregatedStatuses() и
     * countByAggregatedStatus() — DB-агрегация без гидратации лотов.
     *
     * @return list<array{tender_id: string, admin_status: TenderStatusEnum|string, statuses: string|null}>
     */
    private function aggregatedStatusRows(Uuid $tenantId): array
    {
        /** @var list<array{tender_id: string, admin_status: TenderStatusEnum|string, statuses: string|null}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.id AS tender_id')
            ->addSelect('t.status AS admin_status')
            ->addSelect("STRING_AGG(l.status, ',') AS statuses")
            ->leftJoin('t.lots', 'l')
            ->where('t.tenantId = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->groupBy('t.id', 't.status')
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }

    /**
     * Агрегированный статус тендера из ряда DB-агрегации (FR-1.1.3, вариант C).
     *
     * @param array{tender_id: string, admin_status: TenderStatusEnum|string, statuses: string|null} $row
     */
    private static function aggregateRow(array $row): TenderStatusEnum
    {
        /** @var string|null $statusesRaw */
        $statusesRaw = $row['statuses'];
        $admin = $row['admin_status'];
        $adminStatus = $admin instanceof TenderStatusEnum ? $admin : TenderStatusEnum::from($admin);
        $statuses = null === $statusesRaw || '' === $statusesRaw
            ? []
            : array_map(
                static fn (string $s): LotStatusEnum => LotStatusEnum::from($s),
                explode(',', $statusesRaw),
            );

        return Tender::aggregateStatus($statuses, $adminStatus);
    }
}
