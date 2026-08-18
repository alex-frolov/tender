<?php

declare(strict_types=1);

namespace App\Question\Presenter;

use App\Question\Entity\TenderQuestion;

/**
 * Публичное представление вопроса по тендеру (openapi Question).
 *
 * Поля строго по схеме Question из api/openapi.yaml.
 */
final readonly class TenderQuestionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function single(TenderQuestion $question): array
    {
        return [
            'id' => (string) $question->getId(),
            'tender_id' => (string) $question->getTenderId(),
            'lot_id' => null !== $question->getLotId() ? (string) $question->getLotId() : null,
            'text' => $question->getText(),
            'answer' => $question->getAnswer(),
            'published_at' => $question->getPublishedAt()?->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
