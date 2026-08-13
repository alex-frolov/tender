<?php

declare(strict_types=1);

namespace App\Iam\Input;

/**
 * Входные данные модерации компании (FR-1.5.7).
 * Заполняется формой CompanyVerifyType из JSON-тела POST /companies/{companyId}/verify.
 * action — ChoiceType через enum (approve/reject/suspend); reason опционален,
 * обязательность для reject проверяет сервис (бизнес-правило).
 */
final class CompanyVerifyInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $action = '';

    public ?string $reason = null;
}
