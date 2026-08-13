<?php

declare(strict_types=1);

namespace App\Bid\Input;

/**
 * Входные данные отзыва заявки (FR-1.2.5, AM-4).
 * reason — обязательная причина отзыва (до 500 символов), сохраняется
 * в decision_reason и аудите.
 */
final class WithdrawBidInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $reason = '';
}
