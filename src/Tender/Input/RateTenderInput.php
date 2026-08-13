<?php

declare(strict_types=1);

namespace App\Tender\Input;

/**
 * Входные данные оценки исполнения (FR-1.1.10, UC-10c, POST /tenders/{tenderId}/rating).
 * execution_rating — int 1..10 (nullable: сброс оценки).
 * Публичные nullable-поля (data_class формы TenderRateType).
 */
final class RateTenderInput
{
    public ?int $executionRating = null;
}
