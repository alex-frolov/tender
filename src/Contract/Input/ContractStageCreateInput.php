<?php

declare(strict_types=1);

namespace App\Contract\Input;

/**
 * Входные данные создания этапа исполнения (FR-1.4.3, UC-10,
 * POST /contract_tenders/{contractTenderId}/stages).
 * number опционален — при отсутствии назначается следующий по порядку;
 * due_at — срок этапа (ISO-8601, UTC).
 */
final class ContractStageCreateInput
{
    public ?int $number = null;

    public string $title = '';

    public ?int $amountMinor = null;

    public ?string $dueAt = null;
}
