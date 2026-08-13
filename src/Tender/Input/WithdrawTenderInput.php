<?php

declare(strict_types=1);

namespace App\Tender\Input;

/**
 * Входные данные отзыва публикации (B3, FR-1.1.3, POST /tenders/{tenderId}/withdraw).
 * reason — свободный текст причины отзыва (обязателен, до 500 символов).
 * Отзыв допустим только до старта приёма заявок (published → withdrawn).
 */
final class WithdrawTenderInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $reason = '';
}
