<?php

declare(strict_types=1);

namespace App\Tender\UseCase;

use App\Iam\Entity\User;
use App\Shared\Exception\ValidationException;
use App\Shared\Input\InputValue;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\TenderCatalogQuery;
use App\Tender\TenderPresenter;

/**
 * Список тендеров компании (FR-1.1.1, GET /tenders?status=&limit=&cursor=).
 *
 * Query-use-case на read-модели TenderCatalogQuery (AR-6, NFR-22): keyset-пагинация
 * по (created_at, id), агрегированный статус при мультилоте — по id страницы
 * (FR-1.1.3, вариант C). Ответ — {items, next_cursor}; next_cursor — OPAQUE-курсор
 * для следующей страницы (null — страниц больше нет). Доступ — право
 * tenders.board.view через TenderVoter.
 */
final readonly class ListTendersUseCase implements TenderUseCase
{
    public const int DEFAULT_LIMIT = 20;
    public const int MAX_LIMIT = 100;

    public function __construct(
        private TenderCatalogQuery $catalog,
        private TenderPresenter $presenter,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function execute(User $user, ?string $status = null, ?string $limit = null, ?string $cursor = null): array
    {
        $page = $this->catalog->page(
            InputValue::companyId($user),
            $this->statusEnum($status),
            $cursor,
            $this->limit($limit),
        );

        $items = array_map(
            fn (array $row): array => $this->presenter->listItemFromRow($row),
            $page->items,
        );

        return ['items' => $items, 'next_cursor' => $page->nextCursor];
    }

    /**
     * @throws ValidationException если статус не из TenderStatusEnum
     */
    private function statusEnum(?string $status): ?TenderStatusEnum
    {
        if (null === $status || '' === $status) {
            return null;
        }

        $enum = TenderStatusEnum::tryFrom($status);
        if (null === $enum) {
            throw new ValidationException('invalid status');
        }

        return $enum;
    }

    /**
     * Лимит страницы: default 20, диапазон 1..100 (openapi Limit). Значения
     * вне диапазона клампятся, нечисловые — 422.
     *
     * @throws ValidationException если limit не является положительным целым
     */
    private function limit(?string $raw): int
    {
        if (null === $raw || '' === $raw) {
            return self::DEFAULT_LIMIT;
        }
        if (!preg_match('/^\d+$/', $raw)) {
            throw new ValidationException('limit must be a positive integer');
        }

        return max(1, min(self::MAX_LIMIT, (int) $raw));
    }
}
