<?php

declare(strict_types=1);

namespace App\Contract\Input;

/**
 * Входные данные подписания договора (FR-1.4.3, AM-9 POST /contracts/{id}/sign).
 * party — customer|supplier; signature — ЭП/УКЭП-заглушка (в MVP произвольная
 * строка). Публичные nullable-поля (data_class формы ContractSignType).
 */
final class SignContractInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $party = '';

    public ?string $signature = null;
}
