<?php

declare(strict_types=1);

namespace App\Tender\Service;

use App\Shared\Exception\ValidationException;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Tender;
use App\Tender\Repository\TenderRepository;
use App\Tender\TenderCatalogPage;
use App\Tender\TenderCatalogQuery;
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

    public function page(Uuid $tenantId, ?TenderStatusEnum $status, ?string $cursor, int $limit): TenderCatalogPage
    {
        [$cursorCreatedAt, $cursorId] = $this->decodeCursor($cursor);

        $rows = $this->tenders->listCatalogPage($tenantId, $status, $cursorCreatedAt, $cursorId, $limit + 1);

        $hasMore = \count($rows) > $limit;
        if ($hasMore) {
            $rows = \array_slice($rows, 0, $limit);
        }

        $items = $this->buildItems($rows);

        $nextCursor = null;
        if ($hasMore && [] !== $rows) {
            $last = $rows[\count($rows) - 1];
            $nextCursor = self::encodeCursor($last['created_at'], (string) $last['id']);
        }

        return new TenderCatalogPage($items, $nextCursor);
    }

    /**
     * Сборка строк-проекций списка: агрегированный статус (FR-1.1.3) и
     * lot_count берутся из DB-агрегации по id страницы, остальное — из среза.
     *
     * @param list<array{id: string, number: string, title: string, status: TenderStatusEnum|string, nmck_minor: int|string|null, currency: string, region: string|null, timeline: array<string, string>|null, created_at: \DateTimeImmutable}> $rows
     *
     * @return list<array{id: string, number: string, title: string, status: TenderStatusEnum, aggregated_status: TenderStatusEnum, nmck_minor: int|string|null, currency: string, region: string|null, deadline: string|null, lot_count: int}>
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

    /**
     * Кодирование OPAQUE-курсора: base64url(JSON {c: created_at, i: id}).
     */
    private static function encodeCursor(\DateTimeImmutable $createdAt, string $id): string
    {
        $payload = json_encode(
            ['c' => $createdAt->format('Y-m-d\TH:i:s\Z'), 'i' => $id],
            \JSON_THROW_ON_ERROR,
        );

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /**
     * Декодирование курсора. null/'' — первая страница. Любая некорректность
     * (не base64url, не JSON, неверная форма, невалидный id/дата) — 422.
     *
     * @return array{\DateTimeImmutable|null, Uuid|null} [created_at, id]
     */
    private function decodeCursor(?string $cursor): array
    {
        if (null === $cursor || '' === $cursor) {
            return [null, null];
        }

        try {
            $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
            if (false === $raw) {
                throw new \RuntimeException('bad base64url');
            }
            $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($data) || !isset($data['c'], $data['i'])
                || !\is_string($data['c']) || !\is_string($data['i']) || !Uuid::isValid($data['i'])) {
                throw new \RuntimeException('bad cursor shape');
            }
            $createdAt = new \DateTimeImmutable($data['c'], new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            throw new ValidationException('invalid cursor');
        }

        return [$createdAt, Uuid::fromString($data['i'])];
    }
}
