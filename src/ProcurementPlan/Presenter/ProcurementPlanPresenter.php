<?php

declare(strict_types=1);

namespace App\ProcurementPlan\Presenter;

use App\ProcurementPlan\Entity\ProcurementPlan;

/**
 * Публичное представление плана закупок (openapi ProcurementPlan).
 *
 * Поля строго по схеме ProcurementPlan из api/openapi.yaml.
 */
final readonly class ProcurementPlanPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function single(ProcurementPlan $plan): array
    {
        return [
            'id' => (string) $plan->getId(),
            'company_id' => (string) $plan->getCompanyId(),
            'period' => $plan->getPeriod(),
            'status' => $plan->getStatus()->value,
            'items' => $plan->getItems(),
        ];
    }
}
