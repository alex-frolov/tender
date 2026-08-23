<?php

declare(strict_types=1);

namespace App\Tender\Service;

use App\Shared\Repository\KeysetCursor;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Repository\TenderRepository;
use App\Tender\TenderCatalogPage;
use App\Tender\TenderCatalogQuery;
use App\Tender\TenderFilters;
use App\Tender\TenderVisibility;
use Symfony\Component\Uid\Uuid;

/**
 * Реализация публичного read-контракта каталога тендеров (FR-1.1.1, AR-6).
 *
 * Read-модель GET /tenders: keyset-пагинация по (created_at, id), страница —
 * область видимости (TenderVisibility::scopeFor — один запрос к договорам) плюс
 * два index-запроса в TenderRepository (срез тендеров с готовой колонкой
 * aggregated_status + счётчик лотов по id страницы), без гидратации сущностей
 * (FR-1.1.3, вариант C). Курсор — OPAQUE base64url(JSON {c, i}); невалидный
 * курсор → ValidationException (422).
 */
final readonly class TenderCatalogQueryService implements TenderCatalogQuery
{
    public function __construct(
        private TenderRepository $tenders,
        private TenderVisibility $visibility,
    ) {
    }

    public function page(Uuid $viewerCompanyId, TenderFilters $filters, ?string $cursor, int $limit): TenderCatalogPage
    {
        $cursorPos = KeysetCursor::decode($cursor);
        $cursorCreatedAt = $cursorPos?->createdAt;
        $cursorId = $cursorPos?->id;

        $scope = $this->visibility->scopeFor($viewerCompanyId);
        [$rows, $nextCursor] = KeysetCursor::pageOf(
            $this->tenders->listCatalogPage($scope, $filters, $cursorCreatedAt, $cursorId, $limit + 1),
            $limit,
            static fn (array $row): array => [$row['created_at'], (string) $row['id']],
        );

        return new TenderCatalogPage($this->buildItems($rows), $nextCursor);
    }

    /**
     * Сборка строк-проекций списка: агрегированный статус приходит готовой
     * колонкой в самом срезе каталога (tenders.aggregated_status), lot_count —
     * одним запросом по id страницы, остальное — из среза.
     *
     * @param list<array{id: string, number: string, title: string, status: TenderStatusEnum|string, aggregated_status: TenderStatusEnum|string, nmck_minor: int|string|null, currency: string, region: string|null, okpd2: string|null, timeline: array<string, string>|null, created_at: \DateTimeImmutable}> $rows
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
        $lotCounts = $this->tenders->lotCountsForIds($ids);

        $items = [];
        foreach ($rows as $row) {
            $id = (string) $row['id'];

            $items[] = [
                'id' => $id,
                'number' => $row['number'],
                'title' => $row['title'],
                'status' => self::status($row['status']),
                'aggregated_status' => self::status($row['aggregated_status']),
                'nmck_minor' => $row['nmck_minor'],
                'currency' => $row['currency'],
                'region' => $row['region'],
                'okpd2' => $row['okpd2'],
                'deadline' => self::deadline($row['timeline']),
                'lot_count' => $lotCounts[$id] ?? 0,
            ];
        }

        return $items;
    }

    /**
     * Скалярная DQL-проекция enum-колонки отдаёт строку значения; в гидратированном
     * объекте то же поле — уже enum.
     */
    private static function status(TenderStatusEnum|string $value): TenderStatusEnum
    {
        return $value instanceof TenderStatusEnum ? $value : TenderStatusEnum::from($value);
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
