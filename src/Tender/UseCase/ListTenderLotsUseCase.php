<?php

declare(strict_types=1);

namespace App\Tender\UseCase;

use App\Iam\Entity\User;
use App\Shared\Input\InputValue;
use App\Tender\Service\TenderVisibilityService;
use App\Tender\TenderPresenter;
use App\Tender\TenderService;

/**
 * Лоты тендера (FR-1.1.1, GET /tenders/{tenderId}/lots).
 *
 * Query-use-case: чтение без мутаций. Видимость тендера (404 для невидимого)
 * — в TenderService::get; состав списка для зрителя (FR-1.5.14) —
 * TenderVisibilityService::lotViewOf: завершённые и отменённые лоты видны
 * заказчику и исполнителю лота, прочим нет. Доступ — право tenders.board.view
 * через TenderVoter.
 */
final readonly class ListTenderLotsUseCase implements TenderUseCase
{
    public function __construct(
        private TenderService $tenders,
        private TenderVisibilityService $visibility,
        private TenderPresenter $presenter,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function execute(User $user, string $tenderId): array
    {
        $tender = $this->tenders->get($user, $tenderId);

        // Порядок лотов (по номеру) задан маппингом Tender::$lots (ORM\OrderBy),
        // поэтому сортировать здесь нечего: presenter читает ту же коллекцию.
        return [
            'items' => $this->presenter->lotsList(
                $tender,
                $this->visibility->lotViewOf($tender, InputValue::companyId($user)),
            ),
        ];
    }
}
