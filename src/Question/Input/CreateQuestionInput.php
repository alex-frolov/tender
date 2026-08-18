<?php

declare(strict_types=1);

namespace App\Question\Input;

/**
 * Входные данные создания вопроса по тендеру (FR-1.2.9,
 * POST /tenders/{tenderId}/questions).
 * lot_id — опционален (вопрос по тендеру в целом); text — текст вопроса
 * (обязателен, ≤ 4000). Валидация — в форме.
 */
final class CreateQuestionInput
{
    public ?string $lotId = null;

    public string $text = '';
}
