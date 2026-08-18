<?php

declare(strict_types=1);

namespace App\Question\UseCase;

use App\Iam\Entity\User;
use App\Question\Presenter\TenderQuestionPresenter;
use App\Question\Service\TenderQuestionService;

/**
 * Список вопросов/ответов по тендеру (FR-1.2.9, GET /tenders/{tenderId}/questions).
 *
 * Query-use-case: вопросы (новые сверху) без пагинации (ограниченный набор).
 * Доступ — право tenders.qa через TenderQaVoter.
 */
final readonly class ListQuestionsUseCase implements QuestionUseCase
{
    public function __construct(
        private TenderQuestionService $questions,
        private TenderQuestionPresenter $presenter,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function execute(User $user, string $tenderId): array
    {
        $items = [];
        foreach ($this->questions->listForTender($tenderId) as $question) {
            $items[] = $this->presenter->single($question);
        }

        return ['items' => $items];
    }
}
