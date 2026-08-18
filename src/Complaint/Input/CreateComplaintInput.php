<?php

declare(strict_types=1);

namespace App\Complaint\Input;

/**
 * Входные данные подачи жалобы по тендеру (FR-1.2.10,
 * POST /tenders/{tenderId}/complaints).
 * lot_id — опционален; text и ground обязательны; document_ids — приложения.
 * Валидация — в форме.
 */
final class CreateComplaintInput
{
    public ?string $lotId = null;

    public string $text = '';

    public string $ground = '';

    /** @var list<string> */
    public array $documentIds = [];
}
