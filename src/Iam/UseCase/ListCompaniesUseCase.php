<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\CompanyStatusEnum;
use App\Iam\Input\CompanyListFiltersInput;
use App\Iam\Presenter\CompanyPresenter;
use App\Iam\Repository\CompanyRepository;
use App\Shared\Input\Paginator;
use App\Shared\Repository\KeysetCursor;

/**
 * Реестр компаний площадки для модерации (FR-1.5.7, GET /admin/companies).
 *
 * Query-use-case: чтение без мутаций. Доступ — только platform_admin
 * (CompanyVoter::VERIFY на контроллере): это единственный экран, с которого
 * суперадмин видит заявки на верификацию и вызывает
 * POST /companies/{companyId}/verify. Tenant-изоляции нет намеренно — реестр
 * платформенный, а не компанийный.
 *
 * Фильтры — status/q (CompanyListFiltersType), пагинация — keyset-курсор
 * по (created_at, id) DESC (AR-6): запрашивается limit+1 строк, лишняя
 * означает следующую страницу и даёт next_cursor.
 */
final readonly class ListCompaniesUseCase implements IamUseCase
{
    public function __construct(
        private CompanyRepository $companies,
        private CompanyPresenter $presenter,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function execute(CompanyListFiltersInput $filters, Paginator $paginator): array
    {
        $limit = $paginator->limitValue();
        $cursor = KeysetCursor::decode($paginator->cursor);

        $rows = $this->companies->listPage(
            status: null === $filters->status ? null : CompanyStatusEnum::from($filters->status),
            q: $filters->q,
            cursorCreatedAt: $cursor?->createdAt,
            cursorId: $cursor?->id,
            limit: $limit + 1,
        );

        $hasMore = \count($rows) > $limit;
        if ($hasMore) {
            $rows = \array_slice($rows, 0, $limit);
        }

        $nextCursor = null;
        if ($hasMore && [] !== $rows) {
            $last = $rows[\count($rows) - 1];
            $nextCursor = KeysetCursor::encode($last->getCreatedAt(), $last->getId());
        }

        return [
            'items' => array_map(
                fn (Company $company): array => $this->presenter->single($company),
                $rows,
            ),
            'next_cursor' => $nextCursor,
        ];
    }
}
