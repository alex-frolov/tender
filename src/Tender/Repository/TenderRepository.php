<?php

declare(strict_types=1);

namespace App\Tender\Repository;

use App\Tender\Entity\Enum\AccessTypeEnum;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Enum\TenderVisibilityLevelEnum;
use App\Tender\Entity\Tender;
use App\Tender\TenderFilters;
use App\Tender\TenderVisibilityScope;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Read-оптимизированные запросы к тендерам (FR-1.1.1/1.1.3).
 *
 * - listCatalogPage()/filterVisibleIds(): выборка по правилу видимости
 *   (applyVisibility: свои в любом статусе + чужие на торговых стадиях
 *   (открытые и закрытые по договору) + чужие после определения победителя,
 *   если зритель и есть исполнитель, FR-1.5.14) — договор и победитель
 *   попадают в условие списком id, а не построчной проверкой.
 * - listForTenant(): eager-загрузка лотов (JOIN + addSelect) — один запрос вместо
 *   N+1 на lotCount()/aggregatedStatus() в списке.
 * - aggregatedStatuses()/countByAggregatedStatus(): агрегированный статус при
 *   мультилоте (FR-1.1.3, вариант C) читается из материализованной колонки
 *   tenders.aggregated_status. Раньше он считался на лету — LEFT JOIN lots +
 *   STRING_AGG + GROUP BY по ВСЕМ тендерам компании без LIMIT, на каждое
 *   открытие дашборда и статистики. Колонку пишет Tender::refreshAggregatedStatus()
 *   на изменении входов агрегации;
 *   единый источник истины — по-прежнему Tender::aggregateStatus().
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
     * Срез каталога тендеров, видимых компании (read-модель, FR-1.1.1, AR-6/NFR-22).
     *
     * Видимость (FR-1.5.14) — условие applyVisibility(): свои тендеры + чужие
     * опубликованные открытые + чужие опубликованные закрытые заказчиков
     * из scope->contractCustomerIds. Проверка договора не делается построчно —
     * список заказчиков подставляется в IN одним параметром.
     *
     * Keyset-пагинация: условие «следующая страница» — (created_at, id) строго
     * меньше позиции курсора при ORDER BY created_at DESC, id DESC. Возвращает
     * до $limit строк-проекций БЕЗ гидратации сущностей (getArrayResult) —
     * O(limit) строк и памяти независимо от размера каталога. Вызывающий
     * запрашивает limit+1, чтобы определить наличие следующей страницы.
     * Свою часть выборки покрывают idx_tenders_catalog_* (миграция
     * Version20260813160000: tenant_id [+ status] → created_at → id), чужую —
     * idx_tenders_catalog_access (Version20260819120000: access_type, status →
     * created_at → id).
     *
     * @param TenderVisibilityScope   $scope           компания-зритель + заказчики с договором
     * @param TenderFilters           $filters         фильтры каталога (q/status/law_type/region/price/access)
     * @param \DateTimeImmutable|null $cursorCreatedAt created_at позиции курсора (null — первая страница)
     * @param Uuid|null               $cursorId        id позиции курсора (tiebreaker, null — первая страница)
     * @param int                     $limit           размер страницы
     *
     * @return list<array{id: string, number: string, title: string, status: TenderStatusEnum|string, aggregated_status: TenderStatusEnum|string, nmck_minor: int|string|null, currency: string, region: string|null, okpd2: string|null, timeline: array<string, string>|null, created_at: \DateTimeImmutable}>
     */
    public function listCatalogPage(TenderVisibilityScope $scope, TenderFilters $filters, ?\DateTimeImmutable $cursorCreatedAt, ?Uuid $cursorId, int $limit): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select(
                't.id AS id',
                't.number AS number',
                't.title AS title',
                't.status AS status',
                't.aggregatedStatusCache AS aggregated_status',
                't.nmckMinor AS nmck_minor',
                't.currency AS currency',
                't.region AS region',
                't.okpd2 AS okpd2',
                't.timeline AS timeline',
                't.createdAt AS created_at',
            )
            ->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults(max(1, $limit));

        $this->applyVisibility($qb, $scope);

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

        /** @var list<array{id: string, number: string, title: string, status: TenderStatusEnum|string, aggregated_status: TenderStatusEnum|string, nmck_minor: int|string|null, currency: string, region: string|null, okpd2: string|null, timeline: array<string, string>|null, created_at: \DateTimeImmutable}> $result */
        $result = $qb->getQuery()->getArrayResult();

        return $result;
    }

    /**
     * Фильтр набора тендеров по видимости (TenderVisibility::filterVisible):
     * одним запросом на весь набор — для списков других модулей (GET /auctions),
     * где проверка видимости построчно дала бы N+1.
     *
     * @param list<Uuid> $tenderIds
     *
     * @return list<Uuid> видимые id
     */
    public function filterVisibleIds(array $tenderIds, TenderVisibilityScope $scope): array
    {
        if ([] === $tenderIds) {
            return [];
        }

        $qb = $this->createQueryBuilder('t')
            ->select('t.id AS id')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $tenderIds);

        $this->applyVisibility($qb, $scope);

        /** @var list<array{id: string|Uuid}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        // DQL-проекция uuid-колонки отдаёт Uuid-объект, а не строку.
        return array_map(
            static fn (array $row): Uuid => $row['id'] instanceof Uuid ? $row['id'] : Uuid::fromString($row['id']),
            $rows,
        );
    }

    /**
     * Условие видимости тендера для компании-зрителя (FR-1.1.1, FR-1.5.14),
     * единое для каталога и для фильтра по набору id:
     *
     *   t.tenant_id = :viewer                       -- свои, в любом статусе
     *   OR (t.status <> 'draft' AND (               -- чужие, вышедшие из черновика
     *        t.access_type = 'open'
     *        OR (t.access_type = 'contract_holders'
     *            AND t.customer_id IN (:contractCustomers))))
     *
     * Ветка закрытых тендеров добавляется только если договоры есть: пустой
     * IN () в DQL не выражается, а без договоров ветка всё равно ложна.
     */
    private function applyVisibility(QueryBuilder $qb, TenderVisibilityScope $scope): void
    {
        // Своё видно всегда и в любом статусе.
        $visible = $qb->expr()->orX($qb->expr()->eq('t.tenantId', ':viewerCompany'));
        $qb->setParameter('viewerCompany', $scope->companyId);

        // Торговая стадия: открытый тендер — всем, закрытый — только тем,
        // у кого с заказчиком есть действующий многоразовый договор.
        $access = $qb->expr()->orX($qb->expr()->eq('t.accessType', ':accessOpen'));
        $qb->setParameter('accessOpen', AccessTypeEnum::OPEN->value);

        if ([] !== $scope->contractCustomerIds) {
            $access->add($qb->expr()->andX(
                $qb->expr()->eq('t.accessType', ':accessContractHolders'),
                $qb->expr()->in('t.customerId', ':contractCustomers'),
            ));
            $qb->setParameter('accessContractHolders', AccessTypeEnum::CONTRACT_HOLDERS->value)
                ->setParameter('contractCustomers', $scope->contractCustomerIds);
        }

        $visible->add($qb->expr()->andX(
            $qb->expr()->in('t.status', ':participantStatuses'),
            $access,
        ));
        $qb->setParameter(
            'participantStatuses',
            TenderStatusEnum::valuesWithVisibility(TenderVisibilityLevelEnum::PARTICIPANTS),
        );

        // После определения победителя закупка остаётся видимой только
        // исполнителю. Пустой список — условие не добавляем вовсе: `IN ()`
        // в DQL невалиден, а пустое множество и так ничего не даёт.
        if ([] !== $scope->wonTenderIds) {
            $visible->add($qb->expr()->andX(
                $qb->expr()->in('t.status', ':winnerStatuses'),
                $qb->expr()->in('t.id', ':wonTenders'),
            ));
            $qb->setParameter(
                'winnerStatuses',
                TenderStatusEnum::valuesWithVisibility(TenderVisibilityLevelEnum::OWNER_AND_WINNER),
            )->setParameter('wonTenders', $scope->wonTenderIds);
        }

        // Статусы уровня OWNER_ONLY (draft/withdrawn/evaluation) не попадают
        // ни в одну ветку — чужой тендер в них не виден никому.
        $qb->andWhere($visible);
    }

    /**
     * Число лотов по id тендеров страницы каталога (lot_count, FR-1.1.3).
     *
     * Раньше этот же запрос собирал ещё и STRING_AGG статусов лотов, чтобы
     * вызывающий пересобрал из них агрегированный статус. Теперь агрегат
     * приходит готовой колонкой в самой строке каталога
     * (listCatalogPage → aggregated_status), и от JOIN'а остался только
     * счётчик.
     *
     * @param list<Uuid> $ids id тендеров страницы
     *
     * @return array<string, int> tender_id → число лотов
     */
    public function lotCountsForIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<array{tender_id: string, lot_count: int|string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.id AS tender_id')
            ->addSelect('COUNT(l.id) AS lot_count')
            ->leftJoin('t.lots', 'l')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->groupBy('t.id')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['tender_id']] = (int) $row['lot_count'];
        }

        return $counts;
    }

    /**
     * Только идентификаторы тендеров компании-заказчика.
     *
     * Нужны потребителям, которым сами тендеры не требуются — например
     * списку жалоб: заказчик видит жалобы на свои процедуры, а гидратировать
     * ради этого весь каталог тендеров с лотами незачем.
     *
     * @return list<Uuid>
     */
    public function idsForTenant(Uuid $tenantId): array
    {
        /** @var list<array{id: Uuid}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.id')
            ->where('t.tenantId = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): Uuid => $row['id'], $rows);
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
     * Карта агрегированных статусов тендеров компании (FR-1.1.3, вариант C):
     * чтение материализованной колонки tenders.aggregated_status.
     *
     * Прежняя версия считала агрегат на лету — LEFT JOIN lots + STRING_AGG +
     * GROUP BY по всем тендерам тенанта без LIMIT.
     * Значение колонки — то же, что вернул бы
     * Tender::aggregatedStatus(): её пишет Tender::refreshAggregatedStatus()
     * на каждом изменении входов агрегации.
     *
     * @return array<string, TenderStatusEnum> тендер_id → агрегированный статус
     */
    public function aggregatedStatuses(Uuid $tenantId): array
    {
        /** @var list<array{tender_id: string, aggregated: TenderStatusEnum|string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.id AS tender_id')
            ->addSelect('t.aggregatedStatusCache AS aggregated')
            ->where('t.tenantId = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['tender_id']] = self::toStatus($row['aggregated']);
        }

        return $result;
    }

    /**
     * Счётчик тендеров компании по агрегированному статусу (FR-1.1.3, AM-13):
     * карта статус → число тендеров, для дашборда (active_tenders и др.).
     * COUNT + GROUP BY по колонке aggregated_status — лоты в запрос не входят
     * вовсе; выборку покрывает idx_tenders_tenant_aggregated.
     *
     * $participatingTenderIds добавляет процедуры участия компании (чужие по
     * тенанту): у исполнителя своих тендеров нет, и без них счётчик всегда 0.
     *
     * @param list<Uuid> $participatingTenderIds
     *
     * @return array<string, int> агрегированный статус (value) → количество
     */
    public function countByAggregatedStatus(Uuid $tenantId, array $participatingTenderIds = []): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t.aggregatedStatusCache AS aggregated')
            ->addSelect('COUNT(t.id) AS total')
            ->where([] === $participatingTenderIds
                ? 't.tenantId = :tenantId'
                : 't.tenantId = :tenantId OR t.id IN (:participating)')
            ->setParameter('tenantId', $tenantId)
            ->groupBy('t.aggregatedStatusCache');
        if ([] !== $participatingTenderIds) {
            $qb->setParameter('participating', $participatingTenderIds);
        }

        /** @var list<array{aggregated: TenderStatusEnum|string, total: int|string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[self::toStatus($row['aggregated'])->value] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * DQL-проекция enum-колонки отдаёт то перечисление, то строку (зависит от
     * гидратора) — приводим к перечислению в одном месте.
     */
    private static function toStatus(TenderStatusEnum|string $value): TenderStatusEnum
    {
        return $value instanceof TenderStatusEnum ? $value : TenderStatusEnum::from($value);
    }

    /**
     * Ближайшие дедлайны приёма заявок (FR-1.1.4/1.1.7, AM-13): тендеры компании
     * с непустым таймлайном (bids_end ещё в будущем), отсортированные по сроку.
     * Ограничение — $limit; $until — верхняя граница горизонта дедлайнов
     * (period day/week/month дашборда): null = без ограничения.
     * Результат для GET /dashboard upcoming_deadlines.
     *
     * $participatingTenderIds — процедуры участия компании (чужие по тенанту):
     * срок подачи заявки по чужой процедуре и есть дедлайн исполнителя.
     *
     * @param list<Uuid> $participatingTenderIds
     *
     * @return list<array{tender_id: string, deadline_at: string}> до $limit записей
     */
    public function upcomingBidDeadlines(Uuid $tenantId, int $limit, ?\DateTimeImmutable $until = null, array $participatingTenderIds = []): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t.id', 't.status', 't.timeline')
            ->where([] === $participatingTenderIds
                ? 't.tenantId = :tenantId'
                : 't.tenantId = :tenantId OR t.id IN (:participating)')
            ->andWhere('t.timeline IS NOT NULL')
            ->andWhere('t.status IN (:statuses)')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('statuses', [TenderStatusEnum::PUBLISHED->value, TenderStatusEnum::ACCEPTING_BIDS->value]);
        if ([] !== $participatingTenderIds) {
            $qb->setParameter('participating', $participatingTenderIds);
        }

        /** @var list<array{id: string, status: string, timeline: array<string, string>|null}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

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
     * $participatingTenderIds добавляет процедуры участия компании (чужие по
     * тенанту) — иначе у исполнителя статистика пуста при любых торгах.
     *
     * @param list<Uuid> $participatingTenderIds
     *
     * @return list<array{tender_id: string, dimension_value: string, nmck_minor: int|null}>
     */
    public function factsByDimension(Uuid $tenantId, string $dimension, \DateTimeImmutable $from, \DateTimeImmutable $to, array $participatingTenderIds = []): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t.id AS tender_id')
            ->addSelect('t.nmckMinor AS nmck_minor')
            ->where([] === $participatingTenderIds
                ? 't.tenantId = :tenantId'
                : 't.tenantId = :tenantId OR t.id IN (:participating)')
            ->andWhere('t.createdAt >= :from')
            ->andWhere('t.createdAt < :to')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('from', $from)
            ->setParameter('to', $to);
        if ([] !== $participatingTenderIds) {
            $qb->setParameter('participating', $participatingTenderIds);
        }

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
}
