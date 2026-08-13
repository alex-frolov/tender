<?php

declare(strict_types=1);

namespace App\Tender\UseCase;

use App\Iam\Entity\User;
use App\Tender\TenderPresenter;
use App\Tender\TenderService;

/**
 * Публикация тендера (FR-1.1.4, POST /tenders/{tenderId}/publish).
 *
 * draft → published, расчёт таймлайна и планирование авто-переходов — в
 * TenderService::publish; ответ — TenderPresenter::single. Доступ — право
 * tenders.publish через TenderVoter; принадлежность компании (tenant-изоляция)
 * в доменном сервисе.
 */
final readonly class PublishTenderUseCase implements TenderUseCase
{
    public function __construct(
        private TenderService $tenders,
        private TenderPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация тендера (openapi Tender)
     */
    public function execute(User $user, string $tenderId, ?string $ip = null): array
    {
        return $this->presenter->single($this->tenders->publish($user, $tenderId, $ip));
    }
}
