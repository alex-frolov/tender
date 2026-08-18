<?php

declare(strict_types=1);

namespace App\Tender\UseCase;

use App\Iam\Entity\User;
use App\Shared\Input\InputValue;
use App\Shared\Input\Paginator;
use App\Tender\Entity\Enum\AccessTypeEnum;
use App\Tender\Entity\Enum\LawTypeEnum;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Input\TenderListFiltersInput;
use App\Tender\TenderCatalogQuery;
use App\Tender\TenderFilters;
use App\Tender\TenderPresenter;

/**
 * Список тендеров компании (FR-1.1.1, GET /tenders).
 *
 * Query-use-case на read-модели TenderCatalogQuery (AR-6, NFR-22): keyset-пагинация
 * по (created_at, id), агрегированный статус при мультилоте — по id страницы
 * (FR-1.1.3, вариант C). Ответ — {items, next_cursor}; next_cursor — OPAQUE-курсор
 * для следующей страницы (null — страниц больше нет). Вход уже провалидирован
 * формами (TenderListFiltersType + PaginatorForm) — здесь только маппинг DTO в
 * доменный TenderFilters и лимит из Paginator. Доступ — право tenders.board.view
 * через TenderVoter.
 */
final readonly class ListTendersUseCase implements TenderUseCase
{
    public function __construct(
        private TenderCatalogQuery $catalog,
        private TenderPresenter $presenter,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function execute(User $user, TenderListFiltersInput $filters, Paginator $paginator): array
    {
        $page = $this->catalog->page(
            InputValue::companyId($user),
            $this->toDomainFilters($filters),
            $paginator->cursor,
            $paginator->limitValue(),
        );

        $items = array_map(
            fn (array $row): array => $this->presenter->listItemFromRow($row),
            $page->items,
        );

        return ['items' => $items, 'next_cursor' => $page->nextCursor];
    }

    /**
     * Маппинг DTO фильтров (формы) в доменный объект каталога.
     */
    private function toDomainFilters(TenderListFiltersInput $input): TenderFilters
    {
        return new TenderFilters(
            q: $this->trimmedOrNull($input->q),
            status: null !== $input->status && '' !== $input->status
                ? TenderStatusEnum::from($input->status)
                : null,
            lawType: null !== $input->lawType && '' !== $input->lawType
                ? LawTypeEnum::from($input->lawType)
                : null,
            region: $this->trimmedOrNull($input->region),
            okpd2: $this->trimmedOrNull($input->okpd2),
            priceMin: $input->priceMin,
            priceMax: $input->priceMax,
            accessType: null !== $input->accessType && '' !== $input->accessType
                ? AccessTypeEnum::from($input->accessType)
                : null,
        );
    }

    private function trimmedOrNull(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
