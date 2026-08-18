<?php

declare(strict_types=1);

namespace App\Tender\Service;

use App\Shared\Repository\KeysetCursor;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Tender;
use App\Tender\Repository\TenderRepository;
use App\Tender\TenderCatalogPage;
use App\Tender\TenderCatalogQuery;
use App\Tender\TenderFilters;
use Symfony\Component\Uid\Uuid;

/**
 * Реализация публичного read-контракта каталога тендеров (FR-1.1.1, AR-6).
 *
 * Read-модель GET /tenders: keyset-пагинация по (created_at, id), страница —
 * два index-запроса в TenderRepository (срез тендеров + агрегация статусов/
 * lot_count по id страницы), без гидратации сущностей. Агрегация статуса при
 * мультилоте пересобирается Tender::aggregateStatus() — единый источник истины
 * (FR-1.1.3, вариант C). Курсор — OPAQUE base64url(JSON {c, i}); невалидный
 * курсор → ValidationException (422).
 */
final readonly class TenderCatalogQueryService implements TenderCatalogQuery
{
    public function __construct(private TenderRepository $tenders)
    {
    }

    public function page(Uuid $tenantId, TenderFilters $filters, ?string $cursor, int $limit): TenderCatalogPage
    {
        $cursorPos = KeysetCursor::decode($cursor);
        $cursorCreatedAt = $cursorPos?->createdAt;
        $cursorId = $cursorPos?->id;

        $rows = $this->tenders->listCatalogPage($tenantId, $filters, $cursorCreatedAt, $cursorId, $limit + 1);

        $hasMore = \count($rows) > $limit;
        if ($hasMore) {
            $rows = \array_slice($rows, 0, $limit);
        }

        $items = $this->buildItems($rows);

        $nextCursor = null;
        if ($hasMore && [] !== $rows) {
            $last = $rows[\count($rows) - 1];
            $nextCursor = KeysetCursor::encode($last['created_at'], (string) $last['id']);
        }

        return new TenderCatalogPage($items, $nextCursor);
    }

    /**
     * Сборка строк-проекций списка: агрегированный статус (FR-1.1.3) и
     * lot_count берутся из DB-агрегации по id страницы, остальное — из среза.
     *
     * @param list<array{id: string, number: string, title: string, status: TenderStatusEnum|string, nmck_minor: int|string|null, currency: string, region: string|null, okpd2: string|null, timeline: array<string, string>|null, created_at: \DateTimeImmutable}> $rows
     *
     * @return list<array{id: string, number: string, title: string, status: TenderStatusEnum, aggregated_status: TenderStatusEnum, nmck_minor: int|string|null, currency: string, region: string|null, okpd2: string|null, deadline: string|null, lot_count: int}>
     */
    private function buildItems(array $rows): array
    {
        if ([] === $rows) {
            return [];
        }

        $ids = array_map(
            static fn (array $row): Uuid => Uuid::fromString((string) $row['id']),
            $rows,
        );
        $aggregated = $this->tenders->aggregatedStatusesForIds($ids);

        $items = [];
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $adminStatus = $row['status'] instanceof TenderStatusEnum
                ? $row['status']
                : TenderStatusEnum::from($row['status']);
            $lotAgg = $aggregated[$id] ?? null;

            $items[] = [
                'id' => $id,
                'number' => $row['number'],
                'title' => $row['title'],
                'status' => $adminStatus,
                'aggregated_status' => null !== $lotAgg
                    ? Tender::aggregateStatus($lotAgg['lot_statuses'], $adminStatus)
                    : $adminStatus,
                'nmck_minor' => $row['nmck_minor'],
                'currency' => $row['currency'],
                'region' => $row['region'],
                'okpd2' => $row['okpd2'],
                'deadline' => self::deadline($row['timeline']),
                'lot_count' => $lotAgg['lot_count'] ?? 0,
            ];
        }

        return $items;
    }

    /**
     * Ближайший дедлайн приёма заявок (bids_end из таймлайна) для списка.
     *
     * @param array<string, string>|null $timeline
     */
    private static function deadline(?array $timeline): ?string
    {
        $deadline = $timeline['bids_end'] ?? null;

        return \is_string($deadline) && '' !== $deadline ? $deadline : null;
    }
}
