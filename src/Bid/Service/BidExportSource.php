<?php

declare(strict_types=1);

namespace App\Bid\Service;

use App\Bid\Repository\BidRepository;
use App\Export\Entity\Enum\ExportTypeEnum;
use App\Export\ExportRowSource;
use App\Export\ExportRowValues;
use Symfony\Component\Uid\Uuid;

/**
 * Источник строк экспорта заявок (UC-31, AM-15, тип bids).
 *
 * Заявки по тендерам компании (tenant) — заказчик видит заявки на свои
 * тендеры (bids.tenant_id = компания-заказчик), исполнитель — свои поданные
 * заявки (supplier_id = компания). Содержимое (encrypted_payload) до вскрытия
 * не экспортируется (FR-1.2.2); после вскрытия — цена из decrypted_payload
 * (price_minor, только если расшифрована). Реализация — в модуле Bid
 * (публичный read-контракт ExportRowSource), строки — потоково (NFR-18).
 *
 * Фильтры: status, from (createdAt >=), to (createdAt <).
 */
final readonly class BidExportSource implements ExportRowSource
{
    public function __construct(private BidRepository $bids)
    {
    }

    public function supports(ExportTypeEnum $type): bool
    {
        return ExportTypeEnum::BIDS === $type;
    }

    /**
     * @return list<string>
     */
    public function headers(): array
    {
        return [
            'id',
            'tender_id',
            'lot_id',
            'supplier_id',
            'status',
            'price_minor',
            'submitted_at',
            'decision_reason',
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
        $qb = $this->bids->createQueryBuilder('b')
            ->select(
                'b.id, b.tenderId, b.lotId, b.supplierId, b.status, '
                .'b.decryptedPayload, b.submittedAt, b.decisionReason, b.createdAt',
            )
            ->where('b.tenantId = :tenant OR b.supplierId = :tenant')
            ->setParameter('tenant', $tenantId)
            ->orderBy('b.createdAt', 'DESC');

        if (isset($filters['status']) && \is_string($filters['status']) && '' !== $filters['status']) {
            $qb->andWhere('b.status = :status')->setParameter('status', $filters['status']);
        }
        if (isset($filters['from'])) {
            $qb->andWhere('b.createdAt >= :from')->setParameter('from', new \DateTimeImmutable(ExportRowValues::string($filters['from'])));
        }
        if (isset($filters['to'])) {
            $qb->andWhere('b.createdAt < :to')->setParameter('to', new \DateTimeImmutable(ExportRowValues::string($filters['to'])));
        }

        $query = $qb->getQuery();

        /** @var iterable<array<string, mixed>> $rows */
        $rows = $query->toIterable([], \Doctrine\ORM\AbstractQuery::HYDRATE_ARRAY);

        foreach ($rows as $row) {
            /** @var array<string, mixed>|null $decrypted */
            $decrypted = $row['decryptedPayload'];
            $priceMinor = \is_array($decrypted) && isset($decrypted['price_minor'])
                ? ExportRowValues::intOrNull($decrypted['price_minor'])
                : null;

            yield [
                ExportRowValues::string($row['id']),
                ExportRowValues::string($row['tenderId']),
                ExportRowValues::string($row['lotId']),
                ExportRowValues::string($row['supplierId']),
                ExportRowValues::string($row['status']),
                $priceMinor,
                ExportRowValues::string($row['submittedAt']),
                ExportRowValues::string($row['decisionReason']),
                ExportRowValues::string($row['createdAt']),
            ];
        }
    }
}
