<?php

declare(strict_types=1);

namespace App\Tender\UseCase;

use App\Iam\Entity\User;
use App\Tender\Entity\Tender;
use App\Tender\Input\RateTenderInput;
use App\Tender\TenderPresenter;
use App\Tender\TenderService;

/**
 * Оценка исполнения заказа (FR-1.1.10, UC-10c, POST /tenders/{tenderId}/rating).
 *
 * Заказчик выставляет оценку (1–10) ПОСЛЕ завершения исполнения
 * (DONE/DONE_BY_CLAIM); хранится в тендере (execution_rating). Тендер приходит
 * через #[MapEntity] (субъект TenderVoter::RATE). Оркестрация — TenderService::rate,
 * ответ — TenderPresenter::single. Доступ — право tenders.rate через TenderVoter.
 */
final readonly class RateTenderUseCase implements TenderUseCase
{
    public function __construct(
        private TenderService $tenders,
        private TenderPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация тендера (openapi Tender)
     */
    public function execute(Tender $tender, User $user, RateTenderInput $input, ?string $ip = null): array
    {
        return $this->presenter->single($this->tenders->rate(
            $user,
            (string) $tender->getId(),
            $input->executionRating,
            $ip,
        ));
    }
}
