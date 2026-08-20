<?php

declare(strict_types=1);

namespace App\ProcurementPlan\UseCase;

use App\Iam\Entity\User;
use App\ProcurementPlan\Entity\ProcurementPlan;
use App\ProcurementPlan\Presenter\ProcurementPlanPresenter;
use App\ProcurementPlan\Service\ProcurementPlanService;
use App\Shared\Exception\ConflictException;
use App\Shared\Input\Paginator;
use App\Shared\Repository\CursorDirection;
use App\Shared\Repository\KeysetCursor;

/**
 * Список планов закупок компании (FR-1.5.6, GET /procurement-plans).
 *
 * Query-use-case: планы компании (новые сверху), keyset-пагинация
 * (created_at, id) DESC через KeysetCursor::sliceAfter (in-memory срез —
 * планы компании ограниченный набор). Доступ: любой сотрудник компании
 * (agent — минимальная роль).
 */
final readonly class ListProcurementPlansUseCase implements ProcurementPlanUseCase
{
    public function __construct(
        private ProcurementPlanService $plans,
        private ProcurementPlanPresenter $presenter,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     *
     * @throws ConflictException если актор без компании
     */
    public function execute(User $user, Paginator $paginator): array
    {
        $companyId = $user->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        [$page, $nextCursor] = KeysetCursor::sliceAfter(
            $this->plans->listForCompany($companyId),
            $paginator->cursor,
            $paginator->limitValue(),
            static fn (ProcurementPlan $p): array => [$p->getCreatedAt(), (string) $p->getId()],
            CursorDirection::DESC,
        );

        $items = array_map(
            fn (ProcurementPlan $p): array => $this->presenter->single($p),
            $page,
        );

        return ['items' => $items, 'next_cursor' => $nextCursor];
    }
}
