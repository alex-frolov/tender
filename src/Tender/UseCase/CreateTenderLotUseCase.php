<?php

declare(strict_types=1);

namespace App\Tender\UseCase;

use App\Iam\Entity\User;
use App\Tender\Input\LotCreateInput;
use App\Tender\TenderPresenter;
use App\Tender\TenderService;

/**
 * Добавление лота в тендер (FR-1.1.7, POST /tenders/{tenderId}/lots).
 *
 * Вход — валидированный LotCreateInput (форма LotCreateType); оркестрация в
 * TenderService::addLot (editable-проверка + инвариант суммы лотов), ответ —
 * презентация лота. Доступ — право tenders.update через TenderVoter
 * (admin/manager; agent — 403).
 */
final readonly class CreateTenderLotUseCase implements TenderUseCase
{
    public function __construct(
        private TenderService $tenders,
        private TenderPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация лота (openapi Lot)
     */
    public function execute(User $user, string $tenderId, LotCreateInput $input, ?string $ip = null): array
    {
        $lot = $this->tenders->addLot($user, $tenderId, $input, $ip);
        $tender = $this->tenders->get($user, $tenderId);

        return $this->presenter->singleLot($lot, $tender);
    }
}
