<?php

declare(strict_types=1);

namespace App\ProcurementPlan\Input;

/**
 * Входные данные создания плана закупок (FR-1.5.6, POST /procurement-plans).
 * period — период плана (ISO-дата/год, строка); items — позиции плана
 * (subject/okpd2/volume/planned_date/method из openapi ProcurementPlanCreate).
 */
final class ProcurementPlanCreateInput
{
    public string $period = '';

    /** @var list<array<string, mixed>> */
    public array $items = [];
}
