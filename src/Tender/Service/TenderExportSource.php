<?php

declare(strict_types=1);

namespace App\Tender\Service;

use App\Export\Entity\Enum\ExportTypeEnum;
use App\Export\ExportRowSource;
use App\Export\ExportRowValues;
use App\Tender\Repository\TenderRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Источник строк экспорта тендеров (UC-31, AM-15, тип tenders).
 *
 * Тендеры компании-заказчика (tenant). Реализация — в модуле Tender
 * (публичный read-контракт ExportRowSource), поэтому запросы идут через
 * собственный TenderRepository; Export-модуль получает строки потоково
 * (toIterable, HYDRATE_ARRAY — память не растёт с размером выборки, NFR-18).
 *
 * Фильтры: status (t.status), from (createdAt >=), to (createdAt <).
 * Деньги — minor units (PR-1..11); перевод в major — только presentation.
 */
final readonly class TenderExportSource implements ExportRowSource
{
    public function __construct(private TenderRepository $tenders)
    {
    }

    public function supports(ExportTypeEnum $type): bool
    {
        return ExportTypeEnum::TENDERS === $type;
    }

    /**
     * @return list<string>
     */
    public function headers(): array
    {
        return [
            'id',
            'number',
            'title',
            'status',
            'procedure_type',
            'law_type',
            'region',
            'nmck_minor',
            'currency',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return iterable<list<int|string|null>>
     */
    public function rows(Uuid $tenantId, array $filters): iterable
    {
        $qb = $this->tenders->createQueryBuilder('t')
            ->select(
                't.id, t.number, t.title, t.status, t.procedureType, t.lawType, '
                .'t.region, t.nmckMinor, t.currency, t.createdAt, t.updatedAt',
            )
            ->where('t.tenantId = :tenant')
            ->setParameter('tenant', $tenantId)
            ->orderBy('t.createdAt', 'DESC');

        if (isset($filters['status']) && \is_string($filters['status']) && '' !== $filters['status']) {
            $qb->andWhere('t.status = :status')->setParameter('status', $filters['status']);
        }
        if (isset($filters['from'])) {
            $qb->andWhere('t.createdAt >= :from')->setParameter('from', new \DateTimeImmutable(ExportRowValues::string($filters['from'])));
        }
        if (isset($filters['to'])) {
            $qb->andWhere('t.createdAt < :to')->setParameter('to', new \DateTimeImmutable(ExportRowValues::string($filters['to'])));
        }

        $query = $qb->getQuery();

        /** @var iterable<array<string, mixed>> $rows */
        $rows = $query->toIterable([], \Doctrine\ORM\AbstractQuery::HYDRATE_ARRAY);

        foreach ($rows as $row) {
            yield [
                ExportRowValues::string($row['id']),
                ExportRowValues::string($row['number']),
                ExportRowValues::string($row['title']),
                ExportRowValues::string($row['status']),
                ExportRowValues::string($row['procedureType']),
                ExportRowValues::string($row['lawType']),
                ExportRowValues::string($row['region']),
                ExportRowValues::intOrNull($row['nmckMinor']),
                ExportRowValues::string($row['currency']),
                ExportRowValues::string($row['createdAt']),
                ExportRowValues::string($row['updatedAt']),
            ];
        }
    }
}
