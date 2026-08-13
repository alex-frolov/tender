<?php

declare(strict_types=1);

namespace App\Contract\Service;

use App\Contract\Repository\ContractRepository;
use App\Export\Entity\Enum\ExportTypeEnum;
use App\Export\ExportRowSource;
use App\Export\ExportRowValues;
use Symfony\Component\Uid\Uuid;

/**
 * Источник строк экспорта договоров (UC-31, AM-15, тип contracts).
 *
 * Договоры, где компания — сторона: заказчик (tenant_id = customer) или
 * исполнитель (supplier_id). Реализация — в модуле Contract (публичный
 * read-контракт ExportRowSource), строки — потоково (NFR-18).
 *
 * Фильтры: status, from (createdAt >=), to (createdAt <).
 * Деньги — minor units (PR-1..11); перевод в major — только presentation.
 */
final readonly class ContractExportSource implements ExportRowSource
{
    public function __construct(private ContractRepository $contracts)
    {
    }

    public function supports(ExportTypeEnum $type): bool
    {
        return ExportTypeEnum::CONTRACTS === $type;
    }

    /**
     * @return list<string>
     */
    public function headers(): array
    {
        return [
            'id',
            'number',
            'contract_type_id',
            'customer_id',
            'supplier_id',
            'source',
            'status',
            'scope',
            'price_net_minor',
            'price_gross_minor',
            'currency',
            'valid_from',
            'valid_to',
            'signed_at',
            'created_at',
        ];
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return iterable<list<int|string|null>>
     */
    public function rows(Uuid $tenantId, array $filters): iterable
    {
        $qb = $this->contracts->createQueryBuilder('c')
            ->select(
                'c.id, c.number, c.contractTypeId, c.customerId, c.supplierId, c.source, '
                .'c.status, c.scope, c.priceNetMinor, c.priceGrossMinor, c.currency, '
                .'c.validFrom, c.validTo, c.signedAt, c.createdAt',
            )
            ->where('c.tenantId = :tenant OR c.supplierId = :tenant')
            ->setParameter('tenant', $tenantId)
            ->orderBy('c.createdAt', 'DESC');

        if (isset($filters['status']) && \is_string($filters['status']) && '' !== $filters['status']) {
            $qb->andWhere('c.status = :status')->setParameter('status', $filters['status']);
        }
        if (isset($filters['from'])) {
            $qb->andWhere('c.createdAt >= :from')->setParameter('from', new \DateTimeImmutable(ExportRowValues::string($filters['from'])));
        }
        if (isset($filters['to'])) {
            $qb->andWhere('c.createdAt < :to')->setParameter('to', new \DateTimeImmutable(ExportRowValues::string($filters['to'])));
        }

        $query = $qb->getQuery();

        /** @var iterable<array<string, mixed>> $rows */
        $rows = $query->toIterable([], \Doctrine\ORM\AbstractQuery::HYDRATE_ARRAY);

        foreach ($rows as $row) {
            yield [
                ExportRowValues::string($row['id']),
                ExportRowValues::string($row['number']),
                ExportRowValues::string($row['contractTypeId']),
                ExportRowValues::string($row['customerId']),
                ExportRowValues::string($row['supplierId']),
                ExportRowValues::string($row['source']),
                ExportRowValues::string($row['status']),
                ExportRowValues::string($row['scope']),
                ExportRowValues::intOrNull($row['priceNetMinor']),
                ExportRowValues::intOrNull($row['priceGrossMinor']),
                ExportRowValues::string($row['currency']),
                ExportRowValues::string($row['validFrom']),
                ExportRowValues::string($row['validTo']),
                ExportRowValues::string($row['signedAt']),
                ExportRowValues::string($row['createdAt']),
            ];
        }
    }
}
