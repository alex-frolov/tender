<?php

declare(strict_types=1);

namespace App\Bid\Input;

/**
 * Входные данные решения по заявке (FR-1.2.4, POST /bids/{bidId}/qualification).
 * decision — admit|reject (BidDecisionEnum); reason — обязательная причина
 * (до 1000 символов), сохраняется в decision_reason и аудите; при отклонении
 * уведомляется участник.
 */
final class QualifyBidInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $decision = '';

    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $reason = '';
}
