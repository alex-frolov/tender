<?php

declare(strict_types=1);

namespace App\ProcurementPlan\Entity\Enum;

/**
 * Статус плана закупок (procurement_plans.status, FR-1.5.6).
 */
enum ProcurementPlanStatusEnum: string
{
    case DRAFT = 'draft';
}
