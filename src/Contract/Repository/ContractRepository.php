<?php

declare(strict_types=1);

namespace App\Contract\Repository;

use App\Contract\Entity\Contract;
use App\Contract\Entity\Enum\ContractScopeEnum;
use App\Contract\Entity\Enum\ContractStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Read-оптимизированные запросы к договорам (FR-1.4.3, FR-1.5.14).
 *
 * - listPageForParties(): страница договоров, где компания актора — заказчик
 *   ИЛИ исполнитель (keyset в SQL, AR-6);
 * - findActiveMultiUse(): действующий multi_use-договор между заказчиком и
 *   исполнителем для закрытых тендеров (contract_holders, FR-1.5.14);
 * - findById(): публичный lookup для действий по id (party-проверка в сервисе).
 *
 * @extends ServiceEntityRepository<Contract>
 */
final class ContractRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contract::class);
    }

    /**
     * Договор по id БЕЗ tenant-фильтра (для действий по id). Возвращает null,
     * если id невалиден или договор не найден; принадлежность сторонам
     * (customer/supplier) проверяет ContractService (404 для чужих).
     */
    public function findById(string $contractId): ?Contract
    {
        if (!Uuid::isValid($contractId)) {
            return null;
        }

        /** @var Contract|null $contract */
        $contract = $this->findOneBy(['id' => Uuid::fromString($contractId)]);

        return $contract;
    }

    /**
     * Страница договоров компании (AM-9 GET /contracts, AR-6/NFR-22): те же
     * стороны, что и в остальных party-запросах, плюс необязательные фильтры по
     * статусу и по привязанной процедуре (JOIN contract_tenders).
     *
     * Раньше список отдавался целиком, а страницу вырезал курсор в PHP
     * (KeysetCursor::sliceAfter). Число договоров компании ничем не ограничено
     * и растёт линейно с историей закупок — вместе с ним росла цена КАЖДОГО
     * GET /contracts (../docs/db-query-audit.md, п. 6). Условие «следующая
     * страница» — (created_at, id) строго МЕНЬШЕ позиции курсора при
     * ORDER BY created_at DESC, id DESC (новые сверху). Вызывающий запрашивает
     * limit+1, чтобы узнать о наличии следующей страницы (KeysetCursor::pageOf).
     *
     * @param \DateTimeImmutable|null $cursorCreatedAt позиция курсора (null — первая страница)
     * @param Uuid|null               $cursorId        tiebreaker позиции курсора
     *
     * @return list<Contract>
     */
    public function listPageForParties(
        Uuid $customerId,
        Uuid $supplierId,
        ?ContractStatusEnum $status,
        ?Uuid $tenderId,
        ?\DateTimeImmutable $cursorCreatedAt,
        ?Uuid $cursorId,
        int $limit,
    ): array {
        $qb = $this->createQueryBuilder('c')
            ->where('c.customerId = :customer')
            ->orWhere('c.supplierId = :supplier')
            ->setParameter('customer', $customerId)
            ->setParameter('supplier', $supplierId)
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(max(1, $limit));

        if (null !== $status) {
            $qb->andWhere('c.status = :status')->setParameter('status', $status->value);
        }

        if (null !== $tenderId) {
            $qb->innerJoin('c.tenders', 'ct')
                ->andWhere('ct.tenderId = :tenderId')
                ->setParameter('tenderId', $tenderId);
        }

        if (null !== $cursorCreatedAt && null !== $cursorId) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->lt('c.createdAt', ':cursorCreatedAt'),
                    $qb->expr()->andX(
                        $qb->expr()->eq('c.createdAt', ':cursorCreatedAt'),
                        $qb->expr()->lt('c.id', ':cursorId'),
                    ),
                ),
            )
                ->setParameter('cursorCreatedAt', $cursorCreatedAt)
                ->setParameter('cursorId', $cursorId);
        }

        /** @var list<Contract> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Число договоров компании (как заказчика или исполнителя) — счётчик
     * дашборда my_contracts (AM-13, GET /dashboard). Договор в любой стадии.
     */
    public function countForCompany(Uuid $companyId): int
    {
        $count = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.customerId = :company')
            ->orWhere('c.supplierId = :company')
            ->setParameter('company', $companyId)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * Действующий multi_use-договор между заказчиком и исполнителем (FR-1.5.14,
     * contract_holders): scope=multi_use, статус signed/registered, срок действия
     * не истёк (valid_to либо null, либо >= сегодня). Возвращает null — доступа нет.
     *
     * Примечание (схема): tenders.required_contract_type_id — UUID, contract_types.id
     * — BIGINT; сравнение по типу на уровне схемы не согласовано (TODO) —
     * фильтр по типу здесь не применяется.
     */
    public function findActiveMultiUse(Uuid $customerId, Uuid $supplierId): ?Contract
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));

        /** @var Contract|null $contract */
        $contract = $this->createQueryBuilder('c')
            ->where('c.customerId = :customer')
            ->andWhere('c.supplierId = :supplier')
            ->andWhere('c.scope = :scope')
            ->andWhere('c.status IN (:statuses)')
            ->andWhere('c.validTo IS NULL OR c.validTo >= :today')
            ->setParameter('customer', $customerId)
            ->setParameter('supplier', $supplierId)
            ->setParameter('scope', ContractScopeEnum::MULTI_USE->value)
            ->setParameter('statuses', [ContractStatusEnum::SIGNED->value, ContractStatusEnum::REGISTERED->value])
            ->setParameter('today', $today)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $contract;
    }

    /**
     * Заказчики, у которых с компанией-исполнителем есть действующий
     * multi_use-договор (FR-1.5.14): те же условия, что в findActiveMultiUse(),
     * но одним запросом на всех контрагентов — множество customer_id для
     * фильтра видимости закрытых тендеров (каталог/карточка).
     *
     * @return list<Uuid> id заказчиков (без дублей)
     */
    public function activeMultiUseCustomerIds(Uuid $supplierId): array
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));

        /** @var list<array{customer_id: string|Uuid}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('DISTINCT c.customerId AS customer_id')
            ->where('c.supplierId = :supplier')
            ->andWhere('c.scope = :scope')
            ->andWhere('c.status IN (:statuses)')
            ->andWhere('c.validTo IS NULL OR c.validTo >= :today')
            ->setParameter('supplier', $supplierId)
            ->setParameter('scope', ContractScopeEnum::MULTI_USE->value)
            ->setParameter('statuses', [ContractStatusEnum::SIGNED->value, ContractStatusEnum::REGISTERED->value])
            ->setParameter('today', $today)
            ->getQuery()
            ->getArrayResult();

        // DQL-проекция uuid-колонки отдаёт Uuid-объект, а не строку.
        return array_map(
            static fn (array $row): Uuid => $row['customer_id'] instanceof Uuid
                ? $row['customer_id']
                : Uuid::fromString($row['customer_id']),
            $rows,
        );
    }

    /**
     * Последний multi_use-договор между заказчиком и исполнителем ЛЮБОГО статуса
     * (для определения причины отсутствия доступа в GET /tenders/{id}/access:
     * contract_required/contract_expired/contract_terminated, FR-1.5.14).
     * Возвращает null, если между сторонами не было ни одного multi_use-договора.
     */
    public function findAnyMultiUse(Uuid $customerId, Uuid $supplierId): ?Contract
    {
        /** @var Contract|null $contract */
        $contract = $this->createQueryBuilder('c')
            ->where('c.customerId = :customer')
            ->andWhere('c.supplierId = :supplier')
            ->andWhere('c.scope = :scope')
            ->setParameter('customer', $customerId)
            ->setParameter('supplier', $supplierId)
            ->setParameter('scope', ContractScopeEnum::MULTI_USE->value)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $contract;
    }
}
