<?php

declare(strict_types=1);

namespace App\Tender\Input;

/**
 * Входные данные отмены тендера (FR-1.1.8, POST /tenders/{tenderId}/cancel).
 * cancellationReasonCode — код причины из CancellationReasonEnum (обязателен);
 * cancellationReasonText — свободный текст, обязателен при code=other.
 * Сохраняется в тендере, аудите и событии tender.cancelled.
 */
final class CancelTenderInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $cancellationReasonCode = '';

    public ?string $cancellationReasonText = null;
}
