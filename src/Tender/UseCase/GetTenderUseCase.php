<?php

declare(strict_types=1);

namespace App\Tender\UseCase;

use App\Iam\Entity\User;
use App\Shared\Input\InputValue;
use App\Tender\Service\TenderVisibilityService;
use App\Tender\TenderPresenter;
use App\Tender\TenderService;

/**
 * Карточка тендера (FR-1.1.1, GET /tenders/{tenderId}).
 *
 * Query-use-case: чтение без мутаций. Видимость самого тендера (404 для
 * невидимого) — в TenderService::get; состав карточки для зрителя (какие лоты
 * и раскрывается ли победитель, FR-1.5.14) — TenderVisibilityService::lotViewOf.
 * Ответ — TenderPresenter::single. Доступ — право tenders.board.view через
 * TenderVoter.
 */
final readonly class GetTenderUseCase implements TenderUseCase
{
    public function __construct(
        private TenderService $tenders,
        private TenderVisibilityService $visibility,
        private TenderPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация тендера (openapi Tender)
     */
    public function execute(User $user, string $tenderId): array
    {
        $tender = $this->tenders->get($user, $tenderId);

        return $this->presenter->single(
            $tender,
            $this->visibility->lotViewOf($tender, InputValue::companyId($user)),
        );
    }
}
