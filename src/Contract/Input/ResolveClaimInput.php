<?php

declare(strict_types=1);

namespace App\Contract\Input;

/**
 * Входные данные урегулирования претензии (FR-1.4.5, POST /claims/{claimId}/resolve).
 * outcome: rejected/settled → IN_WORK; accepted → DONE_BY_CLAIM;
 * terminate_contract → CANCELLED. Публичные nullable-поля (data_class ResolveClaimType).
 */
final class ResolveClaimInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $outcome = '';

    public ?string $resolution = null;

    public ?int $acceptedAmountMinor = null;
}
