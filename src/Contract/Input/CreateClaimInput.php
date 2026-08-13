<?php

declare(strict_types=1);

namespace App\Contract\Input;

/**
 * Входные данные создания претензии (FR-1.4.5, POST /claims).
 * contract_id — договор; stage — стадия (approve/in_work/done_by_performer);
 * reason — основание; amount_minor — сумма (копейки); document_ids —
 * приложенные документы. Публичные nullable-поля (data_class CreateClaimType).
 */
final class CreateClaimInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $contractId = '';

    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $stage = '';

    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $reason = '';

    public ?string $description = null;

    public ?int $amountMinor = null;

    /** @var array<int, string>|null */
    public ?array $documentIds = null;
}
