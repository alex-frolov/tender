<?php

declare(strict_types=1);

namespace App\Question\Input;

/**
 * Входные данные ответа на вопрос по тендеру (FR-1.2.9,
 * POST /tenders/{tenderId}/questions/{questionId}/answer).
 * answer — текст разъяснения заказчика (обязателен, ≤ 4000). Валидация — в форме.
 */
final class AnswerQuestionInput
{
    public string $answer = '';
}
