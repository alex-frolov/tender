<?php

declare(strict_types=1);

namespace App\ProcurementPlan\UseCase;

use App\Iam\Entity\User;
use App\ProcurementPlan\Input\ProcurementPlanCreateInput;
use App\ProcurementPlan\Presenter\ProcurementPlanPresenter;
use App\ProcurementPlan\Service\ProcurementPlanService;
use App\Shared\Exception\ConflictException;

/**
 * Создание плана закупок (FR-1.5.6, POST /procurement-plans).
 *
 * Только admin компании (IsGranted в контроллере); lazy-валидация период —
 * в сервисе. Ответ — ProcurementPlanPresenter::single (openapi ProcurementPlan).
 */
final readonly class CreateProcurementPlanUseCase implements ProcurementPlanUseCase
{
    public function __construct(
        private ProcurementPlanService $plans,
        private ProcurementPlanPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация плана (openapi ProcurementPlan)
     *
     * @throws ConflictException если актор без компании
     */
    public function execute(User $user, ProcurementPlanCreateInput $input, ?string $ip = null): array
    {
        $companyId = $user->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        return $this->presenter->single($this->plans->create($companyId, $input, (string) $user->getId(), $ip));
    }
}
