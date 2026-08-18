<?php

declare(strict_types=1);

namespace App\Tender\UseCase;

use App\Iam\Entity\User;
use App\Tender\Input\LotUpdateInput;
use App\Tender\TenderPresenter;
use App\Tender\TenderService;

/**
 * Изменение лота (FR-1.1.7, PATCH /tenders/{tenderId}/lots/{lotId}).
 *
 * Вход — валидированный LotUpdateInput (поле не указано = не менять);
 * оркестрация в TenderService::updateLot (editable-проверка + инвариант суммы),
 * ответ — презентация лота. Доступ — право tenders.update через TenderVoter.
 */
final readonly class UpdateTenderLotUseCase implements TenderUseCase
{
    public function __construct(
        private TenderService $tenders,
        private TenderPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация лота (openapi Lot)
     */
    public function execute(User $user, string $tenderId, string $lotId, LotUpdateInput $input, ?string $ip = null): array
    {
        $lot = $this->tenders->updateLot($user, $tenderId, $lotId, $input, $ip);
        $tender = $this->tenders->get($user, $tenderId);

        return $this->presenter->singleLot($lot, $tender);
    }
}
