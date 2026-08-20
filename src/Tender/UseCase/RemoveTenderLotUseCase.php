<?php

declare(strict_types=1);

namespace App\Tender\UseCase;

use App\Iam\Entity\User;
use App\Tender\TenderService;

/**
 * Удаление лота (FR-1.1.7, DELETE /tenders/{tenderId}/lots/{lotId}).
 *
 * Оркестрация в TenderService::removeLot (editable-проверка, запрет удаления
 * последнего лота, перенумерация оставшихся). Доступ — право tenders.update
 * через TenderVoter. Ответ — 204 No Content.
 */
final readonly class RemoveTenderLotUseCase implements TenderUseCase
{
    public function __construct(private TenderService $tenders)
    {
    }

    public function execute(User $user, string $tenderId, string $lotId, ?string $ip = null): void
    {
        $this->tenders->removeLot($user, $tenderId, $lotId, $ip);
    }
}
